@extends('layouts.app')

@section('title', __('Assets'))

@section('content')
<div x-data="assetGrid()">
    <!-- Header with search and filters -->
    <div class="mb-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">
                    {{ __('Assets') }}
                    <span class="ml-2 relative -top-1 inline-flex items-center justify-center px-3 py-0.5 text-base font-semibold rounded-full bg-gray-200 text-gray-700">
                        {{ number_format($assets->total()) }}
                    </span>
                </h1>
                <p class="text-gray-600 mt-2">{{ __('Browse and manage your digital assets') }}</p>
            </div>

            <div class="flex flex-col gap-3">
                <!-- Row 1: Search (full width on sm-lg, auto on lg+) -->
                <div class="relative lg:hidden">
                    <input type="text"
                           x-model="search"
                           @keyup.enter="applyFilters"
                           placeholder="{{ __('Search... (+require -exclude)') }}"
                           class="w-full pl-10 pr-10 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    <button @click="applyFilters"
                            class="absolute right-2 top-1/2 -translate-y-1/2 p-1 text-gray-500 hover:text-gray-700">
                        <i class="fas fa-arrow-right text-sm"></i>
                    </button>
                </div>

                <!-- Row 2: Filters and Upload -->
                <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-end gap-3">
                    <!-- Search (hidden on mobile, visible inline on lg+) -->
                    <div class="relative hidden lg:block">
                        <input type="text"
                               x-model="search"
                               @keyup.enter="applyFilters"
                               placeholder="{{ __('Search... (+require -exclude)') }}"
                               class="w-64 pl-10 pr-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    </div>

                    <!-- Folder filter -->
                    <select x-model="folder"
                            @change="applyFilters"
                            :class="folder && folderCount > 1 ? 'ring-2 ring-orca-black border-orca-black' : ''"
                            class="pr-dropdown px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent font-mono">
                        <x-folder-tree-options :folders="$folders" :root-folder="$rootFolder" />
                    </select>

                    <!-- Sort -->
                    <select x-model="sort"
                            @change="applyFilters"
                            class="pr-dropdown px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <option value="date_desc">{{ __('Newest First') }}</option>
                        <option value="date_asc">{{ __('Oldest First') }}</option>
                        <option value="upload_desc">{{ __('Newest Uploads') }}</option>
                        <option value="upload_asc">{{ __('Oldest Uploads') }}</option>
                        <option value="size_desc">{{ __('Largest First') }}</option>
                        <option value="size_asc">{{ __('Smallest First') }}</option>
                        <option value="name_asc">{{ __('Name A-Z') }}</option>
                        <option value="name_desc">{{ __('Name Z-A') }}</option>
                        <option value="s3key_asc">{{ __('S3 Key A-Z') }}</option>
                        <option value="s3key_desc">{{ __('S3 Key Z-A') }}</option>
                    </select>

                    <!-- Type filter -->
                    <select x-model="type"
                            @change="applyFilters"
                            :class="type ? 'ring-2 ring-orca-black border-orca-black' : ''"
                            class="pr-dropdown px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <option value="">{{ __('All Types') }}</option>
                        <option value="image">{{ __('Images') }}</option>
                        <option value="video">{{ __('Videos') }}</option>
                        <option value="application">{{ __('Documents') }}</option>
                    </select>

                    <!-- Tag filter -->
                    <button @click="showTagFilter = !showTagFilter"
                            :class="selectedTags.length > 0 ? 'ring-2 ring-orca-black border-orca-black' : ''"
                            class="px-4 py-2 bg-white text-sm border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center justify-center">
                        <i class="fas fa-filter mr-2"></i>
                        <span x-text="selectedTags.length > 0 ? @js(__('Tags')) + ` (${selectedTags.length})` : @js(__('Filter Tags'))"></span>
                    </button>

                    <!-- Upload button -->
                    <a :href="`{{ route('assets.create') }}${folder ? '?folder=' + encodeURIComponent(folder) : ''}`"
                       class="px-4 py-2 text-sm bg-orca-black text-white rounded-lg hover:bg-orca-black-hover flex items-center justify-center whitespace-nowrap">
                        <i class="fas fa-upload mr-2"></i> {{ __('Upload') }}
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
                <h3 class="font-semibold">{{ __('Filter by Tags') }}</h3>
                <div class="flex items-center gap-3">
                    <!-- Tag search input -->
                    <div class="relative">
                        <input type="text"
                               x-model="tagSearch"
                               placeholder="{{ __('Search tags...') }}"
                               class="text-sm pl-8 pr-3 py-1 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent w-40">
                        <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                    </div>
                    <!-- Tag sort dropdown -->
                    <select x-model="tagSort"
                            class="pr-dropdown text-sm px-2 py-1 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <option value="name_asc">{{ __('Name (A-Z)') }}</option>
                        <option value="name_desc">{{ __('Name (Z-A)') }}</option>
                        <option value="most_used">{{ __('Most used') }}</option>
                        <option value="least_used">{{ __('Least used') }}</option>
                        <option value="newest">{{ __('Newest') }}</option>
                        <option value="oldest">{{ __('Oldest') }}</option>
                    </select>
                    <div class="flex gap-2">
                        <button @click="applyFilters()"
                                x-show="tagsChanged()"
                                class="text-sm px-4 py-1 bg-orca-black text-white hover:bg-orca-black-hover rounded-lg transition">
                            <i class="fas fa-check mr-1"></i> {{ __('Apply') }}
                        </button>
                        <button @click="selectedTags = []; tagSearch = ''"
                                x-show="selectedTags.length > 0"
                                class="text-sm px-3 py-1 text-red-600 hover:bg-red-50 rounded-lg transition">
                            <i class="fas fa-times mr-1"></i> {{ __('Clear All') }}
                        </button>
                    </div>
                </div>
            </div>
            <div class="max-h-96 overflow-y-auto invert-scrollbar-colors">
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-2">
                    <template x-for="tag in sortedTags" :key="tag.id">
                        <label x-show="shouldShowTag(tag)"
                               class="flex items-start space-x-2 p-2 hover:bg-gray-50 rounded cursor-pointer border border-gray-200">
                            <input type="checkbox"
                                   :value="tag.id"
                                   x-model="selectedTags"
                                   class="rounded text-blue-600 focus:ring-orca-black flex-shrink-0 mt-0.5">
                            <div class="flex flex-col gap-1 min-w-0 flex-1">
                                <span class="text-sm font-medium truncate" x-text="tag.name"></span>
                                <div class="flex items-center gap-1.5">
                                    <span :class="tag.type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'"
                                          class="tag attention text-xs px-2 py-0.5 rounded-full inline-block w-fit"
                                          x-text="tag.type"></span>
                                    <span class="text-xs text-gray-400" x-text="tag.assets_count"></span>
                                </div>
                            </div>
                        </label>
                    </template>
                </div>
            </div>

            @if(count($tags) === 0)
            <p class="text-gray-500 text-sm">{{ __('No tags available yet.') }}</p>
            @endif
        </div>
    </div>

    <!-- Active Filters Bar -->
    <div x-show="(folder && folderCount > 1) || type || selectedTags.length > 0" x-cloak class="mb-4 flex flex-wrap items-center gap-2">
        <span class="text-sm text-gray-500 font-medium">{{ __('Active filters') }}:</span>

        <!-- Folder pill -->
        <template x-if="folder && folderCount > 1">
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-200 text-orca-black text-sm rounded-full">
                <i class="fas fa-folder text-xs"></i>
                <span x-text="folder"></span>
                <button @click="folder = ''; applyFilters()" class="ml-1 hover:text-gray-600">&times;</button>
            </span>
        </template>

        <!-- Type pill -->
        <template x-if="type">
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-200 text-orca-black text-sm rounded-full">
                <i class="fas fa-file text-xs"></i>
                <span x-text="type.charAt(0).toUpperCase() + type.slice(1)"></span>
                <button @click="type = ''; applyFilters()" class="ml-1 hover:text-gray-600">&times;</button>
            </span>
        </template>

        <!-- Tag pills -->
        <template x-for="tagId in selectedTags" :key="tagId">
            <span class="inline-flex items-center gap-1 px-3 py-1 bg-gray-200 text-orca-black text-sm rounded-full">
                <i class="fas fa-tag text-xs"></i>
                <span x-text="allTagsData.find(t => t.id == tagId)?.name || tagId"></span>
                <button @click="selectedTags = selectedTags.filter(id => id != tagId); applyFilters()" class="ml-1 hover:text-gray-600">&times;</button>
            </span>
        </template>

        <!-- Clear all -->
        <button @click="folder = ''; type = ''; selectedTags = []; applyFilters()"
                class="text-sm text-gray-500 hover:text-gray-700 underline ml-2">
            {{ __('Clear all filters') }}
        </button>
    </div>

    <!-- View Toggle -->
    <div class="mb-4 flex justify-end gap-2">
        <!-- Fit Mode Toggle -->
        <div class="inline-flex rounded-md shadow-sm" role="group">
            <button @click="fitMode = 'cover'; saveFitMode()"
                    :class="fitMode === 'cover' ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                    class="px-3 py-2 text-xs font-medium border border-gray-300 rounded-l-lg transition-colors"
                    title="{{ __('Zoom and crop') }}">
                <i class="fas fa-crop-alt"></i>
            </button>
            <button @click="fitMode = 'contain'; saveFitMode()"
                    :class="fitMode === 'contain' ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                    class="px-3 py-2 text-xs font-medium border border-gray-300 rounded-r-lg transition-colors"
                    title="{{ __('Fit to keep proportions') }}">
                <i class="fas fa-expand"></i>
            </button>
        </div>

        <div class="inline-flex rounded-md shadow-sm" role="group">
            <button @click="viewMode = 'grid'; saveViewMode()"
                    :class="viewMode === 'grid' ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                    class="px-4 py-2 text-xs font-medium border border-gray-300 rounded-l-lg transition-colors">
                <i class="fas fa-th mr-2"></i> {{ __('Grid') }}
            </button>
            <button @click="viewMode = 'list'; saveViewMode()"
                    :class="viewMode === 'list' ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                    class="px-4 py-2 text-xs font-medium border border-gray-300 rounded-r-lg transition-colors">
                <i class="fas fa-list mr-2"></i> {{ __('List') }}
            </button>
        </div>
    </div>

    <!-- Missing assets warning bar -->
    @if($missingCount > 0)
    <div class="attention mb-4 p-3 border border-red-800 rounded-lg flex items-center justify-between">
        <span class="text-sm text-red-800">
            <i class="fas fa-triangle-exclamation mr-2"></i>
            {{ trans_choice(':count asset has a missing S3 object|:count assets have missing S3 objects', $missingCount) }}
        </span>
        <a href="?missing=1" class="text-sm text-red-800 font-medium hover:text-red-700">
            {{ __('View') }} <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>
    @endif

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
                @if($asset->is_missing)
                <div class="absolute top-1 right-1 z-10">
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-red-600 text-white">
                        <i class="fas fa-triangle-exclamation mr-1"></i>{{ __('Missing') }}
                    </span>
                </div>
                @endif
                @if($asset->isImage() && $asset->thumbnail_url)
                    <img src="{{ $asset->thumbnail_url }}"
                         alt="{{ $asset->filename }}"
                         :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                         loading="lazy">
                @elseif($asset->isVideo() && $asset->thumbnail_url)
                    <img src="{{ $asset->thumbnail_url }}"
                         alt="{{ $asset->filename }}"
                         :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                         loading="lazy">
                    <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                        <div class="w-10 h-10 bg-black/50 rounded-full flex items-center justify-center">
                            <i class="fas fa-play text-white text-sm ml-0.5"></i>
                        </div>
                    </div>
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
                                'fa-file-image' => 'text-emerald-600',
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
                            :title="downloading ? @js(__('Downloading...')) : @js(__('Download'))"
                            class="text-gray-900 px-3 py-2 rounded-lg transition-all duration-300 mr-2">
                        <i :class="downloading ? 'fas fa-spinner fa-spin text-white' : 'fas fa-download'"></i>
                    </button>
                    <button @click.stop="copyAssetUrl('{{ $asset->url }}')"
                            :class="copied ? 'attention bg-green-600' : 'bg-white hover:bg-gray-100'"
                            :title="copied ? @js(__('Copied!')) : @js(__('Copy URL'))"
                            class="text-gray-900 px-3 py-2 rounded-lg transition-all duration-300 mr-2">
                        <i :class="copied ? 'fas fa-check text-white' : 'fas fa-copy'"></i>
                    </button>
                    <a href="{{ route('assets.edit', $asset) }}"
                       @click.stop
                       class="bg-white text-gray-900 px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors"
                       title="{{ __('Edit') }}">
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
                            {{ __('Thumbnail') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[200px]">
                            {{ __('Filename') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-40">
                            {{ __('Actions') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[250px]">
                            {{ __('S3 Key') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">
                            {{ __('Size') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[300px]">
                            {{ __('Tags') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[180px]">
                            {{ __('License') }}
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
                                <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center overflow-hidden hover:ring-2 hover:ring-orca-500 transition-all relative">
                                    @if($asset->is_missing)
                                    <div class="absolute top-0 right-0 z-10">
                                        <span class="inline-flex items-center px-1 py-0.5 rounded text-[0.6rem] font-medium bg-red-600 text-white">
                                            <i class="fas fa-triangle-exclamation"></i>
                                        </span>
                                    </div>
                                    @endif
                                    @if($asset->isImage() && $asset->thumbnail_url)
                                        <img src="{{ $asset->thumbnail_url }}"
                                             alt="{{ $asset->filename }}"
                                             :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                                             loading="lazy">
                                    @elseif($asset->isVideo() && $asset->thumbnail_url)
                                        <img src="{{ $asset->thumbnail_url }}"
                                             alt="{{ $asset->filename }}"
                                             :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                                             loading="lazy">
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <div class="w-6 h-6 bg-black/50 rounded-full flex items-center justify-center">
                                                <i class="fas fa-play text-white text-[0.5rem] ml-px"></i>
                                            </div>
                                        </div>
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
                                                'fa-file-image' => 'text-emerald-600',
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
                                <span title="{{ __('Last modified') }} {{ $asset->updated_at }}">{{ $asset->updated_at->diffForHumans() }}</span>
                                <span class="mx-1">•</span>
                                <span title="{{ __('Uploaded by') }} {{ $asset->user->email }}">{{ $asset->user->name }}</span>
                            </div>
                        </td>

                        <!-- Actions -->
                        <td class="actions-icons px-4 py-3">
                            <div class="flex gap-3">
                                <a href="{{ route('assets.show', $asset) }}"
                                   class="text-blue-600 hover:text-blue-800"
                                   title="{{ __('View asset') }}">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button @click="copyUrl()"
                                        :class="copied ? 'attention text-green-600' : 'text-gray-600 hover:text-gray-800'"
                                        :title="copied ? @js(__('Copied!')) : @js(__('Copy URL'))">
                                    <i :class="copied ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                                <a href="{{ route('assets.edit', $asset) }}"
                                   class="attention text-yellow-600 hover:text-yellow-800"
                                   title="{{ __('Edit asset') }}">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="{{ route('assets.replace', $asset) }}"
                                   class="attention text-amber-600 hover:text-amber-800"
                                   title="{{ __('Replace asset') }}">
                                    <i class="fas fa-shuffle"></i>
                                </a>
                                <button @click="deleteAsset()"
                                        :disabled="loading"
                                        class="text-red-800 hover:text-red-900 disabled:opacity-50"
                                        title="{{ __('Delete asset') }}">
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
                                                title="{{ __('Remove tag') }}">
                                            <i class="fas fa-times text-xs"></i>
                                        </button>
                                    </span>
                                </template>

                                <!-- Add Tag Button/Input -->
                                <div x-show="!addingTag">
                                    <button @click="showAddTagInput()"
                                            :disabled="loading"
                                            class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-gray-100 text-gray-600 hover:bg-gray-200 disabled:opacity-50">
                                        <i class="fas fa-plus mr-1"></i> {{ __('Add') }}
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
                                               placeholder="{{ __('Tag name') }}"
                                               style="width: 120px;">

                                        <!-- Autocomplete dropdown -->
                                        <div x-show="showSuggestions && filteredSuggestions.length > 0"
                                             x-cloak
                                             class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded shadow-lg max-h-40 overflow-y-auto">
                                            <template x-for="(suggestion, index) in filteredSuggestions" :key="suggestion">
                                                <button type="button"
                                                        @mousedown.prevent="selectSuggestion(suggestion)"
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
                                        {{ __('Add') }}
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
                                <option value="">{{ __('Not specified') }}</option>
                                <option value="public_domain">{{ __('Public Domain') }}</option>
                                <option value="cc0">{{ __('CC0') }}</option>
                                <option value="cc_by">{{ __('CC BY') }}</option>
                                <option value="cc_by_sa">{{ __('CC BY-SA') }}</option>
                                <option value="cc_by_nd">{{ __('CC BY-ND') }}</option>
                                <option value="cc_by_nc">{{ __('CC BY-NC') }}</option>
                                <option value="cc_by_nc_sa">{{ __('CC BY-NC-SA') }}</option>
                                <option value="cc_by_nc_nd">{{ __('CC BY-NC-ND') }}</option>
                                <option value="fair_use">{{ __('Fair Use') }}</option>
                                <option value="all_rights_reserved">{{ __('All Rights Reserved') }}</option>
                                <option value="other">{{ __('Other') }}</option>
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
            <label for="perPageSelect" class="text-sm text-gray-600">{{ __('Results per page:') }}</label>
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
        <h3 class="text-xl font-semibold text-gray-700 mb-2">{{ __('No assets found') }}</h3>
        <p class="text-gray-500 mb-6">
            @if(request()->has('search') || request()->has('tags') || request()->has('type'))
                {{ __('Try adjusting your filters or') }}
                <a href="{{ route('assets.index') }}" class="text-blue-600 hover:underline">{{ __('clear all filters') }}</a>
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

@php
    $allTagsData = $tags->map(fn($t) => ['id' => (string)$t->id, 'name' => $t->name, 'type' => $t->type, 'assets_count' => $t->assets_count, 'created_at' => $t->created_at->toISOString()]);
@endphp

@push('scripts')
<script>
// Page data for Alpine.js components
window.allTags = @json($tags->pluck('name')->toArray());

window.assetGridConfig = {
    search: @json(request('search', '')),
    type: @json(request('type', '')),
    folder: @json($folder),
    folderCount: {{ count($folders) }},
    sort: @json(request('sort', 'date_desc')),
    selectedTags: @json(request('tags', [])),
    initialTags: @json(request('tags', [])),
    perPage: '{{ $perPage }}',
    allTagsData: @json($allTagsData),
    indexRoute: '{{ route('assets.index') }}'
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
    assetDeleteFailed: @js(__('Failed to delete asset'))
};
</script>
@endpush
@endsection
