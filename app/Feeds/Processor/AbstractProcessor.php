<?php

namespace App\Feeds\Processor;

use App\Feeds\Downloader\FtpDownloader;
use App\Feeds\Downloader\HttpDownloader;
use App\Feeds\Feed\FeedItem;
use App\Feeds\Feed\FeedValidate;
use App\Feeds\Parser\ParserInterface;
use App\Feeds\Parser\XLSNParser;
use App\Feeds\Storage\AbstractFeedStorage;
use App\Feeds\Storage\FileStorage;
use App\Feeds\Utils\Collection;
use App\Feeds\Utils\Data;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Repositories\DxRepositoryInterface;
use DateTime;
use Exception;
use Ms48\LaravelConsoleProgressBar\Facades\ConsoleProgressBar;

/**
 * Hooks
 * @method processInit
 * @method afterProcessItem
 * @method beforeProcess
 * @method afterProcess
 * @method afterFeedItemMerge( FeedItem $fi )
 */
abstract class AbstractProcessor
{
    /**
     * Указывает номера листов, которые должен обработать прай-парсер
     * Используется в том случае, если прайс-лист содержит несколько листов с необходимой информацией
     *
     * Для этого необходимо указать массив чисел, например [0, 1, 2] в котором каждый ключ является идентификатором листа в таблице,
     * а значение каждого ключа является идентификатором парсера с именем вида Price<число из массива>Parser
     */
    public const PRICE_ACTIVE_SHEET = [ 0 ];
    public const PRICE_ACTIVE_MULTIPLE_SHEET = [];
    /**
     * Указывает номера файлов, отсортированных в алфавитном порядке, которые должен обработать прай-парсер
     * Используется в том случае, если необходимо обработать несколько прайс-листов
     *
     * Аналогично PRICE_ACTIVE_SHEET необходимо указать массив, где ключи являются идентификаторами прайс-листов,
     * а значение каждого ключа является идентификатором парсера
     */
    public const PRICE_ACTIVE_FILES = [ 0 ];
    public const FEED_TYPE_INVENTORY = 'inventory';
    public const FEED_TYPE_PRODUCT = 'product';
    public const FEED_SOURCE_PRICE = 'price';
    public const FEED_SOURCE_SITE = 'site';
    public const DX_ID = null;
    public const DX_NAME = null;
    public const DX_PREFIX = null;
    public const DX_SOURCE = null;
    /**
     * Массив css селекторов, выбирающих элементы ссылок (<a>), на категории товаров для их дальнейшего обхода
     */
    public const CATEGORY_LINK_CSS_SELECTORS = [];
    /**
     * Массив css селекторов, выбирающих элементы ссылок (<a>), на страницы товаров для сбора информации с них
     */
    public const PRODUCT_LINK_CSS_SELECTORS = [];
    /**
     * Определяет количество ссылок, которое будет обработано за один запрос
     */
    protected const CHUNK_SIZE = 20;
    /**
     * Определяет время ожидания обработки запроса в секундах
     */
    protected const REQUEST_TIMEOUT_S = 60;
    /**
     * Определяет задержку между отправкой запросов в секундах
     */
    protected const DELAY_S = 0;
    /**
     * Определяет использовать статичный user agent или менять его при каждом запросе
     */
    protected const STATIC_USER_AGENT = false;
    /**
     * Определяет испольовать прокси или нет
     */
    protected const USE_PROXY = false;

    private const E_PARSER_NOT_FOUND = 'Class %s does not exists';

