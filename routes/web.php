<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('assets.index');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth'])->group(function () {
	Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
	
    // Asset routes
    Route::resource('assets', AssetController::class);

    // Asset download
    Route::get('assets/{asset}/download', [AssetController::class, 'download'])->name('assets.download');

    // Asset tag management
    Route::post('assets/{asset}/tags', [AssetController::class, 'addTags'])->name('assets.tags.add');
    Route::delete('assets/{asset}/tags/{tag}', [AssetController::class, 'removeTag'])->name('assets.tags.remove');
    
    // Tag routes
    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::get('tags/search', [TagController::class, 'search'])->name('tags.search');
    Route::patch('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');
    
    // Discover routes (admin only)
    Route::middleware(['can:discover,App\Models\Asset'])->group(function () {
        Route::get('discover', [DiscoverController::class, 'index'])->name('discover.index');
        Route::post('discover/scan', [DiscoverController::class, 'scan'])->name('discover.scan');
        Route::post('discover/import', [DiscoverController::class, 'import'])->name('discover.import');
    });

    // Export routes (admin only)
    Route::middleware(['can:export,App\Models\Asset'])->group(function () {
        Route::get('export', [ExportController::class, 'index'])->name('export.index');
        Route::post('export', [ExportController::class, 'export'])->name('export.download');
    });

    // User management routes (admin only)
    Route::resource('users', UserController::class)->except(['show']);
});

require __DIR__.'/auth.php';
