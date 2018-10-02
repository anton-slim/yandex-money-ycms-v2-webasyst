<?php

require_once dirname(__FILE__).'/YandexMarketSettingsCategoryTree.php';


class YandexMarketSettingsAdditionalCondition
{
    const MAX_CATEGORY_NUMBER = 3;

    private $settings;

    private $waCategories;

    private $categoryTree;

    /**
     * @var array() [parent_id][category_id] => category_name
     */
    private $categories = array();

    private $langName               = 'Название условия';
    private $langTag                = 'Тег';
    private $langStaticValue        = 'Постоянное значение';
    private $langDataValue          = 'Значение из карточки товара';
    private $langForCategories      = 'Для категорий';
    private $langMore               = 'Добавить условие';
    private $langMakeTag            = 'задает параметру';
    private $langWithValue          = 'значение';
    private $langForCategory        = 'для';
    private $langForAllCategories   = 'всех категорий';
    private $langForMoreCategories  = 'и еще %s кат.';
    private $langJoin               = 'Одинаковые теги в предложении';
    private $langJoinView           = 'объединять в один тег';
    private $langDontJoinView       = 'оставить в нескольких тегах';
    private $langAllCategories      = 'для всех';
    private $langSelectedCategories = 'для выбранных';
    private $langOk                 = 'OK';
    private $langCancel             = 'Отменить';
    private $langDelete             = 'Удалить';

    private $productFields = array(
        'id',
        'name',
        'summary',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'description',
        'contact_id',
        'create_datetime',
        'edit_datetime',
        'status',
        'type_id',
        'image_id',
        'image_filename',
        'video_url',
        'sku_id',
        'ext',
        'url',
        'rating',
        'price',
        'compare_price',
        'currency',
        'min_price',
        'max_price',
        'tax_id',
        'count',
        'cross_selling',
        'upselling',
        'rating_count',
        'total_sales',
        'category_id',
        'badge',
        'sku_type',
        'base_price_selectable',
        'compare_price_selectable',
        'purchase_price_selectable',
        'sku_count',
        'unconverted_currency',
        'unconverted_price',
        'frontend_price',
        'unconverted_min_price',
        'frontend_min_price',
        'unconverted_max_price',
        'frontend_max_price',
        'unconverted_compare_price',
        'frontend_compare_price',
        'total_sales_html',
        'rating_html',
        'frontend_url',
        'original_price',
        'original_compare_price',
    );

    /**
     * @param array $settings
     * @param $waCategories
     */
    public function __construct($settings, $waCategories)
    {
        $this->settings     = (array)$settings;
        $this->waCategories = $waCategories;
        $this->initCategories($waCategories);
    }

    /**
     * @param $waCategories
     */
    private function initCategories($waCategories)
    {
        $categories = array();
        foreach ($waCategories as $category) {
            $categories[$category['parent_id']][$category['id']] = $category['name'];
        }
        $this->categories = $categories;
    }

    /**
     * @return array
     */
    public function getCategories()
    {
        return $this->categories;
    }

    /**
     * @return string
     */
    public function htmlAdditionalConditionList()
    {
        $typeValues = $this->getConfig('type_value');
        $maxIndex   = empty($typeValues) ? 0 : max(array_keys($typeValues));

        $html = '<div class="yandex-money-market-additional-condition-list">';
        for ($index = 1; $index <= $maxIndex; $index++) {
            $html .= $this->htmlAdditionalConditionItem($index);
        }
        $html .= $this->htmlAdditionalConditionItem('');

        $html .= <<<HTML
            </div>
            <div>
                <a onclick="return false;" data-index="{$index}" class="yandex-money-market-additional-condition-more">
                   {$this->langMore}
               </a>
            </div>
HTML;

        return $html;
    }

