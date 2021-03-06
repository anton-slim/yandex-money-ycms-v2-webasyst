<?php
require_once dirname(__FILE__).'/../../../../../wa-plugins/payment/yamodulepay_api/lib/yamodulepay_apiPayment.class.php';

use YandexCheckout\Client;
use YandexCheckout\Model\PaymentStatus;
use YandexCheckout\Request\Payments\Payment\CreateCaptureRequest;
use YandexCheckout\Request\Refunds\CreateRefundRequest;
use YandexCheckout\Request\Refunds\CreateRefundRequestSerializer;

class shopYamodule_apiPlugin extends shopPlugin
{
    public function sendStatistics()
    {
        global $wa;
        $headers   = array();
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $sm        = new waAppSettingsModel();
        $data      = $sm->get('shop.yamodule_api');
        $data_shop = $sm->get('webasyst');
        $array     = array(
            'url'      => wa()->getUrl(true),
            'cms'      => 'api-shop-script5',
            'version'  => wa()->getVersion('webasyst'),
            'ver_mod'  => $this->info['version'],
            'email'    => $data_shop['email'],
            'shopid'   => $data['ya_kassa_shopid'],
            'settings' => array(
                'kassa'   => $data['ya_kassa_active'],
                'p2p'     => $data['ya_p2p_active'],
                'metrika' => $data['ya_metrika_active'],
            ),
        );

        $array_crypt = base64_encode(serialize($array));

        $url     = 'https://statcms.yamoney.ru/v2/';
        $curlOpt = array(
            CURLOPT_HEADER         => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_POST           => true,
        );

        $curlOpt[CURLOPT_HTTPHEADER] = $headers;
        $curlOpt[CURLOPT_POSTFIELDS] = http_build_query(array('data' => $array_crypt, 'lbl' => 1));

        $curl = curl_init($url);
        curl_setopt_array($curl, $curlOpt);
        $rbody = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $rcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        $json = json_decode($rbody);
        if ($rcode == 200 && isset($json->new_version)) {
            return $json->new_version;
        } else {
            return false;
        }
    }

