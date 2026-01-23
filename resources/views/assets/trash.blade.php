@extends('layouts.app')

@section('title', 'Trash')

@section('content')
<div x-data="trashGrid()">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">
                    Trash
                </h1>
                <p class="text-gray-600 mt-2">Soft-deleted assets (S3 objects are kept)</p>
            </div>

            <div class="flex gap-3">
                <a href="{{ route('assets.index') }}"
                   class="px-4 py-2 text-sm bg-gray-600 text-white rounded-lg hover:bg-gray-700 flex items-center justify-center whitespace-nowrap">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Assets
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
                    <strong>Soft Delete:</strong> These assets are hidden but their S3 objects are still in the bucket.
                    You can restore them or permanently delete them (which will also remove the S3 objects).
                </p>
            </div>
        </div>
    </div>
    @endif

    <!-- Asset grid -->
    @if($assets->count() > 0)
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 2xl:grid-cols-10 gap-4">
        @foreach($assets as $asset)
        <div class="group relative bg-white rounded-lg shadow hover:shadow-lg transition-shadow overflow-hidden"
             x-data="trashCard({{ $asset->id }})">
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

                <!-- Trash badge -->
                <div class="absolute top-2 right-2 bg-red-600 text-white px-2 py-1 rounded-full text-xs">
                    <i class="fas fa-trash-alt mr-1"></i>Deleted
                </div>

                <!-- Overlay with actions -->
                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                    <button @click="restoreAsset({{ $asset->id }})"
                            class="bg-green-600 text-white px-3 py-2 rounded-lg hover:bg-green-700 transition-colors mr-2"
                            title="Restore">
                        <i class="fas fa-undo"></i>
                    </button>
                    <button @click="confirmDelete({{ $asset->id }})"
                            class="bg-red-600 text-white px-3 py-2 rounded-lg hover:bg-red-700 transition-colors"
                            title="Permanently Delete">
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
                    <p title="{{ $asset->deleted_at }}"><i class="fas fa-clock mr-1"></i>Deleted {{ $asset->deleted_at->diffForHumans() }}</p>
                    <p class="truncate" title="{{ $asset->user->name }}"><i class="fas fa-user mr-1"></i>{{ $asset->user->name }}</p>
                </div>

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
        <i class="fas fa-trash-alt text-gray-300 text-6xl mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">Trash is Empty</h3>
        <p class="text-gray-500">No deleted assets found</p>
        <a href="{{ route('assets.index') }}"
           class="inline-block mt-4 px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Back to Assets
        </a>
    </div>
    @endif
</div>

<script>
function trashGrid() {
    return {
        init() {
            console.log('Trash grid initialized');
        }
    }
}

function trashCard(assetId) {
    return {
        restoreAsset(id) {
            if (confirm('Restore this asset?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `/assets/${id}/restore`;

                const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = csrfToken;

                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            }
        },

        confirmDelete(id) {
            if (confirm('⚠️ PERMANENTLY DELETE this asset?\n\nThis will:\n- Remove the database record\n- Delete the S3 object\n- Delete the thumbnail\n\nThis action CANNOT be undone!')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `/assets/${id}/force-delete`;

                const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = csrfToken;

                const methodInput = document.createElement('input');
                methodInput.type = 'hidden';
                methodInput.name = '_method';
                methodInput.value = 'DELETE';

                form.appendChild(csrfInput);
                form.appendChild(methodInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
    }
}
</script>
@endsection
