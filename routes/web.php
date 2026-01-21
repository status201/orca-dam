<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('assets.index');
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Asset routes
    Route::resource('assets', AssetController::class);

    // Asset download
    Route::get('assets/{asset}/download', [AssetController::class, 'download'])->name('assets.download');

    // Trash routes (admin only)
    Route::middleware(['can:restore,App\Models\Asset'])->group(function () {
        Route::get('assets/trash/index', [AssetController::class, 'trash'])->name('assets.trash');
        Route::post('assets/{id}/restore', [AssetController::class, 'restore'])->name('assets.restore');
        Route::delete('assets/{id}/force-delete', [AssetController::class, 'forceDelete'])->name('assets.force-delete');
    });

    // AI tagging
    Route::post('assets/{asset}/ai-tag', [AssetController::class, 'generateAiTags'])->name('assets.ai-tag');

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

    // System administration routes (admin only)
    Route::middleware(['can:access,App\Http\Controllers\SystemController'])->group(function () {
        Route::get('system', [SystemController::class, 'index'])->name('system.index');
        Route::get('system/queue-status', [SystemController::class, 'queueStatus'])->name('system.queue-status');
        Route::get('system/logs', [SystemController::class, 'logs'])->name('system.logs');
        Route::post('system/execute-command', [SystemController::class, 'executeCommand'])->name('system.execute-command');
        Route::get('system/test-s3', [SystemController::class, 'testS3'])->name('system.test-s3');
        Route::post('system/retry-job', [SystemController::class, 'retryJob'])->name('system.retry-job');
        Route::post('system/flush-queue', [SystemController::class, 'flushQueue'])->name('system.flush-queue');
        Route::post('system/restart-queue', [SystemController::class, 'restartQueue'])->name('system.restart-queue');
        Route::get('system/supervisor-status', [SystemController::class, 'supervisorStatus'])->name('system.supervisor-status');
        Route::post('system/settings', [SystemController::class, 'updateSetting'])->name('system.update-setting');
        Route::get('system/documentation', [SystemController::class, 'documentation'])->name('system.documentation');
        Route::post('system/run-tests', [SystemController::class, 'runTests'])->name('system.run-tests');
    });
});

require __DIR__.'/auth.php';
