<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\CsvExportService;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected CsvExportService $csvExportService) {}

    /**
     * Show the export page
     */
    public function index()
    {
        $this->authorize('export', Asset::class);

        // The categories scopeOfType understands, narrowed to what the library
        // actually holds. Not mime prefixes: "application" is not a filter value,
        // and the scope would ignore it and export everything (REQ-7).
        $present = Asset::select('mime_type')
            ->distinct()
            ->pluck('mime_type')
            ->map(fn (string $mimeType) => Asset::typeCategoryFor($mimeType))
            ->filter()
            ->unique();

        $fileTypes = collect(Asset::typeCategories())
            ->filter(fn (string $category) => $present->contains($category))
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
            // Enumerated, not a free string: scopeOfType silently ignores a value
            // it does not know, which would export the whole library (REQ-7).
            'file_type' => ['nullable', 'string', Rule::in(Asset::typeCategories())],
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

        $csvExportService = $this->csvExportService;
        $callback = function () use ($assets, $csvExportService) {
            $file = fopen('php://output', 'w');

            fputcsv($file, $csvExportService->generateHeaders());

            foreach ($assets as $asset) {
                fputcsv($file, $csvExportService->formatRow($asset));
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
