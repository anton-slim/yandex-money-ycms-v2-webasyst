<?php
require_once dirname(__FILE__).'/../vendor/autoload.php';
require_once dirname(__FILE__).'/helpers.php';

use YandexCheckout\Client;
use YandexCheckout\Common\Exceptions\ApiException;
use YandexCheckout\Model\ConfirmationType;
use YandexCheckout\Model\Notification\NotificationSucceeded;
use YandexCheckout\Model\Notification\NotificationWaitingForCapture;
use YandexCheckout\Model\NotificationEventType;
use YandexCheckout\Model\Payment;
use YandexCheckout\Model\PaymentData\PaymentDataAlfabank;
use YandexCheckout\Model\PaymentData\PaymentDataQiwi;
use YandexCheckout\Model\PaymentMethodType;
use YandexCheckout\Model\PaymentStatus;
use YandexCheckout\Request\Payments\CreatePaymentRequest;
use YandexCheckout\Request\Payments\CreatePaymentRequestBuilder;
use YandexCheckout\Request\Payments\CreatePaymentRequestSerializer;
use YandexCheckout\Request\Payments\Payment\CreateCaptureRequest;
use YandexCheckout\Request\Payments\Payment\CreateCaptureRequestBuilder;

/**
 *
 * @author Webasyst
 * @name YandexMoney
 * @description YandexMoney payment module
 * @property-read string $integration_type
 * @property-read string $TESTMODE
 * @property-read string $shopPassword
 * @property-read string $ShopID
 * @property-read string $scid
 * @property-read string $payment_mode
 * @property-read array $paymentType
 *
 * @see https://money.yandex.ru/doc.xml?id=526537
 */
class yamodulepay_apiPayment extends waPayment implements waIPayment
{
    const DEFAULT_VAT_CODE = 1;

    const ORDER_STATE_COMPLETE = 'paid';

    const INSTALLMENTS_MIN_AMOUNT = 3000;


    private $version = '1.1.0';

    private $errors;


    public function allowedCurrency()
    {
        return 'RUB';
    }

    public static function extendItems(&$order)
    {
        $items         = $order->items;
        $product_model = new shopProductModel();
        $discount      = $order->discount;
        foreach ($items as & $item) {
            $data             = $product_model->getById($item['product_id']);
            $item['tax_id']   = ifset($data['tax_id']);
            $item['currency'] = $order->currency;
            if (!empty($item['total_discount'])) {
                $discount      -= $item['total_discount'];
                $item['total'] -= $item['total_discount'];
                $item['price'] -= $item['total_discount'] / $item['quantity'];
            }
        }

        unset($item);

        $discount_rate = $order->total ? ($order->discount / ($order->total + $order->discount - $order->tax - $order->shipping)) : 0;

        $taxes_params = array(
            'billing'       => $order->billing_address,
            'shipping'      => $order->shipping_address,
            'discount_rate' => $discount_rate,
        );
        shopTaxes::apply($items, $taxes_params, $order->currency);

        if ($discount) {
            $k = 1 - $discount_rate;

            foreach ($items as & $item) {
                if ($item['tax_included']) {
                    $item['tax'] = round($k * $item['tax'], 4);
                }

                $item['price'] = round($k * $item['price'], 4);
                $item['total'] = round($k * $item['total'], 4);
            }

            unset($item);
        }

        $order->items = $items;

        return $items;
    }

    /**
     * @param $payment_form_data
     * @param $order_data
     * @param bool $auto_submit
     * @return array
     * @throws Exception
     */
    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $waOrder = waOrder::factory($order_data);
        if ($waOrder['currency_id'] != 'RUB') {
            return array(
                'type' => 'error',
                'data' => _w(
                    'Оплата на сайте Яндекс.Денег производится в только в рублях (RUB) и в данный момент невозможна, так как эта валюта не определена в настройках.'
                ),
            );
        }

        require_once dirname(__FILE__).'/../api/yamoney.php';

        $app_m  = new waAppSettingsModel();
        $yclass = new YandexMoney();
        $data   = $app_m->get('shop.yamodule_api');

