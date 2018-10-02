<?php

class YandexMarketSettingsCategoryTree
{
    /**
     * @var array() [parent_id][category_id] => category_name
     */
    private $categories = array();

    private $langCollapseAll = 'Свернуть всё';
    private $langExpandAll   = 'Развернуть всё';
    private $langCheckAll    = 'Отметить всё';
    private $langUncheckAll  = 'Убрать все отметки';

    /**
     * @param $waCategories
     */
    public function __construct($waCategories)
    {
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
    private function getCategories()
    {
        return $this->categories;
    }

    /**
     * @param array $checkedList
     * @param string $inputName
     * @return string
     */
    public function getCategoryTree(array $checkedList, $inputName = 'name="yandex_money_market_category_list[]"')
    {
        return <<<HTML
            <div class="yandex-money-market-category-tree-block">
                {$this->htmlControlsPanel()}
                {$this->htmlTreeCat($checkedList, $inputName)}
            </div>
HTML;
    }

    /**
     * @param array $checkedList
     * @param string $inputName
     * @return string
     */
    public function htmlTreeCat(array $checkedList, $inputName = 'name="yandex_money_market_category_list[]"')
    {
        $html = $this->htmlTreeFolder($this->getCategories(), 0, $checkedList, $inputName);

        return $html;
    }

    /**
     * @param array $categories
     * @param string $id
     * @param array $checkedList
     * @param string $inputAttr
     * @return string
     */
    private function htmlTreeFolder($categories, $id, $checkedList, $inputAttr)
    {
        if (!isset($categories[$id])) {
            return '';
        }

        $className = empty($id)
            ? 'yandex-money-market-category-tree-trunk'
            : 'yandex-money-market-category-tree-branch';

        $html = '<ul class="'.$className.'">';
        foreach ($categories[$id] as $categoryId => $categoryName) {
            $checked = in_array($categoryId, $checkedList) ? ' checked' : '';
            $html    .= '<li>
                <label>
                    <input type="checkbox" '.$inputAttr.' value="'.$categoryId.'" '.$checked.'>
                    '.$categoryName.'
                </label>';
            if (isset($categories[$categoryId]) && !empty($categories[$categoryId])) {
                $html .= $this->htmlTreeFolder($categories, $categoryId, $checkedList, $inputAttr);
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    /**
     * @return string
     */
    public function htmlControlsPanel()
    {
        $html = <<<HTML
            <div class="yandex-money-market-category-tree-panel-heading-controls clearfix">
                <div class="yandex-money-market-category-tree-actions pull-right">
                    <a onclick="return false;" class="btn btn-default market-collapse-all-category-box">
                        {$this->langCollapseAll}
                    </a>
                    <a onclick="return false;" class="btn btn-default market-expand-all-category-box">
                        {$this->langExpandAll}
                    </a>
                    <a onclick="return false;" class="btn btn-default market-check-all-category-box">
                        {$this->langCheckAll}
                    </a>
                    <a onclick="return false;" class="btn btn-default market-uncheck-all-category-box">
                        {$this->langUncheckAll}
                    </a>
                </div>
            </div>
HTML;

        return $html;
    }
}