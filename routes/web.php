<?php

use App\Http\Controllers\ApiDocsController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\JwtSecretController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TokenController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('assets.index');
});

// Test error pages (admin only)
Route::middleware(['auth', 'can:access,App\Http\Controllers\SystemController'])->get('/test-error/{code}', function ($code) {
    abort((int) $code);
});

// CSRF token refresh endpoint (keeps session alive)
Route::middleware(['web'])->get('/csrf-token', function () {
    return response()->json(['token' => csrf_token()]);
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Bulk asset tag management (must be before resource route)
    Route::post('assets/bulk/tags', [AssetController::class, 'bulkAddTags'])->name('assets.bulk.tags.add');
    Route::post('assets/bulk/tags/remove', [AssetController::class, 'bulkRemoveTags'])->name('assets.bulk.tags.remove');
    Route::post('assets/bulk/tags/list', [AssetController::class, 'bulkGetTags'])->name('assets.bulk.tags.list');
    Route::post('assets/bulk/move', [AssetController::class, 'bulkMoveAssets'])->name('assets.bulk.move');
    Route::delete('assets/bulk/force-delete', [AssetController::class, 'bulkForceDelete'])->name('assets.bulk.force-delete');

    // Asset routes
    Route::resource('assets', AssetController::class);

    // Asset download
    Route::get('assets/{asset}/download', [AssetController::class, 'download'])->name('assets.download');

    // Asset replace
    Route::get('assets/{asset}/replace', [AssetController::class, 'showReplace'])->name('assets.replace');
    Route::post('assets/{asset}/replace', [AssetController::class, 'replace'])->name('assets.replace.store');

    // Trash routes (admin only)
    Route::middleware(['can:restore,App\Models\Asset'])->group(function () {
        Route::get('assets/trash/index', [AssetController::class, 'trash'])->name('assets.trash');
        Route::post('assets/{id}/restore', [AssetController::class, 'restore'])->name('assets.restore');
        Route::delete('assets/{id}/force-delete', [AssetController::class, 'forceDelete'])->name('assets.force-delete');
    });

    // AI tagging
    Route::post('assets/{asset}/ai-tag', [AssetController::class, 'generateAiTags'])->name('assets.ai-tag');

    // Video thumbnail
    Route::post('assets/{asset}/thumbnail', [AssetController::class, 'storeThumbnail'])->name('assets.thumbnail.store');

    // Asset tag management
    Route::post('assets/{asset}/tags', [AssetController::class, 'addTags'])->name('assets.tags.add');
    Route::delete('assets/{asset}/tags/{tag}', [AssetController::class, 'removeTag'])->name('assets.tags.remove');

    // Tag routes
    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::get('tags/search', [TagController::class, 'search'])->name('tags.search');
    Route::post('tags/by-ids', [TagController::class, 'byIds'])->name('tags.byIds');
    Route::patch('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
    Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

    // Folder management (admin only)
    Route::middleware(['can:discover,App\Models\Asset'])->group(function () {
        Route::post('folders/scan', [FolderController::class, 'scan'])->name('folders.scan');
        Route::post('folders', [FolderController::class, 'store'])->name('folders.store');
    });

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
        Route::post('system/process-queue', [SystemController::class, 'processQueue'])->name('system.process-queue');
        Route::get('system/supervisor-status', [SystemController::class, 'supervisorStatus'])->name('system.supervisor-status');
        Route::post('system/settings', [SystemController::class, 'updateSetting'])->name('system.update-setting');
        Route::get('system/documentation', [SystemController::class, 'documentation'])->name('system.documentation');
        Route::post('system/regenerate-resized-images', [SystemController::class, 'regenerateResizedImages'])->name('system.regenerate-resized-images');
        Route::get('system/integrity-status', [SystemController::class, 'integrityStatus'])->name('system.integrity-status');
        Route::post('system/verify-integrity', [SystemController::class, 'verifyIntegrity'])->name('system.verify-integrity');
        Route::post('system/run-tests', [SystemController::class, 'runTests'])->name('system.run-tests');

        // Import metadata
        Route::get('import', [ImportController::class, 'index'])->name('import.index');
        Route::post('import/preview', [ImportController::class, 'preview'])->name('import.preview');
        Route::post('import/import', [ImportController::class, 'import'])->name('import.import');

        // API Documentation page
        Route::get('api-docs', [ApiDocsController::class, 'index'])->name('api.index');
        Route::get('api-docs/dashboard', [ApiDocsController::class, 'dashboard'])->name('api.dashboard');
        Route::post('api-docs/settings', [ApiDocsController::class, 'updateSettings'])->name('api.settings.update');

        // API Token management (moved from system to api-docs)
        Route::get('api-docs/tokens', [TokenController::class, 'index'])->name('api.tokens');
        Route::post('api-docs/tokens', [TokenController::class, 'store'])->name('api.tokens.store');
        Route::delete('api-docs/tokens/user/{userId}', [TokenController::class, 'destroyUserTokens'])->name('api.tokens.destroy-user');
        Route::delete('api-docs/tokens/{id}', [TokenController::class, 'destroy'])->name('api.tokens.destroy');

        // JWT Secret management
        Route::get('api-docs/jwt-secrets', [JwtSecretController::class, 'index'])->name('api.jwt-secrets');
        Route::post('api-docs/jwt-secrets/{user}', [JwtSecretController::class, 'generate'])->name('api.jwt-secrets.generate');
        Route::delete('api-docs/jwt-secrets/{user}', [JwtSecretController::class, 'revoke'])->name('api.jwt-secrets.revoke');
    });
});

require __DIR__.'/auth.php';
