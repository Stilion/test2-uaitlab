<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CatalogService
{
    private const REDIS_PREFIX = 'laravel_database_filter:';

    public function getProducts(int $page, int $limit, string $sortBy, array $filters): array
    {
        Log::info('Starting getProducts with filters:', ['filters' => $filters]);

        $query = Product::query()->with(['attributes']);

        if (!empty($filters)) {
            $productIds = $this->getFilteredProductIds($filters);
            Log::info('Found product IDs:', ['count' => count($productIds), 'ids' => $productIds]);

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

    public function getFilters(array $activeFilters): array
    {
        $result = [];
        $filterKeys = Redis::keys(self::REDIS_PREFIX . '*');

        foreach ($filterKeys as $filterKey) {
            $parts = explode(':', $filterKey);
            if (count($parts) >= 3) {
                $filterName = $parts[1];
                $filterValue = $parts[2];

                // Getting a base set of products for the current active filters
                $activeSet = $this->getActiveFilterSet($activeFilters);

                // Get the number of products for the current filter value
                $count = $activeSet ?
                    Redis::sintercard([$activeSet, $filterKey]) :
                    Redis::scard($filterKey);

                // Check if the current filter is active
                $isActive = isset($activeFilters[$filterName]) &&
                    (is_array($activeFilters[$filterName])
                        ? in_array($filterValue, $activeFilters[$filterName])
                        : $activeFilters[$filterName] === $filterValue);

                if (!isset($result[$filterName])) {
                    $result[$filterName] = [
                        'name' => $this->getFilterDisplayName($filterName),
                        'slug' => $filterName,
                        'values' => []
                    ];
                }

                if ($count > 0 || $isActive) {
                    $result[$filterName]['values'][] = [
                        'value' => $filterValue,
                        'count' => $count,
                        'active' => $isActive
                    ];
                }
            }
        }

        return array_values($result);
    }

    private function getFilteredProductIds(array $filters): array
    {
        Log::info('Getting filtered product IDs for filters:', ['filters' => $filters]);

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
            Log::info('After intersection:', [
                'count' => count($result),
                'first_few' => array_slice($result, 0, 5)
            ]);
        }

        $finalResult = array_values($result);
        Log::info('Final filtered product IDs:', [
            'count' => count($finalResult),
            'first_few' => array_slice($finalResult, 0, 5)
        ]);

        return $finalResult;
    }

    private function getRedisMembers(string $key, string $value): ?array
    {
        $redisKey = self::REDIS_PREFIX . "$key:$value";
        Log::info('Checking Redis key:', ['key' => $redisKey]);

        $members = Redis::smembers($redisKey);
        if (empty($members)) {
            Log::warning('Redis key is empty or not found:', ['key' => $redisKey]);
            return null;
        }

        Log::info('Redis members for key:', [
            'key' => $redisKey,
            'count' => count($members),
            'first_few' => array_slice($members, 0, 5)
        ]);

        return $members;
    }


    private function getActiveFilterSet(array $activeFilters): ?string
    {
        if (empty($activeFilters)) {
            return null;
        }

        $filterSets = [];
        foreach ($activeFilters as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $val) {
                    $filterSets[] = self::REDIS_PREFIX . "$key:$val";
                }
            } else {
                $filterSets[] = self::REDIS_PREFIX . "$key:$value";
            }
        }

        $tempKey = 'temp:' . uniqid();
        Redis::sinterstore($tempKey, ...$filterSets);
        Redis::expire($tempKey, 3600); // Set the lifetime to 1 hour

        return $tempKey;
    }

    private function getFilterDisplayName(string $filterKey): string
    {
        $displayNames = [
            'brend' => 'Бренд',
            'kolir' => 'Колiр',
            'rozmir' => 'Розмiр',
            'category' => 'Категорiя',
        ];

        return $displayNames[$filterKey] ?? ucfirst($filterKey);
    }
}
