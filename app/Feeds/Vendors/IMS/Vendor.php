<?php

namespace App\Feeds\Vendors\IMS;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{

    protected const CHUNK_SIZE = 10;

    protected array $first = ['https://www.allegromedical.com/pub/sitemap/allegromedical/products.xml'];

    public array $custom_products = [
        'https://www.allegromedical.com/products/human-body-model-10-acupunture-point-model/', # задублировалось описание и шорты
        'https://www.allegromedical.com/products/ten-motor-massage-cushion-w/-heat-and-memory-foam/', # убрать информацию про гарантию
        'https://www.allegromedical.com/products/drive-medical-tall-crutches-adult-size-pair/', # не польностю удалилась строка - HCPC CODE: E0114
        'https://www.allegromedical.com/products/complete-massage-cupping-kit/', # удалить строку - for an electric version please see
    ];

    public function filterProductLinks(Link $link): bool
    {
        return str_contains($link->getUrl(), '/products/');
    }

    public function isValidFeedItem(FeedItem $fi): bool
    {
        return ($fi->getProduct() !== "" && $fi->getProduct() !== 'Dummy' && $fi->getProduct() !== "Quote")
            && (($fi->mpn !== null && $fi->mpn !== '') || $fi->isGroup())
            && $fi->getCostToUs() > 0.0;
    }
}