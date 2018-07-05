<?php
$model = new waModel();
$sql1  = 'CREATE TABLE IF NOT EXISTS `shop_ym_order_receipt` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `order_id` INT NULL,
            `receipt` TEXT NULL,
            PRIMARY KEY (`id`),
            INDEX `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

$sql_mws = 'CREATE TABLE IF NOT EXISTS `shop_ym_order_refund` (
                `id` INT(10) NOT NULL AUTO_INCREMENT,
                `payment_id` VARCHAR(256) NOT NULL,
                `order_id` INT(11) NOT NULL,
                `cause` TEXT NOT NULL,
                `amount` DECIMAL(10,2) NOT NULL,
                `request` TEXT NOT NULL,
                `response` TEXT NOT NULL,
                `status` VARCHAR(256) NOT NULL,
                `error` VARCHAR(1024) NOT NULL,
                `date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `refund_receipt` TEXT NULL,
                PRIMARY KEY (`id`)
            )
            ENGINE=InnoDB DEFAULT CHARSET=utf8;';
$s       = $model->query($sql1);
$s       = $model->query($sql2);
$s_mws   = $model->query($sql_mws);

$plugin_id          = array('shop', 'yamodule_api');
$app_settings_model = new waAppSettingsModel();
$data_db            = array(
    'action'                  => 'kassa',
    'ya_kassa_active'         => '0',
    'ya_kassa_inside'         => '1',
    'ya_kassa_paylogo'        => '1',
    'ya_kassa_installments_button' => '1',
    'ya_kassa_log'            => '1',
    'ya_kassa_qw'             => '1',
    'ya_kassa_alfa'           => '1',
    'ya_kassa_sber'           => '1',
    'ya_kassa_wm'             => '1',
    'ya_p2p_active'           => '1',
    'ya_kassa_terminal'       => '1',
    'ya_kassa_card'           => '1',
    'ya_kassa_wallet'         => '1',
    'ya_kassa_pw'             => '',
    'ya_kassa_shopid'         => '',
    'ya_kassa_description_template' => '',
    'ya_kassa_send_check'     => '0',
    'ya_kassa_default_vat_code' => '1',
    'status'                  => 'you',
    'update_time'             => '1',
    'ya_kassa_test'           => '1',
    'ya_p2p_test'             => '1',
    'ya_p2p_number'           => '',
    'ya_p2p_skey'             => '',
    'ya_p2p_log'              => '1',
    'ya_metrika_active'       => '1',
    'ya_metrika_number'       => '',
    'ya_metrika_appid'        => '',
    'ya_metrika_pwapp'        => '',
    'ya_metrika_login'        => '',
    'ya_metrika_userpw'       => '',
    'ya_metrika_token'        => '',
    'ya_metrika_ww'           => '1',
    'ya_metrika_map'          => '1',
    'ya_metrika_hash'         => '1',
    'ya_metrika_log'          => '1',
    'ya_market_simpleyml'     => '0',
    'ya_market_selected'      => '1',
    'ya_market_set_available' => '1',
    'ya_market_name'          => '',
    'ya_market_price'         => '',
    'ya_market_available'     => '1',
    'ya_market_home'          => '0',
    'ya_market_comb'          => '1',
    'ya_market_fea'           => '1',
    'ya_market_dm'            => '1',
    'ya_market_currencies'    => '1',
    'ya_market_store'         => '',
    'ya_market_delivery'      => '',
    'ya_market_pickup'        => '',
    'ya_market_log'           => '1',
    'ya_metrika_code'         => ' ',
    'ya_metrika_informer'     => '1',
    'ya_market_vendor'        => '',
    'ya_market_currency'      => 'RUB',
    'ya_market_categories'    => '',
    'ya_plugin_contact'       => '',
    'type'                    => 'metrika',
    'ya_billing_active'       => '',
    'ya_billing_id'           => '',
    'ya_billing_purpose'      => 'Номер заказа %order_id% Оплата через Яндекс.Платежку',
    'ya_billing_status'       => 'created',
    'ya_wallet_status'        => 'created',
);

if ($s) {
    foreach ($data_db as $k => $val) {
        $app_settings_model->set($plugin_id, $k, $val);
    }
}

$contact    = new waContactEmailsModel();
$contact_id = $contact->getContactIdByEmail('yandex@buy.rux');

if (!$contact_id) {
    $user                    = new waContact();
    $user['firstname']       = 'Yandex';
    $user['lastname']        = 'Buy';
    $user['email']           = 'yandex@buy.rux';
    $user['create_datetime'] = date('Y-m-d H:i:s');
    $user['create_app_id']   = 'shop';
    $user['password']        = base64_decode('000000');
    $errors_c                = $user->save();

    $app_settings_model->set($plugin_id, 'ya_plugin_contact', $user->getId());
} else {
    $app_settings_model->set($plugin_id, 'ya_plugin_contact', $contact_id);
}