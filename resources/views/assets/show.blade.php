@extends('layouts.app')

@section('title', $asset->filename)

@section('content')
<div class="max-w-7xl mx-auto" x-data="assetDetail()">
    <!-- Back button and breadcrumb -->
    <div class="mb-6 flex items-center justify-between">
        <a href="{{ route('assets.index') }}" class="inline-flex items-center text-orca-black hover:text-orca-black-hover">
            <i class="fas fa-arrow-left mr-2"></i> {{ __('Back to Assets') }}
        </a>

        <x-asset-breadcrumb :asset="$asset" />
    </div>

    @if(session('success'))
    <div class="attention mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i>{{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="attention mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i>{{ session('error') }}
    </div>
    @endif

    @if(session('warning'))
    <div class="attention mb-6 p-4 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg">
        <i class="fas fa-exclamation-triangle mr-2"></i>{{ session('warning') }}
    </div>
    @endif

    @if($asset->is_missing)
    <div class="attention mb-6 p-4 border border-red-200 text-red-800 rounded-lg">
        <i class="fas fa-triangle-exclamation mr-2"></i>
        {{ __('This asset\'s S3 object is missing. The file may have been deleted from the S3 bucket.') }}
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Preview column -->
        <div class="lg:col-span-2">
            <div class="image-container bg-white rounded-lg shadow-lg overflow-hidden relative"
                 @if($asset->isImage())
                     x-data="imageRefresher(@js($asset->url), @js($asset->filename))"
                    @endif
            >

                @if($asset->isImage())

                    <!-- Refresh Icon Button -->
                    <button @click="refreshImage()" title="{{ __('Force a refresh') }}"
                            class="absolute top-2 right-2 bg-white/90 hover:bg-white rounded-full p-2 shadow-lg transition-all hover:scale-110 z-10"
                            :disabled="isRefreshing">
                        <svg class="w-5 h-5 text-gray-700" :class="{ 'animate-spin [animation-direction:reverse]': isRefreshing }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    </button>

                    <!-- Original Image -->
                    <img :src="imageSrc"
                         :alt="imageAlt"
                         class="h-auto my-0 mx-auto"
                         :style="isBlurred ? 'filter: blur(12px)' + (document.documentElement.classList.contains('dark-mode') ? ' invert(1)' : '') + '; transition: filter 0.5s ease' : 'transition: filter 0.5s ease'"
                         x-ref="mainImage">


                @elseif($asset->isVideo())
                    <video controls class="w-full" preload="metadata">
                        <source src="{{ $asset->url }}" type="{{ $asset->mime_type }}">
                        {{ __('Your browser does not support the video tag.') }}
                    </video>
                @else
                    <div class="aspect-video bg-gray-100 flex items-center justify-center">
                        <div class="text-center">
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
                            <i class="fas {{ $icon }} {{ $colorClass }} opacity-60 mb-4" style="font-size: 12rem;"></i>
                            <p class="text-gray-600 font-medium">{{ $asset->mime_type }}</p>
                            <p class="text-gray-500 text-sm mt-1">{{ strtoupper(pathinfo($asset->filename, PATHINFO_EXTENSION)) }} {{ __('File') }}</p>
                        </div>
                    </div>
                @endif
            </div>

            <!-- URL Copy Section -->
            <div class="mt-6 bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-3">{{ __('Asset URL') }}</h3>
                <div class="flex items-center space-x-2">
                    <input type="text"
                           value="{{ $asset->url }}"
                           readonly
                           class="flex-1 px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm font-mono">
                    <button @click="copyUrl('{{ $asset->url }}', 'main')"
                            :class="copiedStates.main ? 'attention bg-green-600 hover:bg-green-700' : 'bg-orca-black hover:bg-orca-black-hover'"
                            class="px-4 py-2 text-white rounded-lg whitespace-nowrap transition-all duration-300">
                        <i :class="copiedStates.main ? 'fas fa-check' : 'fas fa-copy'" class="mr-2"></i>
                        <span x-text="copiedStates.main ? @js(__('Copied!')) : @js(__('Copy'))"></span>
                    </button>
                </div>

                @if($asset->thumbnail_url)
                <div class="mt-4">
                    <h4 class="text-sm font-semibold mb-2 text-gray-700">{{ __('Thumbnail URL') }}</h4>
                    <div class="flex items-center space-x-2">
                        <input type="text"
                               value="{{ $asset->thumbnail_url }}"
                               readonly
                               class="flex-1 px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm font-mono">
                        <button @click="copyUrl('{{ $asset->thumbnail_url }}', 'thumb')"
                                :class="copiedStates.thumb ? 'attention bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700'"
                                class="px-4 py-2 text-white rounded-lg whitespace-nowrap transition-all duration-300">
                            <i :class="copiedStates.thumb ? 'fas fa-check' : 'fas fa-copy'" class="mr-2"></i>
                            <span x-text="copiedStates.thumb ? @js(__('Copied!')) : @js(__('Copy'))"></span>
                        </button>
                    </div>
                </div>
                @endif

                @if($asset->resize_s_url || $asset->resize_m_url || $asset->resize_l_url)
                <div class="mt-4">
                    <h4 class="text-sm font-semibold mb-2 text-gray-700">{{ __('Resize Presets') }}</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                        @if($asset->resize_s_url)
                        <div>
                            <label class="text-xs text-gray-500 mb-1 block">{{ __('Small (S)') }}</label>
                            <div class="flex items-center space-x-1">
                                <input type="text"
                                       value="{{ $asset->resize_s_url }}"
                                       readonly
                                       class="flex-1 min-w-0 px-2 py-1 bg-gray-50 border border-gray-300 rounded text-xs font-mono truncate">
                                <button @click="copyUrl('{{ $asset->resize_s_url }}', 'resize_s')"
                                        :class="copiedStates.resize_s ? 'attention bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700'"
                                        class="px-2 py-1 text-white rounded text-xs whitespace-nowrap transition-all duration-300">
                                    <i :class="copiedStates.resize_s ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                            </div>
                        </div>
                        @endif

                        @if($asset->resize_m_url)
                        <div>
                            <label class="text-xs text-gray-500 mb-1 block">{{ __('Medium (M)') }}</label>
                            <div class="flex items-center space-x-1">
                                <input type="text"
                                       value="{{ $asset->resize_m_url }}"
                                       readonly
                                       class="flex-1 min-w-0 px-2 py-1 bg-gray-50 border border-gray-300 rounded text-xs font-mono truncate">
                                <button @click="copyUrl('{{ $asset->resize_m_url }}', 'resize_m')"
                                        :class="copiedStates.resize_m ? 'attention bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700'"
                                        class="px-2 py-1 text-white rounded text-xs whitespace-nowrap transition-all duration-300">
                                    <i :class="copiedStates.resize_m ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                            </div>
                        </div>
                        @endif

                        @if($asset->resize_l_url)
                        <div>
                            <label class="text-xs text-gray-500 mb-1 block">{{ __('Large (L)') }}</label>
                            <div class="flex items-center space-x-1">
                                <input type="text"
                                       value="{{ $asset->resize_l_url }}"
                                       readonly
                                       class="flex-1 min-w-0 px-2 py-1 bg-gray-50 border border-gray-300 rounded text-xs font-mono truncate">
                                <button @click="copyUrl('{{ $asset->resize_l_url }}', 'resize_l')"
                                        :class="copiedStates.resize_l ? 'attention bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700'"
                                        class="px-2 py-1 text-white rounded text-xs whitespace-nowrap transition-all duration-300">
                                    <i :class="copiedStates.resize_l ? 'fas fa-check' : 'fas fa-copy'"></i>
                                </button>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>

        <!-- Info column -->
        <div class="space-y-6">
            <!-- Details card -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-2xl font-bold mb-4 break-words">{{ $asset->filename }}</h2>

                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ __('File Size') }}</dt>
                        <dd class="font-medium">{{ $asset->formatted_size }}</dd>
                    </div>

                    @if($asset->width && $asset->height)
                    <div>
                        <dt class="text-gray-500">{{ __('Dimensions') }}</dt>
                        <dd class="font-medium">{{ $asset->width }} × {{ $asset->height }} px</dd>
                    </div>
                    @endif

                    <div>
                        <dt class="text-gray-500">{{ __('Type') }}</dt>
                        <dd class="font-medium">{{ $asset->mime_type }}</dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">{{ __('Uploaded By') }}</dt>
                        <dd title="{{ $asset->user->email }}" class="font-medium">{{ $asset->user->name }}</dd>
                    </div>

                    <div>
                        <dt class="text-gray-500">{{ __('Uploaded') }}</dt>
                        <dd class="font-medium" title="{{ $asset->created_at->format('M d, Y H:i:s') }}">{{ $asset->created_at->format('M d, Y') }}</dd>
                    </div>

                    @if($asset->wasModified())
                    <div>
                        <dt class="text-gray-500">{{ __('Last Modified By') }}</dt>
                        <dd title="{{ $asset->modifier?->email ?? __('Unknown') }}" class="font-medium">{{ $asset->modifier?->name ?? __('Unknown') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('Last Modified') }}</dt>
                        <dd class="font-medium" title="{{ $asset->updated_at->format('M d, Y H:i:s') }}">{{ $asset->updated_at->format('M d, Y') }}</dd>
                    </div>
                    @endif
                </dl>

                @if($asset->alt_text)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">{{ __('Alt Text') }}</h4>
                    <p class="text-sm text-gray-600">{{ $asset->alt_text }}</p>
                </div>
                @endif

                @if($asset->caption)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">{{ __('Caption') }}</h4>
                    <p class="text-sm text-gray-600">{{ $asset->caption }}</p>
                </div>
                @endif

                @if($asset->license_type)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">{{ __('License Type') }}</h4>
                    <p class="text-sm text-gray-600">
                        @switch($asset->license_type)
                            @case('public_domain')
                                {{ __('Public Domain') }}
                                @break
                            @case('cc0')
                                {{ __('CC0 (No Rights Reserved)') }}
                                @break
                            @case('cc_by')
                                {{ __('CC BY (Attribution)') }}
                                @break
                            @case('cc_by_sa')
                                {{ __('CC BY-SA (Attribution-ShareAlike)') }}
                                @break
                            @case('cc_by_nd')
                                {{ __('CC BY-ND (Attribution-NoDerivs)') }}
                                @break
                            @case('cc_by_nc')
                                {{ __('CC BY-NC (Attribution-NonCommercial)') }}
                                @break
                            @case('cc_by_nc_sa')
                                {{ __('CC BY-NC-SA (Attribution-NonCommercial-ShareAlike)') }}
                                @break
                            @case('cc_by_nc_nd')
                                {{ __('CC BY-NC-ND (Attribution-NonCommercial-NoDerivs)') }}
                                @break
                            @case('fair_use')
                                {{ __('Fair Use') }}
                                @break
                            @case('all_rights_reserved')
                                {{ __('All Rights Reserved') }}
                                @break
                            @case('other')
                                {{ __('Other') }}
                                @break
                            @default
                                {{ $asset->license_type }}
                        @endswitch
                    </p>
                </div>
                @endif

                @if($asset->license_expiry_date)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">{{ __('License Expiry Date') }}</h4>
                    <p class="text-sm text-gray-600">
                        {{ $asset->license_expiry_date->format('M d, Y') }}
                        @if($asset->license_expiry_date->isPast())
                            <span class="ml-2 text-xs px-2 py-0.5 bg-red-100 text-red-700 rounded-full">{{ __('Expired') }}</span>
                        @elseif($asset->license_expiry_date->diffInDays(now()) <= 30)
                            <span class="ml-2 text-xs px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded-full">{{ __('Expiring soon') }}</span>
                        @endif
                    </p>
                </div>
                @endif

                @if($asset->copyright)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">{{ __('Copyright Information') }}</h4>
                    <p class="text-sm text-gray-600">{{ $asset->copyright }}</p>
                </div>
                @endif

                @if($asset->copyright_source)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">{{ __('Copyright Source') }}</h4>
                    <p class="text-sm text-gray-600">
                        @if(Str::startsWith($asset->copyright_source, ['http://', 'https://']))
                            <a href="{{ $asset->copyright_source }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:text-blue-800 break-all">
                                {{ $asset->copyright_source }} <i class="fas fa-external-link-alt text-xs ml-1"></i>
                            </a>
                        @else
                            {{ $asset->copyright_source }}
                        @endif
                    </p>
                </div>
                @endif
            </div>

            <!-- Tags card -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-4">{{ __('Tags') }}</h3>

                @if($asset->tags->count() > 0)
                <div class="flex flex-wrap gap-2 mb-4">
                    @foreach($asset->tags as $tag)
                    <a href="{{ route('assets.index', ['tags[]' => $tag->id]) }}"
                       class="tag attention inline-flex items-center px-3 py-1 rounded-full text-sm {{ $tag->type === 'ai' ? 'bg-purple-100 text-purple-700 hover:bg-purple-200' : ($tag->type === 'reference' ? 'bg-orange-100 text-orange-700 hover:bg-orange-200' : 'bg-blue-100 text-blue-700 hover:bg-blue-200') }} transition-colors no-underline">
                        {{ $tag->name }} ({{ $tag->assets_count }})
                        @if($tag->type === 'ai')
                        <i class="fas fa-robot ml-2 text-xs"></i>
                        @elseif($tag->type === 'reference')
                        <i class="fas fa-link ml-2 text-xs"></i>
                        @endif
                    </a>
                    @endforeach
                </div>

                <div class="flex flex-wrap gap-2 text-xs">
                    @if($asset->userTags->count() > 0)
                    <span class="text-gray-500">
                        <i class="fas fa-user mr-1"></i> {{ $asset->userTags->count() }} {{ __('user tag(s)') }}
                    </span>
                    @endif

                    @if($asset->referenceTags->count() > 0)
                    <span class="text-gray-500">
                        <i class="fas fa-link mr-1"></i> {{ $asset->referenceTags->count() }} {{ __('reference tag(s)') }}
                    </span>
                    @endif

                    @if($asset->aiTags->count() > 0)
                    <span class="text-gray-500">
                        <i class="fas fa-robot mr-1"></i> {{ $asset->aiTags->count() }} {{ __('AI tag(s)') }}
                    </span>
                    @endif
                </div>
                @else
                <p class="text-gray-500 text-sm">{{ __('No tags yet') }}</p>
                @endif
            </div>

            <!-- Actions card -->
            <div class="actions-card bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-4">{{ __('Actions') }}</h3>

                <div class="space-y-3">
                    @can('update', $asset)
                    <a href="{{ route('assets.edit', $asset) }}"
                       class="block w-full px-4 py-2 bg-orca-black text-white text-center rounded-lg hover:bg-orca-black-hover">
                        <i class="fas fa-edit mr-2"></i> {{ __('Edit Asset') }}
                    </a>
                    @endcan

                    <button @click="downloadAsset('{{ route('assets.download', $asset) }}')"
                            :disabled="downloading"
                            :class="downloading ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700'"
                            class="w-full px-4 py-2 text-white rounded-lg transition-all duration-300">
                        <i :class="downloading ? 'fas fa-spinner fa-spin' : 'fas fa-download'" class="mr-2"></i>
                        <span x-text="downloading ? @js(__('Downloading...')) : @js(__('Download'))"></span>
                    </button>

                    @can('replace', $asset)
                        <a href="{{ route('assets.replace', $asset) }}"
                           class="block w-full px-4 py-2 bg-amber-600 text-white text-center rounded-lg hover:bg-amber-700">
                            <i class="fas fa-shuffle mr-2"></i> {{ __('Replace Asset') }}
                        </a>
                    @endcan

                    @can('delete', $asset)
                    <form action="{{ route('assets.destroy', $asset) }}"
                          method="POST"
                          onsubmit="return confirm('{{ __('Are you sure you want to delete') }} {{$asset->filename}}?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="w-full px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            <i class="fas fa-trash mr-2"></i> {{ __('Delete Asset') }}
                        </button>
                    </form>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    window.__pageData = window.__pageData || {};
    window.__pageData.translations = {
        downloadFailed: @js(__('Download failed')),
        urlCopied: @js(__('URL copied to clipboard!')),
        failedToCopy: @js(__('Failed to copy URL'))
    };
</script>

<style>
@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}
</style>
@endpush
@endsection
