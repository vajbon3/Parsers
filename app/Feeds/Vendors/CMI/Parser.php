<?php

namespace App\Feeds\Vendors\CMI;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\Data;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{

    private array $features = [];
    private string $description = "";
    private array $dims = [
        'x' => null,
        'y' => null,
        'z' => null
    ];

    // помошники
    private function parseDims(string $dims) : string
    {
        $result = '';

        $dims = str_replace(' ','',$dims);

        $h = stripos($dims,'h');
        $w = stripos($dims,'w');
        if($h < $w) {
            $result = substr($dims,$h+2,$w-$h) . substr($dims,0,$h+1) . substr($dims,$w+1);
        } else {
            $result = $dims;
        }
        return $result;
    }

    public function beforeParse(): void
    {
        // убрать лишние ковички, если есть
        $this->description = str_replace('""', '', $this->getHtml('#tbmore_info'));

        // убрать таблицы, если есть
        $this->description = preg_replace('/<div>.*?<p>.*?<\/p>.{0,2}<table.*?<\/div>/si','',$this->description);

        // ишем стринг размеров в текст в 5' x 6' формат
        $description2 = $this->getHtml('.product-body');

        // убрать коментированный текст
        $this->description = preg_replace('<!--.*-->','',$this->description);
        $description2 = preg_replace('<!--.*-->','',$description2);

        $matches = [];
        if (preg_match("/\d*((\.\d+)|(\s+\d+\/\d+)*)[\"']+[a-zA-z\s]*x[a-zA-z\s]*\d*((\.\d+)|(\s+\d+\/\d+)*)[\"']+([a-zA-z\s]*x[a-zA-z\s]*\d*((\.\d+)|(\s+\d+\/\d+)*)[\"']+)*/", $description2, $matches) ||
            preg_match("/\d*((\.\d+)|(\s+\d+\/\d+)*)[\"']+[a-zA-z\s]*x[a-zA-z\s]*\d*((\.\d+)|(\s+\d+\/\d+)*)[\"']+([a-zA-z\s]*x[a-zA-z\s]*\d*((\.\d+)|(\s+\d+\/\d+)*)[\"']+)*/", $this->description, $matches)) {

            // парсируем формат если присутствует 'h'
            if(stripos($matches[0],'h') !== false) {
                $matches[0] = $this->parseDims($matches[0]);
            }

            $this->dims = FeedHelper::getDimsInString($matches[0], 'x');
        }

        // если есть таблица в стиле width - height, возмём обший размер товара
        if($this->exists('.product-body table')) {
            if(stripos($this->getText('.product-body table tr td'),'width') !== false) {
                $this->dims['x'] = StringHelper::getFloat($this->getText('.product-body table tr:nth-child(2) td'));
                $this->dims['y'] = StringHelper::getFloat($this->getText('.product-body table tr:nth-child(2) td:nth-child(2)'));
            }
        }

        // описание и short_desc
        if ($this->exists('.product-body div')) {
            $this->filter('.product-body div')->each(function (ParserCrawler $c) {
                if (stripos($c->getText('span'), 'feature') !== false) {
                    $this->features = $c->nextAll()->getContent('li');
                }
            });
        } else {
            $this->features = $this->getContent('.product-body li');

            // если списка нету для features, а описание пустой - сунуть всё в описание
            if(($this->features === []) && $this->description === '') {
                // фильтрировать параграф таблицы и сам таблицу
                $this->description = preg_replace("/<p><strong>specs.*<table.*table>/is","",$this->getHtml(".product-body"));
            }
        }

        // если описание пустое, сунуть там имья продукта
        if($this->description === "") {
            $this->description = $this->getProduct();
        }
    }

    public function getProduct(): string
    {
        return preg_replace('/[^a-z0-9\s]/i', '', $this->getText('h1.title'));
    }

    public function getMpn(): string
    {
        try {
            return trim(explode(':', $this->getText('h1.title > small'))[1]);
        } catch (\Exception) {
            return '';
        }
    }

    public function getCostToUs(): float
    {
        $cost = StringHelper::getMoney($this->getText('#green-price span'));
        if($cost === 0.0) {
            $cost = StringHelper::getMoney($this->getText('#msrp'));
        }
        return $cost;
    }

