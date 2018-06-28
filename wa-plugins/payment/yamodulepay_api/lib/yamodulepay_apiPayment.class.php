<?php
require_once dirname(__FILE__).'/../vendor/autoload.php';

use YandexCheckout\Client;
use YandexCheckout\Common\Exceptions\ApiException;
use YandexCheckout\Model\ConfirmationType;
use YandexCheckout\Model\Notification\NotificationSucceeded;
use YandexCheckout\Model\Notification\NotificationWaitingForCapture;
use YandexCheckout\Model\Payment;
use YandexCheckout\Model\PaymentData\PaymentDataAlfabank;
use YandexCheckout\Model\PaymentData\PaymentDataQiwi;
use YandexCheckout\Model\PaymentMethodType;
use YandexCheckout\Model\PaymentStatus;
use YandexCheckout\Request\Payments\CreatePaymentRequest;
use YandexCheckout\Request\Payments\CreatePaymentRequestSerializer;
use YandexCheckout\Request\Payments\Payment\CreateCaptureRequest;

/**
 *
 * @author Webasyst
 * @name YandexMoney
 * @description YandexMoney pament module
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
    /** @var int Success */
    const XML_SUCCESS = 0;

    /** @var int Authorization failed */
    const XML_AUTH_FAILED = 1;

    /** @var int Payment refused by shop */
    const XML_PAYMENT_REFUSED = 100;

    /** @var int Bad request */
    const XML_BAD_REQUEST = 200;

    /** @var int Temporary technical problems */
    const XML_TEMPORAL_PROBLEMS = 1000;

    const DEFAULT_TAX_RATE = 1;

    const ORDER_STATE_COMPLETE = 'paid';
    const ORDER_STATE_CANCELED = 'canceled';
    const ORDER_STATE_PROCESS = 'process';

    const INSTALLMENTS_MIN_AMOUNT = 3000;


    private $version = '1.0.12';
    private $order_id;
    private $request;

    private $errors;


    public function allowedCurrency()
    {
        return 'RUB';
    }

    private function extendItems(&$order)
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

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        $order_data = waOrder::factory($order_data);
        if ($order_data['currency_id'] != 'RUB') {
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

        $_SESSION['order_data']                          = array();
        $p2p                                             = (bool)$data['ya_p2p_active'];
        $_SESSION['order_data'][$order_data['order_id']] = array(
            $this->app_id,
            $this->merchant_id,
        );

        $view = wa()->getView();
        $view->assign('amount', $order_data['total']);
        $view->assign('shop_id', $data['ya_kassa_shopid']);
        if ((bool)$data['ya_kassa_active']) {
            if (isset($payment_form_data['paymentType']) && $this->validate($payment_form_data)) {
                $this->debugLog('Payment init');
                $result = $this->createPayment($order_data, $payment_form_data, $data);

                if ($result) {
                    $transaction_data = array(
                        'plugin'      => $this->id,
                        'type'        => self::OPERATION_HOSTED_PAYMENT_AFTER_ORDER,
                        'order_id'    => $order_data->id,
                        'merchant_id' => $this->merchant_id,
                        'native_id'   => $result->getId(),
                        'state'       => self::STATE_AUTH,
                        'result'      => '',
                    );

                    $transactionModel = new waTransactionModel();
                    $transaction      = $this->getTransactionByOrder($transactionModel, $order_data);

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
                    $this->assignKassaVariables($order_data, $data, $yclass, $view);

                    return $view->fetch($this->path.'/templates/payment.html');
                }
            } else {
                $this->assignKassaVariables($order_data, $data, $yclass, $view);
            }
            if ($this->errors) {
                $view->assign('errors', $this->errors);
            }
        }
        if ((bool)$data['ya_p2p_active']) {
            $view->assign('form_url', $this->getRelayUrl());
        }
        if ((bool)$data['ya_billing_active']) {
            $this->assignBillingVariables($order_data, $data, $yclass, $view);

            return $view->fetch($this->path.'/templates/billing_payment.html');
        }
        $view->assign('p2p', $p2p);
        $view->assign('auto_submit', false);

        return $view->fetch($this->path.'/templates/payment.html');
    }

    protected function callbackInit($request)
    {

        if (!empty($_POST['orderNumber']) && isset($_POST['action']) && $_POST['action'] == 'paymentAviso') {
            $match             = explode('/', $_POST['orderNumber']);
            $this->app_id      = $match[0];
            $this->merchant_id = $match[1];
            $this->order_id    = $match[2];
        }

        $doit = waRequest::get('doit');
        $id   = waRequest::get('id_order');
        if (($doit == 'wallet' || $doit == 'card') && isset($_SESSION['order_data'][$id])) {
            $this->order_id    = $id;
            $this->app_id      = $_SESSION['order_data'][$id][0];
            $this->merchant_id = $_SESSION['order_data'][$id][1];
        }

        return parent::callbackInit($request);
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

        $order_model = new shopOrderModel();
        $order       = $order_model->getById(
            $this->order_id ? $this->order_id : wa()->getStorage()->get('shop/order_id')
        );
        $action      = waRequest::post('action');
        $doit        = waRequest::get('doit');
        $app_m       = new waAppSettingsModel();
        $settings    = $app_m->get('shop.yamodule_api');
        $yclass      = new YandexMoney();
        $data        = $app_m->get('shop.yamodule_api');

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
                    $message = 'invalid object in body: ' . $source;
                }
                $this->debugLog('warning: Invalid parameters in capture notification controller - ' . $message);
                header('HTTP/1.1 400 Invalid json object in body');
                exit();
            }

            $this->debugLog('info: Notification: ' . $source);

            try {
                $notificationModel = ($callbackParams['event'] === YandexCheckout\Model\NotificationEventType::PAYMENT_SUCCEEDED)
                    ? new NotificationSucceeded($callbackParams)
                    : new NotificationWaitingForCapture($callbackParams);
            } catch (\Exception $e) {
                $this->debugLog('error: Invalid notification object - ' . $e->getMessage());
                header('HTTP/1.1 400 Invalid object in body');
                exit();
            }
            $paymentResponse = $notificationModel->getObject();
            $transaction = $this->getTransactionByPaymentId($paymentResponse->id);
            $transactionModel = new waTransactionModel();
            $orderModel = new shopOrderModel();
            $order = $orderModel->getOrder($transaction['order_id']);
            if (!$order) {
                header("HTTP/1.1 404 Not Found");
                header("Status: 404 Not Found");
                $this->debugLog('error: Order empty ' . $transaction['order_id'] . ' заказа ' . json_encode($order));
                exit();
            }
            $this->debugLog('info: Проведение платежа ' . $notificationModel->getObject()->getId()
                . ' заказа ' . json_encode($order));

            $shopId = $settings['ya_kassa_shopid'];
            $shopPassword = $settings['ya_kassa_pw'];
            $apiClient = $this->getApiClient($shopId, $shopPassword);
            $paymentResponse = $apiClient->getPaymentInfo($paymentResponse->getId());

            $this->debugLog('debug: $paymentInfoResponse ' . json_encode($paymentResponse));

            switch ($paymentResponse->getStatus()) {
                case PaymentStatus::WAITING_FOR_CAPTURE:
                    $captureResponse = $this->capturePayment(
                        $paymentResponse->getId(),
                        $paymentResponse->getAmount()
                    );
                    if ($captureResponse->getStatus() === PaymentStatus::SUCCEEDED) {
                        $text = "Номер транзакции в Яндекс.Кассе: {$paymentResponse->getId()}. Сумма: {$paymentResponse->getAmount()->getValue()}";
                        $transactionModel->updateById($transaction['id'], array('state' => self::STATE_CAPTURED));
                        if ($this->changeOrderState($order, self::ORDER_STATE_COMPLETE)) {
                            $this->addOrderLog($order, self::ORDER_STATE_COMPLETE, $text);
                            $this->debugLog('Payment completed. Payment id: ' . $paymentResponse->getId());
                        } else {
                            $this->debugLog('Complete payment fail. Payment id: ' . $paymentResponse->getId());
                        }
                    } else {
                        $transactionModel->updateById($transaction['id'], array('state' => self::STATE_CANCELED));
                        $this->debugLog('Payment canceled. payment id: ' . $paymentResponse->getId());
                    }
                    header("HTTP/1.1 200 OK");
                    header("Status: 200 OK");
                    break;
                case PaymentStatus::PENDING:
                    $this->debugLog('Pending payment. payment id: ' . $paymentResponse->getId());
                    header("HTTP/1.1 402 Payment Required");
                    header("Status: 402 Payment Required");
                    break;
                case PaymentStatus::SUCCEEDED:
                    $text = "Номер транзакции в Яндекс.Кассе: {$paymentResponse->getId()}. Сумма: {$paymentResponse->getAmount()->getValue()}";
                    $transactionModel->updateById($transaction['id'], array('state' => self::STATE_CAPTURED));
                    if ($this->changeOrderState($order, self::ORDER_STATE_COMPLETE)) {
                        $this->addOrderLog($order, self::ORDER_STATE_COMPLETE, $text);
                        $this->debugLog('Payment completed. Payment id: ' . $paymentResponse->getId());
                    } else {
                        $this->debugLog('Complete payment fail. Payment id: ' . $paymentResponse->getId());
                    }
                    header("HTTP/1.1 200 OK");
                    header("Status: 200 OK");
                    break;
                case PaymentStatus::CANCELED:
                    $transactionModel->updateById($transaction['id'], array('state' => self::STATE_CANCELED));
                    $this->debugLog('Payment canceled. payment id: ' . $paymentResponse->getId());

                    header("HTTP/1.1 200 OK");
                    header("Status: 200 OK");
                    break;
            }
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
            } else {
                if (isset($_POST['action']) && $_POST['action'] == 'checkOrder') {
                    if ($data['ya_kassa_log']) {
                        $this->log_save('callback:  checkOrder');
                    }
                    $code = $yclass->checkOrder($_POST);
                    $yclass->sendCode($_POST, $code);
                }

                if (isset($_POST['action']) && $_POST['action'] == 'paymentAviso') {
                    if ($this->order_id > 0) {
                        if ($data['ya_kassa_log']) {
                            $this->log_save('Payment by kassa for order '.$this->order_id.' success');
                        }
                        $_SESSION['order_data']          = array();
                        $transaction_data['merchant_id'] = $this->merchant_id;
                        $transaction_data['order_id']    = $this->order_id;
                        $transaction_data['currency_id'] = 1;
                        $transaction_data['type']        = self::OPERATION_CAPTURE;
                        $transaction_data['state']       = self::STATE_CAPTURED;
                        $transaction_data['plugin']      = 'yandex_p2p_card';
                        $transaction_data['amount']      = $order['total'];
                        if ($data['ya_kassa_log']) {
                            $this->log_save('callback:  Aviso');
                            $this->log_save('order_id '.$this->order_id);
                        }

                        $result = $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
                        $yclass->checkOrder($_POST, true, true);
                    }
                }
            }
        }

        if ($action == 'p2p') {
            $payment_type = waRequest::post('payment-type');
            if ($payment_type == 'wallet') {
                $scope    = array(
                    "payment.to-account(\"".$data['ya_p2p_number']."\",\"account\").limit(,".number_format(
                        $order['total'],
                        2,
                        '.',
                        ''
                    ).")",
                    "money-source(\"wallet\",\"card\")",
                );
                $auth_url = $yclass->buildObtainTokenUrl(
                    $data['ya_p2p_appid'],
                    $this->getRelayUrl().'wallet?id_order='.$order['id'],
                    $scope
                );
                if ($data['ya_p2p_log']) {
                    $this->log_save('Redirect to '.$auth_url);
                }
                wa()->getResponse()->redirect($auth_url);
            }

            if ($payment_type == 'card') {
                $instance = $yclass->sendRequest('/api/instance-id', array('client_id' => $data['ya_p2p_appid']));
                if ($instance->status == 'success') {
                    $instance_id = $instance->instance_id;
                    $message     = 'payment to order #'.$_SESSION['shop/order_id'];
                    if ($data['ya_p2p_log']) {
                        $this->log_save('payment by card to order #'.$_SESSION['shop/order_id']);
                    }
                    $payment_options = array(
                        'pattern_id'  => 'p2p',
                        'to'          => $data['ya_p2p_number'],
                        'amount_due'  => number_format($order['total'], 2, '.', ''),
                        'comment'     => trim($message),
                        'message'     => trim($message),
                        'instance_id' => $instance_id,
                        'label'       => $_SESSION['shop/order_id'],
                    );

                    $response = $yclass->sendRequest('/api/request-external-payment', $payment_options);
                    if ($response->status == 'success') {
                        $this->error                       = false;
                        $request_id                        = $response->request_id;
                        $_SESSION['ya_encrypt_CRequestId'] = urlencode(base64_encode($request_id));

                        do {
                            $process_options = array(
                                "request_id"           => $request_id,
                                'instance_id'          => $instance_id,
                                'ext_auth_success_uri' => $this->getRelayUrl().'card?id_order='.$order['id'],
                                'ext_auth_fail_uri'    => wa()->getRouteUrl(
                                    'shop/frontend/checkout',
                                    array('step' => 'error'),
                                    true
                                ),
                            );
                            $result          = $yclass->sendRequest('/api/process-external-payment', $process_options);
                            if ($result->status == "in_progress") {
                                if ($data['ya_p2p_log']) {
                                    $this->log_save('Payment card in progress');
                                }
                                sleep(1);
                            }
                        } while ($result->status == "in_progress");

                        if ($result->status == 'success') {
                            if ($data['ya_p2p_log']) {
                                $this->log_save('Payment by card success');
                            }
                            die('success');
                            wa()->getResponse()->redirect($this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS));
                        } elseif ($result->status == 'ext_auth_required') {
                            $_SESSION['cps_context_id'] = $result->acs_params->cps_context_id;
                            $url                        = sprintf(
                                "%s?%s",
                                $result->acs_uri,
                                http_build_query($result->acs_params)
                            );
                            if ($data['ya_p2p_log']) {
                                $this->log_save('Redirect to (card) '.$url);
                            }
                            wa()->getResponse()->redirect($url);
                            exit;
                        } elseif ($result->status == 'refused') {
                            if ($data['ya_p2p_log']) {
                                $this->log_save('Refused '.$result->error);
                            }
                            die(
                            $yclass->descriptionError($result->error) ? $yclass->descriptionError(
                                $result->error
                            ) : $result->error
                            );
                        }
                    }
                }
            }
        }

        if ($doit == 'card') {
            if ($_SESSION['cps_context_id'] == waRequest::get('cps_context_id')) {
                if ($data['ya_p2p_log']) {
                    $this->log_save('Payment by card for order '.$this->order_id.' success');
                }
                $_SESSION['cps_context_id']      = '';
                $transaction_data['merchant_id'] = $this->merchant_id;
                $transaction_data['order_id']    = $this->order_id;
                $transaction_data['type']        = self::OPERATION_CAPTURE;
                $transaction_data['state']       = self::STATE_CAPTURED;
                $transaction_data['currency_id'] = 1;
                $transaction_data['plugin']      = 'yandex_p2p_card';
                $transaction_data['amount']      = $order['total'];
                $result                          = $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
                wa()->getResponse()->redirect(
                    wa()->getRouteUrl(
                        'shop/frontend/checkout',
                        array('step' => 'success'),
                        true
                    ).'?order_id='.$this->order_id
                );
            }
        }

        if ($doit == 'wallet') {
            $code = waRequest::get('code');
            if (empty($code)) {
                if ($data['ya_p2p_log']) {
                    $this->log_save('Empty code');
                }
                wa()->getResponse()->redirect(
                    wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'error'), true)
                );
            } else {
                $response = $yclass->getAccessToken(
                    $data['ya_p2p_appid'],
                    $code,
                    $this->getRelayUrl().'wallet',
                    $data['ya_p2p_skey']
                );
                $token    = '';
                if (isset($response->access_token)) {
                    $token = $response->access_token;
                } else {
                    if ($data['ya_p2p_log']) {
                        $this->log_save('Error '.$response->error);
                    }
                    wa()->getResponse()->redirect(
                        wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'error'), true)
                    );
                }

                if (!empty($token)) {
                    $message = 'payment to order #'.$_SESSION['shop/order_id'];
                    if ($data['ya_p2p_log']) {
                        $this->log_save('payment to order #'.$_SESSION['shop/order_id']);
                    }
                    $rarray = array(
                        'pattern_id' => 'p2p',
                        'to'         => $data['ya_p2p_number'],
                        'amount_due' => number_format($order['total'], 2, '.', ''),
                        'comment'    => trim($message),
                        'message'    => trim($message),
                        'label'      => $_SESSION['shop/order_id'],
                    );

                    $request_payment = $yclass->sendRequest('/api/request-payment', $rarray, $token);
                    switch ($request_payment->status) {
                        case 'success':
                            $_SESSION['ya_encrypt_token']     = urlencode(base64_encode($token));
                            $_SESSION['ya_encrypt_RequestId'] = urlencode(base64_encode($request_payment->request_id));

                            do {
                                $array_p         = array("request_id" => $request_payment->request_id);
                                $process_payment = $yclass->sendRequest("/api/process-payment", $array_p, $token);

                                if ($process_payment->status == "in_progress") {
                                    sleep(1);
                                }
                            } while ($process_payment->status == "in_progress");

                            if ($process_payment->status == 'success') {
                                if ($data['ya_p2p_log']) {
                                    $this->log_save('Payment p2p wallet for order '.$this->order_id.' success');
                                }
                                $_SESSION['ya_encrypt_token']     = '';
                                $_SESSION['ya_encrypt_RequestId'] = '';
                                $transaction_data['merchant_id']  = $this->merchant_id;
                                $transaction_data['order_id']     = $this->order_id;
                                $transaction_data['type']         = self::OPERATION_CAPTURE;
                                $transaction_data['state']        = self::STATE_CAPTURED;
                                $transaction_data['currency_id']  = 1;
                                $transaction_data['plugin']       = 'yandex_p2p_wallet';
                                $transaction_data['amount']       = $order['total'];
                                $result                           = $this->execAppCallback(
                                    self::CALLBACK_PAYMENT,
                                    $transaction_data
                                );
                                wa()->getResponse()->redirect(
                                    wa()->getRouteUrl(
                                        'shop/frontend/checkout',
                                        array('step' => 'success'),
                                        true
                                    ).'?order_id='.$this->order_id
                                );
                            }
                            break;
                        case 'refused':
                            if ($data['ya_p2p_log']) {
                                $this->log_save($request_payment->error.' : '.$request_payment->error_description);
                            }
                            wa()->getResponse()->redirect(
                                wa()->getRouteUrl('shop/frontend/checkout', array('step' => 'error'), true)
                            );
                            break;
                    }
                }
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
        $view->assign('alfa', $paymentInfo['ya_kassa_alfa']);
        $view->assign('wm', $paymentInfo['ya_kassa_wm']);
        $view->assign('sber', $paymentInfo['ya_kassa_sber']);
        $view->assign('sms', $paymentInfo['ya_kassa_sms']);
        $view->assign('terminal', $paymentInfo['ya_kassa_terminal']);
        $view->assign('card', $paymentInfo['ya_kassa_card']);
        $view->assign('wallet', $paymentInfo['ya_kassa_wallet']);
        $view->assign('pb', $paymentInfo['ya_kassa_pb']);
        $view->assign('ma', $paymentInfo['ya_kassa_ma']);
        $view->assign('qw', $paymentInfo['ya_kassa_qw']);
        $view->assign('qp', $paymentInfo['ya_kassa_qp']);
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

    private function createPayment($orderData, $paymentFormData, $data)
    {
        $paymentType = isset($paymentFormData['paymentType']) ? $paymentFormData['paymentType'] : null;
        $paymentMethod = $this->getPaymentMethod($paymentType);
        $order         = waOrder::factory($orderData);
        $shopId        = $data['ya_kassa_shopid'];
        $shopPassword  = $data['ya_kassa_pw'];
        $this->debugLog("Pament method: ".$paymentMethod);
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
        $returnUrl = $this->getRelayUrl().'?action=return&orderId='.$order->id;
        $amount    = number_format($orderData['amount'], 2, '.', '');
        $builder   = CreatePaymentRequest::builder()
                                         ->setAmount($amount)
                                         ->setPaymentMethodData($paymentMethod)
                                         ->setCapture(true)
                                         ->setDescription($this->createDescription($order, $data))
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

        if (isset($data['ya_kassa_send_check']) && $data['ya_kassa_send_check']) {
            $taxValues   = array();
            $order_model = new shopOrderModel();
            $order       = $order_model->getById($orderData['order_id']);
            $model       = new waContactEmailsModel();

            if (isset($paymentInfo['taxValues'])) {
                @$val = unserialize($paymentInfo['taxValues']);
                if (is_array($val)) {
                    $taxValues = $val;
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

            $items = $this->extendItems($orderData);
            foreach ($items as $product) {
                $taxId = 'ya_kassa_tax_'.$product['tax_id'];
                $price = $product['price'] + ($product['tax'] / $product['quantity']);
                if (isset($taxValues[$taxId])) {
                    $builder->addReceiptItem($product['name'], $price, $product['quantity'], $taxValues[$taxId]);
                } else {
                    $builder->addReceiptItem($product['name'], $price, $product['quantity'], self::DEFAULT_TAX_RATE);
                }
            }

            if ($orderData['shipping'] > 0) {
                $builder->addReceiptShipping($orderData['shipping_name'], $orderData['shipping'], self::DEFAULT_TAX_RATE);
            }
        }
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
        if (isset($data['ya_kassa_send_check']) && $data['ya_kassa_send_check']) {
            $config   = waConfig::getAll();
            $appsPath = $config['wa_path_apps'];
            require_once $appsPath.DIRECTORY_SEPARATOR.'shop'.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'yamodule_api'.DIRECTORY_SEPARATOR.'api'.DIRECTORY_SEPARATOR.'orderReceiptModel.php';


            $orderReceiptModel = new orderReceiptModel();
            $receiptData       = $serializedData['receipt'];
            if ($receiptData) {
                $result = $orderReceiptModel->add(
                    array(
                        'order_id' => (int)$order['id'],
                        'receipt'  => json_encode($receiptData, JSON_UNESCAPED_UNICODE),
                    )
                );
            }
        }
        $this->debugLog('Create payment request: '.json_encode($serializedData));
        $this->debugLog('Return url: '.$returnUrl);
        $idempotencyKey = base64_encode($orderData->data['id_str'].str_replace('.', '-', microtime(true)));
        $this->debugLog('Idempotency Key: '.$idempotencyKey);
        try {
            $response = $apiClient->createPayment($paymentRequest, $idempotencyKey);

            return $response;
        } catch (ApiException $e) {
            $this->debugLog('Api error: '.$e->getMessage());
        }
    }

    private function getPaymentMethod($paymentType)
    {
        $paymentMethodsMap = array(
            'PC' => PaymentMethodType::YANDEX_MONEY,
            'AC' => PaymentMethodType::BANK_CARD,
            'GP' => PaymentMethodType::CASH,
            'MC' => PaymentMethodType::MOBILE_BALANCE,
            'WM' => PaymentMethodType::WEBMONEY,
            'SB' => PaymentMethodType::SBERBANK,
            'AB' => PaymentMethodType::ALFABANK,
            'QW' => PaymentMethodType::QIWI,
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
        $transactions = $transactionModel->getByFields(array('order_id' => $order['id']));
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
     * @param $settings
     *
     * @return void
     * @throws ApiException
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
            $result = $apiClient->getPaymentInfo($paymentId);

            if ($result->status == PaymentStatus::WAITING_FOR_CAPTURE) {
                $captureRequest = $this->capturePayment($paymentId, $result->getAmount());
                if ($captureRequest->status == PaymentStatus::SUCCEEDED) {
                    $text = "Номер транзакции в Яндекс.Кассе: {$paymentId}. Сумма: {$result->getAmount()->getValue()}";
                    $transactionModel->updateById($transactionData['id'], array('state' => self::STATE_CAPTURED));
                    if ($this->changeOrderState($order, self::ORDER_STATE_COMPLETE)) {
                        $this->addOrderLog($order, self::ORDER_STATE_COMPLETE, $text);
                    }
                    $redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transactionData);
                    $this->debugLog('Payment succeeded. Redirect: '.$redirect);
                    wa()->getResponse()->redirect($redirect);
                } elseif ($result->status == PaymentStatus::CANCELED) {
                    $transactionModel->updateById($transactionData['id'], array('state' => self::STATE_CANCELED));
                    $redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transactionData);
                    $this->debugLog('Payment canceled. Redirect: '.$redirect);
                    wa()->getResponse()->redirect($redirect);
                }
            } elseif ($result->status == PaymentStatus::CANCELED) {
                $redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transactionData);
                $transactionModel->updateById($transactionData['id'], array('state' => self::STATE_CANCELED));
                $this->debugLog('Payment canceled. Redirect: '.$redirect);
                wa()->getResponse()->redirect($redirect);
            } elseif ($result->status == PaymentStatus::SUCCEEDED) {
                $text = "Номер транзакции в Яндекс.Кассе: {$paymentId}. Сумма: {$result->getAmount()->getValue()}";
                $transactionModel->updateById($transactionData['id'], array('state' => self::STATE_CAPTURED));
                if ($this->changeOrderState($order, self::ORDER_STATE_COMPLETE)) {
                    $this->addOrderLog($order, self::ORDER_STATE_COMPLETE, $text);
                }
                $redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transactionData);
                $this->debugLog('Payment succeeded. Redirect: '.$redirect);
                wa()->getResponse()->redirect($redirect);
            } elseif (($result->status == PaymentStatus::PENDING) && $result->getPaid()) {
                $redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS, $transactionData);
                $this->debugLog('Payment pending and paid. Redirect: ' . $redirect);
                wa()->getResponse()->redirect($redirect);
            } else {
                $redirect = $this->getAdapter()->getBackUrl(waAppPayment::URL_FAIL, $transactionData);
                $this->debugLog('Payment failed. Redirect: '.$redirect);
                wa()->getResponse()->redirect($redirect);
            }
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
    protected function changeOrderState($order, $state_id)
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
     * @param ArrayAccess $order
     * @param $settings
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
}