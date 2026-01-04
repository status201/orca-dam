@extends('layouts.app')

@section('title', 'Tags')

@section('content')
<div>
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Tags</h1>
        <p class="text-gray-600 mt-2">Browse all tags in your asset library</p>
    </div>
    
    <!-- Filter tabs -->
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            <a href="{{ route('tags.index') }}" 
               class="py-4 px-1 border-b-2 {{ !request('type') ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} font-medium text-sm">
                All Tags ({{ $tags->count() }})
            </a>
            <a href="{{ route('tags.index', ['type' => 'user']) }}" 
               class="py-4 px-1 border-b-2 {{ request('type') === 'user' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} font-medium text-sm">
                User Tags
            </a>
            <a href="{{ route('tags.index', ['type' => 'ai']) }}" 
               class="py-4 px-1 border-b-2 {{ request('type') === 'ai' ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} font-medium text-sm">
                AI Tags
            </a>
        </nav>
    </div>
    
    @if($tags->count() > 0)
    <!-- Tags grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        @foreach($tags as $tag)
        <a href="{{ route('assets.index', ['tags' => [$tag->id]]) }}" 
           class="block bg-white rounded-lg shadow hover:shadow-lg transition-shadow p-4">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-lg font-semibold text-gray-900">{{ $tag->name }}</h3>
                <span class="px-2 py-1 text-xs font-semibold rounded-full {{ $tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                    {{ $tag->type }}
                    @if($tag->type === 'ai')
                    <i class="fas fa-robot ml-1"></i>
                    @endif
                </span>
            </div>
            <p class="text-sm text-gray-600">
                <i class="fas fa-images mr-1"></i>
                {{ $tag->assets_count }} {{ Str::plural('asset', $tag->assets_count) }}
            </p>
        </a>
        @endforeach
    </div>
    @else
    <div class="text-center py-12 bg-white rounded-lg shadow">
        <i class="fas fa-tags text-6xl text-gray-300 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">No tags found</h3>
        <p class="text-gray-500">
            Tags will appear here as you add them to your assets
        </p>
    </div>
    @endif
</div>
@endsection