    public function saveSettings($settings = array())
    {
        require_once dirname(__FILE__).'/../api/metrika.php';

        $sm   = new waAppSettingsModel();
        $data = $sm->get('shop.yamodule_api');

        if (waRequest::request('ya_kassa_active')) {
            $sm->set('shop.yamodule_api', 'ya_p2p_active', false);
            $sm->set('shop.yamodule_api', 'ya_billing_active', false);
            $_POST['ya_p2p_active']     = false;
            $_POST['ya_billing_active'] = false;
        } elseif (waRequest::request('ya_p2p_active')) {
            $sm->set('shop.yamodule_api', 'ya_billing_active', false);
            $sm->set('shop.yamodule_api', 'ya_kassa_active', false);
            $_POST['ya_billing_active'] = false;
            $_POST['ya_kassa_active']   = false;
        } elseif (waRequest::request('ya_billing_active')) {
            $sm->set('shop.yamodule_api', 'ya_p2p_active', false);
            $sm->set('shop.yamodule_api', 'ya_kassa_active', false);
            $_POST['ya_p2p_active']   = false;
            $_POST['ya_kassa_active'] = false;
        }

        if (waRequest::request('mode') == 'make_return') {
            $config     = waConfig::getAll();
            $pluginPath = $config['wa_path_plugins'];
            require_once $pluginPath.DIRECTORY_SEPARATOR.'payment'.DIRECTORY_SEPARATOR.'yamodulepay_api'.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
            $appsPath = $config['wa_path_apps'];
            require_once $appsPath.DIRECTORY_SEPARATOR.'shop'.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'yamodule_api'.DIRECTORY_SEPARATOR.'api'.DIRECTORY_SEPARATOR.'orderReceiptModel.php';
            require_once $appsPath.DIRECTORY_SEPARATOR.'shop'.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'yamodule_api'.DIRECTORY_SEPARATOR.'api'.DIRECTORY_SEPARATOR.'orderRefundModel.php';


            $orderId            = waRequest::post('id_order');
            $orderReceiptsModel = new orderReceiptModel();
            $order_model        = new shopOrderModel();
            $order              = $order_model->getById($orderId);
            $orderReceipt       = $orderReceiptsModel->getByOrderId((int)$order['id']);
            $receipt            = $orderReceipt['receipt'];
            $returnAmount       = waRequest::post('return_sum');
            $transaction        = $this->getTransactionByOrder((int)$order['id']);
            $paymentId          = $transaction['native_id'];
            $cause              = waRequest::post('return_cause');
            $builder            = CreateRefundRequest::builder()->setPaymentId($paymentId)
                                                     ->setComment($cause)
                                                     ->setAmount($returnAmount);
            if ($receipt) {
                $receiptData = json_decode($receipt, true);
                if (isset($receiptData['phone'])) {
                    $builder->setReceiptPhone($receiptData['phone']);
                }
                if (isset($receiptData['email'])) {
                    $builder->setReceiptEmail($receiptData['email']);
                }

                foreach ($receiptData['items'] as $item) {
                    $builder->addReceiptItem(
                        $item['description'],
                        $item['amount']['value'],
                        $item['quantity'],
                        $item['vat_code']
                    );
                }
            }
            $refundRequest  = $builder->build();
            $serializer     = new CreateRefundRequestSerializer();
            $serializedData = $serializer->serialize($refundRequest);

            $app          = new waAppSettingsModel();
            $settings     = $app->get('shop.yamodule_api');
            $shopId       = $settings['ya_kassa_shopid'];
            $shopPassword = $settings['ya_kassa_pw'];
            $apiClient    = new Client();
            $apiClient->setAuth($shopId, $shopPassword);
            $that = $this;
            $apiClient->setLogger(
                function ($level, $message, $context) use ($that) {
                    $that->debugLog($message);
                }
            );
            $idempotencyKey = base64_encode($orderId.'/'.microtime());
            $this->debugLog('Idempotency key: '.$idempotencyKey);

            $response = $apiClient->createRefund($refundRequest, $idempotencyKey);
            if ($response) {
                if ($response->status == \YandexCheckout\Model\RefundStatus::SUCCEEDED) {
                    $this->debugLog('Refund create success');
                    $orderRefundModel = new orderRefundModel();
                    $orderRefundModel->add(
                        array(
                            'payment_id'     => $paymentId,
                            'order_id'       => $orderId,
                            'cause'          => $cause,
                            'amount'         => $returnAmount,
                            'request'        => json_encode($serializedData),
                            'status'         => $response->status,
                            'error'          => '',
                            'refund_receipt' => $receipt,
                        )
                    );

                    return array('status' => 'success');
                } elseif ($response->status == \YandexCheckout\Model\RefundStatus::CANCELED) {
                    $this->debugLog('Refund create failed');
                }
            } else {
                $this->debugLog('Refund response not created');
            }

            return array('errors' => array('Не удалось осуществить возврат.'));
        }

        $arrayParams = array(
            'yandex_money_market_category_list',
            'yandex_money_market_currency_enabled',
            'yandex_money_market_currency_rate',
            'yandex_money_market_currency_plus',
            'yandex_money_market_delivery_enabled',
            'yandex_money_market_delivery_cost',
            'yandex_money_market_delivery_days_from',
            'yandex_money_market_delivery_days_to',
            'yandex_money_market_delivery_order_before',
            'yandex_money_market_available_enabled',
            'yandex_money_market_available_available',
            'yandex_money_market_available_delivery',
            'yandex_money_market_available_pickup',
            'yandex_money_market_available_store',
            'yandex_money_market_vat_enabled',
            'yandex_money_market_vat',
            'yandex_money_market_offer_options_export_attributes',
            'yandex_money_market_additional_condition_enabled',
            'yandex_money_market_additional_condition_categories',
            'yandex_money_market_additional_condition_name',
            'yandex_money_market_additional_condition_tag',
            'yandex_money_market_additional_condition_type_value',
            'yandex_money_market_additional_condition_static_value',
            'yandex_money_market_additional_condition_data_value',
            'yandex_money_market_additional_condition_for_all_cat',
            'yandex_money_market_additional_condition_join',
        );
        foreach ($arrayParams as $param) {
            if (!isset($_POST[$param])) {
                $sm->del('shop.yamodule_api', $param);
            }
        }

        $taxValues = array();
        foreach ($_POST as $k => $v) {
            if (strpos($k, 'ya_kassa_tax_') !== false) {
                $taxValues[$k] = $v;
                continue;
            }
            if (is_array($v)) {
                $v = json_encode($v);
            }
            $sm->set('shop.yamodule_api', $k, $v);
        }

        if ($taxValues) {
            $sm->set('shop.yamodule_api', 'taxValues', serialize($taxValues));
        }

        $array_fields = array(
            'ya_kassa_shopid'     => _w('Не заполнен shopId'),
            'ya_kassa_pw'         => _w('Не заполнен Секретный ключ'),
            'ya_p2p_number'       => _w('Не заполнен номер кошелька'),
            'ya_p2p_skey'         => _w('Не заполнен секретный ключ'),
            'ya_metrika_number'   => _w('Не заполнен номер счётчика'),
            'ya_metrika_appid'    => _w('Не заполнен id приложения'),
            'ya_metrika_pwapp'    => _w('Не заполнен пароль приложения'),
            'ya_metrika_token'    => _w('Не заполнен токен. Получите его'),
            'ya_market_name'      => _w('Не заполнено имя магазина'),
            'ya_billing_id'       => _w('Не указан ID формы'),
            'ya_billing_purpose'  => _w('Не указано назначение платежа'),
            'ya_billing_status'   => _w('Не указан статус заказа'),
        );

        $this->formValidate($sm, $array_fields);

        $all_ok = _w('Все настройки верно заполнены!');
        $arr    = array('p2p', 'kassa', 'market', 'metrika', 'yabilling');
        if (waRequest::request('mode') == 'metrika') {
            $_SESSION['metrika_errors'] = array();
            $oldSettings = $data;
            $settings    = $sm->get('shop.yamodule_api');
            $ymetrika    = new YaMetrika(
                $settings['ya_metrika_token'],
                $settings['ya_metrika_number'],
                $settings['ya_metrika_appid'],
                $settings['ya_metrika_pwapp']
            );
            if ($ymetrika->isNeedUpdateToken($oldSettings)) {
                $sm->set('shop.yamodule_api', 'ya_metrika_token', '');
            }
            if ($ymetrika->isNeedUpdateCode($settings, $oldSettings)) {
                $sm->set('shop.yamodule_api', 'ya_metrika_code', '');
                $ymetrika->updateCode();
            }
            $this->errors['metrika'] = array_merge($this->errors['metrika'], $_SESSION['metrika_errors']);
        }
        foreach ($arr as $a) {
            if (!isset($this->errors[$a]) || !count($this->errors[$a])) {
                $this->errors[$a][] = $this->success_alert($all_ok);
            }
        }

        return array('errors' => $this->errors);
    }

