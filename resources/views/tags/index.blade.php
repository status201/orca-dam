@extends('layouts.app')

@section('title', 'Tags')

@section('content')
<div x-data="tagManager()">
    <!-- Header with search -->
    <div class="mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Tags</h1>
                <p class="text-gray-600 mt-2">Browse all tags in your asset library</p>
            </div>

            @if($tags->count() > 0)
            <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
                <!-- Search -->
                <div class="relative">
                    <input type="text"
                           x-model="searchQuery"
                           placeholder="Search tags..."
                           class="w-full sm:w-64 pl-10 pr-10 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    <button x-show="searchQuery.length > 0"
                            x-cloak
                            @click="searchQuery = ''"
                            class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <p x-show="searchQuery.length > 0" x-cloak class="text-sm text-gray-600 whitespace-nowrap">
                    <span x-text="matchingCount"></span> of {{ $tags->count() }}
                </p>
            </div>
            @endif
        </div>
    </div>

    <!-- Filter tabs -->
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            <a href="{{ route('tags.index') }}"
               class="py-4 px-1 border-b-2 {{ !request('type') ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} font-medium text-sm">
                All Tags @if(!request('type'))<span>(<span x-text="matchingCount"></span>)</span>@endif
            </a>
            <a href="{{ route('tags.index', ['type' => 'user']) }}"
               class="py-4 px-1 border-b-2 {{ request('type') === 'user' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} font-medium text-sm">
                User Tags @if(request('type') === 'user')<span>(<span x-text="matchingCount"></span>)</span>@endif
            </a>
            <a href="{{ route('tags.index', ['type' => 'ai']) }}"
               class="py-4 px-1 border-b-2 {{ request('type') === 'ai' ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }} font-medium text-sm">
                AI Tags @if(request('type') === 'ai')<span>(<span x-text="matchingCount"></span>)</span>@endif
            </a>
        </nav>
    </div>
    
    @if($tags->count() > 0)
    <!-- Tags grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 xxl:grid-cols-6 gap-4">
        @foreach($tags as $tag)
        <div x-show="matchesSearch('{{ addslashes($tag->name) }}')"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100"
             class="bg-white rounded-lg shadow hover:shadow-lg transition-shadow p-4">
            <div class="flex items-start justify-between mb-2 gap-3">
                <a href="{{ route('assets.index', ['tags' => [$tag->id]]) }}"
                   class="flex-1 min-w-0">
                    <h3 class="text-lg font-semibold text-gray-900 hover:text-blue-600 truncate">{{ $tag->name }}</h3>
                </a>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full whitespace-nowrap {{ $tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                        {{ $tag->type }}
                        @if($tag->type === 'ai')
                        <i class="fas fa-robot ml-1"></i>
                        @endif
                    </span>

                    @if($tag->type === 'user')
                    <!-- Edit button (only for user tags) -->
                    <button @click="editTag({{ $tag->id }}, '{{ addslashes($tag->name) }}')"
                            class="text-gray-500 hover:text-blue-600 p-1.5 hover:bg-blue-50 rounded transition"
                            title="Edit tag">
                        <i class="fas fa-edit text-sm"></i>
                    </button>
                    @endif

                    <!-- Delete button (for all tags) -->
                    <button @click="deleteTag({{ $tag->id }}, '{{ addslashes($tag->name) }}', '{{ $tag->type }}')"
                            class="text-gray-500 hover:text-red-600 p-1.5 hover:bg-red-50 rounded transition"
                            title="Delete tag">
                        <i class="fas fa-trash text-sm"></i>
                    </button>
                </div>
            </div>
            <p class="text-sm text-gray-600">
                <i class="fas fa-images mr-1"></i>
                {{ $tag->assets_count }} {{ Str::plural('asset', $tag->assets_count) }}
            </p>
        </div>
        @endforeach
    </div>

    <!-- No matching tags message -->
    <div x-show="searchQuery.length > 0 && matchingCount === 0"
         x-cloak
         class="text-center py-12 bg-white rounded-lg shadow">
        <i class="fas fa-search text-6xl text-gray-300 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">No matching tags</h3>
        <p class="text-gray-500">
            No tags match "<span x-text="searchQuery"></span>"
        </p>
        <button @click="searchQuery = ''" class="mt-4 text-blue-600 hover:text-blue-800">
            Clear search
        </button>
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

    <!-- Edit Tag Modal -->
    <div x-show="showEditModal"
         x-cloak
         class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         @click.self="showEditModal = false">
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
            <h2 class="text-2xl font-bold mb-4">Edit Tag</h2>

            <form @submit.prevent="updateTag">
                <div class="mb-4">
                    <label for="editTagName" class="block text-sm font-medium text-gray-700 mb-2">
                        Tag Name
                    </label>
                    <input type="text"
                           id="editTagName"
                           x-model="editingTagName"
                           required
                           maxlength="50"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button"
                            @click="showEditModal = false"
                            class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-save mr-2"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function tagManager() {
    return {
        showEditModal: false,
        editingTagId: null,
        editingTagName: '',
        searchQuery: '',
        tags: @json($tags->map(fn($tag) => ['name' => $tag->name, 'type' => $tag->type])),

        get matchingCount() {
            if (this.searchQuery.length === 0) {
                return this.tags.length;
            }
            const query = this.searchQuery.toLowerCase();
            return this.tags.filter(tag => tag.name.toLowerCase().includes(query)).length;
        },

        get matchingUserCount() {
            const userTags = this.tags.filter(tag => tag.type === 'user');
            if (this.searchQuery.length === 0) {
                return userTags.length;
            }
            const query = this.searchQuery.toLowerCase();
            return userTags.filter(tag => tag.name.toLowerCase().includes(query)).length;
        },

        get matchingAiCount() {
            const aiTags = this.tags.filter(tag => tag.type === 'ai');
            if (this.searchQuery.length === 0) {
                return aiTags.length;
            }
            const query = this.searchQuery.toLowerCase();
            return aiTags.filter(tag => tag.name.toLowerCase().includes(query)).length;
        },

        matchesSearch(tagName) {
            if (this.searchQuery.length === 0) {
                return true;
            }
            return tagName.toLowerCase().includes(this.searchQuery.toLowerCase());
        },

        editTag(id, name) {
            this.editingTagId = id;
            this.editingTagName = name;
            this.showEditModal = true;
        },

        async updateTag() {
            try {
                const response = await fetch(`/tags/${this.editingTagId}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        name: this.editingTagName
                    })
                });

                const data = await response.json();

                if (response.ok) {
                    window.showToast('Tag updated successfully');
                    window.location.reload();
                } else {
                    window.showToast(data.message || 'Failed to update tag', 'error');
                }
            } catch (error) {
                console.error('Update error:', error);
                window.showToast('Failed to update tag', 'error');
            }
        },

        async deleteTag(id, name, type) {
            const tagType = type === 'ai' ? 'AI tag' : 'tag';
            if (!confirm(`Are you sure you want to delete the ${tagType} "${name}"? This will remove it from all assets.`)) {
                return;
            }

            try {
                const response = await fetch(`/tags/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    }
                });

                const data = await response.json();

                if (response.ok) {
                    window.showToast('Tag deleted successfully');
                    window.location.reload();
                } else {
                    window.showToast(data.message || 'Failed to delete tag', 'error');
                }
            } catch (error) {
                console.error('Delete error:', error);
                window.showToast('Failed to delete tag', 'error');
            }
        }
    };
}
</script>
@endpush
@endsection
