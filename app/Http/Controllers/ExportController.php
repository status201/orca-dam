<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class ExportController extends Controller
{
    use AuthorizesRequests;

    /**
     * Show the export page
     */
    public function index()
    {
        $this->authorize('export', Asset::class);

        // Get all tags for the filter dropdown
        $tags = Tag::orderBy('name')->get();

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

        return view('export.index', [
            'tags' => $tags,
            'fileTypes' => $fileTypes,
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
            'tags' => 'nullable|array',
            'tags.*' => 'integer|exists:tags,id',
        ]);

        // Build query with filters
        $query = Asset::with(['user', 'tags']);

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
        $filename = 'orca-assets-export-' . now()->format('Y-m-d-His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($assets) {
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
                'alt_text',
                'caption',
                'user_id',
                'user_name',
                'user_email',
                'tags',
                'url',
                'thumbnail_url',
                'created_at',
                'updated_at',
            ]);

            // Data rows
            foreach ($assets as $asset) {
                // Get tags as comma-separated text
                $tagNames = $asset->tags->pluck('name')->join(', ');

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
                    $asset->alt_text,
                    $asset->caption,
                    $asset->user_id,
                    $asset->user->name ?? '',
                    $asset->user->email ?? '',
                    $tagNames,
                    $asset->url,
                    $asset->thumbnail_url,
                    $asset->created_at?->toDateTimeString(),
                    $asset->updated_at?->toDateTimeString(),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