    public function errors_alert($text)
    {
        $html = '<div class="alert alert-danger">
                <i class="fa fa-exclamation-circle"></i> '.$text.'
            </div>';

        return $html;
    }

    public function success_alert($text)
    {
        $html = ' <div class="alert alert-success">
                    <i class="fa fa-check-circle"></i> '.$text.'
                    </div>';

        return $html;
    }

    public function info_alert($text)
    {
        $html = ' <div class="alert alert-info">
                     '.$text.'
                  </div>';

        return $html;
    }

    public static function settingsPaymentOptions($type)
    {
        $tp = array(
            \YandexCheckout\Model\PaymentMethodType::YANDEX_MONEY   => 'Яндекс.Деньги',
            \YandexCheckout\Model\PaymentMethodType::BANK_CARD      => 'Банковские карты — Visa, Mastercard и Maestro, «Мир»',
            \YandexCheckout\Model\PaymentMethodType::CASH           => 'Наличные',
            \YandexCheckout\Model\PaymentMethodType::MOBILE_BALANCE => 'Оплата со счета мобильного телефона',
            \YandexCheckout\Model\PaymentMethodType::WEBMONEY       => 'WebMoney',
            \YandexCheckout\Model\PaymentMethodType::SBERBANK       => 'Сбербанк Онлайн',
            \YandexCheckout\Model\PaymentMethodType::ALFABANK       => 'Альфа-Клик',
            \YandexCheckout\Model\PaymentMethodType::QIWI           => 'QIWI Wallet',
            \YandexCheckout\Model\PaymentMethodType::INSTALLMENTS   => 'Заплатить по частям',
        );

        return isset($tp[$type]) ? $tp[$type] : $type;
    }