    /**
     * @param $index
     * @return string
     */
    private function htmlAdditionalConditionItem($index)
    {
        if ($index === '') {
            $enabled     = '';
            $name        = '';
            $tag         = '';
            $typeValue   = 'static';
            $staticValue = '';
            $dataValue   = '';
            $addTemplate = 'yandex-money-market-additional-condition-template';
            $fieldName   = 'data-name';
            $forAllCat   = '1';
            $join        = '';
            $checkedList = array();
        } else {
            $enabled     = $this->getConfig('enabled', $index);
            $name        = $this->getConfig('name', $index);
            $tag         = $this->getConfig('tag', $index);
            $typeValue   = $this->getConfig('type_value', $index);
            $staticValue = $this->getConfig('static_value', $index);
            $dataValue   = $this->getConfig('data_value', $index);
            $forAllCat   = $this->getConfig('for_all_cat', $index);
            $join        = $this->getConfig('join', $index);
            $checkedList = (array)$this->getConfig('categories', $index);
            $addTemplate = '';
            $fieldName   = 'name';
        }

        if (empty($typeValue)) {
            return '';
        }

        $htmlView = $this->htmlView($name, $tag, $typeValue, $staticValue, $dataValue, $forAllCat, $checkedList);
        $htmlEdit = $this->htmlEdit($index, $name, $tag, $typeValue, $staticValue, $dataValue, $forAllCat, $checkedList,
            $join, $fieldName);

        $checked = $enabled ? 'checked="checked"' : '';

        $html = <<<HTML
            <div class="yandex-money-market-js-editable yandex-money-market-additional-condition {$addTemplate}">
                <input type="checkbox" {$fieldName}="yandex_money_market_additional_condition_enabled[{$index}]" value="1" {$checked} />
                {$htmlView}
                {$htmlEdit}
                <i class="yandex-money-market-edit-on-button icon16 edit"></i>
            </div>
HTML;
        return $html;
    }

    /**
     * @param string $name
     * @param string $tag
     * @param string $typeValue
     * @param string $staticValue
     * @param string $dataValue
     * @param string $forAllCat
     * @param array $checkedList
     * @return string
     */
    private function htmlView(
        $name,
        $tag,
        $typeValue,
        $staticValue,
        $dataValue,
        $forAllCat,
        array $checkedList
    ) {
        $value = $typeValue === 'static'
            ? $staticValue
            : $dataValue;

        if ($forAllCat) {
            $categoryList = $this->langForAllCategories;
        } else {
            $categories = array();
            foreach ($this->getCategories() as $categoryGroup) {
                foreach ($categoryGroup as $categoryId => $categoryName) {
                    if (in_array($categoryId, $checkedList)) {
                        $categories[] = $categoryName;
                    }
                }
            }
            $count = count($categories);
            if ($count <= self::MAX_CATEGORY_NUMBER) {
                $categoryList = implode(', ', $categories);
            } else {
                $categoryList = implode(', ', array_slice($categories, 0, self::MAX_CATEGORY_NUMBER));
                $categoryList .= ' '.sprintf($this->langForMoreCategories, $count - self::MAX_CATEGORY_NUMBER);
            }
        }

        $html = <<< HTML
            <span class="yandex-money-market-js-editable-view">
                <span class="yandex-money-market-additional-condition-name">{$name}</span>
                {$this->langMakeTag}
                &lt;<span class="yandex-money-market-additional-condition-tag">{$tag}</span>&gt;
                {$this->langWithValue}
                <em><span class="yandex-money-market-additional-condition-value">{$value}</span></em>
                {$this->langForCategory}
                <span class="yandex-money-market-additional-condition-category-list">{$categoryList}</span>
            </span>
HTML;
        return $html;
    }

