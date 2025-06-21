<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use SimpleXMLElement;
use XMLReader;

class ImportProductsCommand extends Command
{
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

        DB::beginTransaction();
        try {
            $this->importCategories($reader);

            $reader->close();
            $reader->open($filePath);
            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name == 'offer') {
                    $xmlData = new SimpleXMLElement($reader->readOuterXML());
                    $productId = (string)$xmlData['id'];

                    if (!$this->validateRequiredFields($xmlData)) {
                        $this->warn("Skipping product $productId due to missing required fields");
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

                    // update created_at field
                    if (!DB::table('products')->where('id', $productData['id'])->exists()) {
                        $productData['created_at'] = $productData['updated_at'];
                    }

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

    private function validateRequiredFields($xmlData): bool
    {
        return !empty((string)$xmlData['id'])
            && !empty($xmlData->name)
            && !empty($xmlData->price);
    }

    private function processParameters(array $product, $params): void
    {
        $attributes = [];
        foreach ($params as $param) {
            $name = (string)$param['name'];
            $value = (string)$param;

            $attributes[] = [
                'product_id' => $product['id'],
                'name' => $name,
                'value' => $value,
                'filter_key' => $this->transliterateToKey($name),
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        if (!empty($attributes)) {
            foreach (array_chunk($attributes, 100) as $chunk) {
                DB::table('product_attributes')->upsert(
                    $chunk,
                    ['product_id', 'name'],
                    ['value', 'filter_key', 'updated_at']
                );
            }
        }

        DB::table('product_attributes')
            ->where('product_id', $product['id'])
            ->whereNotIn('name', array_column($attributes, 'name'))
            ->delete();
    }

    private function transliterateToKey($text): string
    {
        return Str::slug($text);
    }

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

        if (!empty($newImages)) {
            foreach (array_chunk($newImages, 100) as $chunk) {
                DB::table('product_images')->insert($chunk);
            }
        }

        DB::table('product_images')
            ->where('product_id', $product['id'])
            ->whereNotIn('image_url', array_column($newImages, 'image_url'))
            ->delete();
    }

    private function processCategories($product, $categoryId): void
    {
        $categoryIds = $categoryId instanceof SimpleXMLElement ? [$categoryId] : (array)$categoryId;
        $categories = [];

        foreach ($categoryIds as $id) {
            $id = (string)$id;
            if (!empty($id)) {
                if (!isset($this->categoryCache[$id])) {
                    $this->categoryCache[$id] = DB::table('categories')->find($id) !== null;
                }

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

    private function importCategories(XMLReader $reader): void
    {
        $this->info('Import categories...');
        $categories = [];

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

        usort($categories, function ($a, $b) {
           if ($a['parent_id'] == $b['parent_id']) return -1;
           if (!empty($a['parent_id']) && empty($b['parent_id'])) return 1;
           return 0;
        });

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
}