    public ?AbstractFeedStorage $storage;
    /**
     * @var HttpDownloader|FtpDownloader
     */
    protected HttpDownloader|FtpDownloader $downloader;
    /**
     * @var FeedItem[] Массив объектов app/Feeds/Feed/FeedItem содержащих информацию о товарах, взятых из прайс-листа
     */
    public array $price_items = [];
    /**
     * @var FeedItem[] Массив объектов app/Feeds/Feed/FeedItem содержащих информацию о товарах, взятых с сайта
     */
    public array $feed_items = [];
    /**
     * @var array Массив url адресов товаров, которые должен обработать парсер, чтобы не парсить весь сайт
     * Используется только для тестирования работоспособности парсера
     * Перед отправкой парсера в продакшен необходимо удалить данное свойство
     */
    public array $custom_products = [];
    public array $dx_info = [];
    /**
     * @var array Параметры для авторизации
     *
     * 'check_login_text' => 'Log Out' - Проверочное слово, которое отображается только авторизованным пользователям (Log Out, My account и прочие)
     * 'auth_url' => 'https://www.authorise_uri.com/login' - Url адрес на который отправляется запрос для авторизации
     * 'auth_form_url' => 'https://www.authorise_uri.com/login' - Url адрес страницы, на которой находится форма авторизации
     * 'auth_info' => [] - Массив параметров для авторизации, содержит в себе все поля, которые были отправлены браузером для авторизации
     * 'find_fields_form' => true|false - Указывает искать дополнительные поля формы авторизации перед отправкой запроса
     * Если этот параметр будет опущен, система сочтет его значение как "true"
     * 'api_auth' => true|false - Указывает в каком виде отправлять параметры формы авторизации ("request_payload" или "form_data")
     * Если этот параметр будет опущен, система сочтет его значение как "false".
     * По умолчанию параметры отправляются, как обычные поля формы
     *
     * Пример содержания auth_info:
     * 'auth_info' => [
     *     'login[username]' => 'user@my-email.com',
     *     'login[password]' => 'My-Password',
     * ],
     */
    protected array $params = [
        'check_login_text' => '',
        'auth_url' => '',
        'auth_form_url' => '',
        'auth_info' => [],
    ];
    protected array $first = [];
    protected array $headers = [];
    /**
     * @var int|null количество товаров, которое должен собрать парсер, чтобы не парсить весь сайт
     * Используется только для тестирования работоспособности парсера
     * Перед отправкой парсера в продакшен необходимо удалить данное свойство
     */
    protected ?int $max_products = null;
    protected Collection $process_queue;

    public function __construct(
        string $code = null,
        DxRepositoryInterface $dxRepo = null,
        AbstractFeedStorage $storage = null
    )
    {
        if ( $code && $dxRepo ) {
            //мультифиды на разные магазины
            $codeSplit = explode( '__', $code );
            //Замена в префиксе Dx _ на -
            $code = str_replace( '_', '-', $codeSplit[ 0 ] );
            $this->dx_info = $dxRepo->get( $code, $codeSplit[ 1 ] ?? null );
        }
        if ( $storage ) {
            $this->storage = $storage;
        }
        $this->process_queue = app( Collection::class );
    }

    public function __call( $name, $arguments )
    {
    }

    /**
     * проверка на dev mode
     * @return bool
     */
    public function isDevMode(): bool
    {
        return config( 'env', 'production' ) === 'dev';
    }

    public function getFeedType(): string
    {
        return !empty( $this->dx_info[ 'feeds' ] ) ? array_values( $this->dx_info[ 'feeds' ] )[ 0 ][ 'type' ] : self::FEED_TYPE_PRODUCT;
    }

    public function getFeedSource(): string
    {
        return self::FEED_SOURCE_SITE;
    }

    public function getFeedDate(): DateTime
    {
        return new DateTime();
    }

    public function getQueue(): Collection
    {
        return $this->process_queue;
    }

    public function getDownloader(): HttpDownloader|FtpDownloader
    {
        return $this->downloader;
    }

    /**
     * Возврает все ссылки на страницы категорий, которые были найденны по селекторам, указанным в константе "CATEGORY_LINK_CSS_SELECTORS"
     * @param Data $data Html разметка загружаемой страницы
     * @param string $url url адрес загружаемой страницы
     * @return array Массив ссылок, содержащий объекты app/Feeds/Utils/Link
     */
    public function getCategoriesLinks( Data $data, string $url ): array
    {
        return static::CATEGORY_LINK_CSS_SELECTORS
            ? ( new ParserCrawler( $data->getData(), $url ) )
                ->filter( implode( ', ', static::CATEGORY_LINK_CSS_SELECTORS ) )
                ->each( static fn( ParserCrawler $node ) => new Link( static::getNormalizedCategoryLink( $node->link()->getUri() ) ) )
            : [];
    }

    /**
     * Возврает все ссылки на страницы товаров, которые были найденны по селекторам, указанным в константе "PRODUCT_LINK_CSS_SELECTORS"
     * @param Data $data Html разметка загружаемой страницы
     * @param string $url url адрес загружаемой страницы
     * @return array Массив ссылок, содержащий объекты app/Feeds/Utils/Link
     */
    public function getProductsLinks( Data $data, string $url ): array
    {
        $links = static::PRODUCT_LINK_CSS_SELECTORS
            ? ( new ParserCrawler( $data->getData(), $url ) )
                ->filter( implode( ', ', static::PRODUCT_LINK_CSS_SELECTORS ) )
                ->each( static fn( ParserCrawler $node ) => new Link( static::getNormalizedLink( $node->link()->getUri() ) ) )
            : [];
        return array_values( array_filter( $links ?? [], [ $this, 'filterProductLinks' ] ) );
    }

