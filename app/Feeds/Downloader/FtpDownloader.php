<?php

namespace App\Feeds\Downloader;

use App\Feeds\Utils\Data;
use App\Feeds\Utils\Link;
use Illuminate\Contracts\Filesystem\FileNotFoundException as ContractFileNotFoundException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class FtpDownloader implements DownloaderInterface
{
    public FilesystemAdapter $client;

    public function __construct( $params = [] )
    {
        $params_ftp = [
            'host' => $params[ 'host' ],
            'port' => $params[ 'port' ] ?? 21,
            'timeout' => '30',
        ];

        if ( isset( $params[ 'username' ] ) ) {
            $params_ftp[ 'username' ] = $params[ 'username' ];
        }
        if ( isset( $params[ 'password' ] ) ) {
            $params_ftp[ 'password' ] = $params[ 'password' ];
        }

        $this->client = Storage::createFtpDriver( $params_ftp );
    }

    /**
     * Проверяет наличие переданного пути в ftp сервере
     * @param string $url
     * @return bool
     */
    public function isset( string $url ): bool
    {
        return $this->client->exists( $url );
    }

    /**
     * Возвращает все файлы по указанному пути
     * @param string $url
     * @return array
     */
    public function getFiles( string $url ): array
    {
        return $this->client->allFiles( $url );
    }

    /**
     * Загружает несколько ссылок
     * @param array $links
     * @return array
     */
    public function fetch( array $links ): array
    {
        $data = [];
        foreach ( $links as $link ) {
            try {
                if ( !$link instanceof Link ) {
                    $link = new Link( $link );
                }

                $data[ $link->getUrl() ] = new Data( $this->client->get( $link->getUrl() ) );
            } catch ( ContractFileNotFoundException $e ) {
                print "\nFtp parse error: {$e->getMessage()}\nURL: {$link->getUrl}";
            }
        }
        return $data;
    }

    /**
     * Загружает одну ссылку
     * @param $url
     * @param array $params
     * @param array $files
     * @return Data
     */
    public function get( $url, $params = [], $files = [] ): Data
    {
        $data = $this->fetch( [ $url ] );
        return array_shift( $data );
    }
}
