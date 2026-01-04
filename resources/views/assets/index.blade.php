@extends('layouts.app')

@section('title', 'Assets')

@section('content')
<div x-data="assetGrid()">
    <!-- Header with search and filters -->
    <div class="mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <h1 class="text-3xl font-bold text-gray-900">Assets</h1>
            
            <div class="flex flex-col sm:flex-row gap-3">
                <!-- Search -->
                <div class="relative">
                    <input type="text" 
                           x-model="search"
                           @keyup.enter="applyFilters"
                           placeholder="Search assets..." 
                           class="w-full sm:w-64 pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                </div>
                
                <!-- Type filter -->
                <select x-model="type" 
                        @change="applyFilters"
                        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">All Types</option>
                    <option value="image">Images</option>
                    <option value="video">Videos</option>
                    <option value="application">Documents</option>
                </select>
                
                <!-- Tag filter -->
                <button @click="showTagFilter = !showTagFilter" 
                        class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center justify-center">
                    <i class="fas fa-filter mr-2"></i>
                    <span x-text="selectedTags.length > 0 ? `Tags (${selectedTags.length})` : 'Filter Tags'"></span>
                </button>
            </div>
        </div>
        
        <!-- Tag filter dropdown -->
        <div x-show="showTagFilter" 
             x-cloak
             @click.away="showTagFilter = false"
             class="mt-4 bg-white border border-gray-200 rounded-lg shadow-lg p-4 max-w-md">
            <h3 class="font-semibold mb-3">Filter by Tags</h3>
            <div class="max-h-60 overflow-y-auto space-y-2">
                @foreach($tags as $tag)
                <label class="flex items-center space-x-2 p-2 hover:bg-gray-50 rounded cursor-pointer">
                    <input type="checkbox" 
                           value="{{ $tag->id }}"
                           x-model="selectedTags"
                           @change="applyFilters"
                           class="rounded text-blue-600 focus:ring-blue-500">
                    <span class="flex-1">{{ $tag->name }}</span>
                    <span class="text-xs px-2 py-1 rounded-full {{ $tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                        {{ $tag->type }}
                    </span>
                </label>
                @endforeach
            </div>
            
            @if(count($tags) === 0)
            <p class="text-gray-500 text-sm">No tags available yet.</p>
            @endif
        </div>
    </div>
    
    <!-- Asset grid -->
    @if($assets->count() > 0)
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
        @foreach($assets as $asset)
        <div class="group relative bg-white rounded-lg shadow hover:shadow-lg transition-shadow overflow-hidden cursor-pointer"
             @click="window.location.href = '{{ route('assets.show', $asset) }}'">
            <!-- Thumbnail -->
            <div class="aspect-square bg-gray-100 relative">
                @if($asset->isImage() && $asset->thumbnail_url)
                    <img src="{{ $asset->thumbnail_url }}" 
                         alt="{{ $asset->filename }}"
                         class="w-full h-full object-cover"
                         loading="lazy">
                @else
                    <div class="w-full h-full flex items-center justify-center">
                        <i class="fas fa-file text-4xl text-gray-400"></i>
                    </div>
                @endif
                
                <!-- Overlay with actions -->
                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                    <button @click.stop="copyUrl('{{ $asset->url }}')" 
                            class="bg-white text-gray-900 px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors mr-2"
                            title="Copy URL">
                        <i class="fas fa-copy"></i>
                    </button>
                    <a href="{{ route('assets.edit', $asset) }}" 
                       @click.stop
                       class="bg-white text-gray-900 px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors"
                       title="Edit">
                        <i class="fas fa-edit"></i>
                    </a>
                </div>
            </div>
            
            <!-- Info -->
            <div class="p-3">
                <p class="text-sm font-medium text-gray-900 truncate" title="{{ $asset->filename }}">
                    {{ $asset->filename }}
                </p>
                <p class="text-xs text-gray-500 mt-1">{{ $asset->formatted_size }}</p>
                
                @if($asset->tags->count() > 0)
                <div class="flex flex-wrap gap-1 mt-2">
                    @foreach($asset->tags->take(2) as $tag)
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                        {{ $tag->name }}
                    </span>
                    @endforeach
                    
                    @if($asset->tags->count() > 2)
                    <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">
                        +{{ $asset->tags->count() - 2 }}
                    </span>
                    @endif
                </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    
    <!-- Pagination -->
    <div class="mt-8">
        {{ $assets->links() }}
    </div>
    
    @else
    <div class="text-center py-12">
        <i class="fas fa-images text-6xl text-gray-300 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">No assets found</h3>
        <p class="text-gray-500 mb-6">
            @if(request()->has('search') || request()->has('tags') || request()->has('type'))
                Try adjusting your filters or
                <a href="{{ route('assets.index') }}" class="text-blue-600 hover:underline">clear all filters</a>
            @else
                Get started by uploading your first asset
            @endif
        </p>
        <a href="{{ route('assets.create') }}" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <i class="fas fa-upload mr-2"></i> Upload Assets
        </a>
    </div>
    @endif
</div>

@push('scripts')
<script>
function assetGrid() {
    return {
        search: '{{ request('search', '') }}',
        type: '{{ request('type', '') }}',
        selectedTags: {{ json_encode(request('tags', [])) }},
        showTagFilter: false,
        
        applyFilters() {
            const params = new URLSearchParams();
            
            if (this.search) params.append('search', this.search);
            if (this.type) params.append('type', this.type);
            if (this.selectedTags.length > 0) {
                this.selectedTags.forEach(tag => params.append('tags[]', tag));
            }
            
            window.location.href = '{{ route('assets.index') }}' + (params.toString() ? '?' + params.toString() : '');
        },
        
        copyUrl(url) {
            window.copyToClipboard(url);
        }
    };
}
</script>
@endpush
@endsection
