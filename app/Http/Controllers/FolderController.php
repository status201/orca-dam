<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\FolderRegistry;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected S3Service $s3Service,
        protected FolderRegistry $folders,
    ) {}

    /**
     * List cached folders (all authenticated users)
     */
    public function index()
    {
        return response()->json(['folders' => $this->folders->cachedOrDerived()]);
    }

    /**
     * Refresh folders from S3 (admin only)
     */
    public function scan()
    {
        $this->authorize('discover', Asset::class);

        $folders = $this->s3Service->listFolders();
        $this->folders->replace($folders);

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
            return response()->json(['message' => __('Failed to create folder')], 500);
        }

        $this->folders->add($folderPath);

        return response()->json(['folder' => $folderPath], 201);
    }
}
