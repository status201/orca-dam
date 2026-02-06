@extends('layouts.app')

@section('title', 'Assets')

@section('content')
<div x-data="assetGrid()">
    <!-- Header with search and filters -->
    <div class="mb-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Assets</h1>
                <p class="text-gray-600 mt-2">Browse and manage your digital assets</p>
            </div>

            <div class="flex flex-col gap-3">
                <!-- Row 1: Search (full width on sm-lg, auto on lg+) -->
                <div class="relative lg:hidden">
                    <input type="text"
                           x-model="search"
                           @keyup.enter="applyFilters"
                           placeholder="Search assets..."
                           class="w-full pl-10 pr-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                </div>

                <!-- Row 2: Filters and Upload -->
                <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-end gap-3">
                    <!-- Search (hidden on mobile, visible inline on lg+) -->
                    <div class="relative hidden lg:block">
                        <input type="text"
                               x-model="search"
                               @keyup.enter="applyFilters"
                               placeholder="Search assets..."
                               class="w-64 pl-10 pr-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    </div>

                    <!-- Folder filter -->
                    <select x-model="folder"
                            @change="applyFilters"
                            class="pr-dropdown px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent font-mono">
                        @foreach($folders as $f)
                            @php
                                $rootPrefix = $rootFolder !== '' ? $rootFolder . '/' : '';
                                $relativePath = ($f === '' || ($rootFolder !== '' && $f === $rootFolder)) ? '' : ($rootPrefix !== '' ? str_replace($rootPrefix, '', $f) : $f);
                                $depth = $relativePath ? substr_count($relativePath, '/') + 1 : 0;
                                $label = ($f === '' || ($rootFolder !== '' && $f === $rootFolder)) ? '/ (root)' : str_repeat('╎  ', max(0, $depth - 1)) . '├─ ' . basename($f);
                            @endphp
                            <option value="{{ $f }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <!-- Sort -->
                    <select x-model="sort"
                            @change="applyFilters"
                            class="pr-dropdown px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <option value="date_desc">Newest First</option>
                        <option value="date_asc">Oldest First</option>
                        <option value="upload_desc">Newest Uploads</option>
                        <option value="upload_asc">Oldest Uploads</option>
                        <option value="size_desc">Largest First</option>
                        <option value="size_asc">Smallest First</option>
                        <option value="name_asc">Name A-Z</option>
                        <option value="name_desc">Name Z-A</option>
                        <option value="s3key_asc">S3 Key A-Z</option>
                        <option value="s3key_desc">S3 Key Z-A</option>
                    </select>

                    <!-- Type filter -->
                    <select x-model="type"
                            @change="applyFilters"
                            class="pr-dropdown px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <option value="">All Types</option>
                        <option value="image">Images</option>
                        <option value="video">Videos</option>
                        <option value="application">Documents</option>
                    </select>

                    <!-- Tag filter -->
                    <button @click="showTagFilter = !showTagFilter"
                            class="px-4 py-2 bg-white text-sm border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center justify-center">
                        <i class="fas fa-filter mr-2"></i>
                        <span x-text="selectedTags.length > 0 ? `Tags (${selectedTags.length})` : 'Filter Tags'"></span>
                    </button>

                    <!-- Upload button -->
                    <a :href="`{{ route('assets.create') }}${folder ? '?folder=' + encodeURIComponent(folder) : ''}`"
                       class="px-4 py-2 text-sm bg-orca-black text-white rounded-lg hover:bg-orca-black-hover flex items-center justify-center whitespace-nowrap">
                        <i class="fas fa-upload mr-2"></i> Upload
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Tag filter dropdown -->
        <div x-show="showTagFilter"
             x-cloak
             @click.away="if (selectedTags.length === 0) showTagFilter = false"
             class="mt-4 bg-white border text-sm border-gray-200 rounded-lg shadow-lg p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold">Filter by Tags</h3>
                <div class="flex items-center gap-3">
                    <!-- Tag search input -->
                    <div class="relative">
                        <input type="text"
                               x-model="tagSearch"
                               placeholder="Search tags..."
                               class="text-sm pl-8 pr-3 py-1 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent w-40">
                        <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    </div>
                    <div class="flex gap-2">
                        <button @click="applyFilters()"
                                x-show="tagsChanged()"
                                class="text-sm px-4 py-1 bg-orca-black text-white hover:bg-orca-black-hover rounded-lg transition">
                            <i class="fas fa-check mr-1"></i> Apply
                        </button>
                        <button @click="selectedTags = []; tagSearch = ''"
                                x-show="selectedTags.length > 0"
                                class="text-sm px-3 py-1 text-red-600 hover:bg-red-50 rounded-lg transition">
                            <i class="fas fa-times mr-1"></i> Clear All
                        </button>
                    </div>
                </div>
            </div>
            <div class="max-h-96 overflow-y-auto invert-scrollbar-colors">
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-2">
                    <template x-for="tag in allTagsData" :key="tag.id">
                        <label x-show="shouldShowTag(tag)"
                               class="flex items-start space-x-2 p-2 hover:bg-gray-50 rounded cursor-pointer border border-gray-200">
                            <input type="checkbox"
                                   :value="tag.id"
                                   x-model="selectedTags"
                                   class="rounded text-blue-600 focus:ring-orca-black flex-shrink-0 mt-0.5">
                            <div class="flex flex-col gap-1 min-w-0 flex-1">
                                <span class="text-sm font-medium truncate" x-text="tag.name"></span>
                                <span :class="tag.type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'"
                                      class="tag attention text-xs px-2 py-0.5 rounded-full inline-block w-fit"
                                      x-text="tag.type"></span>
                            </div>
                        </label>
                    </template>
                </div>
            </div>

            @if(count($tags) === 0)
            <p class="text-gray-500 text-sm">No tags available yet.</p>
            @endif
        </div>
    </div>

    <!-- View Toggle -->
    <div class="mb-4 flex justify-end">
        <div class="inline-flex rounded-md shadow-sm" role="group">
            <button @click="viewMode = 'grid'; saveViewMode()"
                    :class="viewMode === 'grid' ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                    class="px-4 py-2 text-xs font-medium border border-gray-300 rounded-l-lg transition-colors">
                <i class="fas fa-th mr-2"></i> Grid
            </button>
            <button @click="viewMode = 'list'; saveViewMode()"
                    :class="viewMode === 'list' ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                    class="px-4 py-2 text-xs font-medium border border-gray-300 rounded-r-lg transition-colors">
                <i class="fas fa-list mr-2"></i> List
            </button>
        </div>
    </div>

    <!-- Asset grid -->
    @if($assets->count() > 0)
    <!-- Grid View -->
    <div x-show="viewMode === 'grid'" x-cloak class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 xxl:grid-cols-12 gap-4">
        @foreach($assets as $asset)
        <div class="group relative bg-white rounded-lg shadow hover:shadow-lg transition-shadow overflow-hidden cursor-pointer"
             x-data="assetCard({{ $asset->id }})"
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
                        @php
                            $icon = $asset->getFileIcon();
                            $colorClass = match($icon) {
                                'fa-file-pdf' => 'text-red-500',
                                'fa-file-word' => 'text-blue-600',
                                'fa-file-excel' => 'text-green-600',
                                'fa-file-powerpoint' => 'text-orange-500',
                                'fa-file-zipper' => 'text-yellow-600',
                                'fa-file-code' => 'text-purple-600',
                                'fa-file-video' => 'text-pink-600',
                                'fa-file-audio' => 'text-indigo-600',
                                'fa-file-csv' => 'text-teal-600',
                                'fa-file-lines' => 'text-gray-500',
                                default => 'text-gray-400'
                            };
                        @endphp
                        <i class="fas {{ $icon }} text-9xl {{ $colorClass }} opacity-60"></i>
                    </div>
                @endif

                <!-- Overlay with actions -->
                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                    <button @click.stop="downloadAsset('{{ route('assets.download', $asset) }}')"
                            :disabled="downloading"
                            :class="downloading ? 'bg-green-600' : 'bg-white hover:bg-gray-100'"
                            :title="downloading ? 'Downloading...' : 'Download'"
                            class="text-gray-900 px-3 py-2 rounded-lg transition-all duration-300 mr-2">
                        <i :class="downloading ? 'fas fa-spinner fa-spin text-white' : 'fas fa-download'"></i>
                    </button>
                    <button @click.stop="copyAssetUrl('{{ $asset->url }}')"
                            :class="copied ? 'bg-green-600' : 'bg-white hover:bg-gray-100'"
                            :title="copied ? 'Copied!' : 'Copy URL'"
                            class="text-gray-900 px-3 py-2 rounded-lg transition-all duration-300 mr-2">
                        <i :class="copied ? 'fas fa-check text-white' : 'fas fa-copy'"></i>
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
                <div class="text-xs text-gray-500 mt-1 space-y-0.5">
                    <p><i class="fas fa-hdd mr-1"></i>{{ $asset->formatted_size }}</p>
                    <p title="{{ $asset->updated_at }}"><i class="fas fa-clock mr-1"></i>{{ $asset->updated_at->diffForHumans() }}</p>
                    <p class="truncate" title="{{ $asset->user->name }}"><i class="fas fa-user mr-1"></i>{{ $asset->user->name }}</p>
                </div>

                @if($asset->tags->count() > 0)
                <div class="flex flex-wrap gap-1 mt-2">
                    @foreach($asset->tags->take(2) as $tag)
                    <span class="tag attention text-xs px-2 py-0.5 rounded-full {{ $tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
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

    <!-- List/Table View -->
    <div x-show="viewMode === 'list'" x-cloak class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto invert-scrollbar-colors">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">
                            Thumbnail
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[200px]">
                            Filename
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-40">
                            Actions
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[250px]">
                            S3 Key
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">
                            Size
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[300px]">
                            Tags
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[180px]">
                            License
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($assets as $asset)
                    <tr x-data="assetRow({{ $asset->id }}, @js($asset->tags->map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'type' => $t->type])->toArray()), '{{ $asset->license_type }}', '{{ $asset->url }}')"
                        class="hover:bg-gray-50 transition-colors">

                        <!-- Thumbnail -->
                        <td class="px-4 py-3">
                            <a href="{{ route('assets.show', $asset) }}" class="block">
                                <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center overflow-hidden hover:ring-2 hover:ring-orca-500 transition-all">
                                    @if($asset->isImage() && $asset->thumbnail_url)
                                        <img src="{{ $asset->thumbnail_url }}"
                                             alt="{{ $asset->filename }}"
                                             class="w-full h-full object-cover"
                                             loading="lazy">
                                    @else
                                        @php
                                            $icon = $asset->getFileIcon();
                                            $colorClass = match($icon) {
                                                'fa-file-pdf' => 'text-red-500',
                                                'fa-file-word' => 'text-blue-600',
                                                'fa-file-excel' => 'text-green-600',
                                                'fa-file-powerpoint' => 'text-orange-500',
                                                'fa-file-zipper' => 'text-yellow-600',
                                                'fa-file-code' => 'text-purple-600',
                                                'fa-file-video' => 'text-pink-600',
                                                'fa-file-audio' => 'text-indigo-600',
                                                'fa-file-csv' => 'text-teal-600',
                                                'fa-file-lines' => 'text-gray-500',
                                                default => 'text-gray-400'
                                            };
                                        @endphp
                                        <i class="fas {{ $icon }} text-3xl {{ $colorClass }} opacity-60"></i>
                                    @endif
                                </div>
                            </a>
                        </td>

                        <!-- Filename -->
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-gray-900">{{ $asset->filename }}</div>
                            <div class="text-xs text-gray-500 mt-1">
                                <span title="Last modified {{ $asset->updated_at }}">{{ $asset->updated_at->diffForHumans() }}</span>
                                <span class="mx-1">•</span>
                                <span title="Uploaded by {{ $asset->user->email }}">{{ $asset->user->name }}</span>
                            </div>
                        </td>

                        <!-- Actions -->
                        <td class="actions-icons px-4 py-3">
                            <div class="flex gap-3">
                                <a href="{{ route('assets.show', $asset) }}"
                                   class="text-blue-600 hover:text-blue-800"
                                   title="View asset">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button @click="copyUrl()"
                                        :class="copied ? 'text-green-600' : 'text-gray-600 hover:text-gray-800'"
                                        :title="copied ? 'Copied!' : 'Copy URL'">
                                    <i :class="copied ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                                <a href="{{ route('assets.edit', $asset) }}"
                                   class="text-yellow-600 hover:text-yellow-800"
                                   title="Edit asset">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button @click="deleteAsset()"
                                        :disabled="loading"
                                        class="text-red-600 hover:text-red-800 disabled:opacity-50"
                                        title="Delete asset">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>

                        <!-- S3 Key -->
                        <td class="px-4 py-3">
                            <div class="text-xs text-gray-600 font-mono break-all">{{ $asset->s3_key }}</div>
                        </td>

                        <!-- File Size -->
                        <td class="px-4 py-3">
                            <span class="text-sm text-gray-700">{{ $asset->formatted_size }}</span>
                        </td>

                        <!-- Tags with Inline Editing -->
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <!-- Existing Tags -->
                                <template x-for="(tag, index) in tags" :key="tag.id">
                                    <span :class="tag.type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'"
                                          class="tag attention inline-flex items-center px-2 py-1 rounded text-xs font-medium">
                                        <span x-text="tag.name"></span>
                                        <button @click="removeTag(tag)"
                                                :disabled="loading"
                                                class="ml-1 hover:text-red-600 disabled:opacity-50"
                                                title="Remove tag">
                                            <i class="fas fa-times text-xs"></i>
                                        </button>
                                    </span>
                                </template>

                                <!-- Add Tag Button/Input -->
                                <div x-show="!addingTag">
                                    <button @click="showAddTagInput()"
                                            :disabled="loading"
                                            class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-600 hover:bg-gray-200 disabled:opacity-50">
                                        <i class="fas fa-plus mr-1"></i> Add
                                    </button>
                                </div>

                                <div x-show="addingTag" x-cloak class="relative inline-flex items-center gap-1">
                                    <div class="relative">
                                        <input type="text"
                                               x-ref="tagInput"
                                               x-model="newTagName"
                                               @input="filterTagSuggestions()"
                                               @keydown.enter="if(newTagName.trim()) { addTag(); }"
                                               @keydown.escape="cancelAddTag()"
                                               @keydown.arrow-down.prevent="selectNextSuggestion()"
                                               @keydown.arrow-up.prevent="selectPrevSuggestion()"
                                               @blur="setTimeout(() => showSuggestions = false, 200)"
                                               @focus="filterTagSuggestions()"
                                               class="px-2 py-1 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-blue-500"
                                               placeholder="Tag name"
                                               style="width: 120px;">

                                        <!-- Autocomplete dropdown -->
                                        <div x-show="showSuggestions && filteredSuggestions.length > 0"
                                             x-cloak
                                             class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded shadow-lg max-h-40 overflow-y-auto">
                                            <template x-for="(suggestion, index) in filteredSuggestions" :key="suggestion">
                                                <button type="button"
                                                        @click="selectSuggestion(suggestion)"
                                                        :class="selectedSuggestionIndex === index ? 'bg-blue-100' : 'hover:bg-gray-100'"
                                                        class="w-full text-left px-3 py-1.5 text-xs text-gray-700 border-b border-gray-100 last:border-b-0"
                                                        x-text="suggestion">
                                                </button>
                                            </template>
                                        </div>
                                    </div>

                                    <button @click="addTag()"
                                            :disabled="!newTagName.trim() || loading"
                                            class="px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700 disabled:opacity-50">
                                        Add
                                    </button>
                                    <button @click="cancelAddTag()"
                                            class="px-2 py-1 bg-gray-200 text-gray-700 text-xs rounded hover:bg-gray-300">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </td>

                        <!-- License with Inline Editing -->
                        <td class="px-4 py-3">
                            <select x-model="license"
                                    @change="updateLicense()"
                                    :disabled="loading"
                                    class="text-sm border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50">
                                <option value="">Not specified</option>
                                <option value="public_domain">Public Domain</option>
                                <option value="cc0">CC0</option>
                                <option value="cc_by">CC BY</option>
                                <option value="cc_by_sa">CC BY-SA</option>
                                <option value="cc_by_nd">CC BY-ND</option>
                                <option value="cc_by_nc">CC BY-NC</option>
                                <option value="cc_by_nc_sa">CC BY-NC-SA</option>
                                <option value="cc_by_nc_nd">CC BY-NC-ND</option>
                                <option value="fair_use">Fair Use</option>
                                <option value="all_rights_reserved">All Rights Reserved</option>
                                <option value="other">Other</option>
                            </select>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-2">
            <label for="perPageSelect" class="text-sm text-gray-600">Results per page:</label>
            <select id="perPageSelect"
                    x-model="perPage"
                    @change="savePerPage(); applyFilters()"
                    class="text-sm border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                <option value="12">12</option>
                <option value="24">24</option>
                <option value="36">36</option>
                <option value="48">48</option>
                <option value="60">60</option>
                <option value="72">72</option>
                <option value="96">96</option>
            </select>
        </div>
        <div>
            {{ $assets->links() }}
        </div>
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
        <a :href="`{{ route('assets.create') }}${folder ? '?folder=' + encodeURIComponent(folder) : ''}`" class="inline-flex items-center px-6 py-3 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
            <i class="fas fa-upload mr-2"></i> Upload Assets
        </a>
    </div>
    @endif
