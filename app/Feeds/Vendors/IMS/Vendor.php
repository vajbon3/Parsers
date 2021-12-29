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
        'https://www.allegromedical.com/products/heated-booties-1-pair/', # removed almost all desc
        'https://www.allegromedical.com/products/zoll-aed-plus-defibrillator/',
        # leave list in desc
        # its also duplicated
        # important features empty <li> elements
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