<?php

require_once dirname(__FILE__).'/YandexMarketSettingsCurrency.php';
require_once dirname(__FILE__).'/YandexMarketSettingsCategoryTree.php';
require_once dirname(__FILE__).'/YandexMarketSettingsDelivery.php';
require_once dirname(__FILE__).'/YandexMarketSettingsAvailable.php';
require_once dirname(__FILE__).'/YandexMarketSettingsAdditionalCondition.php';

class YandexMarketSettings
{
    private $currency;
    private $categoryTree;
    private $delivery;
    private $available;
    private $additionalCondition;

    /**
     * @param array $settings
     * @return YandexMarketSettingsCurrency
     */
    public function getCurrency($settings)
    {
        if (!$this->currency) {
            $this->currency = new YandexMarketSettingsCurrency($settings);
        }

        return $this->currency;
    }

    /**
     * @param $waCategories
     * @return YandexMarketSettingsCategoryTree
     */
    public function getCategoryTree($waCategories)
    {
        if (!$this->categoryTree) {
            $this->categoryTree = new YandexMarketSettingsCategoryTree($waCategories);
        }

        return $this->categoryTree;
    }

    /**
     * @param array $settings
     * @param string $defaultCurrency
     * @return YandexMarketSettingsDelivery
     */
    public function getDelivery($settings, $defaultCurrency)
    {
        if (!$this->delivery) {
            $this->delivery = new YandexMarketSettingsDelivery($settings, $defaultCurrency);
        }

        return $this->delivery;
    }

    /**
     * @param array $settings
     * @return YandexMarketSettingsAvailable
     */
    public function getAvailable($settings)
    {
        if (!$this->available) {
            $this->available = new YandexMarketSettingsAvailable($settings);
        }

        return $this->available;
    }

    /**
     * @param array $settings
     * @param $waCategories
     * @return YandexMarketSettingsAdditionalCondition
     */
    public function getAdditionalCondition($settings, $waCategories)
    {
        if (!$this->additionalCondition) {
            $this->additionalCondition = new YandexMarketSettingsAdditionalCondition($settings, $waCategories);
        }

        return $this->additionalCondition;
    }
}