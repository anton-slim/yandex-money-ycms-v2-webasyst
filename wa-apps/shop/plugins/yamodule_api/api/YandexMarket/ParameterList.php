<?php

namespace YandexMoneyModule\YandexMarket;

class ParameterList
{
    /**
     * Название параметра
     * @var string
     */
    private $name;

    /**
     * Единицы измерения
     * @var null|string
     */
    private $unit;

    /**
     * Значение параметра
     * @var string
     */
    private $value;

    /**
     * @param string $name Название параметра
     * @param string $value Значение параметра
     * @param string|null $unit  Единицы измерения
     */
    public function __construct($name, $value, $unit = null)
    {
        $this->name  = $name;
        $this->unit  = $unit;
        $this->value = $value;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getUnit()
    {
        return $this->unit;
    }

    /**
     * @return bool
     */
    public function hasUnit()
    {
        return !empty($this->unit);
    }

    /**
     * @param string|integer $value
     */
    public function setValue($value)
    {
        $this->value = $value;
    }

    /**
     * @return string|integer
     */
    public function getValue()
    {
        return $this->value;
    }

}
