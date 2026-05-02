<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Tag;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AssetBulkController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected S3Service $s3Service) {}

    /**
     * Add tags to multiple assets at once
     */
    public function bulkAddTags(Request $request)
    {
        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
            'tags' => 'required_without:reference_tag_ids|array',
            'tags.*' => 'string|max:50',
            'reference_tag_ids' => 'required_without:tags|array',
            'reference_tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($q) => $q->where('type', 'reference')),
            ],
        ]);

        $tagNames = (array) $request->input('tags', []);
        $referenceIds = array_map('intval', (array) $request->input('reference_tag_ids', []));

        $tagIds = Tag::resolveUserTagIds($tagNames);
        $assets = Asset::whereIn('id', $request->asset_ids)->get();

        foreach ($assets as $asset) {
            $this->authorize('update', $asset);
        }

        DB::transaction(function () use ($assets, $tagIds, $referenceIds, $request) {
            foreach ($assets as $asset) {
                if (! empty($tagIds)) {
                    $asset->syncTagsWithAttribution($tagIds, 'user');
                }
                if (! empty($referenceIds)) {
                    $asset->syncTagsWithAttribution($referenceIds, 'reference');
                }
            }
            Asset::whereIn('id', $request->asset_ids)->update(['last_modified_by' => Auth::id()]);
        });

        return response()->json([
            'message' => __(':count asset(s) updated', ['count' => $assets->count()]),
        ]);
    }

    /**
     * Remove tags from multiple assets at once
     */
    public function bulkRemoveTags(Request $request)
    {
        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
            'tag_ids' => 'required|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        $assets = Asset::whereIn('id', $request->asset_ids)->get();

        foreach ($assets as $asset) {
            $this->authorize('update', $asset);
        }

        DB::transaction(function () use ($assets, $request) {
            foreach ($assets as $asset) {
                $asset->tags()->detach($request->tag_ids);
            }
            Asset::whereIn('id', $request->asset_ids)->update(['last_modified_by' => Auth::id()]);
        });

        return response()->json([
            'message' => __(':count asset(s) updated', ['count' => $assets->count()]),
        ]);
    }

    /**
     * Get all tags across multiple assets with per-tag count
     */
    public function bulkGetTags(Request $request)
    {
        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
        ]);

        $assets = Asset::with('tags')->whereIn('id', $request->asset_ids)->get();

        $tagCounts = [];
        foreach ($assets as $asset) {
            foreach ($asset->tags as $tag) {
                if (! isset($tagCounts[$tag->id])) {
                    $tagCounts[$tag->id] = [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'type' => $tag->type,
                        'count' => 0,
                    ];
                }
                $tagCounts[$tag->id]['count']++;
            }
        }

        $tags = array_values($tagCounts);
        usort($tags, function ($a, $b) {
            return $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']);
        });

        return response()->json([
            'tags' => $tags,
            'total_assets' => $assets->count(),
        ]);
    }

    /**
     * Permanently delete multiple assets and their S3 objects
     */
    public function bulkForceDelete(Request $request)
    {
        $this->authorize('bulkForceDelete', Asset::class);

        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
        ]);

        $assets = Asset::whereIn('id', $request->asset_ids)->get();
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
                Log::error("Bulk force delete failed for asset {$asset->id}: ".$e->getMessage());
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

    /**
     * Move multiple assets to a different S3 folder
     */
    public function bulkMove(Request $request)
    {
        $this->authorize('move', Asset::class);

        $configuredFolders = S3Service::getConfiguredFolders();

        $request->validate([
            'asset_ids' => 'required|array|max:500',
            'asset_ids.*' => 'integer|exists:assets,id',
            'destination_folder' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9\/_-]+$/', function ($attribute, $value, $fail) use ($configuredFolders) {
                $folder = rtrim($value, '/');
                $isConfigured = collect($configuredFolders)->contains(function ($configured) use ($folder) {
                    return $folder === $configured || str_starts_with($folder.'/', $configured.'/');
                });
                if (! $isConfigured) {
                    $fail(__('The destination folder must be within a configured S3 folder.'));
                }
            }],
        ]);

        $destinationFolder = rtrim($request->input('destination_folder'), '/');
        $assets = Asset::whereIn('id', $request->asset_ids)->get();
        $moved = 0;
        $failed = 0;
        $moves = [];

        foreach ($assets as $asset) {
            $oldS3Key = $asset->s3_key;
            $oldDir = dirname($oldS3Key);

            if ($oldDir === $destinationFolder) {
                continue;
            }

            $newS3Key = $destinationFolder.'/'.basename($oldS3Key);

            if (! $this->s3Service->moveObject($oldS3Key, $newS3Key)) {
                $failed++;

                continue;
            }

            $updateData = ['s3_key' => $newS3Key];

            $derivedKeys = $this->s3Service->computeDerivedKeys($newS3Key);
            foreach ($derivedKeys as $column => $newKey) {
                if ($asset->{$column} && $newKey !== $asset->{$column}) {
                    $this->s3Service->moveObject($asset->{$column}, $newKey);
                    $updateData[$column] = $newKey;
                }
            }

            DB::transaction(function () use ($asset, $updateData) {
                $asset->update($updateData);
            });

            Log::info("Asset moved: {$oldS3Key} -> {$newS3Key}");
            $moves[] = ['old' => $oldS3Key, 'new' => $newS3Key];
            $moved++;
        }

        return response()->json([
            'message' => __(':moved asset(s) moved successfully', ['moved' => $moved]),
            'moved' => $moved,
            'failed' => $failed,
            'moves' => $moves,
        ]);
    }

    /**
     * Download multiple assets as a ZIP file
     */
    public function bulkDownload(Request $request)
    {
        $this->authorize('bulkDownload', Asset::class);

        $request->validate([
            'asset_ids' => 'required|array|max:100',
            'asset_ids.*' => 'integer|exists:assets,id',
        ]);

        $assets = Asset::whereIn('id', $request->asset_ids)->get();

        $totalSize = $assets->sum('size');
        $maxSize = 500 * 1024 * 1024;
        if ($totalSize > $maxSize) {
            return response()->json([
                'message' => __('Total file size exceeds 500 MB. Please select fewer assets.'),
            ], 422);
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'orca_dam_');
        $zip = new \ZipArchive;

        if ($zip->open($tempFile, \ZipArchive::OVERWRITE) !== true) {
            return response()->json([
                'message' => __('Failed to create ZIP archive.'),
            ], 500);
        }

        $usedFilenames = [];
        $added = 0;

        foreach ($assets as $asset) {
            try {
                $content = $this->s3Service->getObjectContent($asset->s3_key);
                if ($content === null) {
                    continue;
                }

                $filename = $asset->filename;
                if (isset($usedFilenames[$filename])) {
                    $usedFilenames[$filename]++;
                    $ext = pathinfo($filename, PATHINFO_EXTENSION);
                    $name = pathinfo($filename, PATHINFO_FILENAME);
                    $filename = $name.'_'.$usedFilenames[$filename].($ext ? '.'.$ext : '');
                } else {
                    $usedFilenames[$filename] = 0;
                }

                $zip->addFromString($filename, $content);
                $added++;
            } catch (\Exception $e) {
                Log::warning("Bulk download: failed to fetch asset {$asset->id}: ".$e->getMessage());
            }
        }

        $zip->close();

        if ($added === 0) {
            @unlink($tempFile);

            return response()->json([
                'message' => __('No files could be downloaded.'),
            ], 422);
        }

        $date = now()->format('Y-m-d');
        $zipContent = file_get_contents($tempFile);
        @unlink($tempFile);

        return response($zipContent)
            ->header('Content-Type', 'application/zip')
            ->header('Content-Disposition', 'attachment; filename="orca-dam-assets-'.$date.'.zip"');
    }
}
