<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class ExportController extends Controller
{
    use AuthorizesRequests;

    /**
     * Show the export page
     */
    public function index()
    {
        $this->authorize('export', Asset::class);

        // Get unique file types (mime type prefixes)
        $fileTypes = Asset::select('mime_type')
            ->distinct()
            ->get()
            ->map(function ($asset) {
                return explode('/', $asset->mime_type)[0];
            })
            ->unique()
            ->sort()
            ->values();

        $rootFolder = S3Service::getRootFolder();
        $folders = S3Service::getConfiguredFolders();

        return view('export.index', [
            'fileTypes' => $fileTypes,
            'folders' => $folders,
            'rootFolder' => $rootFolder,
        ]);
    }

    /**
     * Export assets as CSV
     */
    public function export(Request $request)
    {
        $this->authorize('export', Asset::class);

        $request->validate([
            'file_type' => 'nullable|string',
            'folder' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'integer|exists:tags,id',
        ]);

        // Build query with filters
        $query = Asset::with(['user', 'tags', 'modifier']);

        // Filter by folder if specified
        if ($request->filled('folder')) {
            $query->inFolder($request->folder);
        }

        // Filter by file type if specified
        if ($request->filled('file_type')) {
            $query->ofType($request->file_type);
        }

        // Filter by tags if specified
        if ($request->filled('tags')) {
            $query->withTags($request->tags);
        }

        // Get assets ordered by creation date
        $assets = $query->orderBy('created_at', 'desc')->get();

        // Generate CSV
        $filename = 'orca-assets-export-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($assets) {
            $file = fopen('php://output', 'w');

            // CSV Headers (reflecting database column names)
            fputcsv($file, [
                'id',
                's3_key',
                'filename',
                'mime_type',
                'size',
                'etag',
                'width',
                'height',
                'thumbnail_s3_key',
                'resize_s_s3_key',
                'resize_m_s3_key',
                'resize_l_s3_key',
                'alt_text',
                'caption',
                'license_type',
                'license_expiry_date',
                'copyright',
                'copyright_source',
                'user_id',
                'user_name',
                'user_email',
                'last_modified_by_id',
                'last_modified_by_name',
                'user_tags',
                'ai_tags',
                'reference_tags',
                'url',
                'thumbnail_url',
                'resize_s_url',
                'resize_m_url',
                'resize_l_url',
                'created_at',
                'updated_at',
            ]);

            // Data rows
            foreach ($assets as $asset) {
                // Get user tags and AI tags separately
                $userTagNames = $asset->tags->where('type', 'user')->pluck('name')->join(', ');
                $aiTagNames = $asset->tags->where('type', 'ai')->pluck('name')->join(', ');
                $referenceTagNames = $asset->tags->where('type', 'reference')->pluck('name')->join(', ');

                fputcsv($file, [
                    $asset->id,
                    $asset->s3_key,
                    $asset->filename,
                    $asset->mime_type,
                    $asset->size,
                    $asset->etag,
                    $asset->width,
                    $asset->height,
                    $asset->thumbnail_s3_key,
                    $asset->resize_s_s3_key,
                    $asset->resize_m_s3_key,
                    $asset->resize_l_s3_key,
                    $asset->alt_text,
                    $asset->caption,
                    $asset->license_type,
                    $asset->license_expiry_date?->toDateString(),
                    $asset->copyright,
                    $asset->copyright_source,
                    $asset->user_id,
                    $asset->user->name ?? '',
                    $asset->user->email ?? '',
                    $asset->last_modified_by,
                    $asset->modifier->name ?? '',
                    $userTagNames,
                    $aiTagNames,
                    $referenceTagNames,
                    $asset->url,
                    $asset->thumbnail_url,
                    $asset->resize_s_url,
                    $asset->resize_m_url,
                    $asset->resize_l_url,
                    $asset->created_at?->toDateTimeString(),
                    $asset->updated_at?->toDateTimeString(),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
