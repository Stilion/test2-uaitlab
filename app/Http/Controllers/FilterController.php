<?php

namespace App\Http\Controllers;

use App\Services\FilterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FilterController extends Controller
{
    private FilterService $filterService;

    public function __construct(FilterService $filterService)
    {
        $this->filterService = $filterService;
    }

    public function getCounts(Request $request): JsonResponse
    {
        $appliedFilters = $request->all();
        $counts = $this->filterService->getFilterCounts($appliedFilters);

        return response()->json($counts);
    }

    public function getProducts(Request $request): JsonResponse
    {
        $filters = $request->all();
        $products = $this->filterService->getFilteredProducts($filters);

        return response()->json($products);
    }

    public function getAvailableFilters(): JsonResponse
    {
        $filters = $this->filterService->getAvailableFilters();
        return response()->json($filters);
    }
}
