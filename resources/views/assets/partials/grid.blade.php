<div x-data="assetGrid()">
    @include('assets.partials.grid-header')

    @include('assets.partials.grid-filters')

    @include('assets.partials.grid-controls')

    @if($assets->count() > 0)
    @include('assets.partials.grid-cards')

    @include('assets.partials.grid-pagination')

    @include('assets.partials.grid-bulk-bar')

    @include('assets.partials.grid-bulk-modals')

    @else
    <div class="text-center py-12">
        <i class="fas fa-images text-6xl text-gray-300 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">{{ __('No assets found') }}</h3>
        <p class="text-gray-500 mb-6">
            @if(request()->has('search') || request()->has('tags') || request()->has('type'))
                {{ __('Try adjusting your filters or') }}
                <a href="{{ route($indexRoute) }}" class="text-blue-600 hover:underline">{{ __('clear all filters') }}</a>
            @else
                {{ __('Get started by uploading your first asset') }}
            @endif
        </p>
        <a :href="`{{ route('assets.create') }}${folder ? '?folder=' + encodeURIComponent(folder) : ''}`" class="inline-flex items-center px-6 py-3 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
            <i class="fas fa-upload mr-2"></i> {{ __('Upload Assets') }}
        </a>
    </div>
    @endif
</div>

@push('scripts')
<script>
// Page data for Alpine.js components
window.currentPageAssetIds = @json($assets->pluck('id')->toArray());

window.assetGridConfig = {
    search: @json(request('search', '')),
    type: @json(request('type', '')),
    folder: @json($folder),
    rootFolder: @json($rootFolder),
    folderCount: {{ count($folders) }},
    sort: @json(request('sort', 'date_desc')),
    user: @json($filterUser['id'] ?? ''),
    userName: @json($filterUser['name'] ?? ''),
    selectedTags: @json(request('tags', [])),
    initialTags: @json(request('tags', [])),
    perPage: '{{ $perPage }}',
    indexRoute: '{{ route($indexRoute) }}'
};

window.assetTranslations = {
    downloadFailed: @js(__('Download failed')),
    tagRemoved: @js(__('Tag removed successfully')),
    tagRemoveFailed: @js(__('Failed to remove tag')),
    tagAdded: @js(__('Tag added successfully')),
    tagAddFailed: @js(__('Failed to add tag')),
    licenseUpdated: @js(__('License updated successfully')),
    licenseUpdateFailed: @js(__('Failed to update license')),
    deleteConfirm: @js(__('Are you sure you want to delete this asset? It will be moved to trash.')),
    assetDeleted: @js(__('Asset deleted successfully')),
    assetDeleteFailed: @js(__('Failed to delete asset')),
    moveConfirm: @js(__('This will change the S3 keys of the selected assets. External links to the old URLs will break. Are you sure?')),
    moveFailed: @js(__('Failed to move assets')),
    forceDeleteConfirm: @js(__('This will PERMANENTLY delete the selected assets, their thumbnails, and all resized formats from S3. External links will no longer work. This action cannot be undone. Are you sure?')),
    forceDeleteFailed: @js(__('Failed to permanently delete assets')),
    urlCopied: @js(__('URL copied to clipboard!')),
    copied: @js(__('Copied!')),
    failedToCopy: @js(__('Failed to copy URL'))
};
</script>
@endpush
