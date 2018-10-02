<?php

/**
 * @param array $array
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function ym_array_get($array, $key, $default = null)
{
    return isset($array[$key]) ? $array[$key] : $default;
}

/**
 * @param array $settings
 * @param string $key
 * @param mixed $index
 * @param null $default
 * @return mixed
 */
function ym_get_settings($settings, $key, $index = null, $default = null)
{
    if (in_array($key, array(
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
            'yandex_money_market_additional_condition_categories',
            'yandex_money_market_additional_condition_data_value',
            'yandex_money_market_additional_condition_enabled',
            'yandex_money_market_additional_condition_for_all_cat',
            'yandex_money_market_additional_condition_join',
            'yandex_money_market_additional_condition_name',
            'yandex_money_market_additional_condition_static_value',
            'yandex_money_market_additional_condition_tag',
            'yandex_money_market_additional_condition_type_value',
        ))
        && isset($settings[$key])
    ) {
        $settingsKey = json_decode($settings[$key], true);
        if ($index === null) {
            return $settingsKey;
        }

        return ym_array_get(
            $settingsKey,
            $index,
            $default
        );
    }


    return $index === null
        ? ym_array_get($settings, $key)
        : ym_array_get(
            ym_array_get($settings, $key, array()),
            $index,
            $default
        );
}