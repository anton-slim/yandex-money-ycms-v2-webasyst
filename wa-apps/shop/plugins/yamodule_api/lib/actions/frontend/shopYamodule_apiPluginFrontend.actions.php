<?php

use YandexMoneyModule\YandexMarket\Currency;

class shopYamodule_apiPluginFrontendActions extends waActions
{
    public function cartAddInfoAction()
    {
        $data = waRequest::post();
        $sku_model = new shopProductSkusModel();
        $product_model = new shopProductModel();
        if (!isset($data['product_id']) && isset($data['sku_id'])) {
            $sku = $sku_model->getById($data['sku_id']);
            $product = $product_model->getById($sku['product_id']);
        } else {
            $product = $product_model->getById($data['product_id']);
            if (isset($data['sku_id'])) {
                $sku = $sku_model->getById($data['sku_id']);
            } else {
                if (isset($data['features'])) {
                    $product_features_model = new shopProductFeaturesModel();
                    $sku_id = $product_features_model->getSkuByFeatures($product['id'], $data['features']);
                    if ($sku_id) {
                        $sku = $sku_model->getById($sku_id);
                    } else {
                        $sku = array();
                    }
                } else {
                    $sku = $sku_model->getById($product['sku_id']);
                    if (!$sku['available']) {
                        $sku = $sku_model->getByField(array('product_id' => $product['id'], 'available' => 1));
                    }
                }
            }
        }

        $quantity = waRequest::post('quantity', 1);
        $name     = $product['name'].(!empty($sku['name']) ? ' ('.$sku['name'].')' : '');
        $array    = array(
            'id'       => $product['id'],
            'name'     => $name,
            'price'    => (float)(!empty($sku['price']) ? $sku['price'] : $product['price']),
            'quantity' => (int)$quantity
        );

        exit(json_encode($array));
    }