    public function frontendFoot()
    {
        if ($this->getSettings('ya_metrika_code') && $this->getSettings('ya_metrika_active')) {
            $html = '<script type="text/javascript" src="'.wa()->getAppStaticUrl().'plugins/yamodule_api/js/front.js"></script>';
            $html .= $this->getSettings('ya_metrika_code');

            return $html;
        }
    }

    public function frontendSuccess()
    {
        $order_id = wa()->getStorage()->get('shop/order_id');
        if ($this->getSettings('ya_metrika_active') && $order_id) {
            $order_model       = new shopOrderModel();
            $currency_model    = new shopCurrencyModel();
            $order             = $order_model->getById($order_id);
            $currency          = $currency_model->getById($order['currency']);
            $order_items_model = new shopOrderItemsModel();
            $items             = $order_items_model->getByField('order_id', $order_id, true);

            $currency = $order['currency'] === 'RUB' ? 'RUR' : $order['currency'];
            $products = implode(',', array_map(function ($item) {
                return '{id: "'.$item['product_id'].'", name: "'.$item['name'].'", price: '.$item['price'].', quantity: '.$item['quantity'].'}';
            }, $items));

            $html = <<<HTML
<script type="text/javascript">
$(window).on("load", function() {
    window.dataLayer = window.dataLayer || [];
    dataLayer.push({
        ecommerce: {
            currencyCode: "$currency",
            purchase: {
                actionField: {
                    id: "${order['id']}",
                },
                products: [$products],
            },
        },
    });
});
</script>
HTML;
            return $html;
        }

        return '';
    }

    /**
     * @param $sm
     * @param $array_fields
     */
    protected function formValidate($sm, $array_fields)
    {
        $this->errors  = array();
        $update_status = $this->sendStatistics();
        if ($update_status != false) {
            $this->errors['update'][] = '<div class="alert alert-danger">У вас неактуальная версия модуля. Вы можете <a target="_blank" href="https://github.com/yandex-money/yandex-money-cms-shopscript5/releases">загрузить и установить</a> новую ('.$update_status.')</div>';
        }

        $this->errors['metrika'] = array();
        $data                    = $sm->get('shop.yamodule_api');
        $keys                    = array_keys($array_fields);
        foreach ($keys as $key) {
            if (empty($data[$key])) {
                $d                     = explode('_', $key);
                $this->errors[$d[1]][] = $this->errors_alert($array_fields[$key]);
            }
        }

        if (!empty($data['ya_kassa_shopid']) && !preg_match('/^\d+$/i', $data['ya_kassa_shopid'])) {
            $this->errors['kassa'][] = $this->errors_alert(
                _w(
                    'Такого shopId нет. Пожалуйста, скопируйте параметр в <a
                                    href="https://money.yandex.ru/joinups">личном кабинете Яндекс.Кассы</a>  (наверху любой страницы)'
                )
            );
        }

        if (!empty($data['ya_kassa_pw']) && !preg_match('/^test_.*|live_.*$/i', $data['ya_kassa_pw'])) {
            $this->errors['kassa'][] = $this->errors_alert(
                _w(
                    'Такого секретного ключа нет. Если вы уверены, что скопировали ключ правильно, значит, он по какой-то причине не работает. Выпустите и активируйте ключ заново — <a
                                    href="https://money.yandex.ru/joinups">в личном кабинете Яндекс.Кассы</a>'
                )
            );
        }

        if (empty($this->errors['kassa']) && !empty($data['ya_kassa_active'])) {
            if (!$this->checkConnection($data['ya_kassa_shopid'], $data['ya_kassa_pw'])) {
                $this->errors['kassa'][] = $this->errors_alert(
                    _w(
                        'Проверьте shopId и Секретный ключ — где-то есть ошибка. А лучше скопируйте их прямо '
                        .'из <a href="https://kassa.yandex.ru/my" target="_blank">личного кабинета Яндекс.Кассы</a>'
                    )
                );
            }
        }

        if ($this->isTestMode($data)) {
            $this->errors['kassa'][] = $this->info_alert(
                ' Вы включили тестовый режим приема платежей. Проверьте, как проходит оплата. <a
                    href="https://kassa.yandex.ru/">Подробнее</a>'
            );
        }
    }

