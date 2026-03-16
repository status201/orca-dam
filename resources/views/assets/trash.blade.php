@extends('layouts.app')

@section('title', __('Trash'))

@section('content')
<div x-data="trashPage()">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">
                    {{ __('Trash') }}
                    <span class="ml-2 relative -top-1 inline-flex items-center justify-center px-3 py-0.5 text-base font-semibold rounded-full bg-gray-200 text-gray-700">
                        {{ number_format($assets->total()) }}
                    </span>
                </h1>
                <p class="text-gray-600 mt-2">{{ __('Soft-deleted assets (S3 objects are kept)') }}</p>
            </div>

            <div class="flex gap-3">
                <a href="{{ route('assets.index') }}"
                   class="px-4 py-2 text-sm bg-orca-black text-white rounded-lg hover:bg-orca-black-hover flex items-center justify-center whitespace-nowrap">
                    <i class="fas fa-arrow-left mr-2"></i> {{ __('Back to Assets') }}
                </a>
            </div>
        </div>
    </div>

    <!-- Info banner -->
    @if($assets->count() > 0)
    <div class="mb-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
        <div class="flex items-start">
            <i class="fas fa-info-circle text-yellow-600 mr-3 mt-0.5"></i>
            <div>
                <p class="text-sm text-yellow-800">
                    <strong>{{ __('Soft Delete:') }}</strong> {{ __('These assets are hidden but their S3 objects are still in the bucket.') }}
                    {{ __('You can restore them or permanently delete them (which will also remove the S3 objects).') }}
                </p>
            </div>
        </div>
    </div>

    <!-- Toggle buttons -->
    <div class="mb-4 flex justify-end gap-2">
        <!-- Select All (grid mode) -->
        <button x-show="viewMode === 'grid'"
                @click="$store.bulkSelection.toggleSelectAll()"
                :class="$store.bulkSelection.allOnPageSelected ? 'bg-orca-black text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                class="px-3 py-2 text-xs font-medium border border-gray-300 rounded-lg transition-colors"
                :title="$store.bulkSelection.allOnPageSelected ? @js(__('Deselect all')) : @js(__('Select all'))">
            <i class="fas fa-check-double mr-1"></i>
            <span x-text="$store.bulkSelection.allOnPageSelected ? @js(__('Deselect all')) : @js(__('Select all'))"></span>
        </button>

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

        <!-- View Mode Toggle -->
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

    <!-- Grid view -->
    <div x-show="viewMode === 'grid'" x-cloak class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 2xl:grid-cols-10 gap-4">
        @foreach($assets as $asset)
        <div class="group relative bg-white rounded-lg shadow hover:shadow-lg transition-shadow overflow-hidden"
             @click="if ($store.bulkSelection.hasSelection) { $store.bulkSelection.toggle({{ $asset->id }}); }">
            <!-- Selection checkbox -->
            <div class="absolute top-2 left-2 z-20"
                 :class="$store.bulkSelection.hasSelection || $store.bulkSelection.isSelected({{ $asset->id }}) ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'"
                 @click.stop="$store.bulkSelection.shiftToggle({{ $asset->id }}, $event)">
                <div :class="$store.bulkSelection.isSelected({{ $asset->id }}) ? 'bg-orca-black border-orca-black' : 'bg-white/80 border-gray-400'"
                     class="w-6 h-6 rounded border-2 flex items-center justify-center cursor-pointer hover:border-orca-black transition-colors">
                    <i x-show="$store.bulkSelection.isSelected({{ $asset->id }})" class="fas fa-check text-white text-xs"></i>
                </div>
            </div>

            <!-- Thumbnail -->
            <div class="aspect-square bg-gray-100 relative">
                @if($asset->isImage() && $asset->thumbnail_url)
                    <img src="{{ $asset->thumbnail_url }}"
                         alt="{{ $asset->filename }}"
                         :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                         loading="lazy">
                @elseif($asset->isSvg())
                    <img src="{{ $asset->url }}"
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
                @elseif($asset->isMathMl())
                    <x-mml-preview :asset="$asset" size="thumb" />
                @else
                    <div class="w-full h-full flex items-center justify-center">
                        <i class="fas {{ $asset->getFileIcon() }} text-9xl {{ $asset->getIconColorClass() }} opacity-60"></i>
                    </div>
                @endif

                <!-- Trash badge -->
                <div class="warning absolute top-2 right-2 bg-red-600 text-white px-2 py-1 rounded-full text-xs opacity-70">
                    <i class="fas fa-trash-alt mr-1"></i>{{ __('Deleted') }}
                </div>

                <!-- Overlay with actions -->
                <div class="actions absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                    <button @click.stop="restoreAsset({{ $asset->id }})"
                            class="bg-green-600 text-white px-3 py-2 rounded-lg hover:bg-green-700 transition-colors mr-2"
                            title="{{ __('Restore') }}">
                        <i class="fas fa-undo"></i>
                    </button>
                    @can('forceDelete', App\Models\Asset::class)
                    <button @click.stop="confirmDelete({{ $asset->id }})"
                            class="bg-red-600 text-white px-3 py-2 rounded-lg hover:bg-red-700 transition-colors"
                            title="{{ __('Permanently Delete') }}">
                        <i class="fas fa-trash"></i>
                    </button>
                    @endcan
                </div>
            </div>

            <!-- Info -->
            <div class="p-3">
                <p class="text-sm font-medium text-gray-900 truncate" title="{{ $asset->filename }}">
                    {{ $asset->filename }}
                </p>
                <div class="text-xs text-gray-500 mt-1 space-y-0.5">
                    <p><i class="fas fa-hdd mr-1"></i>{{ $asset->formatted_size }}</p>
                    <p title="{{ $asset->deleted_at }}"><i class="fas fa-clock mr-1"></i>{{ __('Deleted') }} {{ $asset->deleted_at->diffForHumans() }}</p>
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

    <!-- List view -->
    <div x-show="viewMode === 'list'" x-cloak class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto invert-scrollbar-colors">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-center w-10">
                            <div @click="$store.bulkSelection.toggleSelectAll()"
                                 :class="$store.bulkSelection.allOnPageSelected ? 'bg-orca-black border-orca-black' : 'bg-white border-gray-400'"
                                 class="w-5 h-5 rounded border-2 flex items-center justify-center cursor-pointer hover:border-orca-black transition-colors mx-auto">
                                <i x-show="$store.bulkSelection.allOnPageSelected" class="fas fa-check text-white text-xs"></i>
                            </div>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-20">
                            {{ __('Thumbnail') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[200px]">
                            {{ __('Filename') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[250px]">
                            {{ __('S3 Key') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-24">
                            {{ __('Size') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider min-w-[200px]">
                            {{ __('Tags') }}
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-28">
                            {{ __('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @foreach($assets as $asset)
                    <tr class="hover:bg-gray-50 transition-colors">

                        <!-- Selection checkbox -->
                        <td class="px-4 py-3 text-center">
                            <div @click="$store.bulkSelection.shiftToggle({{ $asset->id }}, $event)"
                                 :class="$store.bulkSelection.isSelected({{ $asset->id }}) ? 'bg-orca-black border-orca-black' : 'bg-white border-gray-400'"
                                 class="w-5 h-5 rounded border-2 flex items-center justify-center cursor-pointer hover:border-orca-black transition-colors mx-auto">
                                <i x-show="$store.bulkSelection.isSelected({{ $asset->id }})" class="fas fa-check text-white text-xs"></i>
                            </div>
                        </td>

                        <!-- Thumbnail -->
                        <td class="px-4 py-3">
                            <a href="{{ $asset->url }}" target="_blank" title="{{ __('Open in new tab') }}" class="block">
                                <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center overflow-hidden hover:ring-2 hover:ring-orca-500 transition-all relative">
                                    @if($asset->isImage() && $asset->thumbnail_url)
                                        <img src="{{ $asset->thumbnail_url }}"
                                             alt="{{ $asset->filename }}"
                                             :class="fitMode === 'cover' ? 'w-full h-full object-cover' : 'w-full h-full object-contain'"
                                             loading="lazy">
                                    @elseif($asset->isSvg())
                                        <img src="{{ $asset->url }}"
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
                                    @elseif($asset->isMathMl())
                                        <x-mml-preview :asset="$asset" size="thumb" />
                                    @else
                                        <i class="fas {{ $asset->getFileIcon() }} text-3xl {{ $asset->getIconColorClass() }} opacity-60"></i>
                                    @endif
                                </div>
                            </a>
                        </td>

                        <!-- Filename -->
                        <td class="px-4 py-3">
                            <div class="text-sm font-medium text-gray-900">{{ $asset->filename }}</div>
                            <div class="text-xs text-gray-500 mt-1">
                                <span title="{{ $asset->deleted_at }}">{{ __('Deleted') }} {{ $asset->deleted_at->diffForHumans() }}</span>
                                <span class="mx-1">&bull;</span>
                                <span title="{{ $asset->user->email }}">{{ $asset->user->name }}</span>
                            </div>
                        </td>

                        <!-- S3 Key -->
                        <td class="px-4 py-3">
                            <div class="text-xs font-mono text-gray-600 break-all">{{ $asset->s3_key }}</div>
                        </td>

                        <!-- Size -->
                        <td class="px-4 py-3">
                            <div class="text-sm text-gray-700">{{ $asset->formatted_size }}</div>
                        </td>

                        <!-- Tags (read-only) -->
                        <td class="px-4 py-3">
                            @if($asset->tags->count() > 0)
                            <div class="flex flex-wrap gap-1">
                                @foreach($asset->tags as $tag)
                                <span class="tag attention text-xs px-2 py-0.5 rounded-full {{ $tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : ($tag->type === 'reference' ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700') }}">
                                    {{ $tag->name }}
                                </span>
                                @endforeach
                            </div>
                            @endif
                        </td>

                        <!-- Actions -->
                        <td class="actions-icons px-4 py-3">
                            <div class="flex gap-3">
                                <button @click="restoreAsset({{ $asset->id }})"
                                        class="attention text-green-600 hover:text-green-800"
                                        title="{{ __('Restore') }}">
                                    <i class="fas fa-undo"></i>
                                </button>
                                @can('forceDelete', App\Models\Asset::class)
                                <button @click="confirmDelete({{ $asset->id }})"
                                        class="text-red-600 hover:text-red-800"
                                        title="{{ __('Permanently Delete') }}">
                                    <i class="fas fa-trash"></i>
                                </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-8">
        {{ $assets->links() }}
    </div>

    <!-- Floating Bulk Action Bar -->
    <div x-show="$store.bulkSelection.hasSelection"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full opacity-0"
         x-transition:enter-end="translate-y-0 opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0 opacity-100"
         x-transition:leave-end="translate-y-full opacity-0"
         class="fixed bottom-0 left-0 right-0 z-40 bg-gray-900 text-white shadow-2xl border-t border-gray-700">
        <div class="mx-auto px-6 py-3">
            <div class="flex flex-wrap items-center gap-3">
                <!-- Selected count -->
                <span class="text-sm font-medium whitespace-nowrap">
                    <i class="fas fa-check-circle mr-1"></i>
                    <span x-text="$store.bulkSelection.selected.length"></span> {{ __('selected') }}
                </span>

                <div class="w-px h-6 bg-gray-600 hidden sm:block"></div>

                <!-- Bulk restore -->
                <button @click="bulkRestore()"
                        :disabled="bulkRestoring"
                        class="attention px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 disabled:opacity-50 whitespace-nowrap">
                    <i class="fas fa-undo mr-1"></i> {{ __('Restore') }}
                    <i :class="bulkRestoring ? 'fas fa-spinner fa-spin ml-1' : ''"></i>
                </button>

                @can('forceDelete', App\Models\Asset::class)
                <div class="w-px h-6 bg-gray-600 hidden sm:block"></div>

                <!-- Bulk permanent delete -->
                <button @click="bulkForceDelete()"
                        :disabled="bulkDeleting"
                        class="attention px-3 py-1.5 bg-red-700 text-white text-sm rounded-lg hover:bg-red-800 disabled:opacity-50 whitespace-nowrap">
                    <i class="fas fa-skull-crossbones mr-1"></i> {{ __('Permanent delete') }}
                    <i :class="bulkDeleting ? 'fas fa-spinner fa-spin ml-1' : ''"></i>
                </button>
                @endcan

                <!-- Spacer -->
                <div class="flex-1"></div>

                <!-- Clear selection -->
                <button @click="$store.bulkSelection.clear()"
                        class="px-3 py-1.5 bg-gray-700 text-white text-sm rounded-lg hover:bg-gray-600 whitespace-nowrap">
                    <i class="fas fa-times mr-1"></i> {{ __('Clear selection') }}
                </button>
            </div>
        </div>
    </div>

    <!-- Bulk restore loading modal -->
    <div x-show="bulkRestoring"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-white rounded-lg shadow-xl max-w-sm w-full mx-4 p-8 text-center">
            <div class="mb-6 flex justify-center">
                <div class="relative w-24 h-24">
                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" class="w-24 h-24 animate-orca-swim">
                        <ellipse cx="50" cy="55" rx="35" ry="25" fill="#1a1a1a"/>
                        <path d="M 15 60 Q 5 50, 8 42 Q 16 48, 16 50 Z" fill="#1a1a1a"/>
                        <path d="M 15 50 Q 5 60, 8 68 Q 16 62, 16 60 Z" fill="#1a1a1a"/>
                        <path d="M 44 40 L 42 15 L 48 30 Z" fill="#1a1a1a"/>
                        <ellipse cx="60" cy="58" rx="15" ry="10" fill="white"/>
                        <ellipse cx="68" cy="48" rx="8" ry="10" fill="white" transform="rotate(-20 68 48)"/>
                        <circle cx="68" cy="48" r="3" fill="#1a1a1a"/>
                        <circle cx="69" cy="47" r="1" fill="white"/>
                        <path d="M 72 55 Q 78 58, 82 55" stroke="#1a1a1a" stroke-width="2" fill="none" stroke-linecap="round"/>
                        <ellipse cx="48" cy="70" rx="7" ry="15" fill="#1a1a1a" transform="rotate(30 48 70)"/>
                    </svg>
                </div>
            </div>

            <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Restoring assets') }}...</h3>
            <p class="text-sm text-gray-500 mb-5">{{ __('This may take a while depending on the number of selected assets.') }}</p>

            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                <div class="h-full bg-green-500 rounded-full animate-orca-progress"></div>
            </div>
        </div>
    </div>

    <!-- Bulk restore summary modal -->
    <div x-show="bulkRestoreShowSummary"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
         @keydown.escape.window="bulkRestoreDismissSummary()">
        <div class="bg-white rounded-lg shadow-xl max-w-xl w-full mx-4" @click.away="bulkRestoreDismissSummary()">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="attention fas fa-check-circle text-green-500 mr-2"></i>{{ __('Assets restored') }}
                </h3>
                <button @click="bulkRestoreDismissSummary()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <p class="text-sm text-gray-600 mb-3">
                    <span x-text="bulkRestoreResults?.restored || 0"></span> {{ __('asset(s) restored. Restored filenames:') }}
                </p>
                <textarea readonly
                          :value="bulkRestoreSummaryText"
                          class="w-full h-48 px-3 py-2 text-xs font-mono text-gray-700 bg-gray-50 border border-gray-300 rounded-lg resize-none focus:outline-none"
                          @focus="$event.target.select()"></textarea>
                <div class="mt-4 flex justify-end gap-3">
                    <button @click="bulkRestoreCopySummary()"
                            class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">
                        <i class="fas fa-copy mr-1"></i> {{ __('Copy') }}
                    </button>
                    <button @click="bulkRestoreDismissSummary()"
                            class="px-4 py-2 bg-orca-black text-white text-sm rounded-lg hover:bg-orca-black-hover">
                        {{ __('Done') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk delete loading modal -->
    <div x-show="bulkDeleting"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
        <div class="bg-white rounded-lg shadow-xl max-w-sm w-full mx-4 p-8 text-center">
            <div class="mb-6 flex justify-center">
                <div class="relative w-24 h-24">
                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg" class="w-24 h-24 animate-orca-swim">
                        <ellipse cx="50" cy="55" rx="35" ry="25" fill="#1a1a1a"/>
                        <path d="M 15 60 Q 5 50, 8 42 Q 16 48, 16 50 Z" fill="#1a1a1a"/>
                        <path d="M 15 50 Q 5 60, 8 68 Q 16 62, 16 60 Z" fill="#1a1a1a"/>
                        <path d="M 44 40 L 42 15 L 48 30 Z" fill="#1a1a1a"/>
                        <ellipse cx="60" cy="58" rx="15" ry="10" fill="white"/>
                        <ellipse cx="68" cy="48" rx="8" ry="10" fill="white" transform="rotate(-20 68 48)"/>
                        <circle cx="68" cy="48" r="3" fill="#1a1a1a"/>
                        <circle cx="69" cy="47" r="1" fill="white"/>
                        <path d="M 72 55 Q 78 58, 82 55" stroke="#1a1a1a" stroke-width="2" fill="none" stroke-linecap="round"/>
                        <ellipse cx="48" cy="70" rx="7" ry="15" fill="#1a1a1a" transform="rotate(30 48 70)"/>
                    </svg>
                </div>
            </div>

            <h3 class="text-lg font-semibold text-gray-900 mb-2">{{ __('Permanently deleting assets') }}...</h3>
            <p class="text-sm text-gray-500 mb-5">{{ __('This may take a while depending on the number of selected assets.') }}</p>

            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                <div class="h-full bg-red-500 rounded-full animate-orca-progress"></div>
            </div>
        </div>
    </div>

    <!-- Bulk delete summary modal -->
    <div x-show="bulkDeleteShowSummary"
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
         @keydown.escape.window="bulkDeleteDismissSummary()">
        <div class="bg-white rounded-lg shadow-xl max-w-xl w-full mx-4" @click.away="bulkDeleteDismissSummary()">
            <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="attention fas fa-check-circle text-green-500 mr-2"></i>{{ __('Assets permanently deleted') }}
                </h3>
                <button @click="bulkDeleteDismissSummary()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <p class="text-sm text-gray-600 mb-3">
                    <span x-text="bulkDeleteResults?.deleted || 0"></span> {{ __('asset(s) permanently deleted. Deleted S3 keys:') }}
                </p>
                <textarea readonly
                          :value="bulkDeleteSummaryText"
                          class="w-full h-48 px-3 py-2 text-xs font-mono text-gray-700 bg-gray-50 border border-gray-300 rounded-lg resize-none focus:outline-none"
                          @focus="$event.target.select()"></textarea>
                <div class="mt-4 flex justify-end gap-3">
                    <button @click="bulkDeleteCopySummary()"
                            class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-lg hover:bg-gray-200">
                        <i class="fas fa-copy mr-1"></i> {{ __('Copy') }}
                    </button>
                    <button @click="bulkDeleteDismissSummary()"
                            class="px-4 py-2 bg-orca-black text-white text-sm rounded-lg hover:bg-orca-black-hover">
                        {{ __('Done') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    @else
    <div class="text-center py-12">
        <i class="fas fa-trash-alt text-gray-300 text-6xl mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">{{ __('Trash is Empty') }}</h3>
        <p class="text-gray-500">{{ __('No deleted assets found') }}</p>
        <a href="{{ route('assets.index') }}"
           class="inline-block mt-4 px-6 py-2 text-sm bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
            {{ __('Back to Assets') }}
        </a>
    </div>
    @endif
</div>

<script>
    window.currentPageAssetIds = @json($assets->pluck('id')->toArray());

    window.__pageData = window.__pageData || {};
    window.__pageData.confirmRestore = @js(__('Restore this asset?'));
    window.__pageData.confirmPermanentDelete = @js(__("PERMANENTLY DELETE this asset?\n\nThis will:\n- Remove the database record\n- Delete the S3 object\n- Delete the thumbnail\n\nThis action CANNOT be undone!"));
    window.__pageData.confirmBulkRestore = @js(__("Restore the selected assets?\n\nThey will be moved back to the active assets list."));
    window.__pageData.confirmBulkForceDelete = @js(__("PERMANENTLY DELETE the selected assets?\n\nThis will:\n- Remove the database records\n- Delete all S3 objects (originals + thumbnails + resized)\n\nThis action CANNOT be undone!"));
    window.__pageData.bulkRestoreFailed = @js(__('Failed to restore assets'));
    window.__pageData.bulkForceDeleteFailed = @js(__('Failed to permanently delete assets'));
    window.__pageData.copied = @js(__('Copied to clipboard!'));
</script>
@endsection
