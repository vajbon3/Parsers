<?php

namespace App\Feeds\Feed;

use App\Feeds\Parser\HtmlParser;
use App\Feeds\Parser\ParserInterface;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;
use DateTime;
use DateTimeZone;
use Exception;
use Throwable;

class FeedItem
{
    /**
     * @var string Уникальный код товара
     */
    public string $productcode = '';
    /**
     * @var string|null Идентификатор товара в Amazon
     */
    public ?string $ASIN = null;
    /**
     * @var string Название товара
     */
    public string $product = '';
    /**
     * @var float|null Цена товара с учетом скидки
     */
    public ?float $cost_to_us = null;
    /**
     * @var float|null Оригинальная цена товара
     */
    public ?float $list_price = null;
    /**
     * @var string|null Ключевые особенности товара, известные как "features" или "bullets"
     */
    public ?string $descr = null;
    /**
     * @var string|null Описание товара
     */
    public ?string $fulldescr = null;
    /**
     * @var string|null Бренд товара
     */
    public ?string $brand_name = null;
    /**
     * @var bool Присутствует ли бренд товара в названии товара
     */
    public bool $brand_normalized = false;
    /**
     * @var string Можно ли продавать товар "Y/N"
     */
    public string $forsale = 'Y';
    /**
     * @var string|null Дата поступления товара на склад
     */
    public ?string $eta_date_mm_dd_yyyy = null;
    /**
     * @var string|null Штрихкод товара
     */
    public ?string $upc = null;
    // категория
    public array $supplier_categories = [];
    /**
     * @var string|null Ссылка на страницу товара
     */
    public ?string $supplier_internal_id = null;
    /**
     * @var string Хэш сумма товара
     */
    public string $hash_product = '';
    /**
     * @var array Ссылки на изображения товара
     */
    public array $images = [];
    /**
     * @var array Альтернативные названия изображений товара для атрибута "alt"
     */
    public array $alt_names = [];
    /**
     * @var float|null x,y,z размеры товара
     */
    public ?float $dim_x = null;
    public ?float $dim_y = null;
    public ?float $dim_z = null;
    /**
     * @var float|null Вес товара для доставки (брутто)
     */
    public ?float $shipping_weight = null;
    /**
     * @var float|null Размеры товара для доставки
     */
    public ?float $shipping_dim_x = null;
    public ?float $shipping_dim_y = null;
    public ?float $shipping_dim_z = null;
    /**
     * @var float|null Вес товара (нетто)
     */
    public ?float $weight = null;
    /**
     * @var int Минимальное количество покупки товара
     */
    public int $min_amount = 1;
    /**
     * @var string|null Продавать товар упаковкой или поштучно "Y/N"
     */
    public ?string $mult_order_quantity = null;
    /**
     * @var bool Является ли товар групповым
     */
    public bool $is_group = false;
    /**
     * @var FeedItem[] Дочерние товары
     */
    public array $child_products = [];
    /**
     * @var string|null Общая часть названия для групповых товаров
     */
    public ?string $group_mask = null;
    /**
     * @var float|null Минимальная цена продажи товара
     */
    public ?float $new_map_price = null;
    /**
     * @var int|null Количество единиц на складе
     */
    public ?int $r_avail = null;
    /**
     * @var string|null Уникальный идентификатор товара "sku"
     */
    public ?string $mpn = null;
    /**
     * @var string|null Сообщение о требуемом времени для обработки заказа
     */
    public ?string $lead_time_message = null;
    /**
     * @var array|null Характеристики товара
     */
    public ?array $attributes = null;
    /**
     * @var array Файлы к товару: инструкции и пр.
     */
    public array $product_files = [];
    /**
     * @var array Опции товара: размер, цвет и пр.
     */
    public array $options = [];
    /**
     * @var array Видео товара
     */
    public array $videos = [];