    /**
     * @param $settings
     *
     * @return bool
     */
    protected function isTestMode($settings)
    {
        $shopPassword = $settings['ya_kassa_pw'];
        $prefix       = substr($shopPassword, 0, 4);

        return $prefix == "test";
    }

    /**
     * @param $orderId
     *
     * @return mixed
     */
    protected function getTransactionByOrder($orderId)
    {
        $transactionModel = new waTransactionModel();
        $transactions = $transactionModel->getByFields(array('order_id' => $orderId));
        if ($transactions) {
            $transactionData = array_shift($transactions);
        }

        if (isset($transactionData)) {
            return $transactionData;
        }

        return null;
    }

    /**
     * @param $params
     * @return void
     */
    public function orderActionProcess($params)
    {
        $app      = new waAppSettingsModel();
        $settings = $app->get('shop.yamodule_api');
        if ($params['before_state_id'] !== $settings['ya_kassa_hold_order_status']) {
            return;
        }

        if($settings['ya_kassa_enable_hold_mode'] !== '1'){
            return;
        }

        $order_model      = new shopOrderModel();
        $order            = $order_model->getOrder($params['order_id']);
        $waOrder          = waOrder::factory($order);
        $transaction      = $this->getTransactionByOrder($params['order_id']);

        if (empty($transaction)) {
            $this->debugLog('Empty transaction on orderActionProcess');
            return;
        }
        if (empty($transaction['native_id'])) {
            $this->debugLog('Empty transaction.native_id on orderActionProcess');
            return;
        }

        $shopId       = $settings['ya_kassa_shopid'];
        $shopPassword = $settings['ya_kassa_pw'];
        $apiClient    = $this->getApiClient($shopId, $shopPassword);
        $payment      = $apiClient->getPaymentInfo($transaction['native_id']);
        if (empty($payment)) {
            $this->debugLog('Empty payment on orderActionProcess');
            return;
        }
        if ($payment->getStatus() !== PaymentStatus::WAITING_FOR_CAPTURE) {
            $this->debugLog('Wrong payments status: '.$payment->getStatus());
        }

        try {
            $builder = CreateCaptureRequest::builder();
            $builder->setAmount($order['total']);
            yamodulepay_apiPayment::setReceiptIfNeeded($builder, $waOrder);

            $captureRequest = $builder->build();
            $receipt        = $captureRequest->getReceipt();
            if ($receipt instanceof \YandexCheckout\Model\Receipt) {
                $receipt->normalize($captureRequest->getAmount());
            }

            $payment = $apiClient->capturePayment($captureRequest, $payment->getId());
            if ($payment->getStatus() === PaymentStatus::SUCCEEDED) {
                yamodulepay_apiPayment::addOrderLogComment($order['id'], 'Вы подтвердили платёж в Яндекс.Кассе.');
                return;
            }
        } catch (Exception $e) {
            $this->log('error', 'Failed to capture payment: '.$e->getMessage());
        }
        yamodulepay_apiPayment::addOrderLogComment($order['id'], 'Платёж не подтвердился. Попробуйте ещё раз.');
        yamodulepay_apiPayment::changeOrderState($order, $settings['ya_kassa_hold_order_status']);
        $this->debugLog('Failed to capture payment');
    }

