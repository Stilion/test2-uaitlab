<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Redis;

class CatalogService
{
    private const REDIS_PREFIX = 'laravel_database_filter:';

    /**
     * @var Redis
     */
    private Redis $redis;

    /**
     *  Constructor
     */
    public function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect(
            config('database.redis.default.host'),
            config('database.redis.default.port')
        );
        $this->redis->setOption(Redis::OPT_PREFIX, '');
    }

    /**
     * @param int $page
     * @param int $limit
     * @param string $sortBy
     * @param array $filters
     * @return array
     */
    public function getProducts(int $page, int $limit, string $sortBy, array $filters): array
    {
        $query = Product::query()->with(['attributes', 'images', 'categories']);

        if (!empty($filters)) {
            $productIds = $this->getFilteredProductIds($filters);

            if (empty($productIds)) {
                Log::info('No products found matching filters');
                return [
                    'data' => [],
                    'meta' => [
                        'current_page' => $page,
                        'last_page' => 0,
                        'per_page' => $limit,
                        'total' => 0
                    ]
                ];
            }

            $query->whereIn('id', $productIds);
        }

        // applying sorting
        switch ($sortBy) {
            case 'price_asc':
                $query->orderBy('price');
                break;
            case 'price_desc':
                $query->orderBy('price', 'desc');
                break;
            default:
                $query->orderBy('id');
        }

        $paginator = $query->paginate($limit, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total()
            ]
        ];
    }

    /**
     * @param array $activeFilters
     * @return array
     */
    public function getFilters(array $activeFilters): array
    {
        $availableFilters = [
            'category' => 'Категорiї',
            'brend' => 'Бренд',
            'kolir' => 'Колiр',
            'rozmir-postacalnika' => 'Розмiр постачальника',
            'sklad' => 'Склад',
            'price' => 'Цiна'
        ];

        $result = [];

        // If there are active filters, first get the IDs of the products that match these filters
        $filteredProductIds = [];
        if (!empty($activeFilters)) {
            $filteredProductIds = $this->getFilteredProductIds($activeFilters);
        }

        //load all categories
        $categoriesMap = [];
        if (in_array('category', array_keys($availableFilters))) {
            $categories = DB::table('categories')->get(['id', 'name']);
            foreach ($categories as $category) {
                $categoriesMap[$category->id] = $category->name;
            }
        }

        // We process all available filters
        foreach ($availableFilters as $slug => $name) {
            $values = [];
            $processedValues = []; // To track already processed values

            // Get all keys for the current filter
            $filterPattern = self::REDIS_PREFIX . $slug . ':*';
            $filterKeys = $this->redis->keys($filterPattern);

            foreach ($filterKeys as $filterKey) {
                // We get the filter value from the key
                $parts = explode(':', $filterKey);
                $filterValue = end($parts);

                // Skipping already processed values
                if (in_array($filterValue, $processedValues)) {
                    continue;
                }
                $processedValues[] = $filterValue;

                // If there are active filters, we check for intersection
                if (!empty($filteredProductIds)) {
                    $productIds = $this->redis->sMembers($filterKey);
                    $count = count(array_intersect($filteredProductIds, $productIds));
                } else {
                    $count = $this->redis->scard($filterKey);
                }

                if ($count > 0) {
                    // use name for categories
                    $displayValue = $slug === 'category' ? ($categoriesMap[$filterValue] ?? $filterValue) : $filterValue;

                    $values[] = [
                        'value' => $filterValue,
                        'display_value' => $displayValue,
                        'count' => $count,
                        'active' => isset($activeFilters[$slug]) &&
                            in_array($filterValue, (array)$activeFilters[$slug])
                    ];
                }
            }

            // Sort values alphabetically
            if (!empty($values)) {
                usort($values, function($a, $b) {
                    return strcmp($a['value'], $b['value']);
                });

                usort($values, function($a, $b) {
                    return strcmp($a['display_value'], $b['display_value']);
                });

                $result[] = [
                    'name' => $name,
                    'slug' => $slug,
                    'values' => $values
                ];
            }
        }

        return $result;
    }

    /**
     * @param array $filters
     * @return array
     */
    private function getFilteredProductIds(array $filters): array
    {
        $filterSets = [];

        foreach ($filters as $filterName => $filterValues) {
            $filterValues = (array)$filterValues;
            $filterSet = [];

            foreach ($filterValues as $value) {
                $filterKey = self::REDIS_PREFIX . $filterName . ':' . urldecode($value);
                $members = $this->redis->sMembers($filterKey);

                // For values of one filter we use union (OR)
                $filterSet = empty($filterSet) ? $members : array_unique(array_merge($filterSet, $members));
            }

            if (!empty($filterSet)) {
                $filterSets[] = $filterSet;
            }
        }

        if (empty($filterSets)) {
            return [];
        }

        // Find the intersection of all sets (AND between different filter types)
        $result = array_shift($filterSets);
        foreach ($filterSets as $set) {
            $result = array_intersect($result, $set);
        }

        return array_values($result);
    }
}