    public function getShortDescription(): array
    {
        return $this->features;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getDimX(): ?float
    {
        return $this->dims['x'];
    }

    public function getDimY(): ?float
    {
        return $this->dims['y'];
    }

    public function getDimZ(): ?float
    {
        return $this->dims['z'];
    }

    public function getCategories(): array
    {
        return array_slice(array_values(array_unique($this->getContent('div.breadcrumb a'))), 0, 5);
    }

    public function getAvail(): ?int
    {
        return $this->exists('div.product-out-of-stock') ? 0 : self::DEFAULT_AVAIL_NUMBER;
    }

    public function getImages(): array
    {
        $exampleImage = $this->getAttr('.thumb-view a', 'href');
        return $exampleImage !== '' ? [$exampleImage] : [$this->getAttr('.main-product-image a', 'href')];
    }

    public function isGroup(): bool
    {
        return $this->getAvail() !== 0 && $this->getMpn() !== '';
    }

    public function getChildProducts(FeedItem $parent_fi): array
    {
        $child = [];

        // id продукта
        $nid = $this->getAttr("input[name='nid']", 'value');

        // взять контеинер изображении
        $product_data = [];
        $links = ["blankstyle.com/magiczoom-thumbnails-lazyload/$nid"];
        foreach ($this->getVendor()->getDownloader()->fetch($links) as $data) {
            $matches = [];
            preg_match('/{"status".*}}/', $data, $matches);
            $json = json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);

        }

        $c = new ParserCrawler($json["data"]["content"]);

        // ключ атрибута
        $attribute_key = $this->getAttr('select.color-select-picker', 'name');

        // id цвет и имена
        $colors = $this->filter('select.color-select-picker option')->each(static fn(ParserCrawler $c) => ['id' => $c->attr('value'), 'name' => $c->text()]);

        // размеры
        $sizes = [];

        // mapping для размеров потому что на саите используются две стандартные имена
        $sizes['map'] = [
            '2XL' => 'XXL',
            '3XL' => 'XXXL',
            '4XL' => 'XXXXL'
        ];

        $this->filter('.input-qty-cell')->each(static function (ParserCrawler $c) use (&$sizes) {
            $sizes[$c->attr('data-oid')] = $c->closest('.size-field')->getText('.size-label');
        });

        // взять ширину и длину всех размеров, если правилная таблица есть
        if (stripos($this->getText(".product-body table tr td"),"width") !== false) {
            $i = 2;
            $this->filter(".product-body table tbody tr:nth-child(1) td")->each(function (ParserCrawler $c) use (&$sizes, &$i) {
                if (stripos($c->text(), "size") !== false) {
                    return;
                }
                isset($sizes[$c->text()]) ?: $sizes[$c->text()] = ['x' => null, 'y' => null];
                $sizes[$c->text()]['x'] = StringHelper::getFloat($this->getText(".product-body table tbody tr:nth-child(2) td:nth-child($i)"));
                $sizes[$c->text()]['y'] = StringHelper::getFloat($this->getText(".product-body table tbody tr:nth-child(3) td:nth-child($i)"));
                $i++;
            });
        }

        foreach ($colors as $color) {
            $params = [
                $attribute_key => $color['id'],
                'nid' => $nid,
                'qty' => 1,
            ];

            $links = [new Link("https://www.blankstyle.com/cart/add-to-cart-form/$nid", 'POST', $params, 'form-data')];

            foreach ($this->getVendor()->getDownloader()->fetch($links) as $data) {
                $product_data = $data->getJSON();

                foreach ($product_data as $size_id => $info) {
                    if(!isset($sizes[$size_id])) {
                        continue;
                    }
                    if ($size_id === 'otherDetail') {
                        continue;
                    }

                    $fi = clone $parent_fi;

                    $color_name = $color['name'];
                    $color_id = $color['id'];
                    $size_name = $sizes[$size_id];

                    $fi->setProduct("Color: $color_name, Size: $size_name");
                    $fi->setMpn($this->getMpn() . '-' . $color_id . '-' . $size_id);

                    $cost = StringHelper::getMoney($info['price']);
                    $list = StringHelper::getMoney($info['MSRP']);
                    if($cost === 0.0) {
                        $cost = $list;
                    }
                    $fi->setCostToUs($cost);
                    if($list > $cost) {
                        $fi->setListPrice($list);
                    }

                    $fi->setRAvail((int)$info['inventory']);

                    // ставить размеры
                    $size_key = isset($sizes['map'][$size_name]) ? $sizes["map"][$size_name] : $size_name;
                    isset($sizes[$size_key]) ?: $sizes[$size_key] = ['x' => null, 'y' => null];
                    $fi->setDimX($sizes[$size_key]['x'] ?? $this->getDimX());
                    $fi->setDimY($sizes[$size_key]['y'] ?? $this->getDimX());

                    // изображения
                    $images = $c->filter("a[data-oid='$color_id']")
                        ->each(static fn(ParserCrawler $c) => $c->attr('href'));

                    // если изображения есть, ставить, если нету - использовать основной
                    $fi->setImages($images !== [] ? $images : [$this->getAttr(".main-product-image a","href")]);

                    $child[] = $fi;
                }
            }
        }


        return $child;
    }
}