    /**
     * @param $params
     * @return void
     */
    public function orderActionDelete($params)
    {
        $app      = new waAppSettingsModel();
        $settings = $app->get('shop.yamodule_api');
        if ($params['before_state_id'] !== $settings['ya_kassa_hold_order_status']) {
            return;
        }
        $order_model      = new shopOrderModel();
        $order            = $order_model->getOrder($params['order_id']);
        $transaction      = $this->getTransactionByOrder($params['order_id']);

        if (empty($transaction)) {
            $this->debugLog('Empty transaction on orderActionDelete');
            return;
        }
        if (empty($transaction['native_id'])) {
            $this->debugLog('Empty transaction.native_id on orderActionDelete');
            return;
        }

        $shopId       = $settings['ya_kassa_shopid'];
        $shopPassword = $settings['ya_kassa_pw'];
        $apiClient    = $this->getApiClient($shopId, $shopPassword);
        $payment      = $apiClient->getPaymentInfo($transaction['native_id']);
        if (empty($payment)) {
            $this->debugLog('Empty payment on orderActionDelete');
            return;
        }
        if ($payment->getStatus() !== PaymentStatus::WAITING_FOR_CAPTURE) {
            $this->debugLog('Wrong payments status: '.$payment->getStatus());
        }

        try {
            $result = $apiClient->cancelPayment($payment->getId());
            if ($result === null) {
                throw new RuntimeException('Failed to capture payment after 3 retries');
            }
            if ($payment->getStatus() === PaymentStatus::CANCELED) {
                yamodulepay_apiPayment::addOrderLogComment($order['id'], 'Вы отменили платёж в Яндекс.Кассе. Деньги вернутся клиенту.');
                return;
            }
        } catch (Exception $e) {
            $this->log('error', 'Failed to cancel payment: '.$e->getMessage());
        }
        yamodulepay_apiPayment::addOrderLogComment($order['id'], 'Платёж не отменился. Попробуйте ещё раз.');
        yamodulepay_apiPayment::changeOrderState($order, $settings['ya_kassa_hold_order_status']);
        $this->debugLog('Failed to cancel payment');
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
        $plugin = $this;
        $apiClient->setLogger(
            function ($level, $message, $context) use ($plugin) {
                $plugin->debugLog($message);
            }
        );

        return $apiClient;
    }

    public function kassaOrderReturn()
    {
        $config     = waConfig::getAll();
        $appsPath   = $config['wa_path_apps'];
        $pluginPath = $config['wa_path_plugins'];
        require_once $appsPath.DIRECTORY_SEPARATOR.'shop'.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'yamodule_api'.DIRECTORY_SEPARATOR.'api'.DIRECTORY_SEPARATOR.'orderReceiptModel.php';
        require_once $appsPath.DIRECTORY_SEPARATOR.'shop'.DIRECTORY_SEPARATOR.'plugins'.DIRECTORY_SEPARATOR.'yamodule_api'.DIRECTORY_SEPARATOR.'api'.DIRECTORY_SEPARATOR.'orderRefundModel.php';
        require_once $pluginPath.DIRECTORY_SEPARATOR.'payment'.DIRECTORY_SEPARATOR.'yamodulepay_api'.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';

        $view    = wa()->getView();
        $orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        $order_model        = new shopOrderModel();
        $orderReceiptsModel = new orderReceiptModel();
        $orderRefundModel   = new orderRefundModel();
        $order              = $order_model->getById($orderId);
        $state              = $order['state_id'];
        $orderReceipt       = $orderReceiptsModel->getByOrderId((int)$order['id']);
        $app                = new waAppSettingsModel();
        $settings           = $app->get('shop.yamodule_api');
        $shopId             = $settings['ya_kassa_shopid'];
        $shopPassword       = $settings['ya_kassa_pw'];
        $apiClient          = new Client();
        $apiClient->setAuth($shopId, $shopPassword);
        $that = $this;
        $apiClient->setLogger(
            function ($level, $message, $context) use ($that) {
                $that->debugLog($message);
            }
        );

        $transaction = $this->getTransactionByOrder((int)$order['id']);
        if ($transaction && isset($transaction['native_id'])) {
            $paymentId = $transaction['native_id'];

            try {
                $result = $apiClient->getPaymentInfo($paymentId);
            } catch (Exception $e) {
                $this->debugLog($e->getMessage());
            }
            if (!empty($result) && $result->getStatus() != PaymentStatus::PENDING) {
                $paymentMethod      = $result->getPaymentMethod();
                $paymentMethodType  = $paymentMethod->getType();
                $paymentMethodTitle = $this->settingsPaymentOptions($paymentMethodType);

                $receipt = $orderReceipt['receipt'];
                if ($receipt) {
                    $receiptData = json_decode($receipt, true);
                    $items       = $receiptData['items'];
                    $actualTotal = 0;

                    foreach ($items as $item) {
                        $actualTotal += $item['amount']['value'] * $item['quantity'];
                    }
                    if (empty($items)) {
                        $errors[] = 'Нет товаров для отправки в Яндекс.Касса';
                    }
                } else {
                    $items     = array();
                    $email     = '';
                    $taxValues = array();
                }

                $refunds     = $orderRefundModel->getByOrderId($orderId);
                $returnTotal = 0;
                if ($refunds) {
                    foreach ($refunds as $refund) {
                        $returnTotal += (float)$refund['amount'];
                    }
                }
                $showReturnTab = !in_array($order['state_id'], array('new', 'processing'));
                $view->assign(
                    array(
                        'show_return_tab'     => $showReturnTab,
                        'return_total'        => $returnTotal,
                        'return_sum'          => $order['total'],
                        'invoiceId'           => $paymentId,
                        'return_items'        => $refunds,
                        'payment_method'      => $paymentMethodTitle,
                        'return_errors'       => $errors,
                        'total'               => $order['total'],
                        'id_order'            => $orderId,
                        'test'                => 1,
                        'pym'                 => $paymentId,
                        'state'               => $state,
                        'products'            => $items,
                        'orderTotal'          => $order['total'],
                        'taxTotal'            => $order['tax'],
                        'ya_kassa_send_check' => 1,
                    )
                );

                $html = '';

                $html['info_section'] = $view->fetch($this->path.'/templates/actions/settings/tabs_return.html');

                return $html;
            }
        }
    }

