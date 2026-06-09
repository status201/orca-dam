<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tools\StoreGifRequest;
use App\Http\Requests\Tools\StoreMathmlRequest;
use App\Http\Requests\Tools\StoreTexTemplateRequest;
use App\Http\Requests\Tools\StoreTikzPngRequest;
use App\Http\Requests\Tools\StoreTikzSvgFontsRequest;
use App\Http\Requests\Tools\StoreTikzSvgRequest;
use App\Models\Asset;
use App\Models\Setting;
use App\Services\S3Service;
use App\Services\TikzCompilerService;
use App\Services\ToolUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ToolsController extends Controller
{
    public function __construct(
        protected S3Service $s3Service,
        protected ToolUploadService $toolUploadService,
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

    public function uploadMathml(StoreMathmlRequest $request): JsonResponse
    {
        $folder = $request->input('folder', S3Service::getRootFolder());
        $filename = S3Service::ensureExtension($request->input('filename'), 'mml', 'formula.mml');

        try {
            $asset = $this->toolUploadService->store(
                content: $request->input('content'),
                filename: $filename,
                mimeType: 'application/mathml+xml',
                folder: $folder,
                attributes: [
                    'user_id' => Auth::id(),
                    'alt_text' => $request->input('latex') ?: null,
                    'caption' => $request->input('caption') ?: null,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('MathML upload failed: '.$e->getMessage());

            return response()->json(['error' => 'Upload failed: '.$e->getMessage()], 500);
        }

        return $this->assetJson($asset);
    }

    public function tikzSvg()
    {
        $folders = S3Service::getConfiguredFolders();
        $rootFolder = S3Service::getRootFolder();

        return view('tools.tikz-svg', compact('folders', 'rootFolder'));
    }

    public function uploadTikzSvg(StoreTikzSvgRequest $request): JsonResponse
    {
        $folder = $request->input('folder', S3Service::getRootFolder());
        $filename = S3Service::ensureExtension($request->input('filename'), 'svg', 'diagram.svg');

        try {
            $asset = $this->toolUploadService->store(
                content: $request->input('content'),
                filename: $filename,
                mimeType: 'image/svg+xml',
                folder: $folder,
                attributes: [
                    'user_id' => Auth::id(),
                    'parent_id' => $request->input('parent_asset_id'),
                    'caption' => $request->input('caption') ?: null,
                ],
                metadata: $request->uploadMetadata(),
            );
        } catch (\Throwable $e) {
            Log::error('TikZ SVG upload failed: '.$e->getMessage());

            return response()->json(['error' => 'Upload failed: '.$e->getMessage()], 500);
        }

        return $this->assetJson($asset);
    }

    public function tikzSvgFonts()
    {
        $folders = S3Service::getConfiguredFolders();
        $rootFolder = S3Service::getRootFolder();

        return view('tools.tikz-svg-fonts', compact('folders', 'rootFolder'));
    }

    public function uploadTikzSvgFonts(StoreTikzSvgFontsRequest $request): JsonResponse
    {
        $folder = $request->input('folder', S3Service::getRootFolder());
        $filename = S3Service::ensureExtension($request->input('filename'), 'svg', 'diagram.svg');

        try {
            $asset = $this->toolUploadService->store(
                content: $request->input('content'),
                filename: $filename,
                mimeType: 'image/svg+xml',
                folder: $folder,
                attributes: [
                    'user_id' => Auth::id(),
                    'parent_id' => $request->input('parent_asset_id'),
                    'caption' => $request->input('caption') ?: null,
                ],
                metadata: $request->uploadMetadata(),
            );
        } catch (\Throwable $e) {
            Log::error('TikZ SVG (embedded fonts) upload failed: '.$e->getMessage());

            return response()->json(['error' => 'Upload failed: '.$e->getMessage()], 500);
        }

        return $this->assetJson($asset);
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

    public function uploadTikzPng(StoreTikzPngRequest $request): JsonResponse
    {
        $folder = $request->input('folder', S3Service::getRootFolder());
        $filename = S3Service::ensureExtension($request->input('filename'), 'png', 'diagram.png');

        $binaryData = $this->decodeBase64Content($request->input('content'));
        if ($binaryData === null) {
            return response()->json(['error' => 'Invalid base64 data'], 422);
        }

        try {
            $asset = $this->toolUploadService->store(
                content: $binaryData,
                filename: $filename,
                mimeType: 'image/png',
                folder: $folder,
                attributes: [
                    'user_id' => Auth::id(),
                    'width' => $request->input('width'),
                    'height' => $request->input('height'),
                    'parent_id' => $request->input('parent_asset_id'),
                    'caption' => $request->input('caption') ?: null,
                ],
                metadata: $request->uploadMetadata(),
            );
        } catch (\Throwable $e) {
            Log::error('TikZ PNG upload failed: '.$e->getMessage());

            return response()->json(['error' => 'Upload failed: '.$e->getMessage()], 500);
        }

        return $this->assetJson($asset);
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

    public function uploadTexTemplate(StoreTexTemplateRequest $request): JsonResponse
    {
        $folder = $request->input('folder', S3Service::getRootFolder());
        $filename = S3Service::ensureExtension($request->input('filename'), 'tex', 'template.tex');

        try {
            $asset = $this->toolUploadService->store(
                content: $request->input('content'),
                filename: $filename,
                mimeType: 'application/x-tex',
                folder: $folder,
                attributes: [
                    'user_id' => Auth::id(),
                    'caption' => $request->input('caption') ?: null,
                ],
                process: false,
            );
        } catch (\Throwable $e) {
            Log::error('TeX template upload failed: '.$e->getMessage());

            return response()->json(['error' => 'Upload failed: '.$e->getMessage()], 500);
        }

        return $this->assetJson($asset);
    }

    public function gifMaker()
    {
        $folders = S3Service::getConfiguredFolders();
        $rootFolder = S3Service::getRootFolder();

        return view('tools.gif-maker', compact('folders', 'rootFolder'));
    }

    public function uploadGif(StoreGifRequest $request): JsonResponse
    {
        $folder = $request->input('folder', S3Service::getRootFolder());
        $filename = S3Service::ensureExtension($request->input('filename'), 'gif', 'animation.gif');

        $binaryData = $this->decodeBase64Content($request->input('content'));
        if ($binaryData === null) {
            return response()->json(['error' => 'Invalid base64 data'], 422);
        }

        try {
            $asset = $this->toolUploadService->store(
                content: $binaryData,
                filename: $filename,
                mimeType: 'image/gif',
                folder: $folder,
                attributes: [
                    'user_id' => Auth::id(),
                    'width' => $request->input('width'),
                    'height' => $request->input('height'),
                    'parent_id' => $request->input('parent_asset_id'),
                    'caption' => $request->input('caption') ?: null,
                ],
                metadata: $request->uploadMetadata(),
            );
        } catch (\Throwable $e) {
            Log::error('GIF upload failed: '.$e->getMessage());

            return response()->json(['error' => 'Upload failed: '.$e->getMessage()], 500);
        }

        return $this->assetJson($asset);
    }

    /**
     * Decode base64 content (stripping an optional data: URL prefix).
     * Returns null when the payload is not valid base64.
     */
    private function decodeBase64Content(string $content): ?string
    {
        if (str_starts_with($content, 'data:')) {
            $content = preg_replace('/^data:[^;]+;base64,/', '', $content);
        }

        $binaryData = base64_decode($content, true);

        return $binaryData === false ? null : $binaryData;
    }

    /**
     * The standard JSON payload returned by every tool upload endpoint.
     */
    private function assetJson(Asset $asset): JsonResponse
    {
        return response()->json([
            'asset_id' => $asset->id,
            'asset_url' => route('assets.show', $asset),
            'filename' => $asset->filename,
        ]);
    }
}
