<?php

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

    public function marketAction()
    {
        require_once dirname(__FILE__).'/../../../api/market.php';
        $plugin = wa()->getPlugin('yamodule_api');
        $sm = new waAppSettingsModel();
        $settings = $sm->get('shop.yamodule_api');
        $settings['ya_market_categories'] = unserialize($settings['ya_market_categories']);
        $market = new YaMarket();
        $market->from_charset = 'utf-8';
        $market->homeprice = $settings['ya_market_price'];
        $market->simple = $settings['ya_market_simpleyml'];

        //---------------Main settings------------------------//
        $this->getResponse()->addHeader('Content-type', 'application/xml; charset=windows-1251');
        $this->getResponse()->sendHeaders();
        $config = wa('shop')->getConfig();
        $size = $config->getImageSize('big');
        $def_currency = $config->getCurrency();
        $url = preg_replace('@^https@', 'http', wa()->getRouteUrl('shop/frontend', array(), true));
        $version = wa()->getVersion('shop');
        $phone = $config->getGeneralSettings('phone');
        $root = trim(wa()->getRootUrl(true), '/');
        $market->set_shop($settings['ya_market_name'], $config->getGeneralSettings('name'), $url, $version, $phone);

        //---------------Currencies------------------------//
        $price_currency = isset($settings['ya_market_currency']) ? $settings['ya_market_currency'] : 'RUB';
        $allowed = array('RUR', 'RUB', 'UAH', 'USD', 'BYR', 'KZT', 'EUR');
        $currency_model = new shopCurrencyModel();
        if ($settings['ya_market_currencies'])
        {
            $currencies = $currency_model->getCurrencies();
            foreach ($currencies as $currency)
                if (in_array($currency['code'], $allowed))
                    $market->add_currency($currency['code'], $currency['rate']);
        }
        else
        {
            $currency = $currency_model->getById($def_currency);
            if (in_array($currency['code'], $allowed))
                $market->add_currency($currency['code'], $currency['rate']);
        }

        //-----------------Categories----------------------//
        $cat_model = new shopCategoryModel();
        $categories = $cat_model->getFullTree();
        foreach ($categories as $category)
        {
            if ($category['type'] == shopCategoryModel::TYPE_STATIC)
                $market->add_category($category['name'], $category['id'], $category['parent_id']);
        }

        //------------------Products----------------------//
        $product_model = new shopProductModel();
        $product_images_model = new shopProductImagesModel();
        $collection = new shopProductsCollection('', array( 'frontend' => true));
        $products = $collection->getProducts();
        if ($settings['ya_market_comb'])
        {
            $sku_model = new shopProductSkusModel();
            $skus = $sku_model->getDataByProductId(array_keys($products));
            foreach ($skus as $sku_id => $sku) {
                if (isset($products[$sku['product_id']]))
                {
                    if (!isset($products[$sku['product_id']]['skus']))
                        $products[$sku['product_id']]['skus'] = array();
                    $products[$sku['product_id']]['skus'][$sku_id] = $sku;
                }
            }
        }

        foreach ($products as $product)
        {
            if (!$settings['ya_market_selected'])
            {
                if (!in_array($product['category_id'], $settings['ya_market_categories']))
                    continue;
            }

            if ($product['price'] >= 0.5 &&	$product['category_id'])
            {
                if ($settings['ya_market_available'] && (!$product['status']))
                    continue;

                $available = false;
                if ($settings['ya_market_set_available'] == 1)
                    $available = true;
                elseif ($settings['ya_market_set_available'] == 2)
                {
                    if ($product['count'] > 0 && !is_null($product['count']))
                        $available = true;
                }
                elseif ($settings['ya_market_set_available'] == 3)
                {
                    $available = true;
                    if ($product['count'] == 0 && !is_null($product['count']))
                        return;
                }
                elseif ($settings['ya_market_set_available'] == 4)
                    $available = false;

                $data = array();
                $data['id'] = $product['id'];
                $data['url'] = $root.$product['frontend_url'];
                $data['price'] = number_format(shop_currency($product['price'], $product['currency'], $price_currency, false), 2, '.', '');
                $data['description'] = $product['description'] ? $product['description'] : $product['summary'];
                $data['categoryId'] = $product['category_id'];
                $data['delivery'] = $settings['ya_market_delivery'];
                $data['pickup'] = $settings['ya_market_pickup'];
                $data['store'] = $settings['ya_market_store'];
                $data['currencyId'] = $def_currency;
                $data['picture'] = array();
                $data['param'] = array();
                $images = $product_images_model->getImages($product['id'], $size, 'id', false);
                foreach($images as $image)
                    $data['picture'][] = $root.shopImage::getUrl($image, $size);

                if ($settings['ya_market_simpleyml'])
                {
                    $data['name'] = $product['name'];
                    $market->add_offer($product['id'], $data, $available);
                }
                else
                {
                    $data['model'] = $product['name'];
                    // $data['vendor'] = $product['name'];
                    if ($settings['ya_market_comb'] && count($product['skus']) > 1)
                    {
                        foreach ($product['skus'] as $sku)
                        {
                            $available_sku = false;
                            if ($settings['ya_market_set_available'] == 1)
                                $available_sku = true;
                            elseif ($settings['ya_market_set_available'] == 2)
                            {
                                if ($sku['count'] > 0 || is_null($sku['count']))
                                    $available_sku = true;
                            }
                            elseif ($settings['ya_market_set_available'] == 3)
                            {
                                $available_sku = true;
                                if ($sku['count'] == 0 && !is_null($sku['count']))
                                    continue;
                            }
                            elseif ($settings['ya_market_set_available'] == 4)
                                $available_sku = false;

                            $sku_data = array();
                            $sku_data = $data;
                            $sku_data['id'] .= 'c'.$sku['id'];
                            $sku_data['model'] .= ' '.$sku['name'];
                            $sku_data['price'] = number_format(shop_currency($sku['price'], $product['currency'], $price_currency, false), 2, '.', '');
                            $sku_data['url'] .= '#'.$sku['id'];
                            $param = $this->get_param($product['id'], $sku['id'], $settings['ya_market_vendor'], $settings['ya_market_fea']);
                            $sku_data['param'] = $param['param'];
                            $sku_data['vendor'] = $param['vendor'];
                            $sku_data['group_id'] = $product['id'];

                            $market->add_offer($sku_data['id'], $sku_data, $available_sku);
                        }
                    }
                    else
                    {
                        if ($settings['ya_market_fea'])
                        {
                            $sku_one = array_shift($product['skus']);
                            $param = $this->get_param($product['id'], $sku_one['id'], $settings['ya_market_vendor'], $settings['ya_market_fea']);
                            $data['param'] = $param['param'];
                            $data['vendor'] = $param['vendor'];
                        }

                        $market->add_offer($product['id'], $data, $available);
                    }
                }
            }
        }

        exit($market->get_xml());
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