<?php

namespace App\Feeds\Processor;

use App\Feeds\Downloader\FtpDownloader;
use App\Feeds\Utils\Collection;
use App\Feeds\Utils\Link;

abstract class FtpProcessor extends AbstractProcessor
{
    public function processInit(): void
    {
        $this->downloader = new FtpDownloader(
            $this->params
        );

        $this->process_queue->addLinks( array_map( static fn( $url ) => new Link( $url ), $this->first ),  Collection::LINK_TYPE_PRODUCT );
    }
}