    /**
     * FeedItem constructor.
     * @param ParserInterface|null $parser
     */
    public function __construct( ParserInterface $parser = null )
    {
        if ( $parser ) {
            $parser->beforeParse();
            try {
                $this->setMpn( $parser->getMpn() );
                $this->setASIN( $parser->getASIN() );
                $this->setProductCode( $parser->getProductCode() );
                $this->setProduct( $parser->getProduct() ?: $parser::DEFAULT_PRODUCT_NAME );
                $this->setFulldescr( $parser->getDescription() );
                $this->setShortdescr( $parser->getShortDescription() );
                $this->setBrandName( $parser->getBrand() );
                $this->setListPrice( $parser->getListPrice() );
                $this->setCostToUs( $parser->getCostToUs() );
                $this->setNewMapPrice( $parser->getMinimumPrice() );
                $this->setUpc( $parser->getUpc() );
                $this->setImages( $parser->getImages() );
                $this->setMinAmount( $parser->getMinAmount() ?? $this->getMinAmount() );
                $this->setMultOrderQuantity( $parser->getMultOrderQuantity() ?? $this->getMultOrderQuantity() );
                $this->setCategories( $parser->getCategories() );
                $this->setSupplierInternalId( $parser->getInternalId() );
                $this->setBrandNormalized( $parser->getBrandNormalized() );
                $this->setWeight( $parser->getWeight() );
                $this->setShippingWeight( $parser->getShippingWeight() );
                $this->setDimX( $parser->getDimX() );
                $this->setDimY( $parser->getDimY() );
                $this->setDimZ( $parser->getDimZ() );
                $this->setShippingDimX( $parser->getShippingDimX() );
                $this->setShippingDimY( $parser->getShippingDimY() );
                $this->setShippingDimZ( $parser->getShippingDimZ() );
                $this->setEtaDateMmDdYyyy( $parser->getEtaDate() );
                $this->setLeadTimeMessage( $parser->getLeadTimeMessage() );
                $this->setAttributes( $parser->getAttributes() );
                $this->setOptions( $parser->getOptions() );
                $this->setVideos( $parser->getVideos() );
                $this->setProductFiles( $parser->getProductFiles() );

                if ( $parser->isGroup() ) {
                    $children = $parser->getChildProducts( $this );

                    $this->setMpn( '' );
                    $this->setIsGroup( true );
                    $this->setListPrice( null );
                    $this->setCostToUs( 0 );
                    $this->setNewMapPrice();
                    $this->setRAvail( 0 );
                    $this->setForsale( 'Y' );
                    $this->setImages( [] );

                    $children = array_reduce( $children, function ( $c, FeedItem $child ) use ( $parser ) {
                            if ( !isset( $c[ $child->getMpn() ] ) ) {
                                $c[ $child->getMpn() ] = $child;
                                $child->setGroupMask( $this->getProduct() );
                                $child->setMultOrderQuantity( $child->getMinAmount() > 1 ? 'Y' : 'N' );
                                $child->setBrandName( $child->getBrandName() ?: $parser->getVendor()->getSupplierName() );
                                $child->setProductCode( strtoupper( $parser->getVendor()->getPrefix() . $child->getMpn() ) );
                                $child->setSupplierInternalId( $parser->getInternalId() );
                                $child->setHashProduct();
                            }

                            return $c;
                        } ) ?? [];

                    $this->setChildProducts( array_values( $children ) );
                }
                else {
                    $this->setIsGroup( false );
                    $this->setRAvail( $parser->getAvail() );
                    $this->setForsale( $parser->getForsale() );
                    $this->setChildProducts( [] );
                }

                $this->setMultOrderQuantity( $parser->getMinAmount() > 1 ? 'Y' : 'N' );
                $this->setHashProduct();

                $parser->afterParse( $this );
            } catch ( Throwable $e ) {
                $message = '  ERROR: failed parse product' . PHP_EOL;
                $message .= 'message: ' . $e->getMessage() . PHP_EOL;
                $message .= '     in: ' . $e->getFile() . '(' . $e->getLine() . ')' . PHP_EOL;

                if ( $parser instanceof HtmlParser ) {
                    $message .= '    uri: ' . $parser->getUri() . PHP_EOL;
                }

                $stack = $e->getTraceAsString();

                // reduce stack to Parser class errors
                if ( preg_match( "/.*\\\\Parser->.*?\(\)/s", $stack, $matches ) ) {
                    $stack = array_map( static fn( $elem ) => trim( $elem ), explode( "\n", $matches[ 0 ] ) );

                    foreach ( $stack as $i => $line ) {
                        $prefix = ( $i === 0 ) ? '  stack: ' : '         ';
                        $message .= $prefix . $line . PHP_EOL;
                    }

                    $message .= PHP_EOL;
                }
                echo $message;
            }
        }
    }

