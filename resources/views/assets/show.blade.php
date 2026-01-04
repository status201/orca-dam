@extends('layouts.app')

@section('title', $asset->filename)

@section('content')
<div class="max-w-6xl mx-auto">
    <!-- Back button -->
    <div class="mb-6">
        <a href="{{ route('assets.index') }}" class="inline-flex items-center text-blue-600 hover:text-blue-700">
            <i class="fas fa-arrow-left mr-2"></i> Back to Assets
        </a>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Preview column -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                @if($asset->isImage())
                    <img src="{{ $asset->url }}" 
                         alt="{{ $asset->filename }}"
                         class="w-full h-auto">
                @else
                    <div class="aspect-video bg-gray-100 flex items-center justify-center">
                        <div class="text-center">
                            <i class="fas fa-file text-6xl text-gray-400 mb-4"></i>
                            <p class="text-gray-600">{{ $asset->mime_type }}</p>
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
                    <button onclick="copyToClipboard('{{ $asset->url }}')"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 whitespace-nowrap">
                        <i class="fas fa-copy mr-2"></i> Copy
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
                        <button onclick="copyToClipboard('{{ $asset->thumbnail_url }}')"
                                class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 whitespace-nowrap">
                            <i class="fas fa-copy mr-2"></i> Copy
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
                    
                    <a href="{{ $asset->url }}" 
                       target="_blank"
                       download
                       class="block w-full px-4 py-2 bg-gray-600 text-white text-center rounded-lg hover:bg-gray-700">
                        <i class="fas fa-download mr-2"></i> Download
                    </a>
                    
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
@endsection
