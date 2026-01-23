<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Setting;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected S3Service $s3Service) {}

    /**
     * List cached folders (all authenticated users)
     */
    public function index()
    {
        $folders = $this->getCachedFolders();

        return response()->json(['folders' => $folders]);
    }

    /**
     * Refresh folders from S3 (admin only)
     */
    public function scan()
    {
        $this->authorize('discover', Asset::class);

        $folders = $this->s3Service->listFolders();
        Setting::set('s3_folders', $folders, 'json', 'aws');

        return response()->json(['folders' => $folders]);
    }

    /**
     * Create a new folder (admin only)
     */
    public function store(Request $request)
    {
        $this->authorize('discover', Asset::class);

        $request->validate([
            'name' => 'required|string|max:100|regex:/^[a-zA-Z0-9_\-]+$/',
            'parent' => 'nullable|string|max:255',
        ]);

        // Build folder path: parent + name
        $parent = $request->input('parent', S3Service::getRootFolder());
        $parent = rtrim($parent, '/');
        $folderPath = $parent !== '' ? $parent.'/'.trim($request->name, '/') : trim($request->name, '/');

        if (! $this->s3Service->createFolder($folderPath)) {
            return response()->json(['message' => 'Failed to create folder'], 500);
        }

        $folders = Setting::get('s3_folders', []);
        if (! in_array($folderPath, $folders)) {
            $folders[] = $folderPath;
            sort($folders);
            Setting::set('s3_folders', $folders, 'json', 'aws');
        }

        return response()->json(['folder' => $folderPath], 201);
    }

    /**
     * Get cached folders or build from existing assets
     */
    protected function getCachedFolders(): array
    {
        $cached = Setting::get('s3_folders', []);

        if (empty($cached)) {
            $rootFolder = S3Service::getRootFolder();

            // Build folder list from existing assets
            $cached = Asset::select('s3_key')
                ->get()
                ->map(fn ($a) => dirname($a->s3_key))
                ->unique()
                ->values()
                ->toArray();

            $base = $rootFolder !== '' ? [$rootFolder] : [];
            $cached = array_values(array_unique(array_merge($base, $cached)));
            sort($cached);
            Setting::set('s3_folders', $cached, 'json', 'aws');
        }

        return $cached;
    }
}
