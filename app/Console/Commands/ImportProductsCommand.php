<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;
use XMLReader;

class ImportProductsCommand extends Command
{
    private const REDIS_FILTER_PREFIX = 'filter:';
    private const REDIS_CATEGORY_PREFIX = 'category:';
    private const REDIS_PRICE_PREFIX = 'price:';

    /**
     * Cache
     *
     * @var array
     */
    private array $categoryCache = [];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:import {file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import products from XML file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Checking if a file exists
        $filePath = $this->argument('file');
        $this->info('Attempting to access file: ' . $filePath);
        $this->info('Current working directory: ' . getcwd());
        $this->info('File exists: ' . (file_exists($filePath) ? 'Yes' : 'No'));

        if (!file_exists($filePath)) {
            $this->error("File not found: $filePath");
            return 1;
        }

        $reader = new XMLReader();
        $reader->open($filePath);

        // transaction beginning
        DB::beginTransaction();
        try {
            // Import categories
            $this->importCategories($reader);

            $reader->close();

            // Import products
            $reader->open($filePath);
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'offer') {
                    // Reading all XML element and creating an XML object
                    $xmlData = new SimpleXMLElement($reader->readOuterXML());
                    $productId = (string)$xmlData['id'];

                    if (!$this->validateRequiredFields($xmlData)) {
                        $this->warn("Skipping product $productId due to missing required fields");
                        continue;
                    }

                    $productData = [
                        'id' => $productId,
                        'name' => (string)$xmlData->name,
                        'price' => number_format((float)$xmlData->price, 2, '.', ''),
                        'currency_id' => (string)$xmlData->currencyId,
                        'stock_quantity' => (int)$xmlData->stock_quantity,
                        'description' => (string)$xmlData->description,
                        'vendor' => (string)$xmlData->vendor,
                        'vendor_code' => (string)$xmlData->vendor_code,
                        'barcode' => (string)$xmlData->barcode,
                        'available' => ((string)$xmlData['available'] === 'true'),
                        'updated_at' => now(),
                    ];

                    if (!DB::table('products')->where('id', $productData['id'])->exists()) {
                        $productData['created_at'] = $productData['updated_at'];
                    }

                    // creating or update product data
                    DB::table('products')->upsert(
                        [$productData],
                        ['id'],
                        [
                            'name',
                            'price',
                            'currency_id',
                            'stock_quantity',
                            'description',
                            'vendor',
                            'vendor_code',
                            'barcode',
                            'available',
                            'updated_at'
                        ]
                    );

                    $this->processParameters($productData, $xmlData->param);
                    $this->processImages($productData, $xmlData->picture);
                    $this->processCategories($productData, $xmlData->categoryId);
                }
            }

            DB::commit();

            // Adding a Redis update after a successful import
            $this->updateRedisFilters();

