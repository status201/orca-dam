<?php

namespace App\Http\Controllers;

use App\Http\Concerns\ForceDeletesAssets;
use App\Models\Asset;
use App\Services\S3Service;
use App\Support\BulkAssetIds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AssetTrashController extends Controller
{
    use ForceDeletesAssets;

    public function __construct(protected S3Service $s3Service) {}

    /**
     * Soft delete the specified asset (does NOT delete S3 objects)
     */
    public function destroy(Asset $asset)
    {
        $this->authorize('delete', $asset);

        $asset->delete();

        return $this->successResponse(__('Asset moved to trash successfully'), 'assets.index');
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

        return $this->successResponse(__('Asset restored successfully'), 'assets.trash');
    }

    /**
     * Permanently delete asset and remove S3 objects
     */
    public function forceDelete(Asset $asset)
    {
        $this->authorize('forceDelete', $asset);

        $this->s3Service->deleteAssetFiles($asset);
        $asset->forceDelete();

        return $this->successResponse(__('Asset permanently deleted successfully'), 'assets.trash');
    }

    /**
     * Soft delete multiple assets (move to trash)
     */
    public function bulkTrash(Request $request)
    {
        $this->authorize('bulkTrash', Asset::class);

        $request->validate(BulkAssetIds::rules());

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

        $request->validate(BulkAssetIds::rules(mustExist: false));

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

        $request->validate(BulkAssetIds::rules(mustExist: false));

        $result = $this->forceDeleteAssets(
            Asset::onlyTrashed()->whereIn('id', $request->asset_ids)->get(),
            'Bulk force delete (trash)',
        );

        return response()->json([
            'message' => __(':deleted asset(s) permanently deleted', ['deleted' => $result['deleted']]),
        ] + $result);
    }
}
