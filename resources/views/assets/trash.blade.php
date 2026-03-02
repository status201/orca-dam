@extends('layouts.app')

@section('title', __('Trash'))

@section('content')
<div x-data="trashGrid()">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">
                    {{ __('Trash') }}
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
             x-data="trashCard({{ $asset->id }})">
            <!-- Thumbnail -->
            <div class="aspect-square bg-gray-100 relative">
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
                                default => 'text-gray-400'
                            };
                        @endphp
                        <i class="fas {{ $icon }} text-9xl {{ $colorClass }} opacity-60"></i>
                    </div>
                @endif

                <!-- Trash badge -->
                <div class="warning absolute top-2 right-2 bg-red-600 text-white px-2 py-1 rounded-full text-xs opacity-70">
                    <i class="fas fa-trash-alt mr-1"></i>{{ __('Deleted') }}
                </div>

                <!-- Overlay with actions -->
                <div class="actions absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                    <button @click="restoreAsset({{ $asset->id }})"
                            class="bg-green-600 text-white px-3 py-2 rounded-lg hover:bg-green-700 transition-colors mr-2"
                            title="{{ __('Restore') }}">
                        <i class="fas fa-undo"></i>
                    </button>
                    <button @click="confirmDelete({{ $asset->id }})"
                            class="bg-red-600 text-white px-3 py-2 rounded-lg hover:bg-red-700 transition-colors"
                            title="{{ __('Permanently Delete') }}">
                        <i class="fas fa-trash"></i>
                    </button>
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
                    <tr x-data="trashRow({{ $asset->id }})"
                        class="hover:bg-gray-50 transition-colors">

                        <!-- Thumbnail -->
                        <td class="px-4 py-3">
                            <a href="{{ $asset->url }}" target="_blank" title="{{ __('Open in new tab') }}" class="block">
                                <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center overflow-hidden hover:ring-2 hover:ring-orca-500 transition-all relative">
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
                                <button @click="confirmDelete({{ $asset->id }})"
                                        class="text-red-600 hover:text-red-800"
                                        title="{{ __('Permanently Delete') }}">
                                    <i class="fas fa-trash"></i>
                                </button>
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
    window.__pageData = window.__pageData || {};
    window.__pageData.confirmRestore = @js(__('Restore this asset?'));
    window.__pageData.confirmPermanentDelete = @js(__("PERMANENTLY DELETE this asset?\n\nThis will:\n- Remove the database record\n- Delete the S3 object\n- Delete the thumbnail\n\nThis action CANNOT be undone!"));
</script>
@endsection
