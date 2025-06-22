<?php

use App\Http\Controllers\CatalogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('catalog')->group(function () {
    Route::get('/test-redis', [CatalogController::class, 'testRedisKeys']);
    Route::get('/products', [CatalogController::class, 'getProducts']);
    Route::get('/filters', [CatalogController::class, 'getFilters']);
});
