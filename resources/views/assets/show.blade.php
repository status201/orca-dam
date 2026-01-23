@extends('layouts.app')

@section('title', $asset->filename)

@section('content')
<div class="max-w-7xl mx-auto" x-data="assetDetail()">
    <!-- Back button -->
    <div class="mb-6">
        <a href="{{ route('assets.index') }}" class="inline-flex items-center text-blue-600 hover:text-blue-700">
            <i class="fas fa-arrow-left mr-2"></i> Back to Assets
        </a>
    </div>

    @if(session('success'))
    <div class="mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i>{{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i>{{ session('error') }}
    </div>
    @endif

    @if(session('warning'))
    <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 text-yellow-800 rounded-lg">
        <i class="fas fa-exclamation-triangle mr-2"></i>{{ session('warning') }}
    </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Preview column -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                @if($asset->isImage())
                    <img src="{{ $asset->url }}"
                         alt="{{ $asset->filename }}"
                         class="w-full h-auto">
                @elseif($asset->isVideo())
                    <video controls class="w-full" preload="metadata">
                        <source src="{{ $asset->url }}" type="{{ $asset->mime_type }}">
                        Your browser does not support the video tag.
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
                                    default => 'text-gray-400'
                                };
                            @endphp
                            <i class="fas {{ $icon }} {{ $colorClass }} opacity-60 mb-4" style="font-size: 12rem;"></i>
                            <p class="text-gray-600 font-medium">{{ $asset->mime_type }}</p>
                            <p class="text-gray-500 text-sm mt-1">{{ strtoupper(pathinfo($asset->filename, PATHINFO_EXTENSION)) }} File</p>
                        </div>
                    </div>
                @endif
            </div>
            
            <!-- URL Copy Section -->
            <div class="mt-6 bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-3">Asset URL</h3>
                <div class="flex items-center space-x-2">
                    <input type="text"
                           value="{{ $asset->url }}"
                           readonly
                           class="flex-1 px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm font-mono">
                    <button @click="copyUrl('{{ $asset->url }}', 'main')"
                            :class="copiedStates.main ? 'bg-green-600 hover:bg-green-700' : 'bg-blue-600 hover:bg-blue-700'"
                            class="px-4 py-2 text-white rounded-lg whitespace-nowrap transition-all duration-300">
                        <i :class="copiedStates.main ? 'fas fa-check' : 'fas fa-copy'" class="mr-2"></i>
                        <span x-text="copiedStates.main ? 'Copied!' : 'Copy'"></span>
                    </button>
                </div>

                @if($asset->thumbnail_url)
                <div class="mt-4">
                    <h4 class="text-sm font-semibold mb-2 text-gray-700">Thumbnail URL</h4>
                    <div class="flex items-center space-x-2">
                        <input type="text"
                               value="{{ $asset->thumbnail_url }}"
                               readonly
                               class="flex-1 px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm font-mono">
                        <button @click="copyUrl('{{ $asset->thumbnail_url }}', 'thumb')"
                                :class="copiedStates.thumb ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700'"
                                class="px-4 py-2 text-white rounded-lg whitespace-nowrap transition-all duration-300">
                            <i :class="copiedStates.thumb ? 'fas fa-check' : 'fas fa-copy'" class="mr-2"></i>
                            <span x-text="copiedStates.thumb ? 'Copied!' : 'Copy'"></span>
                        </button>
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
                        <dt class="text-gray-500">File Size</dt>
                        <dd class="font-medium">{{ $asset->formatted_size }}</dd>
                    </div>
                    
                    @if($asset->width && $asset->height)
                    <div>
                        <dt class="text-gray-500">Dimensions</dt>
                        <dd class="font-medium">{{ $asset->width }} × {{ $asset->height }} px</dd>
                    </div>
                    @endif
                    
                    <div>
                        <dt class="text-gray-500">Type</dt>
                        <dd class="font-medium">{{ $asset->mime_type }}</dd>
                    </div>
                    
                    <div>
                        <dt class="text-gray-500">Uploaded By</dt>
                        <dd class="font-medium">{{ $asset->user->name }}</dd>
                    </div>
                    
                    <div>
                        <dt class="text-gray-500">Uploaded</dt>
                        <dd class="font-medium">{{ $asset->created_at->format('M d, Y') }}</dd>
                    </div>
                </dl>
                
                @if($asset->alt_text)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">Alt Text</h4>
                    <p class="text-sm text-gray-600">{{ $asset->alt_text }}</p>
                </div>
                @endif
                
                @if($asset->caption)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">Caption</h4>
                    <p class="text-sm text-gray-600">{{ $asset->caption }}</p>
                </div>
                @endif

                @if($asset->license_type)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">License Type</h4>
                    <p class="text-sm text-gray-600">
                        @switch($asset->license_type)
                            @case('public_domain')
                                Public Domain
                                @break
                            @case('cc0')
                                CC0 (No Rights Reserved)
                                @break
                            @case('cc_by')
                                CC BY (Attribution)
                                @break
                            @case('cc_by_sa')
                                CC BY-SA (Attribution-ShareAlike)
                                @break
                            @case('cc_by_nd')
                                CC BY-ND (Attribution-NoDerivs)
                                @break
                            @case('cc_by_nc')
                                CC BY-NC (Attribution-NonCommercial)
                                @break
                            @case('cc_by_nc_sa')
                                CC BY-NC-SA (Attribution-NonCommercial-ShareAlike)
                                @break
                            @case('cc_by_nc_nd')
                                CC BY-NC-ND (Attribution-NonCommercial-NoDerivs)
                                @break
                            @case('fair_use')
                                Fair Use
                                @break
                            @case('all_rights_reserved')
                                All Rights Reserved
                                @break
                            @case('other')
                                Other
                                @break
                            @default
                                {{ $asset->license_type }}
                        @endswitch
                    </p>
                </div>
                @endif

                @if($asset->license_expiry_date)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">License Expiry Date</h4>
                    <p class="text-sm text-gray-600">
                        {{ $asset->license_expiry_date->format('M d, Y') }}
                        @if($asset->license_expiry_date->isPast())
                            <span class="ml-2 text-xs px-2 py-0.5 bg-red-100 text-red-700 rounded-full">Expired</span>
                        @elseif($asset->license_expiry_date->diffInDays(now()) <= 30)
                            <span class="ml-2 text-xs px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded-full">Expiring soon</span>
                        @endif
                    </p>
                </div>
                @endif

                @if($asset->copyright)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">Copyright Information</h4>
                    <p class="text-sm text-gray-600">{{ $asset->copyright }}</p>
                </div>
                @endif

                @if($asset->copyright_source)
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">Copyright Source</h4>
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
                <h3 class="text-lg font-semibold mb-4">Tags</h3>
                
                @if($asset->tags->count() > 0)
                <div class="flex flex-wrap gap-2 mb-4">
                    @foreach($asset->tags as $tag)
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm {{ $tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                        {{ $tag->name }}
                        @if($tag->type === 'ai')
                        <i class="fas fa-robot ml-2 text-xs"></i>
                        @endif
                    </span>
                    @endforeach
                </div>
                
                <div class="flex flex-wrap gap-2 text-xs">
                    @if($asset->userTags->count() > 0)
                    <span class="text-gray-500">
                        <i class="fas fa-user mr-1"></i> {{ $asset->userTags->count() }} user tag(s)
                    </span>
                    @endif
                    
                    @if($asset->aiTags->count() > 0)
                    <span class="text-gray-500">
                        <i class="fas fa-robot mr-1"></i> {{ $asset->aiTags->count() }} AI tag(s)
                    </span>
                    @endif
                </div>
                @else
                <p class="text-gray-500 text-sm">No tags yet</p>
                @endif
            </div>
            
            <!-- Actions card -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-4">Actions</h3>
                
                <div class="space-y-3">
                    @can('update', $asset)
                    <a href="{{ route('assets.edit', $asset) }}" 
                       class="block w-full px-4 py-2 bg-blue-600 text-white text-center rounded-lg hover:bg-blue-700">
                        <i class="fas fa-edit mr-2"></i> Edit Asset
                    </a>
                    @endcan
                    
                    <button @click="downloadAsset('{{ route('assets.download', $asset) }}')"
                            :disabled="downloading"
                            :class="downloading ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700'"
                            class="w-full px-4 py-2 text-white rounded-lg transition-all duration-300">
                        <i :class="downloading ? 'fas fa-spinner fa-spin' : 'fas fa-download'" class="mr-2"></i>
                        <span x-text="downloading ? 'Downloading...' : 'Download'"></span>
                    </button>
                    
                    @can('delete', $asset)
                    <form action="{{ route('assets.destroy', $asset) }}" 
                          method="POST"
                          onsubmit="return confirm('Are you sure you want to delete this asset? This action cannot be undone.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" 
                                class="w-full px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            <i class="fas fa-trash mr-2"></i> Delete Asset
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
function assetDetail() {
    return {
        copiedStates: {
            main: false,
            thumb: false
        },
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

        copyUrl(url, type) {
            // Try modern clipboard API first
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(() => {
                    this.copiedStates[type] = true;
                    window.showToast('URL copied to clipboard!');
                    setTimeout(() => {
                        this.copiedStates[type] = false;
                    }, 2000);
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    window.showToast('Failed to copy URL', 'error');
                });
            } else {
                // Fallback for HTTP/older browsers
                const textArea = document.createElement('textarea');
                textArea.value = url;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    this.copiedStates[type] = true;
                    window.showToast('URL copied to clipboard!');
                    setTimeout(() => {
                        this.copiedStates[type] = false;
                    }, 2000);
                } catch (err) {
                    console.error('Failed to copy:', err);
                    window.showToast('Failed to copy URL', 'error');
                }
                textArea.remove();
            }
        }
    };
}
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