</div>

@push('scripts')
<script>
// Make all tags available globally for autocomplete
window.allTags = @json($tags->pluck('name')->toArray());

function assetGrid() {
    return {
        search: @json(request('search', '')),
        type: @json(request('type', '')),
        folder: @json($folder),
        sort: @json(request('sort', 'date_desc')),
        selectedTags: @json(request('tags', [])),
        initialTags: @json(request('tags', [])),
        showTagFilter: false,
        viewMode: localStorage.getItem('orcaAssetViewMode') || 'grid',
        perPage: localStorage.getItem('orcaAssetsPerPage') || '{{ $perPage }}',
        tagSearch: '',
        allTagsData: @json($tags->map(fn($t) => ['id' => (string)$t->id, 'name' => $t->name, 'type' => $t->type])),

        init() {
            // If user has a stored preference and URL doesn't have per_page, apply it
            const storedPerPage = localStorage.getItem('orcaAssetsPerPage');
            const urlParams = new URLSearchParams(window.location.search);
            if (storedPerPage && !urlParams.has('per_page') && storedPerPage !== '{{ $perPage }}') {
                urlParams.set('per_page', storedPerPage);
                window.location.href = '{{ route('assets.index') }}?' + urlParams.toString();
            }
        },

        saveViewMode() {
            localStorage.setItem('orcaAssetViewMode', this.viewMode);
        },

        savePerPage() {
            localStorage.setItem('orcaAssetsPerPage', this.perPage);
        },

        tagsChanged() {
            // Check if the selected tags differ from initial tags
            if (this.selectedTags.length !== this.initialTags.length) {
                return true;
            }
            // Check if all tags match (order doesn't matter)
            const selected = [...this.selectedTags].sort();
            const initial = [...this.initialTags].sort();
            return !selected.every((tag, index) => tag === initial[index]);
        },

        applyFilters() {
            const params = new URLSearchParams();

            if (this.search) params.append('search', this.search);
            if (this.type) params.append('type', this.type);
            if (this.folder) params.append('folder', this.folder);
            if (this.sort) params.append('sort', this.sort);
            if (this.perPage) params.append('per_page', this.perPage);
            if (this.selectedTags.length > 0) {
                this.selectedTags.forEach(tag => params.append('tags[]', tag));
            }

            window.location.href = '{{ route('assets.index') }}' + (params.toString() ? '?' + params.toString() : '');
        },

        copyUrl(url) {
            window.copyToClipboard(url);
        },

        shouldShowTag(tag) {
            // Always show selected tags
            if (this.selectedTags.includes(tag.id)) {
                return true;
            }
            // Filter unselected tags by search
            if (!this.tagSearch.trim()) {
                return true;
            }
            return tag.name.toLowerCase().includes(this.tagSearch.toLowerCase());
        }
    };
}

