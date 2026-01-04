<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\DiscoverController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('assets.index');
});

Route::middleware(['auth'])->group(function () {
    // Asset routes
    Route::resource('assets', AssetController::class);
    
    // Asset tag management
    Route::post('assets/{asset}/tags', [AssetController::class, 'addTags'])->name('assets.tags.add');
    Route::delete('assets/{asset}/tags/{tag}', [AssetController::class, 'removeTag'])->name('assets.tags.remove');
    
    // Tag routes
    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::get('tags/search', [TagController::class, 'search'])->name('tags.search');
    
    // Discover routes (admin only)
    Route::middleware(['can:discover,App\Models\Asset'])->group(function () {
        Route::get('discover', [DiscoverController::class, 'index'])->name('discover.index');
        Route::post('discover/scan', [DiscoverController::class, 'scan'])->name('discover.scan');
        Route::post('discover/import', [DiscoverController::class, 'import'])->name('discover.import');
    });
});

require __DIR__.'/auth.php';
