<?php

class YandexMarketSettingsDelivery
{
    private $settings;
    private $defaultCurrency;

    private $langCost                = 'Стоимость';
    private $langDays                = 'Срок доставки';
    private $langDaysFrom            = 'от';
    private $langDaysTo              = 'до';
    private $langDaysOrderBefore     = 'При заказе до';
    private $langDaysMeasurementUnit = 'дн.';
    private $langUseDefault          = 'Использовать значение по умолчанию';
    private $langText                = 'доставка';
    private $langOrderBefore         = 'при заказе до';
    private $langDefaultValue        = '13:00 (по умолчанию для Маркета)';
    private $langOk                  = 'OK';
    private $langCancel              = 'Отмена';

    /**
     * @param array $settings
     * @param $defaultCurrency
     */
    public function __construct($settings, $defaultCurrency)
    {
        $this->settings        = (array)$settings;
        $this->defaultCurrency = $defaultCurrency;
    }

    /**
     * @return string
     */
    public function htmlDeliveryList()
    {
        $html = '';

        for ($index = 1; $index <= 5; $index++) {
            $html .= $this->htmlDelivery($index);
        }

        return $html;
    }

    private function htmlDelivery($index)
    {
        $enabled     = $this->getConfig('enabled', $index);
        $cost        = $this->getConfig('cost', $index);
        $daysFrom    = $this->getConfig('days_from', $index);
        $daysTo      = $this->getConfig('days_to', $index);
        $orderBefore = $this->getConfig('order_before', $index);

        $htmlView = $this->htmlView($cost, $daysFrom, $daysTo, $orderBefore);
        $htmlEdit = $this->htmlEdit($index, $cost, $daysFrom, $daysTo, $orderBefore);

        $checked = $enabled ? 'checked="checked"' : '';

        $html = <<<HTML
            <div class="yandex-money-market-delivery yandex-money-market-js-editable">
                <input type="checkbox" name="yandex_money_market_delivery_enabled[{$index}]" value="1" {$checked} />
                {$htmlView}
                {$htmlEdit}
                <i class="yandex-money-market-edit-on-button icon16 edit"></i>
            </div>
HTML;
        return $html;
    }

    private function htmlView($cost, $daysFrom, $daysTo, $orderBefore)
    {
        $costValue        = (int)$cost;
        $daysValue        = empty($daysTo) || $daysFrom === $daysTo ? (int)$daysFrom : $daysFrom.'-'.$daysTo;
        $orderBeforeValue = $orderBefore ? $orderBefore.':00' : $this->langDefaultValue;

        $html = <<< HTML
            <span class="yandex-money-market-js-editable-view">
                <span class="yandex-money-market-delivery-cost">{$costValue}</span>
                {$this->defaultCurrency} 
                {$this->langText} 
                <span class="delivery_days">{$daysValue}</span>
                {$this->langDaysMeasurementUnit}
                {$this->langOrderBefore} 
                <span class="yandex-money-market-delivery-order-before">{$orderBeforeValue}</span>
            </span>
HTML;
        return $html;
    }

    /**
     * @param int $index
     * @param string $cost
     * @param string $daysFrom
     * @param string $daysTo
     * @param int $orderBefore
     * @return string
     */
    private function htmlEdit($index, $cost, $daysFrom, $daysTo, $orderBefore)
    {
        $orderBeforeSelect = $this->htmlOrderBeforeSelect($index, $orderBefore);

        $html = <<<HTML
        <div  class="yandex-money-market-delivery-edit yandex-money-market-js-editable-edit">
            <div class="form-group">
                <label>{$this->langCost} ({$this->defaultCurrency})</label>
                <div>
                    <input type="text" class="yandex-money-market-delivery-cost" 
                    name="yandex_money_market_delivery_cost[{$index}]" 
                    value="{$cost}" data-value="{$cost}"/>
                </div>
            </div>        
            <div class="form-group">
                <label>{$this->langDays}</label>
                <div>
                    {$this->langDaysFrom}
                    <input type="text" class="yandex-money-market-delivery-days-from" name="yandex_money_market_delivery_days_from[{$index}]"
                        value="{$daysFrom}" data-value="{$daysFrom}" min="0" max="31" size="3"/>
                    {$this->langDaysTo}
                    <input type="text" class="yandex-money-market-delivery-days-to" name="yandex_money_market_delivery_days_to[{$index}]"
                        value="{$daysTo}" data-value="{$daysTo}" min="0" max="31" size="3"/>
                    {$this->langDaysMeasurementUnit}   
                </div>
            </div>        
            <div class="form-group">
                <label>{$this->langDaysOrderBefore}</label>
                <div>
                    {$orderBeforeSelect}
                </div>
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
     * @param int $index
     * @param int $selectedTime
     * @return string
     */
    private function htmlOrderBeforeSelect($index, $selectedTime)
    {
        $useDefaultValue = $this->langUseDefault;

        $html = <<<HTML
            <select class="yandex-money-market-delivery-order-before"
                name="yandex_money_market_delivery_order_before[{$index}]"
                data-value="{$selectedTime}">
HTML;

        for ($time = 0; $time <= 24; $time++) {
            $html .= $this->htmlOrderBeforeOption($time, $selectedTime, $useDefaultValue);
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * @param int $time
     * @param int $selectedTime
     * @param string $useDefaultValue
     * @return string
     */
    private function htmlOrderBeforeOption($time, $selectedTime, $useDefaultValue)
    {
        $selected = $time === (int)$selectedTime ? 'selected="selected"' : '';
        $timeText = $time === 0 ? $useDefaultValue : $time.':00';

        return <<<HTML
            <option value="{$time}" {$selected}>{$timeText}</option>
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
        return ym_get_settings($this->settings, 'yandex_money_market_delivery_'.$key, $index, $default);
    }
}