<?php

class YandexMarketSettingsCurrency
{
    const CURRENCY_RUB = 'RUB';
    const CURRENCY_USD = 'USD';
    const CURRENCY_EUR = 'EUR';
    const CURRENCY_UAH = 'UAH';
    const CURRENCY_BYN = 'BYN';
    const CURRENCY_KZT = 'KZT';

    const RATE_MAIN_CURRENCY = '1';
    const RATE_CBRF          = 'CBRF';
    const RATE_NBU           = 'NBU';
    const RATE_NBK           = 'NBK';
    const RATE_CB            = 'CB';
    const RATE___CMS         = '__cms';

    private $currencyIds = array(
        self::CURRENCY_RUB,
        self::CURRENCY_USD,
        self::CURRENCY_EUR,
        self::CURRENCY_UAH,
        self::CURRENCY_BYN,
        self::CURRENCY_KZT
    );

    private $currencyRates = array(self::RATE_CBRF, self::RATE_NBU, self::RATE_NBK, self::RATE_CB);

    private $defaultCurrency = '';

    private $settings;

    private $langRates  = array(
        '1'    => 'основная валюта',
        'CBRF' => 'по курсу ЦБ РФ',
        'NBU'  => 'по курсу НБ Украины',
        'NBK'  => 'по курсу НБ Казахстана',
        'CB'   => 'по курсу банка страны из личного кабинета',
    );
    private $langOk     = 'OK';
    private $langCancel = 'Отменить';
    private $langPlus   = 'надбавка';

    /**
     * YandexMarketSettingsCurrency constructor.
     * @param array $settings
     */
    public function __construct($settings)
    {
        $this->settings = (array)$settings;
    }

    /**
     * @param array $cmsCurrencies
     * @return string
     */
    public function htmlCurrencyList($cmsCurrencies)
    {
        $html = '';

        foreach ($cmsCurrencies as $currency => $currencyData) {
            if (ym_array_get($currencyData, 'is_primary') && in_array($currency, $this->currencyIds)) {
                $this->defaultCurrency = $currency;
            }
        }

        $cmsCurrencyIds = array_keys($cmsCurrencies);

        foreach ($this->currencyIds as $currencyId) {
            $html .= $this->htmlCurrency($currencyId, $cmsCurrencyIds);
        }

        return $html;
    }

    /**
     * @param $id
     * @param $cmsCurrencyIds
     * @return string
     */
    private function htmlCurrency($id, $cmsCurrencyIds)
    {
        if (!in_array($id, $cmsCurrencyIds)) {
            return '<div class="yandex-money-market-currency-disabled"><input type="checkbox" disabled="disabled">'.$id.'</div>';
        }

        $enabled = $this->getConfig('enabled', $id);
        $rate    = $this->getConfig('rate', $id);
        $plus    = (float)$this->getConfig('plus', $id, 0);
        if ($id === $this->defaultCurrency) {
            $rate = self::RATE_MAIN_CURRENCY;
            $plus = '';
        }

        $saveRate = $rate !== self::RATE_MAIN_CURRENCY ? $rate : '';

        $htmlView = $this->htmlView($rate, $plus);
        $htmlEdit = $this->htmlEdit($id, $saveRate, $plus);

        $checked = $enabled ? 'checked="checked"' : '';

        $jsEditableClass = $rate !== self::RATE_MAIN_CURRENCY ? 'yandex-money-market-js-editable' : '';

        $hidden = $rate === self::RATE_MAIN_CURRENCY ? ' style="display:none;"' : '';

        $html = <<<HTML
            <div class="{$jsEditableClass}">
                <input type="checkbox" name="yandex_money_market_currency_enabled[{$id}]" value="1" {$checked} />
                <span id="yandex-money-market-currency-id-text">{$id}</span>
                {$htmlView}              
                {$htmlEdit}
                <i class="yandex-money-market-edit-on-button icon16 edit" {$hidden}></i>
            </div>
HTML;
        return $html;
    }

    /**
     * @param $rate
     * @param $plus
     * @return string
     */
    private function htmlView($rate, $plus)
    {
        $rateText = ym_array_get($this->langRates, $rate, '');
        $plusText = $this->langPlus;
        $hidden   = $rate === self::RATE_MAIN_CURRENCY ? ' style="display:none;"' : '';

        $html = <<< HTML
            <span class="yandex-money-market-js-editable-view">
                <span class="yandex-money-market-currency-view-rate">
                    {$rateText}
                </span>
                <span class="yandex-money-market-currency-view-plus" {$hidden}>
                    ({$plusText} 
                    <span class="yandex-money-market-currency-view-plus-value">{$plus}</span>%)
                </span>
            </span>
HTML;
        return $html;
    }

    /**
     * @param $id
     * @param $rate
     * @param $plus
     * @return string
     */
    private function htmlEdit($id, $rate, $plus)
    {
        $select = $this->htmlRateSelect($id, $rate);

        $html = <<<HTML
        <div class="yandex-money-market-js-editable-edit yandex-money-market-currency-edit">
                <div class="form-group">
                    {$select}
                </div>
                <div class="form-group">
                    {$this->langPlus}
                    <br>
                    <input type="text" size="3" maxlength="3"
                        value="{$plus}" data-value="{$plus}"
                        name="yandex_money_market_currency_plus[{$id}]"
                        class="yandex-money-market-currency-plus">
                </div>
                <div class="form-group">
                    <input type="button" class="button green edit_finish" value="{$this->langOk}"/>
                    <input type="button" class="button edit_finish_reset" value="{$this->langCancel}"/>
                </div>
        </div> 
HTML;
        return $html;
    }

    /**
     * @param $id
     * @param $rate
     * @return string
     */
    private function htmlRateSelect($id, $rate)
    {
        $html = <<<HTML
            <select class="yandex-money-market-currency-rate"
                     name="yandex_money_market_currency_rate[{$id}]" 
                     data-value="{$rate}">
HTML;
        foreach ($this->currencyRates as $rateKey) {
            $html .= $this->htmlRateOption($rateKey, $rate);
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * @param $rateKey
     * @param $currentRate
     * @return string
     */
    private function htmlRateOption($rateKey, $currentRate)
    {
        $selected = $rateKey === $currentRate ? 'selected="selected"' : '';
        $rateText = ym_array_get($this->langRates, $rateKey, '');

        return <<<HTML
            <option value="{$rateKey}" {$selected}>{$rateText}</option>
HTML;
    }

    /**
     * @param $key
     * @param $index
     * @param null $default
     * @return mixed
     */
    private function getConfig($key, $index = null, $default = null)
    {
        return ym_get_settings($this->settings, 'yandex_money_market_currency_'.$key, $index, $default);
    }
}