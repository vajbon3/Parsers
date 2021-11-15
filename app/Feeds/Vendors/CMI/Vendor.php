<?php

namespace App\Feeds\Vendors\CMI;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{
    protected const CHUNK_SIZE = 10;
    protected const REQUEST_TIMEOUT_S = 100;

    protected array $first = [ 'https://www.blankstyle.com/sitemap.xml' ];

    public function filterProductLinks( Link $link ): bool
    {
        $url = explode('.com/',$link->getUrl())[1];

        if(str_contains($url,'article')) {
            return false;
        }

        if(strlen($url) > 10 || preg_match("/\d+/",$url)) {
            return true;
        }
        return false;
    }

    public function isValidFeedItem(FeedItem $fi ): bool
    {
        return !($fi->getMpn() === '' && !$fi->isGroup()) && stripos($fi->getFulldescr(),"discontinued") === false
            && ($fi->isGroup() || $fi->getCostToUs() > 0);
    }
}