function assetCard(assetId) {
    return {
        copied: false,
        downloading: false,

        async downloadAsset(url) {
            this.downloading = true;
            try {
                // Trigger the download
                window.location.href = url;

                // Show success state briefly
                setTimeout(() => {
                    this.downloading = false;
                }, 2000);
            } catch (error) {
                console.error('Download failed:', error);
                this.downloading = false;
                window.showToast('Download failed', 'error');
            }
        },

        copyAssetUrl(url) {
            window.copyToClipboard(url);
            this.copied = true;
            setTimeout(() => {
                this.copied = false;
            }, 2000);
        }
    };
}

function assetRow(assetId, initialTags, initialLicense, assetUrl) {
    return {
        assetId: assetId,
        tags: initialTags,
        license: initialLicense,
        previousLicense: initialLicense,
        assetUrl: assetUrl,
        addingTag: false,
        newTagName: '',
        loading: false,
        copied: false,
        showSuggestions: false,
        filteredSuggestions: [],
        selectedSuggestionIndex: -1,

        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        },

        showToast(message, type = 'success') {
            // Use existing toast if available, otherwise fallback to console
            if (window.showToast) {
                window.showToast(message, type);
            } else {
                console.log(`[${type}] ${message}`);
            }
        },

        copyUrl() {
            if (window.copyToClipboard) {
                window.copyToClipboard(this.assetUrl);
            } else {
                navigator.clipboard.writeText(this.assetUrl);
            }
            this.copied = true;
            setTimeout(() => {
                this.copied = false;
            }, 2000);
        },

        showAddTagInput() {
            this.addingTag = true;
            // Focus the input field after Alpine renders it
            this.$nextTick(() => {
                this.$refs.tagInput.focus();
            });
        },

        cancelAddTag() {
            this.addingTag = false;
            this.newTagName = '';
            this.showSuggestions = false;
            this.selectedSuggestionIndex = -1;
        },

        filterTagSuggestions() {
            const input = this.newTagName.toLowerCase().trim();
            const existingTagNames = this.tags.map(t => t.name.toLowerCase());

            if (input === '') {
                // Show all tags not already on this asset
                this.filteredSuggestions = (window.allTags || [])
                    .filter(tag => !existingTagNames.includes(tag.toLowerCase()))
                    .slice(0, 10);
            } else {
                // Filter tags that match the input and aren't already on this asset
                this.filteredSuggestions = (window.allTags || [])
                    .filter(tag =>
                        tag.toLowerCase().includes(input) &&
                        !existingTagNames.includes(tag.toLowerCase())
                    )
                    .slice(0, 10);
            }

            this.showSuggestions = true;
            this.selectedSuggestionIndex = -1;
        },

        selectSuggestion(suggestion) {
            this.newTagName = suggestion;
            this.showSuggestions = false;
            this.selectedSuggestionIndex = -1;
            // Focus back on input so user can press Enter to add
            this.$refs.tagInput.focus();
        },

        selectNextSuggestion() {
            if (this.filteredSuggestions.length === 0) return;

            this.selectedSuggestionIndex =
                (this.selectedSuggestionIndex + 1) % this.filteredSuggestions.length;

            // Update input with selected suggestion
            if (this.selectedSuggestionIndex >= 0) {
                this.newTagName = this.filteredSuggestions[this.selectedSuggestionIndex];
            }
        },

        selectPrevSuggestion() {
            if (this.filteredSuggestions.length === 0) return;

            this.selectedSuggestionIndex =
                this.selectedSuggestionIndex <= 0
                    ? this.filteredSuggestions.length - 1
                    : this.selectedSuggestionIndex - 1;

            // Update input with selected suggestion
            if (this.selectedSuggestionIndex >= 0) {
                this.newTagName = this.filteredSuggestions[this.selectedSuggestionIndex];
            }
        },

        async removeTag(tag) {
            if (this.loading) return;

            this.loading = true;
            try {
                const response = await fetch(`/assets/${this.assetId}/tags/${tag.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to remove tag');
                }

                // Remove tag from local array
                this.tags = this.tags.filter(t => t.id !== tag.id);
                this.showToast('Tag removed successfully', 'success');
            } catch (error) {
                console.error('Failed to remove tag:', error);
                this.showToast('Failed to remove tag', 'error');
            } finally {
                this.loading = false;
            }
        },

        async addTag() {
            if (this.loading || !this.newTagName.trim()) return;

            this.loading = true;
            try {
                const response = await fetch(`/assets/${this.assetId}/tags`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ tags: [this.newTagName.trim()] }),
                });

                if (!response.ok) {
                    throw new Error('Failed to add tag');
                }

                const data = await response.json();

                // Response includes all tags - update our local array
                if (data.tags && Array.isArray(data.tags)) {
                    this.tags = data.tags.map(t => ({
                        id: t.id,
                        name: t.name,
                        type: t.type
                    }));
                }

                this.showToast('Tag added successfully', 'success');
                this.newTagName = '';
                this.addingTag = false;
                this.showSuggestions = false;
                this.selectedSuggestionIndex = -1;
            } catch (error) {
                console.error('Failed to add tag:', error);
                this.showToast('Failed to add tag', 'error');
            } finally {
                this.loading = false;
            }
        },

        async updateLicense() {
            if (this.loading) return;

            // this.license has already been updated by x-model
            const newLicense = this.license;
            const oldLicense = this.previousLicense;

            // Don't make request if value hasn't actually changed
            if (newLicense === oldLicense) return;

            this.loading = true;

            try {
                // Use POST with _method override for better compatibility
                const formData = new FormData();
                formData.append('_method', 'PATCH');
                formData.append('license_type', newLicense || '');

                const response = await fetch(`/assets/${this.assetId}`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    console.error('Update failed:', errorData);
                    throw new Error(errorData.message || 'Failed to update license');
                }

                const data = await response.json();
                // Update previousLicense to the new value after successful save
                this.previousLicense = newLicense;
                this.showToast('License updated successfully', 'success');
            } catch (error) {
                console.error('Failed to update license:', error);
                this.showToast(error.message || 'Failed to update license', 'error');
                // Revert to previous value on error
                this.license = oldLicense;
            } finally {
                this.loading = false;
            }
        },

        async deleteAsset() {
            if (this.loading) return;

            if (!confirm('Are you sure you want to delete this asset? It will be moved to trash.')) {
                return;
            }

            this.loading = true;
            try {
                const response = await fetch(`/assets/${this.assetId}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to delete asset');
                }

                this.showToast('Asset deleted successfully', 'success');

                // Reload page after short delay to show updated list
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } catch (error) {
                console.error('Failed to delete asset:', error);
                this.showToast('Failed to delete asset', 'error');
                this.loading = false;
            }
        }
    };
}
</script>
@endpush
@endsection