    /**
     * @throws Exception
     */
    public function marketAction()
    {
        require_once dirname(__FILE__).'/../../../api/autoload.php';

        $market   = new \YandexMoneyModule\YandexMarket\YandexMarket();
        $config   = wa('shop')->getConfig();
        $sm       = new waAppSettingsModel();
        $settings = $sm->get('shop.yamodule_api');
        $size     = $config->getImageSize('big');

        $market->setShop(
            ym_get_settings($settings, 'yandex_money_market_shopname'),
            ym_get_settings($settings, 'yandex_money_market_full_shopname'),
            wa()->getRouteUrl('shop/frontend', array(), true)
        );
        $market->getShop()->setPlatform('ya_webasyst');
        $market->getShop()->setVersion(wa()->getVersion('shop'));

        for ($index = 1; $index <= 5; $index++) {
            $enabled = ym_get_settings($settings, 'yandex_money_market_delivery_enabled', $index);
            if (!$enabled) {
                continue;
            }
            $cost = ym_get_settings($settings, 'yandex_money_market_delivery_cost', $index);
            if ($cost === '') {
                continue;
            }
            $daysFrom = ym_get_settings($settings, 'yandex_money_market_delivery_days_from', $index);
            $daysTo   = ym_get_settings($settings, 'yandex_money_market_delivery_days_to', $index);
            $days     = empty($daysTo) || $daysFrom === $daysTo ? $daysFrom : $daysFrom.'-'.$daysTo;
            if ($days === '') {
                continue;
            }
            $orderBefore = ym_get_settings($settings, 'yandex_money_market_delivery_order_before', $index);
            $market->getShop()->addDeliveryOption($cost, $days, $orderBefore);
        }

        $shopCurrency   = new shopCurrencyModel();
        $cmsCurrencies = $shopCurrency->getCurrencies();
        $cmsCurrencyIds = array_keys($cmsCurrencies);
        $defaultCurrency = $config->getCurrency();
        foreach (Currency::getAvailableCurrencies() as $currencyId) {
            if (!in_array($currencyId, $cmsCurrencyIds)) {
                continue;
            }
            $enabled = ym_get_settings($settings, 'yandex_money_market_currency_enabled', $currencyId);
            if (!$enabled) {
                continue;
            }
            if ($currencyId === $defaultCurrency) {
                $rate = '1';
                $plus = null;
            } else {
                $rate = ym_get_settings($settings, 'yandex_money_market_currency_rate', $currencyId);
                $plus = (float)ym_get_settings($settings, 'yandex_money_market_currency_plus', $currencyId, 0.0);
            }
            $market->addCurrency($currencyId, $rate, $plus);
        }

        $shopCategory = new shopCategoryModel();
        $categories = $shopCategory->getFullTree();
        $allowCategories = (array)ym_get_settings($settings, 'yandex_money_market_category_list');
        $allCategoryIds  = array_map(function ($category) {
            return $category['id'];
        }, $categories);
        foreach ($categories as $category)
        {
            if (!ym_get_settings($settings, 'yandex_money_market_category_all')) {
                if (!in_array($category['id'], $allowCategories)) {
                    continue;
                }
            }
            $market->addCategory($category['name'], $category['id'], $category['parent_id']);
        }


        $additionalConditionIds     = array();
        $additionalConditionMap     = array();
        $additionalConditionEnabled = (array)ym_get_settings($settings, 'yandex_money_market_additional_condition_enabled');
        foreach ($additionalConditionEnabled as $id => $enabled) {
            if ($enabled) {
                $additionalConditionIds[] = $id;
            }
        }
        if (!empty($additionalConditionIds)) {
            foreach ($additionalConditionIds as $conditionId) {
                $additionalConditionCategoryIds
                    = ym_get_settings($settings, 'yandex_money_market_additional_condition_for_all_cat', $conditionId)
                    ? $allCategoryIds
                    : (array)ym_get_settings($settings, 'yandex_money_market_additional_condition_categories', $conditionId);
                foreach ($additionalConditionCategoryIds as $categoryId) {
                    $additionalConditionMap[$categoryId][] = $conditionId;
                }
            }
        }

        $productImages = new shopProductImagesModel();
        $collection = new shopProductsCollection('', array( 'frontend' => true));

        $products = $collection->getProducts();
        $nameTemplate = explode('%', ym_get_settings($settings, 'yandex_money_market_name_template'));
        $root = trim(wa()->getRootUrl(true), '/');
        foreach ($products as $product) {
            $shopProduct = new shopProduct($product['id']);
            $statusId  = $product['count'] > 0 ? 'non-zero-quantity' : 'zero-quantity';
            $useStatus = ym_get_settings($settings, 'yandex_money_market_available_enabled', $statusId);
            $available = ym_get_settings($settings, 'yandex_money_market_available_available', $statusId);
            if ($useStatus && $available === 'none') {
                continue;
            }

            $offer = $market->createOffer($product['id'], $product['category_id']);
            if (!$offer) {
                continue;
            }

            $offer
                ->setUrl($root.$product['frontend_url'])
                ->setModel($product['name'])
                ->setVendor(ym_array_get($shopProduct->getFeatures('all'), 'brand'))
                ->setDescription($product['description'] ? $product['description'] : $product['summary'])
                ->setCurrencyId($defaultCurrency);

            if ($useStatus) {
                $offer
                    ->setAvailable($available === 'true')
                    ->setDelivery(ym_get_settings($settings, 'yandex_money_market_available_delivery', $statusId))
                    ->setPickup(ym_get_settings($settings, 'yandex_money_market_available_pickup', $statusId))
                    ->setStore(ym_get_settings($settings, 'yandex_money_market_available_store', $statusId));
            }

            $offer->setPrice($product['price']);
            if ($product['compare_price'] && $product['price'] < $product['compare_price']) {
                $offer->setOldPrice($product['compare_price']);
            }

            $images = $productImages->getImages($product['id'], $size, 'id', false);
            foreach($images as $image)
                $offer->addPicture($root.shopImage::getUrl($image, $size));

            if (ym_get_settings($settings, 'yandex_money_market_vat_enabled')) {
                $vatRates = ym_get_settings($settings, 'yandex_money_market_vat_'.$product['tax_id']);
                if ($vatRates) {
                    $offer->setVat($vatRates);
                }
            }

            if (ym_get_settings($settings, 'yandex_money_market_simple')) {
                $name = '';
                foreach ($nameTemplate as $namePart) {
                    $name .= isset($product[$namePart]) ? $product[$namePart] : $namePart;
                }
                $offer->setName($name);
            }

            if (ym_get_settings($settings, 'yandex_money_market_offer_options_export_attributes')) {
                $attributes = $this->getParam($product['id']);
                foreach ($attributes as $attribute) {
                    $offer->addParameter($attribute['name'], $attribute['value']);
                }
            }

            $allCategories = $this->getCategories($product['id']);
            foreach ($allCategories as $category) {
                if (isset($additionalConditionMap[$category['category_id']])) {
                    foreach ($additionalConditionMap[$category['category_id']] as $conditionId) {
                        $tag       = ym_get_settings($settings, 'yandex_money_market_additional_condition_tag', $conditionId);
                        $typeValue = ym_get_settings($settings, 'yandex_money_market_additional_condition_type_value', $conditionId);
                        if ($typeValue === 'static') {
                            $value = ym_get_settings($settings, 'yandex_money_market_additional_condition_static_value', $conditionId);

                        } else {
                            $dataValue = ym_get_settings($settings, 'yandex_money_market_additional_condition_data_value', $conditionId);
                            $value     = isset($product[$dataValue]) ? $product[$dataValue] : '';
                        }
                        $join = ym_get_settings($settings, 'yandex_money_market_additional_condition_join', $conditionId);

                        if (!empty($tag) && $value !== '') {
                            $offer->addCustomTag($tag, $value, $join);
                        }
                    }
                }
            }

            $market->addOffer($offer);
        }

        header('Content-type:application/xml; charset=utf-8');
        echo $market->getXml(ym_get_settings($settings, 'yandex_money_market_simple'));

        exit();
    }