    /**
     * @param $index
     * @param $name
     * @param $tag
     * @param $typeValue
     * @param $staticValue
     * @param $dataValue
     * @param $forAllCat
     * @param array $checkedList
     * @param string $join
     * @param $fieldName
     * @return string
     */
    private function htmlEdit(
        $index,
        $name,
        $tag,
        $typeValue,
        $staticValue,
        $dataValue,
        $forAllCat,
        $checkedList,
        $join,
        $fieldName
    ) {
        $dataValueSelect = $this->htmlProductDataSelect($index, $dataValue, $fieldName);

        if ($typeValue === 'static') {
            $staticValueChecked = ' checked="checked"';
            $dataValueChecked   = '';
        } else {
            $staticValueChecked = '';
            $dataValueChecked   = ' checked="checked"';
        }

        if ($forAllCat) {
            $allCategoriesChecked      = ' checked="checked"';
            $selectedCategoriesChecked = '';
            $classCategoryTree         = ' yandex-money-market-hidden-element';
        } else {
            $allCategoriesChecked      = '';
            $selectedCategoriesChecked = ' checked="checked"';
            $classCategoryTree         = '';
        }

        if ($join) {
            $joinChecked     = ' checked="checked"';
            $dontJoinChecked = '';
        } else {
            $joinChecked     = '';
            $dontJoinChecked = ' checked="checked"';
        }

        $html = <<<HTML
        <div  class="yandex-money-market-js-editable-edit yandex-money-market-category-tree-container">
            <div class="form-group">
                {$this->langName}
                <div>
                    <input {$fieldName}="yandex_money_market_additional_condition_name[{$index}]" value="{$name}"  data-value="{$name}" />
                </div>
            </div>        
            <div class="form-group">
                {$this->langTag}
                <div>
                    <input {$fieldName}="yandex_money_market_additional_condition_tag[{$index}]" value="{$tag}" data-value="{$tag}"/>
                </div>
            </div>        

            <div class="form-group">
                <label class="yandex-money-market-first-letter-uppercase">
                    <input type="radio" {$fieldName}="yandex_money_market_additional_condition_type_value[{$index}]" value="static" data-value="$typeValue" {$staticValueChecked}/>
                    {$this->langStaticValue}
                </label>
                <div>
                    <input {$fieldName}="yandex_money_market_additional_condition_static_value[{$index}]" value="{$staticValue}" data-value="{$staticValue}"/>
                </div>
            </div>        
            <div class="form-group">
                <label class="yandex-money-market-first-letter-uppercase">
                    <input type="radio" {$fieldName}="yandex_money_market_additional_condition_type_value[{$index}]" value="data" data-value="$typeValue" {$dataValueChecked}/>
                    {$this->langDataValue}
                </label>
                <div>
                    {$dataValueSelect}
                </div>
            </div>
            
            <div class="form-group">
                {$this->langJoin}
                <div>
                    <label>
                        <input type="radio" {$joinChecked} {$fieldName}="yandex_money_market_additional_condition_join[{$index}]" value="1" data-value="{$join}"/>
                            {$this->langJoinView}
                    </label>
                    <br>
                    <label>
                        <input type="radio" {$dontJoinChecked} {$fieldName}="yandex_money_market_additional_condition_join[{$index}]" value="" data-value="{$join}"/>
                        {$this->langDontJoinView}
                    </label>
                </div>
            </div>        
            
            <div class="form-group">
                {$this->langForCategories}
                <div>
                    <label>
                        <input type="radio" {$allCategoriesChecked} 
                            {$fieldName}="yandex_money_market_additional_condition_for_all_cat[{$index}]" 
                            class="yandex-money-market-category-tree-switcher" value="1"/>
                            {$this->langAllCategories}
                    </label>
                    <br/>
                    <label>
                        <input type="radio" {$selectedCategoriesChecked} 
                            {$fieldName}="yandex_money_market_additional_condition_for_all_cat[{$index}]" 
                            class="yandex-money-market-category-tree-switcher" value=""/> 
                        {$this->langSelectedCategories}
                    </label>
                </div>
            </div>        

            <div class="form-group yandex-money-market-category-tree {$classCategoryTree}">
                {$this->getCategoryTree()->getCategoryTree($checkedList,
            $fieldName.'="yandex_money_market_additional_condition_categories['.$index.']"')}
            </div>
            
            <div class="form-group">
                <input type="button" class="button green edit_finish" value="{$this->langOk}"/>
                <input type="button" class="button edit_finish_reset" value="{$this->langCancel}"/>
                <input type="button" class="button red edit_finish_delete" value="{$this->langDelete}"/>
            </div>  
        </div>
HTML;
        return $html;
    }

    /**
     * @param $index
     * @param int $selectedField
     * @param $fieldName
     * @return string
     */
    private function htmlProductDataSelect($index, $selectedField, $fieldName)
    {
        $html = '<select '.$fieldName.'="yandex_money_market_additional_condition_data_value['.$index.']" data-value='.$selectedField.'>';
        foreach ($this->productFields as $productField) {
            $selected = $productField === $selectedField ? 'selected="selected"' : '';
            $text     = $productField;
            $html     .= <<<HTML
            <option value="{$productField}" {$selected}>{$text}</option>
HTML;
        }
        $html .= '</select>';

        return $html;
    }

    /**
     * @return YandexMarketSettingsCategoryTree
     */
    private function getCategoryTree()
    {
        if (!$this->categoryTree) {
            $this->categoryTree = new YandexMarketSettingsCategoryTree($this->waCategories);
        }

        return $this->categoryTree;
    }

    /**
     * @param $key
     * @param $index
     * @param null $default
     * @return mixed
     */
    private function getConfig($key, $index = null, $default = null)
    {
        return ym_get_settings($this->settings, 'yandex_money_market_additional_condition_'.$key, $index, $default);
    }
}