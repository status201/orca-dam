<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssetTrashController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected S3Service $s3Service) {}

    /**
     * Soft delete the specified asset (does NOT delete S3 objects)
     */
    public function destroy(Asset $asset)
    {
        $this->authorize('delete', $asset);

        $asset->delete();

        if (request()->expectsJson()) {
            return response()->json([
                'message' => 'Asset moved to trash successfully',
            ]);
        }

        return redirect()->route('assets.index')
            ->with('success', 'Asset moved to trash successfully');
    }

    /**
     * Show trash page with soft-deleted assets
     */
    public function index()
    {
        $this->authorize('restore', Asset::class);

        $perPage = Auth::user()->getItemsPerPage();
        $assets = Asset::onlyTrashed()
            ->with(['user', 'tags'])
            ->orderBy('deleted_at', 'desc')
            ->paginate($perPage);

        return view('assets.trash', compact('assets'));
    }

    /**
     * Restore a soft-deleted asset
     */
    public function restore(Asset $asset)
    {
        $this->authorize('restore', $asset);

        $asset->restore();

        if (request()->expectsJson()) {
            return response()->json([
                'message' => 'Asset restored successfully',
            ]);
        }

        return redirect()->route('assets.trash')
            ->with('success', 'Asset restored successfully');
    }

    /**
     * Permanently delete asset and remove S3 objects
     */
    public function forceDelete(Asset $asset)
    {
        $this->authorize('forceDelete', $asset);

        $this->s3Service->deleteAssetFiles($asset);
        $asset->forceDelete();

        if (request()->expectsJson()) {
            return response()->json([
                'message' => 'Asset permanently deleted successfully',
            ]);
        }

        return redirect()->route('assets.trash')
            ->with('success', 'Asset permanently deleted successfully');
    }

    /**
     * Soft delete multiple assets (move to trash)
     */
    public function bulkTrash(Request $request)
    {
        $this->authorize('bulkTrash', Asset::class);

        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
        ]);

        $assets = Asset::whereIn('id', $request->asset_ids)->get();
        $trashed = 0;
        $failed = 0;

        foreach ($assets as $asset) {
            try {
                $asset->delete();
                $trashed++;
            } catch (\Exception $e) {
                Log::error("Bulk trash failed for asset {$asset->id}: ".$e->getMessage());
                $failed++;
            }
        }

        return response()->json([
            'message' => __(':trashed asset(s) moved to trash', ['trashed' => $trashed]),
            'trashed' => $trashed,
            'failed' => $failed,
        ]);
    }

    /**
     * Bulk restore trashed assets
     */
    public function bulkRestore(Request $request)
    {
        $this->authorize('bulkRestore', Asset::class);

        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer',
        ]);

        $assets = Asset::onlyTrashed()->whereIn('id', $request->asset_ids)->get();
        $restored = 0;
        $failed = 0;
        $restoredFilenames = [];

        foreach ($assets as $asset) {
            try {
                $asset->restore();
                $restoredFilenames[] = $asset->filename;
                $restored++;
            } catch (\Exception $e) {
                Log::error("Bulk restore failed for asset {$asset->id}: ".$e->getMessage());
                $failed++;
            }
        }

        return response()->json([
            'message' => __(':restored asset(s) restored', ['restored' => $restored]),
            'restored' => $restored,
            'failed' => $failed,
            'restored_filenames' => $restoredFilenames,
        ]);
    }

    /**
     * Bulk permanently delete trashed assets and their S3 objects
     */
    public function bulkForceDeleteTrashed(Request $request)
    {
        $this->authorize('forceDelete', Asset::class);

        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer',
        ]);

        $assets = Asset::onlyTrashed()->whereIn('id', $request->asset_ids)->get();
        $deleted = 0;
        $failed = 0;
        $deletedKeys = [];

        foreach ($assets as $asset) {
            try {
                $this->s3Service->deleteAssetFiles($asset);
                DB::transaction(function () use ($asset) {
                    $asset->forceDelete();
                });
                $deletedKeys[] = $asset->s3_key;
                $deleted++;
            } catch (\Exception $e) {
                Log::error("Bulk force delete (trash) failed for asset {$asset->id}: ".$e->getMessage());
                $failed++;
            }
        }

        return response()->json([
            'message' => __(':deleted asset(s) permanently deleted', ['deleted' => $deleted]),
            'deleted' => $deleted,
            'failed' => $failed,
            'deleted_keys' => $deletedKeys,
        ]);
    }
}
