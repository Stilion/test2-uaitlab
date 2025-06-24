<?php

namespace App\Services;

use App\Models\Product;
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

        Log::info('Final SQL query:', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings()
        ]);

        $paginator = $query->paginate($limit, ['*'], 'page', $page);

        Log::info('Query results:', [
            'total' => $paginator->total(),
            'items_count' => count($paginator->items())
        ]);

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
            'brend' => 'Бренд',
            'kolir' => 'Колiр',
            'rozmir-postacalnika' => 'Розмiр постачальника',
        ];

        $result = [];

        // If there are no active filters, return the base structure
        if (empty($activeFilters)) {
            foreach ($availableFilters as $slug => $name) {
                // Get all keys for the current filter
                $filterPattern = self::REDIS_PREFIX . $slug . ':*';
                $filterKeys = $this->redis->keys($filterPattern);

                $values = [];
                $processedValues = []; // To track already processed values

                foreach ($filterKeys as $filterKey) {
                    // We get the filter value from the key
                    $parts = explode(':', $filterKey);
                    $filterValue = end($parts);

                    // Skipping already processed values
                    if (in_array($filterValue, $processedValues)) {
                        continue;
                    }
                    $processedValues[] = $filterValue;

                    // We get the number of products for this value
                    $count = $this->redis->scard($filterKey);

                    // Check if the value is active
                    $isActive = isset($activeFilters[$slug]) &&
                        (is_array($activeFilters[$slug])
                            ? in_array($filterValue, $activeFilters[$slug])
                            : $activeFilters[$slug] === $filterValue);

                    if ($count > 0) {
                        $values[] = [
                            'value' => $filterValue,
                            'count' => $count,
                            'active' => $isActive
                        ];
                    }
                }

                // Sort values alphabetically
                usort($values, function($a, $b) {
                    return strcmp($a['value'], $b['value']);
                });

                $result[] = [
                    'name' => $name,
                    'slug' => $slug,
                    'values' => $values
                ];
            }

            return $result;
        }

        // Processing active filters
        foreach ($activeFilters as $filterName => $filterValues) {
            // Skipping filters that are not in the list of available ones
            if (!isset($availableFilters[$filterName])) {
                continue;
            }

            $values = [];
            $filterValues = (array)$filterValues; // Преобразуем в массив, если пришло одно значение

            foreach ($filterValues as $value) {
                $decodedValue = urldecode($value);
                $filterKey = self::REDIS_PREFIX . "$filterName:$decodedValue";

                $matchingKeys = $this->redis->keys($filterKey);

                foreach ($matchingKeys as $key) {
                    $count = $this->redis->scard($key);

                    if ($count > 0) {
                        $values[] = [
                            'value' => $value,
                            'count' => $count,
                            'active' => true
                        ];
                    }
                }
            }

            // Also add other possible values for this filter
            $allFilterKeys = $this->redis->keys(self::REDIS_PREFIX . "$filterName:*");
            foreach ($allFilterKeys as $key) {
                $parts = explode(':', $key);
                $filterValue = end($parts);

                // Skipping already added active values
                if (in_array($filterValue, $filterValues)) {
                    continue;
                }

                $count = $this->redis->scard($key);
                if ($count > 0) {
                    $values[] = [
                        'value' => $filterValue,
                        'count' => $count,
                        'active' => false
                    ];
                }
            }

            if (!empty($values)) {
                $result[] = [
                    'name' => $availableFilters[$filterName],
                    'slug' => $filterName,
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
        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                $orSets = [];
                foreach ($value as $val) {
                    if ($members = $this->getRedisMembers($key, $val)) {
                        $orSets[] = $members;
                    }
                }

                // Combine results for one filter type (OR)
                if (!empty($orSets)) {
                    $mergedSet = array_unique(array_merge(...$orSets));
                    Log::info('Merged OR set:', [
                        'filter' => $key,
                        'count' => count($mergedSet),
                        'first_few' => array_slice($mergedSet, 0, 5)
                    ]);
                    $filterSets[] = $mergedSet;
                }
            } else {
                if ($members = $this->getRedisMembers($key, $value)) {
                    $filterSets[] = $members;
                }
            }
        }

        // Find the intersection of all sets (AND between different filter types)
        if (empty($filterSets)) {
            Log::info('No filter sets found');
            return [];
        }

        $result = array_shift($filterSets);
        foreach ($filterSets as $set) {
            $result = array_intersect($result, $set);
        }

        return array_values($result);
    }

    /**
     * @param string $key
     * @param string $value
     * @return array|null
     */
    private function getRedisMembers(string $key, string $value): ?array
    {
        $decodedValue = urldecode($value);
        $redisKey = self::REDIS_PREFIX . "$key:$decodedValue";

        $members = $this->redis->smembers($redisKey);
        if (empty($members)) {
            Log::warning('Redis key is empty or not found:', ['key' => $redisKey]);
            return null;
        }

        return $members;
    }
}
