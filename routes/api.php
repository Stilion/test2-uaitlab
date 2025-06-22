<?php

use App\Http\Controllers\FilterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/filters/counts', [FilterController::class, 'getCounts']);
Route::get('/filters/products', [FilterController::class, 'getProducts']);
Route::get('/filters/available', [FilterController::class, 'getAvailableFilters']);
