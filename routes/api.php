<?php

use App\Http\Controllers\Api\AssetApiController;
use App\Http\Controllers\ChunkedUploadController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\TagController;
use Illuminate\Support\Facades\Route;

// Public API endpoints (no authentication required)
Route::get('assets/meta', [AssetApiController::class, 'getMeta']);
Route::get('health', \App\Http\Controllers\Api\HealthController::class);

Route::middleware('auth.multi')->group(function () {
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

    // Reference Tags API
    Route::post('reference-tags', [AssetApiController::class, 'addReferenceTags']);
    Route::delete('reference-tags/{tag}', [AssetApiController::class, 'removeReferenceTag']);

    // Folders API
    Route::get('folders', [FolderController::class, 'index'])->name('folders.index');
});

// Chunked upload endpoints
Route::middleware(['auth.multi', 'throttle:100,1'])->prefix('chunked-upload')->group(function () {
    Route::post('init', [ChunkedUploadController::class, 'initiate']);
    Route::post('chunk', [ChunkedUploadController::class, 'uploadChunk']);
    Route::post('complete', [ChunkedUploadController::class, 'complete']);
    Route::post('abort', [ChunkedUploadController::class, 'abort']);
});