    final public function getSupplierId(): int
    {
        return $this->dx_info[ 'id' ] ?? static::DX_ID;
    }

    final public function getSupplierName(): string
    {
        return $this->dx_info[ 'name' ] ?? static::DX_NAME;
    }

    final public function getPrefix(): string
    {
        return $this->dx_info[ 'prefix' ] ?? static::DX_PREFIX;
    }

    final public function getSource(): string
    {
        return static::DX_SOURCE ?? $this->dx_info[ 'source' ] ?? '';
    }

    /**
     * Возвращает объект парсера
     * @param string $prefix
     * @return ParserInterface
     */
    public function getParser( string $prefix = '' ): ParserInterface
    {
        $class = substr( static::class, 0, strrpos( static::class, '\\' ) + 1 ) . $prefix . 'Parser';
        if ( class_exists( $class ) ) {
            return new $class( $this );
        }

        die( sprintf( self::E_PARSER_NOT_FOUND, $class ) );
    }

    public static function getNormalizedCategoryLink( string $link ): string
    {
        return $link;
    }

    public static function getNormalizedLink( string $link ): string
    {
        return $link;
    }

    /**
     * Возвращает объект прайс парсера
     * @param array $sheet
     * @param null $price
     * @return ParserInterface
     */
    public function getPriceParser( array $sheet, $price = null ): ParserInterface
    {
        $parser = 'Price';
        if ( !is_null( $price ) && key( $sheet ) && count( static::PRICE_ACTIVE_MULTIPLE_SHEET ) ) {
            $parser .= $price ? "{$price}_" . key( $sheet ) : '_' . key( $sheet );
        }
        else if ( $price ) {
            $parser .= $price;
        }
        else {
            $parser .= key( $sheet ) ?: '';
        }
        return $this->getParser( $parser );
    }

    /**
     * Собирает информацию о товаре со страницы
     * @param Data $data html содержимое страницы товара
     * @param string $url url адрес текущей страницы товара
     * @return FeedItem[]
     */
    protected function getFeedItems( Data $data, string $url ): array
    {
        return $this->getParser()->parseContent( $data, [ 'url' => $url ] );
    }

    /**
     * @param int $max_products Устанавливает максимальное количество товаров, которое должен собрать парсер, чтобы не парсить весь сайт
     * Используетсяя только для тестирования работоспособности парсера
     */
    public function setMaxProducts( int $max_products ): void
    {
        $this->max_products = $max_products;
    }

    /**
     * Загружает ссылки, переданные в массиве
     * @param Link[] $links
     * @return Data[]
     */
    protected function fetchLinks( array $links ): array
    {
        return $this->getDownloader()->fetch( $links );
    }

    /**
     * Собирает ссылки на категории и страницы товаров
     * @param $crawler
     * @param string $url
     */
    protected function parseLinks( $crawler, string $url ): void
    {
        $this->process_queue->addLinks( $this->getCategoriesLinks( $crawler, $url ), Collection::LINK_TYPE_CATEGORY );
        $this->process_queue->addLinks( $this->getProductsLinks( $crawler, $url ), Collection::LINK_TYPE_PRODUCT );

        $num_product_links = $this->process_queue->where( 'type', Collection::LINK_TYPE_PRODUCT )->count();

        if (
            $this->max_products
            && $num_product_links >= $this->max_products
            && $this->isDevMode()
        ) {
            $categories = $this->process_queue->where( 'type', Collection::LINK_TYPE_CATEGORY )->all();
            array_walk( $categories, static fn( array $item ) => $item[ 'link' ]->setVisited() );
        }
    }