    /**
     * @param string $productcode Устанавливает код товара
     */
    public function setProductCode( string $productcode ): void
    {
        $this->productcode = (string)mb_strtoupper( str_replace( ' ', '-', StringHelper::removeSpaces( StringHelper::mb_trim( $productcode ) ) ) );
    }

    /**
     * @return string Возвращает код товара
     */
    public function getProductcode(): string
    {
        return $this->productcode;
    }

    /**
     * @param string|null $ASIN Устанавливает идентификатор товара в Amazon
     */
    public function setASIN( string $ASIN = null ): void
    {
        $this->ASIN = $ASIN;
    }

    /**
     * @return string|null Возвращает идентификатор товара в Amazon
     */
    public function getASIN(): ?string
    {
        return $this->ASIN;
    }

    /**
     * @param string $product Устанавливает название товара
     */
    public function setProduct( string $product ): void
    {
        $this->product = StringHelper::mb_ucwords( mb_strtolower( StringHelper::mb_trim( $product ) ) );
    }

    /**
     * @return string Возвращает название товара
     */
    public function getProduct(): string
    {
        return $this->product;
    }

    /**
     * @param float $cost_to_us Устанавливает стоимость товара со скидкой
     */
    public function setCostToUs( float $cost_to_us ): void
    {
        $this->cost_to_us = round( StringHelper::mb_trim( $cost_to_us ), 2 );
    }

    /**
     * @return float Возвращает стоимость товара со скидкой
     */
    public function getCostToUs(): float
    {
        return $this->cost_to_us;
    }

    /**
     * @param null|float $list_price Устанавливает оригинальную стоимость товара
     */
    public function setListPrice( ?float $list_price ): void
    {
        $this->list_price = null;
        if ( $list_price ) {
            $this->list_price = round( $list_price, 2 );
        }
    }

    /**
     * @return null|float Возвращает оригинальную стоимость товара
     */
    public function getListPrice(): ?float
    {
        return $this->list_price;
    }

    /**
     * @param array $descr Устанавливает ключевые особенности товара
     */
    public function setShortdescr( array $descr = [] ): void
    {
        $descr = FeedHelper::normalizeShortDesc( $descr );
        if ( $descr ) {
            $this->descr = '<ul><li>' . implode( '</li><li>', $descr ) . '</li></ul>';
        }
    }

    /**
     * @return null|string Возвращает ключевые особенности товара
     */
    public function getShortdescr(): ?string
    {
        return $this->descr;
    }

    /**
     * @param string $fulldescr Устанавливает описание товара
     */
    public function setFulldescr( string $fulldescr ): void
    {
        $this->fulldescr = StringHelper::mb_trim( $this->cutTags( $fulldescr ) );
    }

    /**
     * @return string Возвращает описание товара
     */
    public function getFulldescr(): string
    {
        return $this->fulldescr;
    }

    /**
     * @param string|null $brand_name Устанавливает бренд товара
     */
    public function setBrandName( ?string $brand_name ): void
    {
        $this->brand_name = StringHelper::mb_ucwords( mb_strtolower( StringHelper::mb_trim( $brand_name ) ) );
    }

    /**
     * @return string|null Возвращает бренд товара
     */
    public function getBrandName(): ?string
    {
        return $this->brand_name;
    }

    /**
     * @param bool $brand_normalized
     */
    public function setBrandNormalized( bool $brand_normalized ): void
    {
        $this->brand_normalized = $brand_normalized;
    }

    /**
     * @return bool
     */
    public function getBrandNormalized(): bool
    {
        return $this->brand_normalized;
    }

    /**
     * @param string $forsale Устанавливает можно ли продавать товар
     */
    public function setForsale( string $forsale ): void
    {
        $this->forsale = $forsale;
    }

    /**
     * @return string Возвращает можно ли продавать товар
     */
    public function getForsale(): string
    {
        return $this->forsale;
    }

    /**
     * @param DateTime|null $eta Устанавливает дату поступления товара на склад
     */
    public function setEtaDateMmDdYyyy( DateTime $eta = null ): void
    {
        if ( $eta ) {
            $this->eta_date_mm_dd_yyyy = $eta->format( 'm/d/Y' );
        }
    }

