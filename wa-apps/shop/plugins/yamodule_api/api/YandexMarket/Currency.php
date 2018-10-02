<?php

namespace YandexMoneyModule\YandexMarket;

class Currency
{
    /**
     * @var string Идентификатор валюты
     */
    private $id;

    /**
     * @var int|float|string Курс валюты
     */
    private $rate;

    /**
     * @var int|float|null Надбавка
     */
    private $plus;

    /**
     * @param int $id Идентификатор валюты
     * @param int|float|string $rate Курс валюты
     * @param int|float|null $plus Надбавка
     */
    public function __construct($id, $rate, $plus = null)
    {
        $this->id   = $id;
        $this->rate = $rate;
        $this->plus = $plus;
    }

    /**
     * Возвращает список доступных валют
     * @return array
     */
    public static function getAvailableCurrencies()
    {
        return array('RUR', 'RUB', 'USD', 'EUR', 'UAH', 'BYN', 'KZT');
    }

    /**
     * Возвращает список возможных предустановленных значений rate
     * @return array
     */
    public static function getAvailableRateCodes()
    {
        return array('CBRF', 'NBU', 'NBK', 'СВ');
    }

    /**
     * Возвращает идентификатор валюты
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Возвращает курс валюты
     * @return string
     */
    public function getRate()
    {
        return $this->rate;
    }

    /**
     * Возвращает надбавку
     * @return float|null
     */
    public function getPlus()
    {
        return $this->plus;
    }

}