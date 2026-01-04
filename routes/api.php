<?php

use App\Http\Controllers\Api\AssetApiController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Asset API
    Route::get('assets', [AssetApiController::class, 'index']);
    Route::post('assets', [AssetApiController::class, 'store']);
    Route::get('assets/search', [AssetApiController::class, 'search']);
    Route::get('assets/{asset}', [AssetApiController::class, 'show']);
    Route::patch('assets/{asset}', [AssetApiController::class, 'update']);
    Route::delete('assets/{asset}', [AssetApiController::class, 'destroy']);
    
    // Tags API
    Route::get('tags', [TagController::class, 'index']);
    Route::get('tags/search', [TagController::class, 'search']);
});