    /**
     * @return DateTime|null Возвращает дату поступления товара на склад
     */
    public function getEtaDateMmDdYyyy(): ?DateTime
    {
        $date = null;
        if ( $this->eta_date_mm_dd_yyyy ) {
            $date = DateTime::createFromFormat( 'm/d/Y', $this->eta_date_mm_dd_yyyy, new DateTimeZone( 'EST' ) );
        }
        return $date ?: null;
    }

    /**
     * @param string|null $upc Устанавливает штрихкод товара
     */
    public function setUpc( ?string $upc ): void
    {
        if ( $upc !== null ) {
            $this->upc = StringHelper::calculateUPC( StringHelper::mb_trim( $upc ) );
        }
    }

    /**
     * @return string|null Возвращает штрихкод товара
     */
    public function getUpc(): ?string
    {
        return $this->upc;
    }

    /**
     * @param string $supplier_internal_id Устанавливает ссылку на страницу товара
     */
    public function setSupplierInternalId( string $supplier_internal_id ): void
    {
        $this->supplier_internal_id = $supplier_internal_id;
    }

    /**
     * @return string Возвращает ссылку на страницу товара
     */
    public function getSupplierInternalId(): string
    {
        return $this->supplier_internal_id;
    }

    /**
     * @param array $images Устанавливает ссылки на изображения товара
     */
    public function setImages( array $images ): void
    {
        $this->images = $images;
    }

    /**
     * @return array Возвращает ссылки на изображения товара
     */
    public function getImages(): array
    {
        return $this->images;
    }

    /**
     * @param array $alt_names Устанавливает альтернативные названия изображений
     */
    public function setAltNames( array $alt_names ): void
    {
        $this->alt_names = $alt_names;
    }

    /**
     * @param array Возвращает альтернативные названия изображений
     */
    public function getAltNames(): array
    {
        return $this->alt_names;
    }

    /**
     * @param float|null $dim_x Устанавливает размер товара по "X"
     */
    public function setDimX( float $dim_x = null ): void
    {
        $this->dim_x = StringHelper::normalizeFloat( $dim_x );
    }

    /**
     * @param float|null Возвращает размер товара по "X"
     */
    public function getDimX(): ?float
    {
        return $this->dim_x;
    }

    /**
     * @param float|null $dim_y Устанавливает размер товара по "Y"
     */
    public function setDimY( float $dim_y = null ): void
    {
        $this->dim_y = StringHelper::normalizeFloat( $dim_y );
    }

    /**
     * @param float|null Возвращает размер товара по "Y"
     */
    public function getDimY(): ?float
    {
        return $this->dim_x;
    }

    /**
     * @param float|null $dim_z Устанавливает размер товара по "Z"
     */
    public function setDimZ( float $dim_z = null ): void
    {
        $this->dim_z = StringHelper::normalizeFloat( $dim_z );
    }

    /**
     * @param float|null Возвращает размер товара по "Z"
     */
    public function getDimZ(): ?float
    {
        return $this->dim_x;
    }

    /**
     * @param float|null $weight Устанавливает вес товара для доставки
     */
    public function setShippingWeight( float $weight = null ): void
    {
        $this->shipping_weight = StringHelper::normalizeFloat( $weight );
    }

    /**
     * @return float|null Возвращает вес товара для доставки
     */
    public function getShippingWeight(): ?float
    {
        return $this->shipping_weight;
    }

    /**
     * @param float|null $dim_x Устанавливает размер товара для доставки по "X"
     */
    public function setShippingDimX( float $dim_x = null ): void
    {
        $this->shipping_dim_x = StringHelper::normalizeFloat( $dim_x );
    }

    /**
     * @param float|null Возвращает размер товара по "X"
     */
    public function getShippingDimX(): ?float
    {
        return $this->dim_x;
    }

    /**
     * @param float|null $dim_y Устанавливает размер товара для доставки по "Y"
     */
    public function setShippingDimY( float $dim_y = null ): void
    {
        $this->shipping_dim_y = StringHelper::normalizeFloat( $dim_y );
    }

    /**
     * @param float|null Возвращает размер товара по "Y"
     */
    public function getShippingDimY(): ?float
    {
        return $this->dim_x;
    }