    public function getParam($id_product)
    {
        $shopProductFeatures = new shopProductFeaturesModel();
        $shopFeature         = new shopFeatureModel();

        $param    = array();
        $features = $shopProductFeatures->getValues($id_product);
        foreach ($features as $k => $sf) {
            $data = $shopFeature->getByCode($k);

            $param[] = array(
                'name'  => $data['name'],
                'value' => (is_object($sf)) ? $sf->value : $sf
            );
        }

        return $param;
    }

    /**
     * @param $productId
     * @return array
     */
    public function getCategories($productId)
    {
        if (!$productId) {
            return array();
        }

        $cat = new shopCategoryModel();
        $sql = 'SELECT category_id FROM `shop_category_products` WHERE product_id = '.$productId.';';

        return $cat->query($sql)->fetchAll();
    }

    public static function get_param($id_product, $sku_id, $code)
    {
        $pfeatures_model = new shopProductFeaturesModel();
        $feature_model = new shopFeatureModel();

        $param = array();
        $vendor = '-';
        $sku_features = $pfeatures_model->getValues($id_product, $sku_id);
        if (isset($sku_features[$code]))
        {
            $fd = $feature_model->getByCode($code);
            if (is_object($sku_features[$code]))
                $vendor = $sku_features[$code]->value;
            else
                $vendor = $sku_features[$code];
        }

        if (count($sku_features))
        {
            foreach ($sku_features as $k => $sf)
            {
                $f_data = $feature_model->getByCode($k);
                $pname = $f_data['name'];

                if (is_object($sf))
                    $pvalue = $sf->value;
                else
                    $pvalue = $sf;

                $param[] = array(
                    'name' => $pname,
                    'value' => $pvalue
                );
            }
        }

        return array('param' => $param, 'vendor' => $vendor);
    }

}