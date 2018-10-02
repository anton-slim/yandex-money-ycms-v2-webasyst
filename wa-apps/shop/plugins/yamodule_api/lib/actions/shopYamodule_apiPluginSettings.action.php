<?php

require_once dirname(__FILE__).'/../../../../../../wa-plugins/payment/yamodulepay_api/lib/yamodulepay_apiPayment.class.php';
require_once dirname(__FILE__).'/../models/YandexMarketSettings.php';

class shopYamodule_apiPluginSettingsAction extends waViewAction
{

    protected $plugin_id = array('shop', 'yamodule_api');

    public function gocurl($type, $post)
    {
        $url = 'https://oauth.yandex.ru/token';
        $ch  = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 9);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($result);
        if ($status == 200) {
            if ( ! empty($data->access_token)) {
                $sm = new waAppSettingsModel();
                if ($type == 'm') {
                    $sm->set('shop.yamodule_api', 'ya_metrika_token', $data->access_token);
                }
            }

            return $data->access_token;
            //die(json_encode(array('token' => $data->access_token)));
        } else {
            return false;
        }
    }

    public function execute()
    {
        $sm       = new waAppSettingsModel();
        $settings = $sm->get($this->plugin_id);

        if (waRequest::request('code') && waRequest::request('genToken')) {
            if (waRequest::request('state') == 'metrika') {
                $this->gocurl(
                    'm',
                    'grant_type=authorization_code&code='.waRequest::request(
                        'code'
                    ).'&client_id='.$settings['ya_metrika_appid'].'&client_secret='.$settings['ya_metrika_pwapp']
                );
            }

            header('Location: ' . wa()->getRootUrl(true).'webasyst/shop/?action=plugins#/yamodule_api/');
            exit();
        }

        $plugin_model                     = new shopPluginModel();
        $methods                          = $plugin_model->listPlugins('shipping');

        $taxModel = new shopTaxModel();
        $taxes    = $taxModel->getAll();
        $this->view->assign('taxes', $taxes);

        if (isset($settings['taxValues'])) {
            @$val = unserialize($settings['taxValues']);
            if (is_array($val)) {
                $this->view->assign($val);
            }
        }

        $root = str_replace('http://', 'https://', wa()->getRootUrl(true));

        $this->view->assign('ya_kassa_test_mode', $this->isTestMode($settings));
        $this->view->assign('ya_kassa_methods', $methods);
        $this->view->assign('ya_kassa_check', $this->getRelayUrl(true));
        $this->view->assign('ya_kassa_callback', $this->getRelayUrl(true).'?action=callback');
        $this->view->assign('ya_kassa_fail', $this->getRelayUrl().'?result=fail');
        $this->view->assign('ya_kassa_success', $this->getRelayUrl().'?result=success');
        $this->view->assign('ya_p2p_callback', $this->getRelayUrl(true).'?action=callbackwallet');
        $this->view->assign(
            'ya_wallet_status',
            empty($settings['ya_wallet_status']) ? 'created' : $settings['ya_wallet_status']
        );
        $this->view->assign('ya_metrika_callback', $root . 'payments.php/yamodulepay_api/?action=callback&genToken=1');

        $this->view->assign('ya_billing_active', empty($settings['ya_billing_active']) ? false : true);
        $this->view->assign('ya_billing_id', empty($settings['ya_billing_id']) ? '' : $settings['ya_billing_id']);
        $this->view->assign(
            'ya_billing_purpose',
            empty($settings['ya_billing_purpose']) ? 'Номер заказа %order_id% Оплата через Яндекс.Платежку' : $settings['ya_billing_purpose']
        );
        $this->view->assign(
            'ya_billing_status',
            empty($settings['ya_billing_status']) ? 'created' : $settings['ya_billing_status']
        );

        $this->view->assign('ya_kassa_send_check', empty($settings['ya_kassa_send_check']) ? false : true);

        $workflow = new shopWorkflow();
        $states   = $workflow->getAllStates();
        $this->view->assign('ya_billing_statuses', $states);
        $this->view->assign('ya_wallet_statuses', $states);

        $this->view->assign($settings);

        $ya_kassa_description_template = !empty($settings['ya_kassa_description_template'])
            ? $settings['ya_kassa_description_template']
            : 'Оплата заказа №%id%';
        $this->view->assign('ya_kassa_description_template', $ya_kassa_description_template);

        $this->view->assign('ya_kassa_tax_list', array(
            '1' => 'Без НДС',
            '2' => '0%',
            '3' => '10%',
            '4' => '18%',
            '5' => 'Расчётная ставка 10/110',
            '6' => 'Расчётная ставка 18/118',
        ));

        $ya_kassa_default_vat_code = isset($settings['ya_kassa_default_vat_code'])
            ? $settings['ya_kassa_default_vat_code']
            : yamodulepay_apiPayment::DEFAULT_VAT_CODE;
        $this->view->assign('ya_kassa_default_vat_code', $ya_kassa_default_vat_code);

        $workflow = new shopWorkflow();
        $states = $workflow->getAllStates();
        $states_list = array();
        foreach($states as $state) {
            $states_list[$state->getId()] = $state->getName();
        }
        $this->view->assign('ya_kassa_states_list', $states_list);

        $ya_kassa_hold_order_status = ym_array_get($settings, 'ya_kassa_hold_order_status', '');
        $this->view->assign('ya_kassa_hold_order_status', $ya_kassa_hold_order_status);


        /** Market Page */

        $market = new YandexMarketSettings();

        $shopCurrency = new shopCurrencyModel();
        $waCategories = $this->getCategories();
        $this->view->assign('market_currency_list',
            $market->getCurrency($settings)->htmlCurrencyList($shopCurrency->getCurrencies()));
        $settingsCategoryList = ym_array_get($settings, 'yandex_money_market_category_list');
        $selectedCategoryList = !empty($settingsCategoryList) ? (array)json_decode($settingsCategoryList) : array();
        $this->view->assign('market_category_tree',
            $market->getCategoryTree($waCategories)->getCategoryTree($selectedCategoryList));
        $defaultCurrency = $this->getDefaultCurrency($shopCurrency->getCurrencies());
        $this->view->assign('market_delivery_list', $market->getDelivery($settings, $defaultCurrency)->htmlDeliveryList());
        $this->view->assign('yandex_money_market_name_template',
            ym_array_get($settings, 'yandex_money_market_name_template', '%name%')
        );
        $this->view->assign('market_available_list', $market->getAvailable($settings)->htmlAvailableList());
        $this->view->assign('market_vat_const_list',
            array(
                'VAT_18'     => '18%',
                'VAT_10'     => '10%',
                'VAT_18_118' => '18/118',
                'VAT_10_110' => '10/110',
                'VAT_0'      => '0%',
                'NO_VAT'     => 'НДС не облагается'
            ));
        $this->view->assign('market_additional_condition_list',
            $market->getAdditionalCondition($settings, $waCategories)->htmlAdditionalConditionList());

        $ff          = new shopFeatureModel();
        $ya_features = $ff->getAll();
        $this->view->assign('ya_features', $ya_features);
        $this->view->assign(
            'ya_market_yml',
            str_replace(
                'http://',
                'https://',
                wa()->getRouteUrl('shop/frontend', array('module' => 'yamodule_api', 'action' => 'market'), true)
            )
        );
    }

    public final function getRelayUrl($force_https = true)
    {
        $url = wa()->getRootUrl(true).'payments.php/yamodulepay_api/';
        if ($force_https) {
            $url = preg_replace('@^http://@', 'https://', $url);
        } elseif ($force_https === false) {
            $url = preg_replace('@^https://@', 'http://', $url);
        }

        return $url;
    }

    public function getCategories()
    {
        $cat   = new shopCategoryModel();
        $sql   = "SELECT c.* FROM `shop_category` c";
        $where = " status = 1";
        $sql   .= ' WHERE '.$where;
        $sql   .= " ORDER BY `id`";
        $array = $cat->query($sql)->fetchAll();

        return $array;
    }

    /**
     * @param $cmsCurrencies
     * @return string
     */
    public function getDefaultCurrency($cmsCurrencies)
    {
        foreach ($cmsCurrencies as $currency => $currencyData) {
            if (ym_array_get($currencyData, 'is_primary')) {
                return $currency;
            }
        }

        return 'RUB';
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

}