        $_SESSION['order_data']                       = array();
        $p2p                                          = (bool)$data['ya_p2p_active'];
        $_SESSION['order_data'][$waOrder['order_id']] = array(
            $this->app_id,
            $this->merchant_id,
        );

        $view = wa()->getView();
        $view->assign('amount', $waOrder['total']);
        $view->assign('shop_id', $data['ya_kassa_shopid']);
        if ((bool)$data['ya_kassa_active']) {
            if (isset($payment_form_data['paymentType']) && $this->validate($payment_form_data)) {
                $this->debugLog('Payment init');
                $result = $this->createPayment($waOrder, $payment_form_data, $data);

                if ($result) {
                    $transaction_data = array(
                        'plugin'      => $this->id,
                        'type'        => self::OPERATION_HOSTED_PAYMENT_AFTER_ORDER,
                        'order_id'    => $waOrder->id,
                        'merchant_id' => $this->merchant_id,
                        'native_id'   => $result->getId(),
                        'state'       => self::STATE_AUTH,
                        'result'      => '',
                    );

                    $transactionModel = new waTransactionModel();
                    $transaction      = $this->getTransactionByOrder($transactionModel, $waOrder);

                    if ($transaction) {
                        if ($transactionModel->updateById($transaction['id'], $transaction_data)) {
                            wa()->getResponse()->redirect($result->confirmation->confirmationUrl);
                        }
                    } else {
                        if ($transactionModel->insert($transaction_data)) {
                            wa()->getResponse()->redirect($result->confirmation->confirmationUrl);
                        }
                    }

                } else {
                    $this->errors[] = 'Платеж не прошел. Попробуйте еще или выберите другой способ оплаты';
                    if ($this->errors) {
                        $view->assign('errors', $this->errors);
                    }
                    $this->assignKassaVariables($waOrder, $data, $yclass, $view);

                    return $view->fetch($this->path.'/templates/payment.html');
                }
            } else {
                $this->assignKassaVariables($waOrder, $data, $yclass, $view);
            }
            if ($this->errors) {
                $view->assign('errors', $this->errors);
            }
        }
        if ((bool)$data['ya_p2p_active']) {
            $transactionModel = new waTransactionModel();
            $transactionData  = $this->getTransactionByOrder($transactionModel, $waOrder);
            $this->changeOrderState($waOrder, self::ORDER_STATE_COMPLETE);
            $redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transactionData);
            $view->assign('receiver', $data['ya_p2p_number']);
            $view->assign('orderId', $waOrder['id']);
            $view->assign('targets', 'Оплата заказа '.$waOrder->data['id_str']);
            $view->assign('amount', number_format($waOrder['total'], 2, '.', ''));
            $view->assign('successURL', $redirect);

