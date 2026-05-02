<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;
use App\Services\AssetProcessingService;
use App\Services\S3Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    use AuthorizesRequests;

    /**
     * Query parameters that describe an index result set. Used to detect
     * whether the show page was reached "in context" (from a filtered index)
     * so we can render prev/next cycle navigation.
     */
    private const CONTEXT_KEYS = ['search', 'tags', 'type', 'folder', 'user', 'missing', 'sort', 'per_page', 'page'];

    private const ALLOWED_PER_PAGE = [12, 24, 36, 48, 60, 72, 96];

    public function __construct(
        protected S3Service $s3Service,
        protected AssetProcessingService $assetProcessingService,
    ) {}

    /**
     * Display a listing of assets
     */
    public function index(Request $request)
    {
        $data = $this->buildIndexData($request);
        $data['indexRoute'] = 'assets.index';

        session(['assets_return_url' => $request->fullUrl()]);

        return view('assets.index', $data);
    }

    /**
     * Display assets in an embeddable layout (no header/footer)
     */
    public function embed(Request $request)
    {
        $data = $this->buildIndexData($request);
        $data['indexRoute'] = 'assets.embed';

        session(['assets_return_url' => $request->fullUrl()]);

        return view('assets.embed', $data);
    }

    /**
     * Build shared data for the asset index/embed views
     */
    private function buildIndexData(Request $request): array
    {
        $rootFolder = S3Service::getRootFolder();
        $userHomeFolder = Auth::user()->getHomeFolder();
        $folder = $request->input('folder', $userHomeFolder);

        $filterUser = null;
        if ($userId = $request->input('user')) {
            if (Auth::user()->isAdmin() || (int) $userId === Auth::id()) {
                $filterUserModel = User::find($userId);
                if ($filterUserModel) {
                    $filterUser = ['id' => $filterUserModel->id, 'name' => $filterUserModel->name];
                }
            }
        }

        $query = $this->buildFilteredAssetQuery($request)->with(['tags', 'user']);
        $perPage = $this->resolvePerPage($request);

        $assets = $query->paginate($perPage)->onEachSide(2)->withQueryString();
        $folders = S3Service::getConfiguredFolders();

        $missingCount = Cache::remember('assets:missing_count', 300, fn () => Asset::missing()->count());

        return compact('assets', 'perPage', 'folders', 'rootFolder', 'folder', 'missingCount', 'filterUser');
    }

    /**
     * Build the unpaginated, filtered asset query that drives both the index
     * and the show-page cycle navigation. Pure: no eager loads, no pagination.
     */
    private function buildFilteredAssetQuery(Request $request): Builder
    {
        $query = Asset::query();

        if ($search = $request->input('search')) {
            $query->search($search);
        }

        if ($tagIds = $request->input('tags')) {
            $query->withTags(is_array($tagIds) ? $tagIds : explode(',', $tagIds));
        }

        if ($type = $request->input('type')) {
            $query->ofType($type);
        }

        // Folder filter: URL param > user home folder. Empty string means "all folders".
        $userHomeFolder = Auth::user()->getHomeFolder();
        $folder = $request->input('folder', $userHomeFolder);
        if ($folder !== '') {
            $query->inFolder($folder);
        }

        if ($userId = $request->input('user')) {
            if (Auth::user()->isAdmin() || (int) $userId === Auth::id()) {
                $query->byUser($userId);
            }
        }

        if ($request->boolean('missing')) {
            $query->missing();
        }

        $query->applySort($request->input('sort', 'date_desc'));

        return $query;
    }

    /**
     * Resolve items-per-page: URL param > user preference. Falls back to user
     * preference when the URL value is missing or not in the allow-list.
     */
    private function resolvePerPage(Request $request): int
    {
        $perPage = $request->input('per_page');
        if (! $perPage || ! in_array((int) $perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = Auth::user()->getItemsPerPage();
        }

        return (int) $perPage;
    }

    /**
     * Show the form for creating a new asset
     */
    public function create()
    {
        $this->authorize('create', Asset::class);

        $rootFolder = S3Service::getRootFolder();
        $folders = S3Service::getConfiguredFolders();

        return view('assets.create', compact('folders', 'rootFolder'));
    }

    /**
     * Store newly uploaded assets
     */
    public function store(StoreAssetRequest $request)
    {
        $this->authorize('create', Asset::class);

        try {

            $folder = $request->input('folder', S3Service::getRootFolder());
            $keepOriginalFilename = $request->boolean('keep_original_filename');
            $uploadedAssets = [];
            $duplicates = [];

            foreach ($request->file('files') as $file) {
                try {
                    // Upload to S3 with folder support
                    $fileData = $this->s3Service->uploadFile($file, $folder, $keepOriginalFilename);

                    // Check for duplicate by etag (skip when keeping original filename, as overwrite is intentional)
                    if (! $keepOriginalFilename && ! empty($fileData['etag'])) {
                        $existing = Asset::withTrashed()->where('etag', $fileData['etag'])->first();
                        if ($existing) {
                            // Clean up the just-uploaded S3 object
                            $this->s3Service->deleteFile($fileData['s3_key']);
                            Log::warning('Duplicate upload detected', [
                                'filename' => $fileData['filename'],
                                'existing_asset_id' => $existing->id,
                                'etag' => $fileData['etag'],
                            ]);

                            $duplicates[] = [
                                'filename' => $fileData['filename'],
                                'existing_asset_id' => $existing->id,
                                'existing_asset_url' => $existing->trashed() ? null : route('assets.show', $existing),
                            ];

                            continue;
                        }
                    }

                    // Handle s3_key collision when keeping original filename
                    $existingByKey = Asset::withTrashed()->where('s3_key', $fileData['s3_key'])->first();
                    if ($existingByKey) {
                        // Clean up old thumbnails and resized images
                        $this->s3Service->deleteAssetFiles($existingByKey, keepOriginal: true);

                        // Update existing asset record
                        $existingByKey->update([
                            'filename' => $fileData['filename'],
                            'mime_type' => $fileData['mime_type'],
                            'size' => $fileData['size'],
                            'etag' => $fileData['etag'] ?? null,
                            'width' => $fileData['width'],
                            'height' => $fileData['height'],
                            'thumbnail_s3_key' => null,
                            'resize_s_s3_key' => null,
                            'resize_m_s3_key' => null,
                            'resize_l_s3_key' => null,
                            'user_id' => Auth::id(),
                            'deleted_at' => null,
                            'last_modified_by' => Auth::id(),
                        ]);

                        $asset = $existingByKey;
                    } else {
                        // Create asset record
                        $asset = Asset::create([
                            's3_key' => $fileData['s3_key'],
                            'filename' => $fileData['filename'],
                            'mime_type' => $fileData['mime_type'],
                            'size' => $fileData['size'],
                            'etag' => $fileData['etag'] ?? null,
                            'width' => $fileData['width'],
                            'height' => $fileData['height'],
                            'user_id' => Auth::id(),
                        ]);
                    }

                    // Generate thumbnail, resized images, and AI tags
                    $this->assetProcessingService->processImageAsset($asset);

                    // Apply batch upload metadata
                    $this->assetProcessingService->applyUploadMetadata(
                        $asset,
                        $request->input('metadata_tags'),
                        $request->input('metadata_license_type'),
                        $request->input('metadata_copyright'),
                        $request->input('metadata_copyright_source'),
                        $request->input('metadata_reference_tag_ids'),
                    );

                    $uploadedAssets[] = $asset;
                } catch (\Exception $e) {
                    Log::error("Failed to upload {$file->getClientOriginalName()}: ".$e->getMessage());
                    // Continue with other files
                }
            }

            if (empty($uploadedAssets)) {
                if (! empty($duplicates)) {
                    $dupeMessage = __('Duplicate file(s) detected. These files already exist in the library.');
                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => $dupeMessage,
                            'duplicates' => $duplicates,
                        ], 409);
                    }

                    return redirect()->back()->with('error', $dupeMessage);
                }

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'All uploads failed. Please check the logs for details.',
                    ], 500);
                }

                return redirect()->back()
                    ->with('error', 'All uploads failed. Please check the file formats and try again.');
            }

            if ($request->expectsJson()) {
                foreach ($uploadedAssets as $a) {
                    $a->append(Asset::APPEND_FIELDS);
                }

                $response = [
                    'message' => count($uploadedAssets).' file(s) uploaded successfully',
                    'assets' => $uploadedAssets,
                ];
                if (! empty($duplicates)) {
                    $response['duplicates'] = $duplicates;
                }

                return response()->json($response);
            }

            $successMessage = count($uploadedAssets).' file(s) uploaded successfully';
            if (! empty($duplicates)) {
                $dupeNames = array_column($duplicates, 'filename');
                $successMessage .= '. '.__('Skipped :count duplicate(s): :names', [
                    'count' => count($duplicates),
                    'names' => implode(', ', $dupeNames),
                ]);
            }

            return redirect()->route('assets.index')
                ->with('success', $successMessage);
        } catch (\Exception $e) {
            Log::error('Upload process failed: '.$e->getMessage());

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Upload failed: '.$e->getMessage(),
                ], 500);
            }

            return redirect()->back()
                ->with('error', 'Upload failed: '.$e->getMessage());
        }
    }

    /**
     * Display the specified asset
     */
    public function show(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);
        $asset->load(['tags' => function ($query) {
            $query->withCount('assets');
        }, 'user', 'parent', 'children']);

        $cycleNav = $this->buildCycleNav($request, $asset);
        $backUrl = $this->buildBackUrl($request);

        return view('assets.show', compact('asset', 'cycleNav', 'backUrl'));
    }

    /**
     * Extract the index-context query parameters that are present on the
     * current request, in the canonical order. Returns an empty array when
     * the show page was opened as a deeplink.
     */
    private function extractContextParams(Request $request): array
    {
        $context = [];
        foreach (self::CONTEXT_KEYS as $key) {
            if ($request->has($key) && $request->input($key) !== '' && $request->input($key) !== null) {
                $context[$key] = $request->input($key);
            }
        }

        return $context;
    }

    /**
     * Build the cycle-navigation payload used by the show view.
     *
     * Returns null when there is no index context, when the current asset
     * isn't part of the reconstructed result set (stale link), or when the
     * result set has only one entry.
     */
    private function buildCycleNav(Request $request, Asset $asset): ?array
    {
        $context = $this->extractContextParams($request);
        if (empty($context)) {
            return null;
        }

        // The full ordered ID list doesn't depend on the page or per_page params,
        // so cache by the filter+sort fingerprint only.
        $cacheKeyContext = $context;
        unset($cacheKeyContext['page'], $cacheKeyContext['per_page']);
        ksort($cacheKeyContext);
        $cacheKey = 'asset-cycle:'.Auth::id().':'.sha1((string) json_encode($cacheKeyContext));

        $idList = Cache::remember(
            $cacheKey,
            60,
            fn () => $this->buildFilteredAssetQuery($request)->pluck('id')->all()
        );

        $total = count($idList);
        if ($total <= 1) {
            return null;
        }

        $index = array_search($asset->id, $idList, false);
        if ($index === false) {
            return null;
        }

        $perPage = $this->resolvePerPage($request);
        $prevId = $index > 0 ? $idList[$index - 1] : null;
        $nextId = $index < $total - 1 ? $idList[$index + 1] : null;

        $neighbourIds = array_filter([$prevId, $nextId]);
        $neighbours = $neighbourIds
            ? Asset::whereIn('id', $neighbourIds)->get()->keyBy('id')
            : collect();

        return [
            'position' => $index + 1,
            'total' => $total,
            'prev' => $prevId ? $this->buildCycleEntry($context, $prevId, $index - 1, $perPage, $neighbours->get($prevId)) : null,
            'next' => $nextId ? $this->buildCycleEntry($context, $nextId, $index + 1, $perPage, $neighbours->get($nextId)) : null,
            'summary' => $this->buildContextSummary($request),
        ];
    }

    /**
     * Build a single prev/next entry: URL with page param adjusted to the
     * index page that contains this neighbour, plus its thumbnail for prefetch.
     */
    private function buildCycleEntry(array $context, int $neighbourId, int $neighbourIndex, int $perPage, ?Asset $neighbour): array
    {
        $context['page'] = (int) floor($neighbourIndex / max($perPage, 1)) + 1;

        return [
            'url' => route('assets.show', $neighbourId).'?'.http_build_query($context),
            'thumb' => $neighbour?->thumbnail_url ?: $neighbour?->url,
            'filename' => $neighbour?->filename ?? '',
        ];
    }

    /**
     * Build the back-to-index URL. When context params are present we
     * derive the URL from them so the user lands on the exact same view
     * (filters, sort, page). Otherwise fall back to the session-stored
     * return URL (for entries from non-index pages like the dashboard).
     */
    private function buildBackUrl(Request $request): string
    {
        $context = $this->extractContextParams($request);
        if (! empty($context)) {
            return route('assets.index').'?'.http_build_query($context);
        }

        return session('assets_return_url', route('assets.index'));
    }

    /**
     * Build a short, human-readable summary of the active filter/sort, used
     * by the cycle-nav badge ("Filtered by tag-name, sorted by name"). Only
     * mentions non-default values; returns an empty string when nothing
     * notable is active.
     */
    private function buildContextSummary(Request $request): string
    {
        $parts = [];

        if ($search = $request->input('search')) {
            $parts[] = '"'.$search.'"';
        }

        if ($type = $request->input('type')) {
            $parts[] = $type;
        }

        if ($tagIds = $request->input('tags')) {
            $ids = is_array($tagIds) ? $tagIds : explode(',', $tagIds);
            $names = Tag::whereIn('id', $ids)->pluck('name')->all();
            if (! empty($names)) {
                $parts[] = implode(', ', $names);
            }
        }

        if ($userId = $request->input('user')) {
            if (Auth::user()->isAdmin() || (int) $userId === Auth::id()) {
                if ($user = User::find($userId)) {
                    $parts[] = __('Uploaded by').' '.$user->name;
                }
            }
        }

        if ($request->boolean('missing')) {
            $parts[] = __('Missing');
        }

        $summary = '';
        if (! empty($parts)) {
            $summary = __('Filtered by').' '.implode(' · ', $parts);
        }

        $sort = $request->input('sort');
        if ($sort && $sort !== 'date_desc') {
            $sortLabel = $this->sortLabel($sort);
            $summary .= ($summary !== '' ? ' · ' : '').__('sorted by').' '.$sortLabel;
        }

        return $summary;
    }

    private function sortLabel(string $sort): string
    {
        return match ($sort) {
            'date_asc' => __('Oldest First'),
            'upload_desc' => __('Newest Uploads'),
            'upload_asc' => __('Oldest Uploads'),
            'size_desc' => __('Largest First'),
            'size_asc' => __('Smallest First'),
            'name_asc' => __('Name A-Z'),
            'name_desc' => __('Name Z-A'),
            's3key_asc' => __('S3 Key A-Z'),
            's3key_desc' => __('S3 Key Z-A'),
            default => $sort,
        };
    }

    /**
     * Show the form for editing the specified asset
     */
    public function edit(Asset $asset)
    {
        $this->authorize('update', $asset);
        $asset->load('tags');
        $tags = Tag::orderBy('name')->get();

        return view('assets.edit', compact('asset', 'tags'));
    }

    /**
     * Update the specified asset
     */
    public function update(UpdateAssetRequest $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        // Update metadata
        $asset->update(array_merge(
            $request->only(['filename', 'alt_text', 'caption', 'license_type', 'license_expiry_date', 'copyright', 'copyright_source']),
            ['last_modified_by' => Auth::id()]
        ));

        // Handle tags only if explicitly included in request
        if ($request->has('tags') || $request->has('reference_tag_ids')) {
            $tagIds = $request->has('tags')
                ? Tag::resolveUserTagIds($request->input('tags', []))
                : null; // null signals "do not touch user tags"

            // Always preserve AI pivots verbatim
            $aiPivotData = [];
            foreach ($asset->aiTags as $aiTag) {
                $aiPivotData[$aiTag->id] = ['attached_by' => $aiTag->pivot->attached_by];
            }

            // Reference pivots: sync to submitted list when present, otherwise preserve
            if ($request->has('reference_tag_ids')) {
                $referenceIds = array_map('intval', $request->input('reference_tag_ids', []));
                $referencePivotData = array_fill_keys($referenceIds, ['attached_by' => 'reference']);
            } else {
                $referencePivotData = [];
                foreach ($asset->referenceTags as $refTag) {
                    $referencePivotData[$refTag->id] = ['attached_by' => $refTag->pivot->attached_by];
                }
            }

            // User pivots: sync to submitted list when present, otherwise preserve
            if ($tagIds !== null) {
                $userPivotData = array_fill_keys($tagIds, ['attached_by' => 'user']);
            } else {
                $userPivotData = [];
                foreach ($asset->userTags as $userTag) {
                    $userPivotData[$userTag->id] = ['attached_by' => $userTag->pivot->attached_by];
                }
            }

            $asset->tags()->sync($aiPivotData + $referencePivotData + $userPivotData);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Asset updated successfully',
                'asset' => $asset->fresh(['tags'])->append(Asset::APPEND_FIELDS),
            ]);
        }

        return redirect()->route('assets.show', $asset)
            ->with('success', 'Asset updated successfully');
    }

    /**
     * Remove the parent relation from the given asset.
     */
    public function unlinkParent(Asset $asset)
    {
        $this->authorize('update', $asset);

        if ($asset->parent_id !== null) {
            $asset->update([
                'parent_id' => null,
                'last_modified_by' => Auth::id(),
            ]);
        }

        return redirect()->back()->with('success', __('Relation removed.'));
    }

    /**
     * Add tags to an asset
     */
    public function addTags(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $request->validate([
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

        if (! empty($tagNames)) {
            $tagIds = Tag::resolveUserTagIds($tagNames);
            $asset->syncTagsWithAttribution($tagIds, 'user');
        }

        if (! empty($referenceIds)) {
            $asset->syncTagsWithAttribution($referenceIds, 'reference');
        }

        $asset->update(['last_modified_by' => Auth::id()]);

        return response()->json([
            'message' => 'Tags added successfully',
            'tags' => $asset->fresh()->tags,
        ]);
    }

    /**
     * Remove a tag from an asset
     */
    public function removeTag(Asset $asset, Tag $tag)
    {
        $this->authorize('update', $asset);

        $asset->tags()->detach($tag->id);
        $asset->update(['last_modified_by' => Auth::id()]);

        return response()->json([
            'message' => 'Tag removed successfully',
        ]);
    }
}
