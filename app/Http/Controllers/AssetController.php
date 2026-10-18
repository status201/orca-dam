<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateAssetException;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;
use App\Services\AssetIngestionService;
use App\Services\AssetProcessingService;
use App\Services\FolderRegistry;
use App\Services\S3Service;
use App\Support\AssetFilter;
use App\Support\AssetSort;
use App\Support\TagInputParser;
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
    private const CONTEXT_KEYS = ['search', 'tags', 'type', 'folder', 'user', 'missing', 'ids', 'sort', 'per_page', 'page'];

    private const ALLOWED_PER_PAGE = [12, 24, 36, 48, 60, 72, 96];

    public function __construct(
        protected S3Service $s3Service,
        protected AssetProcessingService $assetProcessingService,
        protected AssetIngestionService $ingestion,
        protected FolderRegistry $folders,
    ) {}

    /**
     * Display a listing of assets
     */
    public function index(Request $request)
    {
        $data = $this->buildIndexData($request);
        $data['indexRoute'] = 'assets.index';

        session(['assets_return_url' => route('assets.index').$data['showSuffix']]);

        return view('assets.index', $data);
    }

    /**
     * Display assets in an embeddable layout (no header/footer)
     */
    public function embed(Request $request)
    {
        $data = $this->buildIndexData($request);
        $data['indexRoute'] = 'assets.embed';

        session(['assets_return_url' => route('assets.embed').$data['showSuffix']]);

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
        if ($userId = $this->permittedUserFilter($request)) {
            if ($filterUserModel = User::find($userId)) {
                $filterUser = ['id' => $filterUserModel->id, 'name' => $filterUserModel->name];
            }
        }

        $query = $this->buildFilteredAssetQuery($request)->with(['tags', 'user']);
        $perPage = $this->resolvePerPage($request);

        $assets = $query->paginate($perPage)->onEachSide(2)->withQueryString();
        $folders = $this->folders->forPicker();

        $missingCount = Cache::remember('assets:missing_count', 300, fn () => Asset::missing()->count());

        $activeIds = $this->parseIdsParam($request->input('ids')) ?? [];

        // What the grid's card links append, and what index/embed stamp the return URL
        // with. The CONTEXT_KEYS allowlist, not the raw query string: the show page reads
        // nothing outside it, so anything else would be carried for no reason — and a
        // leaked `?demo=` would re-arm a demo the user has already finished on whatever
        // they open next (specs/features/guided-demos.md REQ-6a).
        $context = $this->extractContextParams($request);
        $showSuffix = $context === [] ? '' : '?'.http_build_query($context);

        return compact('assets', 'perPage', 'folders', 'rootFolder', 'folder', 'missingCount', 'filterUser', 'activeIds', 'showSuffix');
    }

    /**
     * Build the unpaginated, filtered asset query that drives both the index
     * and the show-page cycle navigation. Pure: no eager loads, no pagination.
     */
    private function buildFilteredAssetQuery(Request $request): Builder
    {
        // Explicit ids filter (e.g. from upload duplicates panel "Reveal in library").
        // When present, bypass the implicit home-folder restriction so all listed
        // assets are visible regardless of which folder they live in.
        $explicitIds = $this->parseIdsParam($request->input('ids'));

        // Folder filter: URL param > user home folder. Empty string means "all folders".
        $folder = $request->input('folder', Auth::user()->getHomeFolder());

        return (new AssetFilter(
            search: $request->input('search'),
            tagIds: AssetFilter::tagIds($request->input('tags')),
            type: $request->input('type'),
            folder: $explicitIds === null ? $folder : null,
            userId: $this->permittedUserFilter($request),
            missingOnly: $request->boolean('missing'),
            ids: $explicitIds,
            sort: $request->input('sort'),
        ))->apply(Asset::query());
    }

    /**
     * The `user` filter, honoured only for an admin or for the viewer filtering by themselves.
     * The one guard the query, the filter chip and the summary badge share, so a non-admin can
     * never filter by — or learn the name of — another user (asset-cycle-navigation.md REQ-5).
     */
    private function permittedUserFilter(Request $request): ?int
    {
        $userId = (int) $request->input('user');
        if ($userId <= 0) {
            return null;
        }

        return Auth::user()->isAdmin() || $userId === Auth::id() ? $userId : null;
    }

    /**
     * Parse the `ids` query parameter into a bounded list of positive integers.
     * Accepts both `ids[]=1&ids[]=2` and `ids=1,2,3` forms. Caps at 200 to
     * keep the WHERE IN clause and URL length sane. Returns null when absent
     * or empty so callers can distinguish "no filter" from "matched nothing".
     */
    private function parseIdsParam(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $values),
            fn ($id) => $id > 0,
        )));

        if (empty($ids)) {
            return null;
        }

        return array_slice($ids, 0, 200);
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
        $folders = $this->folders->forPicker();

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

                    // Resolved BEFORE the etag check, because it is what decides whether that check
                    // applies. Only reachable in keep_original_filename mode: otherwise the key is
                    // a fresh UUID and can never collide.
                    //
                    // Gated on the collision, not on keep_original_filename. Those are not the same
                    // thing, and conflating them is what let identical bytes upload twice under
                    // different names. An overwrite is not a duplicate; a second copy under another
                    // name is — see duplicate-detection.md REQ-2.
                    $existingByKey = $this->ingestion->existingAtKey($fileData['s3_key']);

                    $existing = $this->ingestion->findDuplicate($fileData['etag'] ?? null, $existingByKey);
                    if ($existing) {
                        $this->ingestion->discardDuplicate($fileData['s3_key'], $existing, $fileData['filename']);
                        $duplicates[] = DuplicateAssetException::formatDuplicate($existing, $fileData['filename']);

                        continue;
                    }

                    if ($existingByKey) {
                        // Overwrite: the old derived files describe the old bytes
                        $this->s3Service->deleteAssetFiles($existingByKey, keepOriginal: true);
                    }

                    $asset = $this->ingestion->persist([
                        's3_key' => $fileData['s3_key'],
                        'filename' => $fileData['filename'],
                        'mime_type' => $fileData['mime_type'],
                        'size' => $fileData['size'],
                        'etag' => $fileData['etag'] ?? null,
                        'width' => $fileData['width'],
                        'height' => $fileData['height'],
                        'user_id' => Auth::id(),
                    ], $existingByKey);

                    // Generate thumbnail, resized images, and AI tags
                    $this->assetProcessingService->processImageAsset($asset);

                    $this->assetProcessingService->applyUploadMetadata($asset, $request->uploadMetadata());

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
                        'message' => __('All uploads failed. Please check the logs for details.'),
                    ], 500);
                }

                return redirect()->back()
                    ->with('error', __('All uploads failed. Please check the file formats and try again.'));
            }

            if ($request->expectsJson()) {
                foreach ($uploadedAssets as $a) {
                    $a->append(Asset::APPEND_FIELDS);
                }

                $response = [
                    'message' => __(':count file(s) uploaded successfully', ['count' => count($uploadedAssets)]),
                    'assets' => $uploadedAssets,
                ];
                if (! empty($duplicates)) {
                    $response['duplicates'] = $duplicates;
                }

                return response()->json($response);
            }

            $successMessage = __(':count file(s) uploaded successfully', ['count' => count($uploadedAssets)]);
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
                    'message' => __('Upload failed: :error', ['error' => $e->getMessage()]),
                ], 500);
            }

            return redirect()->back()
                ->with('error', __('Upload failed: :error', ['error' => $e->getMessage()]));
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
            if (! $request->has($key)) {
                continue;
            }
            $value = $request->input($key);
            if ($value === '' || $value === null) {
                continue;
            }
            // ids[] is an array — drop the key if every entry is empty/zero so
            // a stray `?ids=` doesn't masquerade as an active filter.
            if ($key === 'ids' && $this->parseIdsParam($value) === null) {
                continue;
            }
            $context[$key] = $value;
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

        if ($userId = $this->permittedUserFilter($request)) {
            if ($user = User::find($userId)) {
                $parts[] = __('Uploaded by').' '.$user->name;
            }
        }

        if ($request->boolean('missing')) {
            $parts[] = __('Missing');
        }

        if ($ids = $this->parseIdsParam($request->input('ids'))) {
            $parts[] = trans_choice(':count selected asset|:count selected assets', count($ids), ['count' => count($ids)]);
        }

        $summary = '';
        if (! empty($parts)) {
            $summary = __('Filtered by').' '.implode(' · ', $parts);
        }

        $sort = $request->input('sort');
        if ($sort && $sort !== 'date_desc') {
            $sortLabel = AssetSort::tryFrom($sort)?->label() ?? $sort;
            $summary .= ($summary !== '' ? ' · ' : '').__('sorted by').' '.$sortLabel;
        }

        return $summary;
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

        $asset->update(array_merge($request->metadataAttributes(), ['last_modified_by' => Auth::id()]));

        // Handle tags only if explicitly included in request
        if ($request->touchesTags()) {
            $asset->syncTagsPreservingAi($request->userTagIds(), $request->referenceTagIds());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Asset updated successfully'),
                'asset' => $asset->fresh(['tags'])->append(Asset::APPEND_FIELDS),
            ]);
        }

        return redirect()->route('assets.show', $asset)
            ->with('success', __('Asset updated successfully'));
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
            'tags.*' => 'string|max:'.Tag::MAX_NAME_LENGTH,
            'reference_tag_ids' => 'required_without:tags|array',
            'reference_tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($q) => $q->where('type', 'reference')),
            ],
        ]);

        // Split any comma-separated entries so "a,b,c" sent as one value still works.
        $tagNames = TagInputParser::parse($request->input('tags', []));
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
            'message' => __('Tags added successfully'),
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
            'message' => __('Tag removed successfully'),
        ]);
    }
}