            return $view->fetch($this->path.'/templates/wallet_payment.html');
        }
        if ((bool)$data['ya_billing_active']) {
            $this->assignBillingVariables($waOrder, $data, $yclass, $view);

            return $view->fetch($this->path.'/templates/billing_payment.html');
        }
        $view->assign('p2p', $p2p);
        $view->assign('auto_submit', false);

        return $view->fetch($this->path.'/templates/payment.html');
    }

    public static function log_save($logtext)
    {
        $real_log_file = './ya_logs/'.date('Y-m-d').'.log';
        $h             = fopen($real_log_file, 'ab');
        fwrite($h, date('Y-m-d H:i:s ').'['.addslashes($_SERVER['REMOTE_ADDR']).'] '.$logtext."\n");
        fclose($h);
    }

    /**
     *
     * @param array $request - get from gateway
     *
     * @return mixed
     * @throws Exception
     */
    protected function callbackHandler($request)
    {
        $shopPath = wa()->getConfig()->getAppConfig('shop')->getAppPath();
        require_once dirname(__FILE__).'/../api/yamoney.php';
        require_once $shopPath.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'model'.DIRECTORY_SEPARATOR.'shopOrder.model.php';
        require_once $shopPath.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'model'.DIRECTORY_SEPARATOR.'shopOrderParams.model.php';
        require_once $shopPath.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'workflow'.DIRECTORY_SEPARATOR.'shopWorkflow.class.php';

        $app_m    = new waAppSettingsModel();
        $settings = $app_m->get('shop.yamodule_api');
        $yclass   = new YandexMoney();
        $data     = $app_m->get('shop.yamodule_api');

        if (isset($request['action']) && $request['action'] == 'return') {
            $this->processReturnUrl($settings);
        }

        if (isset($request['action']) && $request['action'] == 'callback') {
            if (!empty($request['genToken'])) {
                $action = new shopYamodule_apiPluginSettingsAction();
                $action->execute();
                exit();
            }

            if (waRequest::getMethod() == 'get') {
                $version       = wa()->getVersion('webasyst');
                $versionChunks = explode('.', $version);
                if (isset($versionChunks[3])) {
                    unset($versionChunks[3]);
                }

                $vesrionString = implode('.', $versionChunks);
                header("HTTP/1.1 405 Method Not Allowed");
                echo '{
                    module_version: "'.$this->version.'",
                    cms_version: "'.$vesrionString.'"
                }';
                exit();
            }

            $source = @file_get_contents('php://input');
            if (empty($source)) {
                header('HTTP/1.1 400 Empty request body');
                $this->debugLog('warning: Empty notification request body');
                exit();
            }
            $callbackParams = json_decode($source, true);
            if (empty($callbackParams)) {
                if (json_last_error() === JSON_ERROR_NONE) {
                    $message = 'empty object in body';
                } else {
                    $message = 'invalid object in body: '.$source;
                }
                $this->debugLog('warning: Invalid parameters in capture notification controller - '.$message);
                header('HTTP/1.1 400 Invalid json object in body');
                exit();
            }

            $this->debugLog('info: Notification: '.$source);

            try {
                $notification = ($callbackParams['event'] === NotificationEventType::PAYMENT_SUCCEEDED)
                    ? new NotificationSucceeded($callbackParams)
                    : new NotificationWaitingForCapture($callbackParams);
            } catch (\Exception $e) {
                $this->debugLog('error: Invalid notification object - '.$e->getMessage());
                header('HTTP/1.1 400 Invalid object in body');
                exit();
            }
            $payment            = $notification->getObject();
            $transaction        = $this->getTransactionByPaymentId($payment->getId());
            $transactionModel   = new waTransactionModel();
            $orderModel         = new shopOrderModel();
            $order              = $orderModel->getOrder($transaction['order_id']);
            if (!$order) {
                header("HTTP/1.1 404 Not Found");
                header("Status: 404 Not Found");
                $this->debugLog('error: Order empty '.$transaction['order_id'].' заказа '.json_encode($order));
                exit();
            }
            $this->debugLog('info: Проведение платежа '.$notification->getObject()->getId()
                            .' заказа '.json_encode($order));

            $shopId       = $settings['ya_kassa_shopid'];
            $shopPassword = $settings['ya_kassa_pw'];
            $apiClient    = $this->getApiClient($shopId, $shopPassword);
            $payment      = $apiClient->getPaymentInfo($payment->getId());

            $this->debugLog('debug: $paymentInfoResponse '.json_encode($payment));

            if ($notification->getEvent() === NotificationEventType::PAYMENT_WAITING_FOR_CAPTURE
                && $payment->getStatus() === PaymentStatus::WAITING_FOR_CAPTURE
            ) {
                if ($payment->getPaymentMethod()->getType() === PaymentMethodType::BANK_CARD) {
                    if ($this->changeOrderState($order, $settings['ya_kassa_hold_order_status'])) {
                        $text = sprintf('Поступил новый платёж. Он ожидает подтверждения до %1$s, после чего автоматически отменится',
                            $payment->getExpiresAt()->format('d.m.Y H:i'));
                        $this->addOrderLogComment($order['id'], $text);
                        $this->debugLog('Payment hold. Payment id: '.$payment->getId());
                        exit();
                    } else {
                        $this->debugLog('Hold payment failed. Payment id: '.$payment->getId());
                    }
                } elseif ($this->capturePayment($payment->getId(), $payment)) {
                    $this->debugLog('Payment capture. Payment id: '.$payment->getId());
                    exit();
                }
            }
            if ($notification->getEvent() === NotificationEventType::PAYMENT_SUCCEEDED
                && $payment->getStatus() === PaymentStatus::SUCCEEDED
            ) {
                if ($this->changeOrderState($order, self::ORDER_STATE_COMPLETE)) {
                    $transactionModel->updateById($transaction['id'], array('state' => self::ORDER_STATE_COMPLETE));
                    $text = "Номер транзакции в Яндекс.Кассе: {$payment->getId()}. Сумма: {$payment->getAmount()->getValue()}";
                    $this->addOrderLogComment($order['id'], $text);
                    $this->debugLog('Payment completed. Payment id: '.$payment->getId());
                    exit();
                } else {
                    $this->debugLog('Complete payment failed. Payment id: '.$payment->getId());
                }
            }

            header('HTTP/1.1 500 Internal server error');
            exit();
        }

        if (waRequest::request('orderNumber')) {
            $yclass->password = $data['ya_kassa_pw'];
            $yclass->shopid   = $data['ya_kassa_shopid'];
            if (waRequest::get('result') || waRequest::request('action') == 'PaymentFail' || waRequest::request(
                    'action'
                ) == 'PaymentSuccess'
            ) {
                $match = explode('/', waRequest::request('orderNumber'));
                if (waRequest::request('action') == 'PaymentFail') {
                    $red = wa()->getRouteUrl(
                            'shop/frontend/checkout',
                            array('step' => 'error'),
                            true
                        ).'?order_id='.$match[2];
                } else {
                    $red = wa()->getRouteUrl(
                            'shop/frontend/checkout',
                            array('step' => 'success'),
                            true
                        ).'?order_id='.$match[2];
                }

                return array(
                    'redirect' => $red,
                );
            }
        }


        if (isset($request['action']) && $request['action'] == 'callbackwallet') {
            $this->debugLog('p2p callback init: params = '.json_encode($_POST));
            if ($this->checkSignature($settings) && !empty($_POST['label'])) {
                try {
                    $orderId    = $_POST['label'];
                    $orderModel = new shopOrderModel();
                    $order      = $orderModel->getOrder($orderId);
                    $status     = $settings['ya_wallet_status'];
                    $text       = 'Payment completed';

                    if ($this->changeOrderState($order, $status)) {
                        $this->addOrderLog($order, $status, $text);
                    } else {
                        $this->debugLog('Complete payment fail. P2p payment for order No. '.$orderId);
                    }
                } catch (Exception $e) {
                    $this->debugLog('p2p callback error: '.$e->getMessage());
                }
            } else {
                $this->debugLog('p2p callback error: params = '.json_encode($_POST));
            }
        }
    }

    /**
     * @param waOrder $orderData
     * @param array $paymentInfo
     * @param YandexMoney $plugin
     * @param waSmarty3View $view
     */
    private function assignKassaVariables($orderData, $paymentInfo, $plugin, $view)
    {
        if ($paymentInfo['ya_kassa_test']) {
            $plugin->test = true;
        }
        $view->assign('inside', $paymentInfo['ya_kassa_inside']);
        $view->assign('paylogo', $paymentInfo['ya_kassa_paylogo']);
        $view->assign('installments_button', $paymentInfo['ya_kassa_installments_button']);
        $view->assign('add_installments_block', $paymentInfo['ya_kassa_add_installments_block']);
        $view->assign('alfa', $paymentInfo['ya_kassa_alfa']);
        $view->assign('wm', $paymentInfo['ya_kassa_wm']);
        $view->assign('sber', $paymentInfo['ya_kassa_sber']);
        $view->assign('terminal', $paymentInfo['ya_kassa_terminal']);
        $view->assign('card', $paymentInfo['ya_kassa_card']);
        $view->assign('wallet', $paymentInfo['ya_kassa_wallet']);
        $view->assign('qw', $paymentInfo['ya_kassa_qw']);
        if ($orderData->total >= self::INSTALLMENTS_MIN_AMOUNT) {
            $view->assign('installments', $paymentInfo['ya_kassa_installments']);
        }
        $view->assign('kassa', true);
    }

    /**
     * @param waOrder $orderData
     * @param array $paymentInfo
     * @param YandexMoney $plugin
     * @param waSmarty3View $view
     */
    private function assignBillingVariables($orderData, $paymentInfo, $plugin, $view)
    {
        $fio      = array();
        $customer = new waContact($orderData['customer_contact_id']);
        foreach (array('lastname', 'firstname', 'middlename') as $field) {
            $name = $customer->get($field);
            if (!empty($name)) {
                $fio[] = $name;
            }
        }
        $purpose = $this->parsePlaceholders($paymentInfo['ya_billing_purpose'], $orderData);
        $view->assign('formId', $paymentInfo['ya_billing_id']);
        $view->assign('narrative', $purpose);
        $view->assign('amount', number_format($orderData['total'], 2, '.', ''));
        $view->assign('fio', implode(' ', $fio));
        $view->assign('formUrl', 'https://money.yandex.ru/fastpay/confirm');

        if (!empty($paymentInfo['ya_billing_status'])) {
            $this->setOrderState($paymentInfo['ya_billing_status'], $orderData, $purpose);
        }
        $view->assign('logs', $this->logs);
    }

    /**
     * @param string $template
     * @param waOrder $order
     *
     * @return string
     */
    private function parsePlaceholders($template, $order)
    {
        $replace = array(
            '%order_id%' => $order->id,
        );

        return strtr($template, $replace);
    }

    /**
     * Изменить статус заказа
     *
     * @param string $stateId
     * @param waOrder $orderData
     * @param string $purpose
     */
    private function setOrderState($stateId, $orderData, $purpose)
    {
        $stateConfig = shopWorkflow::getConfig();
        if (!array_key_exists($stateId, $stateConfig['states'])) {
            return;
        }

        $previousStateId = $orderData['state_id'];
        if (empty($previousStateId)) {
            $previousStateId = 'new';
        }
        if ($stateId != $previousStateId) {
            $orderModel = new shopOrderModel();
            $orderModel->updateById($orderData->id, array('state_id' => $stateId));

            $logModel = new shopOrderLogModel();
            $logModel->add(
                array(
                    'order_id'        => $orderData->id,
                    'contact_id'      => wa()->getUser()->getId(),
                    'before_state_id' => $previousStateId,
                    'after_state_id'  => $stateId,
                    'text'            => $purpose,
                    'action_id'       => '',
                )
            );
        }
    }

    /**
     * @param waOrder $waOrder
     * @param $paymentFormData
     * @param $settings
     * @return null|\YandexCheckout\Request\Payments\CreatePaymentResponse
     * @throws Exception
     */
    private function createPayment($waOrder, $paymentFormData, $settings)
    {
        $paymentType   = isset($paymentFormData['paymentType']) ? $paymentFormData['paymentType'] : null;
        $paymentMethod = $this->getPaymentMethod($paymentType);
        $shopId        = $settings['ya_kassa_shopid'];
        $shopPassword  = $settings['ya_kassa_pw'];
        $this->debugLog("Payment method: ".$paymentMethod);
        $confirmationType = ConfirmationType::REDIRECT;
        if ($paymentMethod == PaymentMethodType::ALFABANK) {
            $confirmationType = ConfirmationType::EXTERNAL;
            $paymentMethod    = new PaymentDataAlfabank();
            try {
                $paymentMethod->setLogin($paymentFormData['alfabank_login']);
            } catch (Exception $e) {
                $this->errors[] = 'Поле логин заполнено неверно.';
            }
        } elseif ($paymentMethod == PaymentMethodType::QIWI) {
            $paymentMethod = new PaymentDataQiwi();
            $phone         = preg_replace('/[^\d]/', '', $paymentFormData['qiwi_phone']);
            try {
                $paymentMethod->setPhone($phone);
            } catch (Exception $e) {
                $this->errors[] = 'Поле телефон заполнено неверно.';

                return null;
            }
        }

        $apiClient = $this->getApiClient($shopId, $shopPassword);
        $returnUrl = $this->getRelayUrl().'?action=return&orderId='.$waOrder->id;
        $amount    = number_format($waOrder['amount'], 2, '.', '');
        $builder   = CreatePaymentRequest::builder()
                                         ->setAmount($amount)
                                         ->setPaymentMethodData($paymentMethod)
                                         ->setCapture($this->getCaptureValue($settings, $paymentMethod))
                                         ->setDescription($this->createDescription($waOrder, $settings))
                                         ->setConfirmation(
                                             array(
                                                 'type'      => $confirmationType,
                                                 'returnUrl' => $returnUrl,
                                             )
                                         )
                                         ->setMetadata(array(
                                             'cms_name'       => 'ya_api_webasyst',
                                             'module_version' => $this->version,
                                         ));

            self::setReceiptIfNeeded($builder, $waOrder);
        try {
            $paymentRequest = $builder->build();
            $receipt        = $paymentRequest->getReceipt();
            if ($receipt instanceof \YandexCheckout\Model\Receipt) {
                $receipt->normalize($paymentRequest->getAmount());
            }
        } catch (Exception $e) {
            $this->debugLog('Payment request build error: '.$e->getMessage());

            return null;
        }

        $serializer     = new CreatePaymentRequestSerializer();
        $serializedData = $serializer->serialize($paymentRequest);

        $this->debugLog('Create payment request: '.json_encode($serializedData));
        $this->debugLog('Return url: '.$returnUrl);

        try {
            $response = $apiClient->createPayment($paymentRequest);

            return $response;
        } catch (ApiException $e) {
            $this->debugLog('Api error: '.$e->getMessage());
        }
    }

    /**
     * @param CreateCaptureRequestBuilder|CreatePaymentRequestBuilder $builder
     * @param waOrder $waOrder
     */
    public static function setReceiptIfNeeded($builder, $waOrder)
    {
        $sm       = new waAppSettingsModel();
        $settings = $sm->get('shop.yamodule_api');

        if (isset($settings['ya_kassa_send_check']) && $settings['ya_kassa_send_check']) {
            $vatCodes    = array();
            $order_model = new shopOrderModel();
            $order       = $order_model->getById($waOrder['order_id']);
            $model       = new waContactEmailsModel();

            $defaultVatCode = isset($settings['ya_kassa_default_vat_code'])
                ? $settings['ya_kassa_default_vat_code']
                : yamodulepay_apiPayment::DEFAULT_VAT_CODE;
            if (isset($settings['taxValues'])) {
                @$val = unserialize($settings['taxValues']);
                if (is_array($val)) {
                    $vatCodes = $val;
                }
            }

            $emails = $model->getEmails($order['contact_id']);

            $email = '';
            if (count($emails)) {
                foreach ($emails as $erow) {
                    if (!empty($erow['value'])) {
                        $email = $erow['value'];
                        break;
                    }
                }
            }

            if ($email) {
                $builder->setReceiptEmail($email);
            }

            $items = yamodulepay_apiPayment::extendItems($waOrder);
            foreach ($items as $product) {
                $taxId = 'ya_kassa_tax_'.$product['tax_id'];
                $price = $product['price'] + ($product['tax'] / $product['quantity']);
                if (isset($vatCodes[$taxId])) {
                    $builder->addReceiptItem($product['name'], $price, $product['quantity'], $vatCodes[$taxId]);
                } else {
                    $builder->addReceiptItem($product['name'], $price, $product['quantity'], $defaultVatCode);
                }
            }

            if ($waOrder['shipping'] > 0) {
                $builder->addReceiptShipping($waOrder['shipping_name'], $waOrder['shipping'], $defaultVatCode);
            }
        }
    }

    /**
     * @param array $settings
     * @param string $paymentMethod
     * @return bool
     */
    private function getCaptureValue($settings, $paymentMethod)
    {
        if ($settings['ya_kassa_enable_hold_mode'] !== '1') {
            return true;
        }

        return !in_array($paymentMethod, array('', PaymentMethodType::BANK_CARD));
    }

    private function getPaymentMethod($paymentType)
    {
        $paymentMethodsMap = array(
            'PC'           => PaymentMethodType::YANDEX_MONEY,
            'AC'           => PaymentMethodType::BANK_CARD,
            'GP'           => PaymentMethodType::CASH,
            'MC'           => PaymentMethodType::MOBILE_BALANCE,
            'WM'           => PaymentMethodType::WEBMONEY,
            'SB'           => PaymentMethodType::SBERBANK,
            'AB'           => PaymentMethodType::ALFABANK,
            'QW'           => PaymentMethodType::QIWI,
            'installments' => PaymentMethodType::INSTALLMENTS,
        );

        if (isset($paymentMethodsMap[$paymentType])) {
            return $paymentMethodsMap[$paymentType];
        } else {
            return null;
        }
    }

    /**
     * @param $shopId
     * @param $shopPassword
     *
     * @return Client
     */
    private function getApiClient($shopId, $shopPassword)
    {
        $apiClient = new Client();
        $apiClient->setAuth($shopId, $shopPassword);
        $that = $this;
        $apiClient->setLogger(
            function ($level, $message, $context) use ($that) {
                $that->debugLog($message);
            }
        );

        return $apiClient;
    }

    /**
     * @param $paymentId
     * @param $amount
     *
     * @return \YandexCheckout\Request\Payments\Payment\CreateCaptureResponse
     */
    private function capturePayment($paymentId, $amount)
    {
        $app_m          = new waAppSettingsModel();
        $settings       = $app_m->get('shop.yamodule_api');
        $shopId         = $settings['ya_kassa_shopid'];
        $shopPassword   = $settings['ya_kassa_pw'];
        $apiClient      = $this->getApiClient($shopId, $shopPassword);
        $captureRequest = CreateCaptureRequest::builder()->setAmount($amount)->build();

        $result = $apiClient->capturePayment($captureRequest, $paymentId, $paymentId);

        return $result;
    }

    /**
     * @param $transactionModel
     * @param $order
     *
     * @return mixed
     */
    protected function getTransactionByOrder($transactionModel, $order)
    {
        $transactions    = $transactionModel->getByFields(array('order_id' => $order['id']));
        $transactionData = null;
        if ($transactions) {
            $transactionData = array_shift($transactions);
        }

        return $transactionData;
    }

    private function getTransactionByPaymentId($id)
    {
        $transactionModel = new waTransactionModel();
        $transactions     = $transactionModel->getByFields(array('native_id' => $id));
        if ($transactions) {
            $transaction = array_shift($transactions);

            return $transaction;
        } else {
            return null;
        }
    }

    /**
     * @param $transactionData
     * @param string $message
     */
    private function failure($transactionData, $message)
    {
        $this->debugLog('ReturnUrl redirect to failed: '.$message
            .' Order id: '.$transactionData['order_id']
            .' Payment id: '.$transactionData['native_id']);
        $redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transactionData);
        wa()->getResponse()->redirect($redirect);
    }

    /**
     * @param $settings
     *
     * @return void
     */
    protected function processReturnUrl($settings)
    {
        $this->debugLog('Return url init');
        $orderId    = waRequest::get('orderId');
        $orderModel = new shopOrderModel();

        $transactionModel = new waTransactionModel();
        $order            = $orderModel->getOrder($orderId);
        $transactionData  = $this->getTransactionByOrder($transactionModel, $order);
        $shopId           = $settings['ya_kassa_shopid'];
        $shopPassword     = $settings['ya_kassa_pw'];
        $apiClient        = $this->getApiClient($shopId, $shopPassword);
        $paymentId        = $transactionData['native_id'];

        try {
            $payment = $apiClient->getPaymentInfo($paymentId);
            if ($payment === null) {
                $this->failure($transactionData, 'Платеж не найден.');
            } elseif (!$payment->getPaid()) {
                $this->failure($transactionData, 'Платеж не оплачен.');
            } elseif ($payment->getStatus() === PaymentStatus::CANCELED) {
                $this->failure($transactionData, 'Платеж отменен.');
            }

            $redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transactionData);
            $this->debugLog('ReturnUrl redirect to succeeded: '.$redirect);
            wa()->getResponse()->redirect($redirect);
        } catch (ApiException $e) {
            $this->debugLog('Api error: '.$e->getMessage());
            wa()->getResponse()->redirect(
                $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transactionData)
            );
        }
    }

    /**
     * @param $order
     * @param $state_id
     *
     * @return mixed
     *
     */
    public static function changeOrderState($order, $state_id)
    {
        $orderModel = new shopOrderModel();
        $result     = $orderModel->updateByField('id', $order['id'], array('state_id' => $state_id));

        return $result;
    }

    private function validate($payment_form_data)
    {
        $this->errors = array();

        if ($payment_form_data['paymentType'] == 'QW') {
            $phone = preg_replace('/[^\d]/', '', $payment_form_data['qiwi_phone']);
            if (
                empty($payment_form_data['qiwi_phone'])
                || !preg_match('/^[0-9]{4,15}$/', $phone)
            ) {
                $this->errors[] = 'Поле телефон заполнено неверно.';
            }
        }

        if ($payment_form_data['paymentType'] == 'AB' && empty($payment_form_data['alfabank_login'])) {
            $this->errors[] = 'Поле логин заполнено неверно.';
        }

        return empty($this->errors);
    }

    public function debugLog($message)
    {
        self::log('yamodulepayApi', $message);
    }

    /**
     * @param $order
     * @param $state_id
     * @param string $text
     *
     */
    protected function addOrderLog($order, $state_id, $text = '')
    {
        if ($state_id == self::ORDER_STATE_COMPLETE) {
            $actionId = 'pay';
        }

        $orderLogModel = new shopOrderLogModel();
        $orderLogModel->add(
            array(
                'order_id'        => $order['id'],
                'action_id'       => $actionId,
                'before_state_id' => $order['state_id'],
                'after_state_id'  => $state_id,
                'text'            => $text,
            )
        );
    }

    /**
     * @param int $orderId
     * @param string $text
     */
    public static function addOrderLogComment($orderId, $text = '')
    {
        $orderLogModel = new shopOrderLogModel();
        $orderLogModel->add(
            array(
                'order_id'        => $orderId,
                'action_id'       => 'comment',
                'text'            => $text,
            )
        );
    }

    /**
     * @param ArrayAccess $order
     * @param $settings
     *
     * @return bool|string
     */
    private function createDescription($order, $settings)
    {
        $descriptionTemplate = !empty($settings['ya_kassa_description_template'])
            ? $settings['ya_kassa_description_template']
            : 'Оплата заказа №%id%';

        $replace  = array();
        $patterns = explode('%', $descriptionTemplate);
        foreach ($patterns as $pattern) {
            if (!isset($order[$pattern])) {
                continue;
            }
            $value = $order[$pattern];
            if (is_scalar($value)) {
                $replace['%'.$pattern.'%'] = $value;
            }
        }

        $description = strtr($descriptionTemplate, $replace);

        return (string)mb_substr($description, 0, Payment::MAX_LENGTH_DESCRIPTION);
    }

    private function checkSignature($settings)
    {
        if (empty($_POST['sha1_hash'])) {
            return false;
        } else {
            $shaHash = $_POST['sha1_hash'];
        }

        $notificationSecret = $settings['ya_p2p_skey'];

        $notificationType = isset($_POST['notification_type']) ? $_POST['notification_type'] : '';
        $operationId      = isset($_POST['operation_id']) ? $_POST['operation_id'] : '';
        $amount           = isset($_POST['amount']) ? $_POST['amount'] : '';
        $currency         = isset($_POST['currency']) ? $_POST['currency'] : '';
        $datetime         = isset($_POST['datetime']) ? $_POST['datetime'] : '';
        $sender           = isset($_POST['sender']) ? $_POST['sender'] : '';
        $codepro          = isset($_POST['codepro']) ? $_POST['codepro'] : '';
        $label            = isset($_POST['label']) ? $_POST['label'] : '';

        $data = array(
            $notificationType,
            $operationId,
            $amount,
            $currency,
            $datetime,
            $sender,
            $codepro,
            $notificationSecret,
            $label,
        );

        $dataString = implode('&', $data);
        $signature  = sha1($dataString);

        return $shaHash == $signature;
    }
}