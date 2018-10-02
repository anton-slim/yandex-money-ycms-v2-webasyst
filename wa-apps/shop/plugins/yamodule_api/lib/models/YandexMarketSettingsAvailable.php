<?php

class YandexMarketSettingsAvailable
{
    private $settings;

    private $langNonZeroCountGoods   = 'Товары в наличии';
    private $langIfZeroCountGoods    = 'Товары отсутствующие на складе';
    private $langDeliveryDescription = 'доставка до места';
    private $langPickupDescription   = 'самовывоз из пункта заказа';
    private $langStoreDescription    = 'покупка без предварительного заказа';
    private $langDontUnload          = 'Не выгружать';
    private $langReady               = 'Готов к отправке';
    private $langToOrder             = 'На заказ';
    private $langViewDontUpload      = 'не будут выгружены';
    private $langViewWillUpload      = 'будут выгружены со статусом';
    private $langViewReady           = 'готов к отправке';
    private $langViewToOrder         = 'на заказ';
    private $langViewWithAvailable   = 'и доступны';
    private $langViewDelivery        = 'доставкой';
    private $langViewPickup          = 'самовывозом';
    private $langViewStore           = 'покупкой на месте';
    private $langOk                  = 'OK';
    private $langCancel              = 'Отмена';

    /**
     * @param array $settings
     */
    public function __construct($settings)
    {
        $this->settings = (array)$settings;
    }

    /**
     * @return string
     */
    public function htmlAvailableList()
    {
        $html = $this->htmlAvailable('non-zero-quantity', $this->langNonZeroCountGoods)
            .$this->htmlAvailable('zero-quantity', $this->langIfZeroCountGoods);

        return $html;
    }

    /**
     * @param $index
     * @param $statusName
     * @return string
     */
    private function htmlAvailable($index, $statusName)
    {
        $enabled   = $this->getConfig('enabled', $index);
        $available = $this->getConfig('available', $index, 'none');
        $delivery  = $this->getConfig('delivery', $index);
        $pickup    = $this->getConfig('pickup', $index);
        $store     = $this->getConfig('store', $index);

        $enabledCheckbox = $this->htmlCheckbox($index, 'enabled', $enabled, '');

        $htmlView = $this->htmlView($statusName);
        $htmlEdit = $this->htmlEdit($index, $statusName, $available, $delivery, $pickup, $store);

        $html = <<<HTML
            <div class="yandex-money-market-available yandex-money-market-js-editable">
                {$enabledCheckbox}
                {$htmlView}
                {$htmlEdit}
                <i class="yandex-money-market-edit-on-button icon16 edit"></i>
            </div>
HTML;

        return $html;
    }

    /**
     * @param string $statusName
     * @return string
     */
    private function htmlView($statusName)
    {
        $html = <<< HTML
            <span class="yandex-money-market-js-editable-view">
                <span class="yandex-money-market-available-status">{$statusName}</span>
                <span class="available_dont_upload">{$this->langViewDontUpload}</span>
                <span class="available_will_upload">
                    {$this->langViewWillUpload}
                    <span class="yandex-money-market-available-with-ready">{$this->langViewReady}</span>
                    <span class="yandex-money-market-available-with-to-order">{$this->langViewToOrder}</span>
                    <span class="yandex-money-market-available-view-available-list">
                        {$this->langViewWithAvailable}
                        <span class="yandex-money-market-available-options-list yandex-money-market-available-delivery">{$this->langViewDelivery}</span>
                        <span class="yandex-money-market-available-options-list yandex-money-market-available-pickup">{$this->langViewPickup}</span>
                        <span class="yandex-money-market-available-options-list yandex-money-market-available-store last">{$this->langViewStore}</span>
                    </span>
                </span>
            </span>
HTML;

        return $html;
    }

    /**
     * @param string $index
     * @param string $statusName
     * @param $available
     * @param $delivery
     * @param $pickup
     * @param $store
     * @return string
     */
    private function htmlEdit($index, $statusName, $available, $delivery, $pickup, $store)
    {
        $availableSelect  = $this->htmlSelect($index, $available);
        $deliveryCheckbox = $this->htmlCheckbox($index, 'delivery', $delivery, $this->langDeliveryDescription);
        $pickupCheckbox   = $this->htmlCheckbox($index, 'pickup', $pickup, $this->langPickupDescription);
        $storeCheckbox    = $this->htmlCheckbox($index, 'store', $store, $this->langStoreDescription);

        $html = <<<HTML
        <div class="yandex-money-market-available-edit yandex-money-market-js-editable-edit">
            {$statusName}
            <div class="form-group">
                {$availableSelect}
            </div>        
            <div class="form-group">
                {$deliveryCheckbox}
            </div>
            <div class="form-group">
                {$pickupCheckbox}
            </div>
            <div class="form-group">
                {$storeCheckbox}
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
     * @param string $index
     * @param string $available
     * @return string
     */
    private function htmlSelect($index, $available)
    {
        $options = array(
            'none'  => $this->langDontUnload,
            'true'  => $this->langReady,
            'false' => $this->langToOrder,
        );

        $html = '<select name="yandex_money_market_available_available['.$index.']" data-value="'.$available.'">';

        foreach ($options as $value => $text) {
            $html .= $this->htmlOption($value, $text, $available);
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * @param string $value
     * @param string $text
     * @param string $available
     * @return string
     */
    private function htmlOption($value, $text, $available)
    {
        $selected = $value === $available ? 'selected="selected"' : '';

        return <<<HTML
            <option value="{$value}" {$selected}>{$text}</option>
HTML;
    }

    /**
     * @param $index
     * @param $field
     * @param string $value
     * @param string $text
     * @return string
     */
    private function htmlCheckbox($index, $field, $value, $text)
    {
        $checked = $value ? 'checked="checked"' : '';

        return <<<HTML
            <label>
                <input type="checkbox" value="1" data-value="{$value}" {$checked}
                    class="yandex-money-market-available-{$field}" 
                    name="yandex_money_market_available_{$field}[{$index}]" /> 
                {$text}
            </label>              
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
        return ym_get_settings($this->settings, 'yandex_money_market_available_'.$key, $index, $default);
    }
}