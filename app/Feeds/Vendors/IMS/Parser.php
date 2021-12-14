<?php

namespace App\Feeds\Vendors\IMS;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    private string $description = '';
    private array $short_desc = [];
    private array $attributes = [];
    private bool $is_group = false;
    private bool $is_quote = false;
    private array $children = [];
    private array $images = [];
    private array $categories = [];

    protected array $remove_description_patterns = [
        '/[a-z]+@[a-z]+\.[a-z]+/is', # email
        '/\$\d+(?:\.\d{1,2}){0,1}/is', # цена
        '/pictured below/is', # текст до изображения
        "/warranty.{0,20}:/is", # гарантии
        ];

    protected array $clean_description_patterns = [
        '/AllegroMedical(?:\.com){0,1}\s.*?\./is', # имья саита
        "/\.[a-z\s']{0,100}Warranty.*?./is", # гарантии
        "/(?:<center>.{0,5}){0,1}<h\d>.{0,5}.{0,50}.{0,5}<\/h\d>(?:.{0,5}<\/center>){0,1}/is", # имья продукта
        "/<li>[a-z\s']{0,50}warranty[a-z\s'\.]{0,50}<\/li>/is", #  гарантии в features
        "/<center>.{0,5}<img.*<\/center>/is", # изображение
        "/<[a-z0-9]+><strong>.*?(?<!:)<\/strong><\/[a-z0-9]+>/is", # strong текст ( часто имена )
        "/<[a-z0-9]+><small>.*?<\/small><\/[a-z0-9]+>/is", # мелький текст
        '/(?:<strong>.{0,20}warning.*?<\/strong>){0,1}(?<=>)[a-z\s\d&;-]*<a.*?<\/a>/is', # ссылка и его текст
        '/(?<=<li>)<br>/is', # br теги до текста в личке
        '/<br>(?=<\/li>)/is', # br теги после текста в личке
        '/(?<=>)[a-z\s\d]+available in.*?(?=\<)/is', # строки про других продуктов
        '/<table.*<\/table>/is' # удалить таблицу
    ];

    public function beforeParse(): void
    {
        $this->is_quote = $this->exists(".submit-quote-button");
        if($this->is_quote) {
            return;
        }

        // описания
        $this->description = $this->getHtml('div.description div.value');
        $this->short_desc = $this->getContent("div[id*='bulletdescription'] li");

        // возмём features и атрибуты из описания

        $results = $this->getShortsAndAttributesInDescription($this->description,["~(?<content_list><p.{0,80}(?:<b.{0,80}>){0,1}.{0,40}features.*?<\/ul>)~sim", "~(?<content_list><p>.{0,100}specifications.{0,40}<(?:ul|table)>.*?<\/(?:ul|table)>)~sim"]);

        $this->description = $results['description'];
        $shorts = $results['short_description'];
        $this->attributes = $results['attributes'] ?? [];

        // если метод не заработает правельно, возмём features самы
        $matches = [];
        preg_match('/<p.{0,80}(?:<b.{0,80}>){0,1}.{0,40}features.*?<\/ul>/is',$this->description,$matches);
        preg_replace('/<p.{0,80}(?:<b.{0,80}>){0,1}.{0,40}features.*?<\/ul>/is','',$this->description);

        foreach($matches as $match) {
            $ul = new ParserCrawler($match);
            $ul->filter('li')->each(static function($li) use(&$shorts) {
                $shorts[] = $li->text();
            });
        }

        // чек на оригинальность
        foreach($shorts as $li) {
            if(!in_array($li, $this->short_desc, true)) {
                $this->short_desc[] = $li;
            }
        }

        if($this->description === "") {
            $this->description = $this->getProduct();
        }

        // вырезаем из шорта размеры если они уже есть в атрибуты
        if(isset($this->attributes['Size']) || isset($this->attributes['size'])) {
            foreach($this->short_desc as $key => $li) {
                if(stripos($li,'size')  !== false) {
                    unset($this->short_desc[$key]);
                }
            }
        }

        // парсирование json data с саита
        $child_matches = [];
        $gallery_matches = [];
        $breadcrumbs_matches = [];
        $this->filter("script[type='text/x-magento-init']")->each(static function (ParserCrawler $c) use (&$child_matches, &$gallery_matches,&$breadcrumbs_matches) {
            if (str_contains($c->html(), 'jsonConfig')) {
                preg_match('/{.*}/s', $c->html(), $child_matches);
            } else if (str_contains($c->html(), 'Xumulus_FastGalleryLoad/js/gallery/custom_gallery')) {
                preg_match('/{.*}/s', $c->html(), $gallery_matches);
            } else if(str_contains($c->html(), 'breadcrumbs')) {
                preg_match('/{.*}/s', $c->html(), $breadcrumbs_matches);
            }
        });

        // если json config сушествует, у продукта есть дочерные
        if (isset($child_matches[0])) {
            $data = json_decode($child_matches[0], true, 512, JSON_THROW_ON_ERROR);
            $this->is_group = true;

            $data = $data['[data-role=swatch-options]']['Magento_Swatches/js/swatch-renderer']['jsonConfig'];

            // имена
            foreach ($data['names'] as $id => $name) {
                $this->children[$id] = [];
                $this->children[$id]['product'] = $name;
            }

            // sku
            foreach ($data['skus'] as $id => $sku) {
                // если инфо не про дочерного продукта
                if(!array_key_exists($id,$this->children)) {
                    continue;
                }
                $this->children[$id]['sku'] = $sku;
            }

            // изображения
            foreach ($data['images'] as $id => $image_array) {
                // если инфо не про дочерного продукта
                if(!array_key_exists($id,$this->children)) {
                    continue;
                }
                $this->children[$id]['images'] = [];
                foreach ($image_array as $image) {
                    if ($image['type'] === 'image') {
                        $this->children[$id]['images'][] = $image['full'];
                    }
                }
            }

            // цены
            foreach ($data['optionPrices'] as $id => $prices) {
                // если инфо не про дочерного продукта
                if(!array_key_exists($id,$this->children)) {
                    continue;
                }
                $this->children[$id]['prices']['cost_to_us'] = $prices['finalPrice']['amount'];
                $this->children[$id]['prices']['msrp'] = $prices['basePrice']['amount'];
            }

            // stock
            foreach($data['stock_status'] as $id => $status) {
                // если инфо не про дочерного продукта
                if(!array_key_exists($id,$this->children)) {
                    continue;
                }
                $this->children[$id]['avail'] = stripos($status,"out of stock") === false ? self::DEFAULT_AVAIL_NUMBER : 0;
            }
        }

        // изображения для основного
        if(isset($gallery_matches[0])) {
            $data = json_decode($gallery_matches[0], true, 512, JSON_THROW_ON_ERROR);

            $data = $data['[data-gallery-role=gallery-placeholder]']['Xumulus_FastGalleryLoad/js/gallery/custom_gallery']['data'];

            foreach($data as $image) {
                $this->images[] = $image['full'];
            }
        }

        // категории
        if(isset($breadcrumbs_matches[0])) {
            $data = json_decode($breadcrumbs_matches[0], true, 512, JSON_THROW_ON_ERROR);

            $data = $data['.breadcrumbs']['breadcrumbs']['categoriesConfig']['default'] ?? [];

            foreach($data as $url) {
                $breadcrumbs_matches = [];
                preg_match('/>(.+)</s',$url,$breadcrumbs_matches);
                $this->categories[] = $breadcrumbs_matches[1];
                $breadcrumbs_matches = [];
            }
        }

    }

    public function getProduct(): string
    {
        if($this->is_quote === True) {
            return "quote";
        }
        return $this->getText('.page-title');
    }

    public function getMpn(): string
    {
        return $this->getText('.sku div.value');
    }

    public function getBrand(): ?string
    {
        return $this->getText('.amshopby-option-link a');
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getShortDescription(): array
    {
        return $this->short_desc;
    }

    public function getAttributes(): ?array
    {
        return $this->attributes !== [] ? $this->attributes : null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney($this->getText(".product-info-price span[id*='product-price'] .price"));
    }

    public function getImages(): array
    {
        return $this->images;
    }

    public function getVideos(): array
    {
        $videos = [];
        $this->filter('div.description iframe')->each(function (ParserCrawler $c) use (&$videos) {
            $videos[] = [
                'name' => $this->getProduct(),
                'provider' => 'youtube',
                'video' => $c->attr('src')
            ];
        });
        return $videos;
    }

    public function getProductFiles(): array
    {
        return $this->filter('a.am-filelink')->each(static fn(ParserCrawler $c) => ['name' => $c->text(), "link" => $c->attr('href')]);
    }

    public function getCategories(): array
    {
        return $this->categories;
    }

    public function getAvail(): ?int
    {
        return $this->exists("#product-addtocart-button") ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function isGroup(): bool
    {
        return $this->is_group;
    }

    public function getChildProducts(FeedItem $parent_fi): array
    {
        $child = [];

        foreach($this->children as $id => $child_info) {
            if($id === 'default') {
                continue;
            }

            $child_fi = clone $parent_fi;
            $child_fi->setProduct($child_info['product']);
            $child_fi->setMpn($child_info['sku']);
            $child_fi->setImages($child_info['images'] ?? $parent_fi->getImages());
            $child_fi->setCostToUs($child_info['prices']['cost_to_us']);
            // если listPrice > costToUs ставить
            if($child_info['prices']['msrp'] > $child_fi->getCostToUs()) {
                $child_fi->setListPrice($child_info['prices']['msrp']);
            }
            $child_fi->setRAvail($child_info['avail'] ?? self::DEFAULT_AVAIL_NUMBER);

            $child[] = $child_fi;
        }

        return $child;
    }
}