    /**
     * @param float|null $dim_z Устанавливает размер товара для доставки по "Z"
     */
    public function setShippingDimZ( float $dim_z = null ): void
    {
        $this->shipping_dim_z = StringHelper::normalizeFloat( $dim_z );
    }

    /**
     * @param float|null Возвращает размер товара по "Z"
     */
    public function getShippingDimZ(): ?float
    {
        return $this->dim_x;
    }

    /**
     * @param float|null $weight Устанавливает вес товара
     */
    public function setWeight( float $weight = null ): void
    {
        $this->weight = StringHelper::normalizeFloat( $weight );
    }

    /**
     * @return float|null Возвращает вес товара
     */
    public function getWeight(): ?float
    {
        return $this->weight;
    }

    /**
     * @param int $min_amount Устанавливает минимальное количество покупки товара
     */
    public function setMinAmount( int $min_amount ): void
    {
        $this->min_amount = $min_amount;
    }

    /**
     * @return int Возвращает минимальное количество покупки товара
     */
    public function getMinAmount(): int
    {
        return $this->min_amount;
    }

    /**
     * @param string $mult_order_quantity Устанавливает продавать товар упаковкой или поштучно
     */
    public function setMultOrderQuantity( string $mult_order_quantity ): void
    {
        $this->mult_order_quantity = $mult_order_quantity;
    }

    /**
     * @return string|null Возвращает продавать товар упаковкой или поштучно
     */
    public function getMultOrderQuantity(): ?string
    {
        return $this->mult_order_quantity;
    }

    /**
     * @param bool $is_group Устанавливает является ли товар групповым
     */
    public function setIsGroup( bool $is_group ): void
    {
        $this->is_group = $is_group;
    }

    /**
     * @return bool Возвращает является ли товар групповым
     */
    public function isGroup(): bool
    {
        return $this->is_group;
    }

    /**
     * @param array $child_products Устанавливает дочерние товары
     */
    public function setChildProducts( array $child_products ): void
    {
        $this->child_products = $child_products;
    }

    /**
     * @return array Возвращает дочерние товары
     */
    public function getChildProducts(): array
    {
        return $this->child_products;
    }

    /**
     * @param string|null $group_mask Устанавливает общую часть названия для групповых товаров
     */
    public function setGroupMask( ?string $group_mask ): void
    {
        $this->group_mask = $group_mask;
    }

    /**
     * @return string|null Возвращает общую часть названия для групповых товаров
     */
    public function getGroupMask(): ?string
    {
        return $this->group_mask;
    }

    /**
     * @param float|null $new_map_price Устанавливает минимальную цену продажи товара
     */
    public function setNewMapPrice( float $new_map_price = null ): void
    {
        $this->new_map_price = $new_map_price ? round( $new_map_price, 2 ) : $new_map_price;
    }

    /**
     * @return float|null Возвращает минимальную цену продажи товара
     */
    public function getNewMapPrice(): ?float
    {
        return $this->new_map_price;
    }

    /**
     * @param int|null $r_avail Устанавливает количество единиц на складе
     */
    public function setRAvail( ?int $r_avail = null ): void
    {
        $this->r_avail = $r_avail;
    }

    /**
     * @return int|null Возвращает количество единиц на складе
     */
    public function getRAvail(): ?int
    {
        return $this->r_avail;
    }

    /**
     * @param string $mpn Устанавливает уникальный идентификатор товара
     */
    public function setMpn( string $mpn ): void
    {
        $this->mpn = $mpn;
    }

    /**
     * @return string Возвращает уникальный идентификатор товара
     */
    public function getMpn(): string
    {
        return $this->mpn;
    }

    /**
     * @param string|null $lead_time_message Устанавливает сообщение о требуемом времени для обработки заказа
     */
    public function setLeadTimeMessage( ?string $lead_time_message ): void
    {
        $this->lead_time_message = $lead_time_message;
    }

    /**
     * @return string|null Возвращает сообщение о требуемом времени для обработки заказа
     */
    public function getLeadTimeMessage(): ?string
    {
        return $this->lead_time_message;
    }

    /**
     * @param array|null $getAttributes Устанавливает характеристики товара
     */
    public function setAttributes( ?array $getAttributes ): void
    {
        $this->attributes = $getAttributes;
    }

    /**
     * @param array|null Возвращает характеристики товара
     */
    public function getAttributes(): ?array
    {
        return $this->attributes;
    }