            $this->info("Import completed successfully");
            return 0;
        } catch (Exception $e) {
            DB::rollBack();
            $this->error("Import failed: {$e->getMessage()}");
            return 1;
        } finally {
            $reader->close();
        }
    }

    /**
     * @param $xmlData
     * @return bool
     */
    private function validateRequiredFields($xmlData): bool
    {
        return !empty((string)$xmlData['id'])
            && !empty($xmlData->name)
            && !empty($xmlData->price);
    }

    /**
     * Processing product parameters
     *
     * @param array $product
     * @param $params
     * @return void
     */
    private function processParameters(array $product, $params): void
    {
        // Creating product attributes
        $attributes = [];
        foreach ($params as $param) {
            $name = (string)$param['name'];
            $value = (string)$param;

            $attributes[] = [
                'product_id' => $product['id'],
                'name' => $name,
                'value' => $value,
                'filter_key' => $this->transliterateToKey($name), // generate filter key
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        // Creating or update attributes
        if (!empty($attributes)) {
            foreach (array_chunk($attributes, 100) as $chunk) {
                DB::table('product_attributes')->upsert(
                    $chunk,
                    ['product_id', 'name'],
                    ['value', 'filter_key', 'updated_at']
                );
            }
        }

        // Removing unneeded attributes
        DB::table('product_attributes')
            ->where('product_id', $product['id'])
            ->whereNotIn('name', array_column($attributes, 'name'))
            ->delete();
    }

    /**
     * @param $text
     * @return string
     */
    private function transliterateToKey($text): string
    {
        return Str::slug($text);
    }

    /**
     * Image processing
     *
     * @param $product
     * @param $images
     * @return void
     */
    private function processImages($product, $images): void
    {
        $imageUrls = $images instanceof SimpleXMLElement ? [$images] : (array)$images;
        $newImages = [];

        foreach ($imageUrls as $imageUrl) {
            $imageUrl = (string)$imageUrl;
            if (!empty($imageUrl)) {
                $newImages[] = [
                    'product_id' => $product['id'],
                    'image_url' => $imageUrl,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
        }

        // Saving URL image
        if (!empty($newImages)) {
            foreach (array_chunk($newImages, 100) as $chunk) {
                DB::table('product_images')->insert($chunk);
            }
        }

        // Remove old image
        DB::table('product_images')
            ->where('product_id', $product['id'])
            ->whereNotIn('image_url', array_column($newImages, 'image_url'))
            ->delete();
    }

    /**
     * Processing categories
     *
     * @param $product
     * @param $categoryId
     * @return void
     */
    private function processCategories($product, $categoryId): void
    {
        // Converting input data into an array of categories
        $categoryIds = $categoryId instanceof SimpleXMLElement ? [$categoryId] : (array)$categoryId;
        $categories = [];

        foreach ($categoryIds as $id) {
            $id = (string)$id;
            if (!empty($id)) {
                // Checking the existence of a category via cache
                if (!isset($this->categoryCache[$id])) {
                    $this->categoryCache[$id] = DB::table('categories')->find($id) !== null;
                }

                // If the category exists, create a category existence link through the cache
                if ($this->categoryCache[$id]) {
                    $categories[] = [
                        'product_id' => $product['id'],
                        'category_id' => $id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                } else {
                    $this->warn("Product {$product['id']}: Category not found: $id");
                }
            }

        }

        // Removing old connections
        DB::table('product_categories')
            ->where('product_id', $product['id'])
            ->delete();

        // Insert new connections
        if (!empty($categories)) {
            foreach (array_chunk($categories, 100) as $chunk) {
                DB::table('product_categories')->insert($chunk);
            }
        }
    }

    /**
     * Import categories
     *
     * @param XMLReader $reader
     * @return void
     */
    private function importCategories(XMLReader $reader): void
    {
        $this->info('Import categories...');
        $categories = [];

        // read categories from an XML file
        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'category') {
                try {
                    $xmlData = new SimpleXMLElement($reader->readOuterXML());
                    $categoryId = (string)$xmlData['id'];
                    $parentId = (string)$xmlData['parentId'];
                    $name = (string)$xmlData;

                    if (!empty($categoryId) && !empty($name)) {
                        $categories[] = [
                            'id' => $categoryId,
                            'name' => $name,
                            'parent_id' => !empty($parentId) ? $parentId : null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }

                    if ($reader->nodeType == XMLReader::END_ELEMENT && $reader->name == 'category') {
                        break;
                    }
                } catch (Exception $e) {
                    $this->warn("Failed to process offer: " . $e->getMessage());
                    continue;
                }
            }
        }

        // Sorting categories(parent categories first)
        usort($categories, function ($a, $b) {
           if ($a['parent_id'] == $b['parent_id']) return -1;
           if (!empty($a['parent_id']) && empty($b['parent_id'])) return 1;
           return 0;
        });

        // Saving to the DB
        foreach (array_chunk($categories, 100) as $chunk) {
            DB::table('categories')->upsert(
                $chunk,
                ['id'],
                ['name', 'parent_id']
            );
        }

        $this->info(count($categories) . ' categories imported');

        $this->categoryCache = array_fill_keys(
            array_column($categories, 'id'),
            true
        );
    }

    /**
     * Redis caching
     *
     * @return void
     */
    private function updateRedisFilters(): void
    {
        $this->info('Updating Redis filters...');

        // Clearing old data
        $this->info('Clearing old Redis data...');
        Redis::pipeline(function ($pipe) {
            foreach (Redis::keys(self::REDIS_FILTER_PREFIX . '*') as $key) {
                $pipe->del($key);
            }
            foreach (Redis::keys(self::REDIS_CATEGORY_PREFIX . '*') as $key) {
                $pipe->del($key);
            }
        });

        // Receiving all products
        $products = DB::table('products')->get();

        // Processing attributes for filters
        $this->info('Processing attributes for filters...');
        $attributes = DB::table('product_attributes')
            ->select('product_id', 'filter_key', 'value', 'name')
            ->get();

        $this->info('Found attributes: ' . $attributes->count());

        foreach ($attributes as $attribute) {
            $filterKey = self::REDIS_FILTER_PREFIX . $attribute->filter_key . ':' . $attribute->value;
            Redis::sadd($filterKey, $attribute->product_id);
        }

        // Processing categories filter
        $categoryProducts = DB::table('product_categories')
            ->select('product_id', 'category_id')
            ->get();

        foreach ($categoryProducts as $item) {
            $filterKey = self::REDIS_FILTER_PREFIX . self::REDIS_CATEGORY_PREFIX . $item->category_id;
            Redis::sadd($filterKey, $item->product_id);
        }

        // Processing price ranges filter
        $priceRanges = [
            '0-1000' => [0, 1000],
            '1000-5000' => [1000, 5000],
            '5000-10000' => [5000, 10000],
            '10000-50000' => [10000, 50000],
            '50000+' => [50000, PHP_FLOAT_MAX]
        ];

        foreach ($priceRanges as $range => $bounds) {
            $productsInRange = $products
                ->where('price', '>=', $bounds[0])
                ->where('price', '<', $bounds[1])
                ->pluck('id')
                ->toArray();

            if (!empty($productsInRange)) {
                Redis::sadd(self::REDIS_FILTER_PREFIX . self::REDIS_PRICE_PREFIX . $range, $productsInRange);
            }
        }

        $this->info('Redis filters updated successfully');
    }
}
