<?php

use App\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

Route::prefix('catalog')->group(function () {
    Route::get('/test-redis', [CatalogController::class, 'testRedisKeys']);
    Route::get('/products', [CatalogController::class, 'getProducts']);
    Route::get('/filters', [CatalogController::class, 'getFilters']);
});
