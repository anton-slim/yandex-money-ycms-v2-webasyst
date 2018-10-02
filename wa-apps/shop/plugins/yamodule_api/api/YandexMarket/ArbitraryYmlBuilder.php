<?php

namespace YandexMoneyModule\YandexMarket;

class ArbitraryYmlBuilder extends YmlBuilder
{
    /**
     * Флаг Произвольного типа
     * @var string
     */
    protected $offerType = ' type="vendor.model"';

    /**
     * @param Offer $offer Товарное предложение
     * @return string
     */
    protected function generateOffer(Offer $offer)
    {
        if (!$offer->hasModel() || !$offer->hasVendor()) {
            return '';
        }

        return parent::generateOffer($offer);
    }
}
