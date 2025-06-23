<?php

namespace App\Http\Controllers;

use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
}
