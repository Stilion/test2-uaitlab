<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class FilterService
{
    public function getFilterCounts(array $appliedFilters = []): array
    {
        $counts = [];
        $baseSet = 'products:all';

        // If there are filters applied, we find the intersection
        if (!empty($appliedFilters)) {
            $intersectKeys = array_map(function ($filter, $value) {
                return "filter:{$filter}:{$value}";
            }, array_keys($appliedFilters), $appliedFilters);

            $baseSet = $this->getIntersection($intersectKeys);
        }

        // Getting the quantity for each possible filter value
        $filterKeys = Redis::keys('filter:*');
        foreach ($filterKeys as $filterKey) {
            $count = Redis::sinter($baseSet, $filterKey);
            if ($count > 0) {
                // Convert the filter:category:1 key to a structured array
                $keyParts = explode(':', $filterKey);
                $type = $keyParts[1];
                $value = $keyParts[2];

                $counts[$type][$value] = count($count);
            }
        }

        return $counts;
    }

    private function getIntersection(array $keys): string
    {
        $tempKey = 'temp:' . uniqid();
        Redis::sinterstore($tempKey, ...$keys);

        // Set the lifetime for the temporary key (for example, 1 hour)
        Redis::expire($tempKey, 3600);

        return $tempKey;
    }

    public function getFilteredProducts(array $filters = []): array
    {
        if (empty($filters)) {
            return Redis::smembers('products:all');
        }

        $filterKeys = array_map(function ($filter, $value) {
            return "filter:{$filter}:{$value}";
        }, array_keys($filters), $filters);

        return Redis::sinter(...$filterKeys);
    }

    public function getAvailableFilters(): array
    {
        $filters = [];
        $keys = Redis::keys('filter:*');

        foreach ($keys as $key) {
            $parts = explode(':', $key);
            if (count($parts) >= 3) {
                $type = $parts[1];
                $value = $parts[2];
                $count = Redis::scard($key); // get a quantity of products for this filter

                $filters[$type][$value] = $count;
            }
        }

        return $filters;
    }
}
