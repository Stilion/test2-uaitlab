<?php

namespace App\Http\Controllers;

use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    private CatalogService $catalogService;

    /**
     * @param CatalogService $catalogService
     */
    public function __construct(CatalogService $catalogService)
    {
        $this->catalogService = $catalogService;
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getProducts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'integer|min:1',
            'limit' => 'integer|min:1|max:100',
            'sort_by' => ['string', Rule::in(['price_asc', 'price_desc', 'id_asc'])],
            'filter' => 'array',
            'filter.*' => ['required'],
            'filter.*.*' => ['string']
        ]);

        $page = $validated['page'] ?? 1;
        $limit = $validated['limit'] ?? 10;
        $sortBy = $validated['sort_by'] ?? 'id_asc';
        $filters = $validated['filter'] ?? [];

        $result = $this->catalogService->getProducts($page, $limit, $sortBy, $filters);

        return response()->json($result);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getFilters(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'filter' => 'array'
        ]);

        $activeFilters = $filters['filter'] ?? [];
        $result = $this->catalogService->getFilters($activeFilters);

        return response()->json($result);
    }

    public function testRedisKeys(): JsonResponse
    {
        $pattern = 'laravel_database_filter:kolir:*';

        // Getting all the keys
        $keys = Redis::keys($pattern);
        Log::info('Found Redis keys:', ['pattern' => $pattern, 'keys' => $keys]);

        // Testing a specific key
        $testKey = 'laravel_database_filter:kolir:чорний';
        $members = Redis::smembers($testKey);

        Log::info('Test specific key:', [
            'key' => $testKey,
            'exists_check' => Redis::exists($testKey),
            'members_count' => count($members),
            'first_few_members' => array_slice($members, 0, 5)
        ]);

        return response()->json([
            'pattern' => $pattern,
            'keys' => $keys,
            'test_key' => [
                'key' => $testKey,
                'exists' => Redis::exists($testKey),
                'members_count' => count($members),
                'first_few_members' => array_slice($members, 0, 5)
            ]
        ]);
    }
}
