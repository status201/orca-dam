@extends('layouts.app')

@section('title', 'Edit Asset')

@section('content')
<div class="max-w-4xl mx-auto" x-data="assetEditor()">
    <div class="mb-6">
        <a href="{{ route('assets.show', $asset) }}" class="inline-flex items-center text-blue-600 hover:text-blue-700">
            <i class="fas fa-arrow-left mr-2"></i> Back to Asset
        </a>
    </div>
    
    <div class="bg-white rounded-lg shadow-lg p-6">
        <h1 class="text-3xl font-bold mb-6">Edit Asset</h1>
        
        <form action="{{ route('assets.update', $asset) }}" method="POST">
            @csrf
            @method('PATCH')
            
            <!-- Preview -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Preview</label>
                @if($asset->isImage())
                    <img src="{{ $asset->thumbnail_url ?? $asset->url }}"
                         alt="{{ $asset->filename }}"
                         class="max-w-sm rounded-lg">
                @else
                    <div class="w-48 h-48 bg-gray-100 rounded-lg flex items-center justify-center">
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
                        <i class="fas {{ $icon }} {{ $colorClass }} opacity-60" style="font-size: 8rem;"></i>
                    </div>
                @endif
            </div>
            
            <!-- Filename (read-only) -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Filename
                </label>
                <input type="text" 
                       value="{{ $asset->filename }}"
                       readonly
                       class="w-full px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg">
            </div>
            
            <!-- Alt Text -->
            <div class="mb-6">
                <label for="alt_text" class="block text-sm font-medium text-gray-700 mb-2">
                    Alt Text
                    <span class="text-gray-500 font-normal">(for accessibility)</span>
                </label>
                <input type="text" 
                       id="alt_text"
                       name="alt_text" 
                       value="{{ old('alt_text', $asset->alt_text) }}"
                       maxlength="500"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       placeholder="Brief description of the image">
                @error('alt_text')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            
            <!-- Caption -->
            <div class="mb-6">
                <label for="caption" class="block text-sm font-medium text-gray-700 mb-2">
                    Caption
                </label>
                <textarea id="caption"
                          name="caption" 
                          rows="3"
                          maxlength="1000"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                          placeholder="Optional caption or description">{{ old('caption', $asset->caption) }}</textarea>
                @error('caption')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            
            <!-- Tags -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    User Tags
                    <span class="text-gray-500 font-normal">(AI tags are preserved automatically)</span>
                </label>
                
                <!-- Tag input with autocomplete -->
                <div class="mb-3">
                    <div class="flex space-x-2 relative">
                        <div class="flex-1 relative">
                            <input type="text"
                                   x-model="newTag"
                                   @keyup="searchTags"
                                   @keyup.enter.prevent="addTag"
                                   @keydown.down.prevent="navigateDown"
                                   @keydown.up.prevent="navigateUp"
                                   @blur="hideSuggestions"
                                   placeholder="Add a tag..."
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">

                            <!-- Autocomplete suggestions -->
                            <div x-show="showSuggestions && suggestions.length > 0"
                                 x-cloak
                                 class="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                                <template x-for="(suggestion, index) in suggestions" :key="suggestion.id">
                                    <div @mousedown.prevent="selectSuggestion(suggestion.name)"
                                         :class="{'bg-blue-50': index === selectedIndex}"
                                         class="px-4 py-2 cursor-pointer hover:bg-blue-50 flex items-center justify-between">
                                        <span x-text="suggestion.name"></span>
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-700">user</span>
                                    </div>
                                </template>
                            </div>
                        </div>
                        <button type="button"
                                @click="addTag"
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <i class="fas fa-plus mr-2"></i> Add
                        </button>
                    </div>
                </div>
                
                <!-- Current tags -->
                <div class="flex flex-wrap gap-2 mb-3">
                    <template x-for="(tag, index) in userTags" :key="index">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-blue-100 text-blue-700">
                            <span x-text="tag"></span>
                            <button type="button" 
                                    @click="removeTag(index)"
                                    class="ml-2 hover:text-blue-900">
                                <i class="fas fa-times"></i>
                            </button>
                            <input type="hidden" name="tags[]" :value="tag">
                        </span>
                    </template>
                </div>
                
                <!-- AI tags (read-only) -->
                @if($asset->aiTags->count() > 0)
                <div class="mt-4 pt-4 border-t">
                    <p class="text-sm text-gray-600 mb-2">
                        <i class="fas fa-robot mr-1"></i> AI-Generated Tags (cannot be edited)
                    </p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($asset->aiTags as $tag)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-purple-100 text-purple-700">
                            {{ $tag->name }}
                            <i class="fas fa-lock ml-2 text-xs"></i>
                        </span>
                        @endforeach
                    </div>
                </div>
                @endif
                
                @error('tags')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            
            <!-- Submit buttons -->
            <div class="flex justify-end space-x-3">
                <a href="{{ route('assets.show', $asset) }}" 
                   class="px-6 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit" 
                        class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-save mr-2"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function assetEditor() {
    return {
        newTag: '',
        userTags: @json($asset->userTags->pluck('name')->toArray()),
        suggestions: [],
        showSuggestions: false,
        selectedIndex: -1,
        searchTimeout: null,

        addTag() {
            const tag = this.newTag.trim().toLowerCase();

            if (!tag) return;

            if (this.userTags.includes(tag)) {
                window.showToast('Tag already exists', 'error');
                return;
            }

            this.userTags.push(tag);
            this.newTag = '';
            this.hideSuggestions();
        },

        removeTag(index) {
            this.userTags.splice(index, 1);
        },

        searchTags() {
            clearTimeout(this.searchTimeout);

            if (this.newTag.trim().length < 1) {
                this.suggestions = [];
                this.showSuggestions = false;
                return;
            }

            this.searchTimeout = setTimeout(async () => {
                try {
                    const response = await fetch(`{{ route('tags.search') }}?q=${encodeURIComponent(this.newTag)}&type=user`);
                    const data = await response.json();

                    // Filter out tags that are already added
                    this.suggestions = data.filter(tag => !this.userTags.includes(tag.name));
                    this.showSuggestions = this.suggestions.length > 0;
                    this.selectedIndex = -1;
                } catch (error) {
                    console.error('Tag search failed:', error);
                }
            }, 300);
        },

        selectSuggestion(tagName) {
            this.newTag = tagName;
            this.addTag();
        },

        navigateDown() {
            if (this.selectedIndex < this.suggestions.length - 1) {
                this.selectedIndex++;
            }
        },

        navigateUp() {
            if (this.selectedIndex > 0) {
                this.selectedIndex--;
            }
        },

        hideSuggestions() {
            setTimeout(() => {
                this.showSuggestions = false;
                this.selectedIndex = -1;
            }, 200);
        }
    };
}
</script>
@endpush
@endsection