    /**
     * Производит слияние товаров, взятых из прайс-листа и с сайта
     * У товаров, взятых с сайта приоритет ниже, поэтому информация из прай-листа заменит собой информацию с сайта
     * За исключением нескольких полей
     * @param FeedItem[] $feed_target
     * @param FeedItem[] $feed_source
     */
    public function mergeFeeds( array $feed_target, array $feed_source ): void
    {
        foreach ( $feed_target as $fi_target ) {
            if ( $fi_target->isGroup() ) {
                $this->mergeFeeds( $fi_target->getChildProducts(), $feed_source );
            }

            if (
                isset( $feed_source[ $fi_target->getMpn() ] )
                && $fi_source = $feed_source[ $fi_target->getMpn() ]
            ) {
                foreach ( get_object_vars( $fi_source ) as $name => $source_value ) {
                    if ( !empty( $source_value ) ) {
                        switch ( $name ) {
                            case 'product':
                            case 'fulldescr':
                                if ( $fi_target->$name !== XLSNParser::DUMMY_PRODUCT_NAME ) {
                                    continue 2;
                                }
                                break;
                            case 'min_amount':
                                if ( $fi_target->$name !== 1 ) {
                                    continue 2;
                                }
                                break;
                            case 'forsale':
                                if ( $fi_target->$name !== 'Y' ) {
                                    continue 2;
                                }
                                break;
                        }
                        $fi_target->$name = $source_value;
                    }
                }
            }

            $this->afterFeedItemMerge( $fi_target );
        }
    }

    /**
     * Используется для удаления невалидных товаров из фида перед сохранением
     * @param FeedItem $fi
     * @return bool
     */
    protected function isValidFeedItem( FeedItem $fi ): bool
    {
        return true;
    }

    public function processError( $e, $key = null ): void
    {

    }

    /**
     * loading errors handler hook
     */
    protected function loadExceptionHandler( Exception $e ): void
    {
        print PHP_EOL . $e->getMessage() . PHP_EOL;
    }

    /**
     * Обходит страницы категорий и собирает информацию о товарах
     */
    final public function process(): void
    {
        $this->processInit();

        $this->beforeProcess();

        $total = $this->process_queue->count();

        if ( $this->custom_products && $this->isDevMode() ) {
            $this->process_queue = $this->process_queue->clear();
            $this->process_queue->addLinks( $this->custom_products, Collection::LINK_TYPE_PRODUCT );
        }

        while ( $links = $this->process_queue->next( null, static::CHUNK_SIZE ) ) {
            try {
                $new_links = $this->fetchLinks( $links );
            } catch ( Exception $e ) {
                $this->loadExceptionHandler( $e );
                sleep( 1 );
                continue;
            }

            foreach ( $new_links as $current_link => $data ) {
                if ( $data ) {
                    switch ( $this->process_queue->get( $current_link )[ 'type' ] ) {
                        case  Collection::LINK_TYPE_CATEGORY:
                            try {
                                $this->parseLinks( $data, $current_link );
                            } catch ( Exception $e ) {
                                echo PHP_EOL . 'Loading error: ' . $current_link . PHP_EOL . $e->getMessage() . PHP_EOL;
                                continue 2;
                            }
                            break;
                        case  Collection::LINK_TYPE_PRODUCT:
                            $feed_item = $this->getFeedItems( $data, $current_link );
                            if ( $this->storage instanceof FileStorage ) {
                                $this->feed_items += $feed_item;
                            }
                            else {
                                $this->mergeFeeds( $feed_item, $this->price_items );
                                $fi = array_shift( $feed_item );
                                if ( $this->isValidFeedItem( $fi ) ) {
                                    $this->storage->saveFeed( $this, [ $fi ] );
                                }
                            }
                            break;
                    }

                    if (
                        $this->max_products
                        && count( $this->feed_items ) >= $this->max_products
                        && $this->isDevMode()
                    ) {
                        break 2;
                    }
                }

                $this->afterProcessItem();

                $total = $this->process_queue->count();
                ConsoleProgressBar::showProgress( 1, $total );
            }

            array_walk( $links, static fn( Link $link ) => $link->setVisited( true ) );

            if ( $this->max_products && $this->isDevMode() && count( $this->feed_items ) >= $this->max_products ) {
                break;
            }
        }

        $this->afterProcess();

        if ( $total ) {
            ConsoleProgressBar::showProgress( $total, $total );
        }

        if ( $this->feed_items ) {
            $this->mergeFeeds( $this->feed_items, $this->price_items );
            foreach ( $this->feed_items as $key => $fi ) {
                if ( !$this->isValidFeedItem( $fi ) ) {
                    unset( $this->feed_items[ $key ] );
                }
            }

            if ( $this->isDevMode() ) {
                new FeedValidate( $this->feed_items, $this->dx_info );
            }

            $this->storage->saveFeed( $this, $this->feed_items );
        }
        $this->storage->shutdown();
    }
}