    public function debugLog($message)
    {
        $this->log('yamodulepayApi', $message);
    }

    private function log($module_id, $data)
    {
        static $id;
        if (empty($id)) {
            $id = uniqid();
        }
        $rec       = '#'.$id."\n";
        $module_id = strtolower($module_id);
        if (!preg_match('@^[a-z][a-z0-9]+$@', $module_id)) {
            $rec       .= 'Invalid module_id: '.$module_id."\n";
            $module_id = 'general';
        }
        $filename = 'payment/'.$module_id.'Payment.log';
        $rec      .= "data:\n";
        if (!is_string($data)) {
            $data = var_export($data, true);
        }
        $rec .= "$data\n";
        waLog::log($rec, $filename);
    }

    private function checkConnection($shopId, $password)
    {
        $file = realpath(dirname(__FILE__).'/../../../../..').'/wa-plugins/payment/yamodulepay_api/vendor/autoload.php';
        if (!file_exists($file)) {
            return false;
        }
        require_once $file;

        $apiClient = new Client();
        $apiClient->setAuth($shopId, $password);

        try {
            $payment = $apiClient->getPaymentInfo('00000000-0000-0000-0000-000000000001');
        } catch (\YandexCheckout\Common\Exceptions\NotFoundException $e) {
            return true;
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    public function yaFrontendHead()
    {
        return '
<style>.installments-info{padding-top: 20px;}</style>
<script src="https://static.yandex.net/kassa/pay-in-parts/ui/v1/"></script>
';
    }

    /**
     * @param shopProduct $data
     * @return array
     */
    public function yaFrontendProduct($data)
    {
        $sm = new waAppSettingsModel();
        $shopData = $sm->get('shop.yamodule_api');
        $shopId = $shopData['ya_kassa_shopid'];

        ob_start();
        echo empty($shopData['ya_kassa_add_installments_block']) ? '' : '<div class="installments-info"></div>';
        ?>
        <script type="text/javascript">
            $(window).on("load", function () {
                if (typeof YandexCheckoutCreditUI !== 'undefined') {
                    const $yamoneyCheckoutCreditUI = YandexCheckoutCreditUI({
                        shopId: <?= $shopId; ?>,
                        sum: "<?= $data['data']['price']; ?>",
                        language: "ru"
                    });
                    $yamoneyCheckoutCreditUI({
                        type: "info",
                        domSelector: ".installments-info"
                    });
                }
                window.dataLayer = window.dataLayer || [];
                dataLayer.push({
                    ecommerce: {
                        detail: {
                            products: [{
                                id: "<?= $data['data']['id']; ?>",
                                name: "<?= $data['data']['name']; ?>",
                                price: parseFloat("0<?= $data['data']['price'] ?: 0; ?>")
                            }]
                        }
                    }
                });
            });
        </script>
        <?php
        $html = ob_get_contents();
        ob_end_clean();

        return array(
            'cart' => $html
        );
    }
}