<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Setting;
use App\Services\AssetProcessingService;
use App\Services\S3Service;
use App\Services\TikzCompilerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ToolsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected S3Service $s3Service,
        protected AssetProcessingService $assetProcessingService,
        protected TikzCompilerService $tikzCompilerService
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
            'parent_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            ...$this->metadataValidationRules(),
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
                'parent_id' => $request->input('parent_asset_id'),
                'alt_text' => null,
                'caption' => $request->input('caption') ?: null,
            ]);

            $this->assetProcessingService->processImageAsset($asset, dispatchAiTagging: true);

            // Apply batch upload metadata
            $this->assetProcessingService->applyUploadMetadata(
                $asset,
                $request->input('metadata_tags'),
                $request->input('metadata_license_type'),
                $request->input('metadata_copyright'),
                $request->input('metadata_copyright_source'),
                $request->input('metadata_reference_tag_ids'),
            );
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

    public function tikzSvgFonts()
    {
        $folders = S3Service::getConfiguredFolders();
        $rootFolder = S3Service::getRootFolder();

        return view('tools.tikz-svg-fonts', compact('folders', 'rootFolder'));
    }

    public function uploadTikzSvgFonts(Request $request)
    {
        $this->authorize('create', Asset::class);

        $request->validate([
            'content' => ['required', 'string', 'max:5242880'],
            'filename' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:1000'],
            'parent_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            ...$this->metadataValidationRules(),
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
                'parent_id' => $request->input('parent_asset_id'),
                'alt_text' => null,
                'caption' => $request->input('caption') ?: null,
            ]);

            $this->assetProcessingService->processImageAsset($asset, dispatchAiTagging: true);

            // Apply batch upload metadata
            $this->assetProcessingService->applyUploadMetadata(
                $asset,
                $request->input('metadata_tags'),
                $request->input('metadata_license_type'),
                $request->input('metadata_copyright'),
                $request->input('metadata_copyright_source'),
                $request->input('metadata_reference_tag_ids'),
            );
        } catch (\Exception $e) {
            Log::error('TikZ SVG (embedded fonts) upload failed: '.$e->getMessage());

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

    public function bakomaFont(string $name)
    {
        // Whitelist: only lowercase alphanumeric font names
        if (! preg_match('/^[a-z0-9]+$/', $name)) {
            abort(404);
        }

        $cacheKey = 'bakoma_font_'.$name;

        $fontData = Cache::get($cacheKey);

        if ($fontData === null) {
            try {
                $response = Http::timeout(10)->get("https://tikzjax.com/bakoma/ttf/{$name}.ttf");

                if (! $response->successful()) {
                    Log::warning("BaKoMa font not found: {$name} (HTTP {$response->status()})");
                    abort(404);
                }

                $fontData = $response->body();
                Cache::put($cacheKey, base64_encode($fontData), 86400);
            } catch (\Exception $e) {
                Log::error("BaKoMa font proxy failed for {$name}: {$e->getMessage()}");
                abort(502, 'Font fetch failed');
            }
        } else {
            $fontData = base64_decode($fontData);
        }

        return response($fontData)
            ->header('Content-Type', 'font/ttf')
            ->header('Cache-Control', 'public, max-age=31536000, immutable');
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
            'parent_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            ...$this->metadataValidationRules(),
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
                'parent_id' => $request->input('parent_asset_id'),
                'alt_text' => null,
                'caption' => $request->input('caption') ?: null,
            ]);

            $this->assetProcessingService->processImageAsset($asset, dispatchAiTagging: true);

            // Apply batch upload metadata
            $this->assetProcessingService->applyUploadMetadata(
                $asset,
                $request->input('metadata_tags'),
                $request->input('metadata_license_type'),
                $request->input('metadata_copyright'),
                $request->input('metadata_copyright_source'),
                $request->input('metadata_reference_tag_ids'),
            );
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

    public function tikzServer()
    {
        $folders = S3Service::getConfiguredFolders();
        $rootFolder = S3Service::getRootFolder();
        $compilerAvailable = $this->tikzCompilerService->isAvailable();
        $fontPackages = TikzCompilerService::fontPackages();
        $colorPackage = Setting::get('tikz_color_package', '');
        $colorPackageName = Setting::get('tikz_color_package_name', '');

        return view('tools.tikz-server', compact('folders', 'rootFolder', 'compilerAvailable', 'fontPackages', 'colorPackage', 'colorPackageName'));
    }

    public function renderTikzServer(Request $request)
    {
        $request->validate([
            'tikz_code' => ['required', 'string', 'max:50000'],
            'png_dpi' => ['nullable', 'integer', 'min:72', 'max:600'],
            'border_pt' => ['nullable', 'integer', 'min:0', 'max:50'],
            'font_package' => ['nullable', 'string', 'max:30'],
            'preamble' => ['nullable', 'string', 'max:50000'],
            'variants' => ['nullable', 'array'],
            'variants.svg_standard' => ['nullable', 'boolean'],
            'variants.svg_embedded' => ['nullable', 'boolean'],
            'variants.svg_paths' => ['nullable', 'boolean'],
            'variants.png' => ['nullable', 'boolean'],
            'extra_libraries' => ['nullable', 'string', 'max:500'],
            'force_canvas' => ['nullable', 'boolean'],
            'canvas_width_cm' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            'canvas_height_cm' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            'clip_to_canvas' => ['nullable', 'boolean'],
        ]);

        if (! $this->tikzCompilerService->isAvailable()) {
            return response()->json(['error' => 'TeX Live is not installed on this server.'], 503);
        }

        $result = $this->tikzCompilerService->compile(
            $request->input('tikz_code'),
            $request->input('png_dpi', config('tikz.png_dpi', 300)),
            $request->input('border_pt', 5),
            $request->input('font_package', 'arev'),
            $request->input('preamble', ''),
            $request->input('variants', []),
            $request->input('extra_libraries', ''),
            $request->boolean('force_canvas'),
            (float) $request->input('canvas_width_cm', 0),
            (float) $request->input('canvas_height_cm', 0),
            $request->boolean('clip_to_canvas'),
        );

        if (! $result['success']) {
            return response()->json([
                'error' => $result['error'] ?? 'Compilation failed',
                'log' => $result['log'] ?? null,
            ], 422);
        }

        return response()->json([
            'variants' => $result['variants'],
            'log' => $result['log'] ?? null,
        ]);
    }

    public function searchTexTemplates(Request $request)
    {
        $query = Asset::query()
            ->where(function ($q) {
                $q->where('filename', 'like', '%.tex')
                    ->orWhere('s3_key', 'like', '%.tex');
            });

        if ($search = $request->input('search')) {
            $query->search($search);
        }

        $assets = $query->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'filename', 's3_key', 'size', 'updated_at']);

        return response()->json($assets->map(function ($asset) {
            return [
                'id' => $asset->id,
                'filename' => $asset->filename,
                'folder' => $asset->folder,
                'size' => $asset->size,
                'formatted_size' => $asset->formatted_size,
                'updated_at' => $asset->updated_at->format('Y-m-d H:i'),
            ];
        }));
    }

    public function loadTexTemplate(Asset $asset)
    {
        $ext = strtolower(pathinfo($asset->filename, PATHINFO_EXTENSION));
        if (! in_array($ext, ['tex', 'txt'])) {
            return response()->json(['error' => 'Not a .tex or .txt file'], 422);
        }

        $content = $this->s3Service->getObjectContent($asset->s3_key);
        if ($content === null) {
            return response()->json(['error' => 'Could not retrieve file content'], 500);
        }

        return response()->json([
            'content' => $content,
            'filename' => $asset->filename,
            'id' => $asset->id,
        ]);
    }

    public function uploadTexTemplate(Request $request)
    {
        $this->authorize('create', Asset::class);

        $request->validate([
            'content' => ['required', 'string', 'max:1048576'],
            'filename' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:1000'],
        ]);

        $folder = $request->input('folder', S3Service::getRootFolder());

        $filename = S3Service::sanitizeFilename($request->input('filename'));
        if (! str_ends_with(strtolower($filename), '.tex')) {
            $filename = pathinfo($filename, PATHINFO_FILENAME).'.tex';
            if ($filename === '.tex') {
                $filename = 'template.tex';
            }
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'orca_tex_');
        file_put_contents($tmpPath, $request->input('content'));

        try {
            $uploadedFile = new UploadedFile($tmpPath, $filename, 'application/x-tex', null, true);
            $fileData = $this->s3Service->uploadFile($uploadedFile, $folder, keepOriginalFilename: false);

            $asset = Asset::create([
                's3_key' => $fileData['s3_key'],
                'filename' => $filename,
                'mime_type' => 'application/x-tex',
                'size' => $fileData['size'],
                'etag' => $fileData['etag'] ?? null,
                'width' => null,
                'height' => null,
                'user_id' => Auth::id(),
                'alt_text' => null,
                'caption' => $request->input('caption') ?: null,
            ]);
        } catch (\Exception $e) {
            Log::error('TeX template upload failed: '.$e->getMessage());

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

    public function gifMaker()
    {
        $folders = S3Service::getConfiguredFolders();
        $rootFolder = S3Service::getRootFolder();

        return view('tools.gif-maker', compact('folders', 'rootFolder'));
    }

    public function uploadGif(Request $request)
    {
        $this->authorize('create', Asset::class);

        $request->validate([
            'content' => ['required', 'string', 'max:15000000'],
            'filename' => ['required', 'string', 'max:255'],
            'folder' => ['nullable', 'string', 'max:255'],
            'width' => ['nullable', 'integer', 'min:1'],
            'height' => ['nullable', 'integer', 'min:1'],
            'caption' => ['nullable', 'string', 'max:1000'],
            'parent_asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            ...$this->metadataValidationRules(),
        ]);

        $folder = $request->input('folder', S3Service::getRootFolder());

        // Sanitize filename and ensure .gif extension
        $filename = S3Service::sanitizeFilename($request->input('filename'));
        if (! str_ends_with(strtolower($filename), '.gif')) {
            $filename = pathinfo($filename, PATHINFO_FILENAME).'.gif';
            if ($filename === '.gif') {
                $filename = 'animation.gif';
            }
        }

        // Decode base64 GIF data (strip data URL prefix if present)
        $content = $request->input('content');
        if (str_starts_with($content, 'data:')) {
            $content = preg_replace('/^data:[^;]+;base64,/', '', $content);
        }
        $binaryData = base64_decode($content, true);
        if ($binaryData === false) {
            return response()->json(['error' => 'Invalid base64 data'], 422);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'orca_gif_');
        file_put_contents($tmpPath, $binaryData);

        try {
            $uploadedFile = new UploadedFile($tmpPath, $filename, 'image/gif', null, true);
            $fileData = $this->s3Service->uploadFile($uploadedFile, $folder, keepOriginalFilename: false);

            $asset = Asset::create([
                's3_key' => $fileData['s3_key'],
                'filename' => $filename,
                'mime_type' => 'image/gif',
                'size' => $fileData['size'],
                'etag' => $fileData['etag'] ?? null,
                'width' => $request->input('width'),
                'height' => $request->input('height'),
                'user_id' => Auth::id(),
                'parent_id' => $request->input('parent_asset_id'),
                'alt_text' => null,
                'caption' => $request->input('caption') ?: null,
            ]);

            $this->assetProcessingService->processImageAsset($asset, dispatchAiTagging: true);

            // Apply batch upload metadata (shared form with TikZ Server handoff and direct usage)
            $this->assetProcessingService->applyUploadMetadata(
                $asset,
                $request->input('metadata_tags'),
                $request->input('metadata_license_type'),
                $request->input('metadata_copyright'),
                $request->input('metadata_copyright_source'),
                $request->input('metadata_reference_tag_ids'),
            );
        } catch (\Exception $e) {
            Log::error('GIF upload failed: '.$e->getMessage());

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

    private function metadataValidationRules(): array
    {
        return [
            'metadata_tags' => 'nullable|array',
            'metadata_tags.*' => 'string|max:100',
            'metadata_reference_tag_ids' => 'nullable|array',
            'metadata_reference_tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($q) => $q->where('type', 'reference')),
            ],
            'metadata_license_type' => ['nullable', 'string', Rule::in(array_keys(Asset::licenseTypes()))],
            'metadata_copyright' => 'nullable|string|max:500',
            'metadata_copyright_source' => 'nullable|string|max:500',
        ];
    }
}