    /**
     * @param array $product_files Устанавливает файлы к товару
     */
    public function setProductFiles( array $product_files ): void
    {
        $this->product_files = $product_files;
    }

    /**
     * @return array Возвращает файлы к товару
     */
    public function getProductFiles(): array
    {
        return $this->product_files;
    }

    /**
     * @param array $options Устанавливает опции товара
     */
    public function setOptions( array $options ): void
    {
        $this->options = $options;
    }

    /**
     * @return array Возвращает опции товара
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param array $videos Устанавливает видео товара
     */
    public function setVideos( array $videos ): void
    {
        $this->videos = $videos;
    }

    /**
     * @return array Возвращает видео товара
     */
    public function getVideos(): array
    {
        return $this->videos;
    }

    /**
     * Устанавливает хэш сумму товара
     * @throws Exception
     */
    public function setHashProduct(): void
    {
        $attrs = $this->propsToArray();
        unset( $attrs[ 'images' ] );
        $this->hash_product = md5( json_encode( $attrs, JSON_THROW_ON_ERROR ) );
    }

    /**
     * @return string Возвращает хэш сумму товара
     */
    public function getHashProduct(): string
    {
        return $this->hash_product;
    }

    /**
     * Вырезает тэги из описания, оставляя разрешенные теги, очищаяя их от стилей и т.п.
     *
     * @param $fulldescr
     * @param bool $flag
     * @param array $tags
     * @return null|string
     */
    public function cutTags( $fulldescr, $flag = true, array $tags = [] ): ?string
    {
        $mass = [
            'span',
            'p',
            'br',
            'ol',
            'ul',
            'li',
            'table',
            'thead',
            'tbody',
            'th',
            'tr',
            'td',
        ];

        $regexps = [
            '/<script[^>]*?>.*?<\/script>/i',
            '/<noscript[^>]*?>.*?<\/noscript>/i',
            '/<style[^>]*?>.*?<\/style>/i',
            '/<video[^>]*?>.*?<\/video>/i',
            '/<a[^>]*?>.*?<\/a>/i',
            '/<iframe[^>]*?>.*?<\/iframe>/i'
        ];
        foreach ( $regexps as $regexp ) {
            if ( preg_match( $regexp, $fulldescr ) ) {
                $fulldescr = preg_replace( $regexp, '', $fulldescr );
            }
        }

        $fulldescr = StringHelper::mb_trim( $fulldescr );

        if ( !$flag ) {
            $mass = [];
        }

        if ( !empty( $tags ) && is_array( $tags ) ) {
            foreach ( $tags as $tag ) {
                $regexp = '/<(\D+)\s?[^>]*?>/';
                if ( preg_match( $regexp, $tag, $matches ) ) {
                    $mass[] = $matches[ 1 ];
                }
                else {
                    $mass[] = $tag;
                }
            }
        }

        $tags_string = '';
        foreach ( $mass as $tag ) {
            $tags_string .= "<$tag>";
        }

        $fulldescr = strip_tags( $fulldescr, $tags_string );

        foreach ( $mass as $tag ) {

            $regexp = "/(<$tag)([^>]*)(>)/i";

            if ( preg_match( $regexp, $fulldescr ) ) {
                $fulldescr = preg_replace( $regexp, '$1$3', $fulldescr );
            }
        }
        return $fulldescr;
    }

    /**
     * Преобразует свойства объекта в массив
     *
     * @param array $attrs
     * @return array
     */
    public function propsToArray( $attrs = [] ): array
    {
        $result = get_object_vars( $this );

        return !$attrs ? $result : array_intersect_key( $result, array_flip( $attrs ) );
    }

    /**
     * @return string Преобразует свойства объекта в json строку
     * @throws Exception
     */
    public function propsToJson(): string
    {
        return json_encode( $this->propsToArray(), JSON_THROW_ON_ERROR );
    }

    /**
     * @param array $categories
     */
    public function setCategories( array $categories ): void
    {
        $this->supplier_categories = array_map( 'mb_strtolower', $categories );
        $this->supplier_categories = array_map( [ StringHelper::class, 'mb_ucfirst' ], $this->supplier_categories );
    }

    /**
     * @return array
     */
    public function getCategories(): array
    {
        return $this->supplier_categories;
    }
}
