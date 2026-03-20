<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\AssetProcessingService;
use App\Services\S3Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ToolsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected S3Service $s3Service,
        protected AssetProcessingService $assetProcessingService
    ) {}

    public function index()
    {
        return view('tools.index');
    }

    public function latexMathml()
    {
        $folders = S3Service::getConfiguredFolders();
        $rootFolder = S3Service::getRootFolder();

        return view('tools.latex-mathml', compact('folders', 'rootFolder'));
    }

    public function uploadMathml(Request $request)
    {
        $this->authorize('create', Asset::class);

        $request->validate([
            'content' => ['required', 'string', 'max:1000000'],
            'filename' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:255'],
            'latex' => ['nullable', 'string', 'max:10000'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ]);

        $folder = $request->input('folder', S3Service::getRootFolder());

        // Sanitize filename and ensure .mml extension
        $filename = S3Service::sanitizeFilename($request->input('filename'));
        if (! str_ends_with(strtolower($filename), '.mml')) {
            $filename = pathinfo($filename, PATHINFO_FILENAME).'.mml';
            if ($filename === '.mml') {
                $filename = 'formula.mml';
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'orca_mml_');
        file_put_contents($tmpPath, $request->input('content'));

        try {
            $uploadedFile = new UploadedFile($tmpPath, $filename, 'application/mathml+xml', null, true);
            $fileData = $this->s3Service->uploadFile($uploadedFile, $folder, keepOriginalFilename: false);

            $asset = Asset::create([
                's3_key' => $fileData['s3_key'],
                'filename' => $filename,
                'mime_type' => 'application/mathml+xml',
                'size' => $fileData['size'],
                'etag' => $fileData['etag'] ?? null,
                'width' => null,
                'height' => null,
                'user_id' => Auth::id(),
                'alt_text' => $request->input('latex') ?: null,
                'caption' => $request->input('caption') ?: null,
            ]);

            $this->assetProcessingService->processImageAsset($asset, dispatchAiTagging: true);
        } catch (\Exception $e) {
            Log::error('MathML upload failed: '.$e->getMessage());

            return response()->json(['error' => 'Upload failed: '.$e->getMessage()], 500);
        } finally {
            @unlink($tmpPath);
        }

        return response()->json([
            'asset_id' => $asset->id,
            'asset_url' => route('assets.show', $asset),
            'filename' => $asset->filename,
        ]);
    }

    public function tikzSvg()
    {
        $folders = S3Service::getConfiguredFolders();
        $rootFolder = S3Service::getRootFolder();

        return view('tools.tikz-svg', compact('folders', 'rootFolder'));
    }

    public function uploadTikzSvg(Request $request)
    {
        $this->authorize('create', Asset::class);

        $request->validate([
            'content' => ['required', 'string', 'max:5242880'],
            'filename' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ]);

        $folder = $request->input('folder', S3Service::getRootFolder());

        // Sanitize filename and ensure .svg extension
        $filename = S3Service::sanitizeFilename($request->input('filename'));
        if (! str_ends_with(strtolower($filename), '.svg')) {
            $filename = pathinfo($filename, PATHINFO_FILENAME).'.svg';
            if ($filename === '.svg') {
                $filename = 'diagram.svg';
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'orca_svg_');
        file_put_contents($tmpPath, $request->input('content'));

        try {
            $uploadedFile = new UploadedFile($tmpPath, $filename, 'image/svg+xml', null, true);
            $fileData = $this->s3Service->uploadFile($uploadedFile, $folder, keepOriginalFilename: false);

            $asset = Asset::create([
                's3_key' => $fileData['s3_key'],
                'filename' => $filename,
                'mime_type' => 'image/svg+xml',
                'size' => $fileData['size'],
                'etag' => $fileData['etag'] ?? null,
                'width' => null,
                'height' => null,
                'user_id' => Auth::id(),
                'alt_text' => null,
                'caption' => $request->input('caption') ?: null,
            ]);

            $this->assetProcessingService->processImageAsset($asset, dispatchAiTagging: true);
        } catch (\Exception $e) {
            Log::error('TikZ SVG upload failed: '.$e->getMessage());

            return response()->json(['error' => 'Upload failed: '.$e->getMessage()], 500);
        } finally {
            @unlink($tmpPath);
        }

        return response()->json([
            'asset_id' => $asset->id,
            'asset_url' => route('assets.show', $asset),
            'filename' => $asset->filename,
        ]);
    }

    public function tikzPng()
    {
        $folders = S3Service::getConfiguredFolders();
        $rootFolder = S3Service::getRootFolder();

        return view('tools.tikz-png', compact('folders', 'rootFolder'));
    }

    public function uploadTikzPng(Request $request)
    {
        $this->authorize('create', Asset::class);

        $request->validate([
            'content' => ['required', 'string', 'max:10485760'],
            'filename' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:255'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ]);

        $folder = $request->input('folder', S3Service::getRootFolder());

        // Sanitize filename and ensure .png extension
        $filename = S3Service::sanitizeFilename($request->input('filename'));
        if (! str_ends_with(strtolower($filename), '.png')) {
            $filename = pathinfo($filename, PATHINFO_FILENAME).'.png';
            if ($filename === '.png') {
                $filename = 'diagram.png';
            }
        }

        // Decode base64 PNG data (strip data URL prefix if present)
        $content = $request->input('content');
        if (str_starts_with($content, 'data:')) {
            $content = preg_replace('/^data:[^;]+;base64,/', '', $content);
        }
        $binaryData = base64_decode($content, true);
        if ($binaryData === false) {
            return response()->json(['error' => 'Invalid base64 data'], 422);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'orca_png_');
        file_put_contents($tmpPath, $binaryData);

        try {
            $uploadedFile = new UploadedFile($tmpPath, $filename, 'image/png', null, true);
            $fileData = $this->s3Service->uploadFile($uploadedFile, $folder, keepOriginalFilename: false);

            $asset = Asset::create([
                's3_key' => $fileData['s3_key'],
                'filename' => $filename,
                'mime_type' => 'image/png',
                'size' => $fileData['size'],
                'etag' => $fileData['etag'] ?? null,
                'width' => $request->input('width'),
                'height' => $request->input('height'),
                'user_id' => Auth::id(),
                'alt_text' => null,
                'caption' => $request->input('caption') ?: null,
            ]);

            $this->assetProcessingService->processImageAsset($asset, dispatchAiTagging: true);
        } catch (\Exception $e) {
            Log::error('TikZ PNG upload failed: '.$e->getMessage());

            return response()->json(['error' => 'Upload failed: '.$e->getMessage()], 500);
        } finally {
            @unlink($tmpPath);
        }

        return response()->json([
            'asset_id' => $asset->id,
            'asset_url' => route('assets.show', $asset),
            'filename' => $asset->filename,
        ]);
    }
}
