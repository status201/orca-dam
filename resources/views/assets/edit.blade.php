@extends('layouts.app')

@section('title', 'Edit Asset')

@section('content')
<div class="max-w-7xl mx-auto" x-data="assetEditor()">
    @php
        $rootFolder = \App\Services\S3Service::getRootFolder();
        $allSegments = array_filter(explode('/', $asset->folder));

        // Build full paths for each segment
        $allPaths = [];
        $currentPath = '';
        foreach ($allSegments as $segment) {
            $currentPath = $currentPath ? $currentPath . '/' . $segment : $segment;
            $allPaths[] = $currentPath;
        }

        // If root folder is set, remove it from display
        $breadcrumbSegments = array_values($allSegments);
        $breadcrumbPaths = $allPaths;
        if ($rootFolder !== '' && count($breadcrumbSegments) > 0 && $breadcrumbSegments[0] === $rootFolder) {
            array_shift($breadcrumbSegments);
            array_shift($breadcrumbPaths);
        }
    @endphp

    <!-- Back button and breadcrumb -->
    <div class="mb-6 flex items-center justify-between">
        <a href="{{ route('assets.show', $asset) }}" class="inline-flex items-center text-orca-black hover:text-orca-black-hover">
            <i class="fas fa-arrow-left mr-2"></i> Back to Asset
        </a>

        @if(count($breadcrumbSegments) > 0)
        <nav class="text-sm text-gray-500 flex items-center">
            <!-- Full breadcrumb (hidden on small screens) -->
            <span class="hidden sm:flex items-center">
                @foreach($breadcrumbSegments as $index => $segment)
                    <span class="mx-1 text-gray-400">/</span>
                    <a href="{{ route('assets.index', ['folder' => $breadcrumbPaths[$index]]) }}"
                       class="hover:text-orca-black transition-colors {{ $loop->last ? 'font-medium text-gray-700' : '' }}">
                        {{ $segment }}
                    </a>
                @endforeach
            </span>

            <!-- Collapsed breadcrumb (shown only on small screens) -->
            <span class="flex items-center sm:hidden">
                @if(count($breadcrumbSegments) > 1)
                    <span class="mx-1 text-gray-400">/</span>
                    <span class="text-gray-400">...</span>
                @endif
                <span class="mx-1 text-gray-400">/</span>
                <a href="{{ route('assets.index', ['folder' => end($breadcrumbPaths)]) }}"
                   class="font-medium text-gray-700 hover:text-orca-black transition-colors">
                    {{ end($breadcrumbSegments) }}
                </a>
            </span>
        </nav>
        @endif
    </div>
    
    <div class="bg-white rounded-lg shadow-lg p-6">
        <h1 class="text-3xl font-bold mb-6">Edit Asset</h1>

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

        <form action="{{ route('assets.update', $asset) }}" class="mb-6" method="POST">
            @csrf
            @method('PATCH')

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Filename (read-only) -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Filename
                    </label>
                    <input type="text"
                           value="{{ $asset->filename }}"
                           readonly
                           class="w-full px-4 py-2 focus:ring-2 focus:ring-orca-black bg-gray-50 border border-gray-300 rounded-lg">
                </div>

                <!-- Preview -->
                <div class="mb-4 md:row-span-3 md:justify-self-center">
                    <label class="block text-sm font-medium md:text-center text-gray-700 mb-2">Preview</label>
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

                <!-- Alt Text -->
                <div class="mb-4">
                    <label for="alt_text" class="block text-sm font-medium text-gray-700 mb-2">
                        Alt Text
                        <span class="text-gray-500 font-normal">(for accessibility)</span>
                    </label>
                    <input type="text"
                           id="alt_text"
                           name="alt_text"
                           value="{{ old('alt_text', $asset->alt_text) }}"
                           maxlength="500"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent"
                           placeholder="Brief description of the image">
                    @error('alt_text')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Caption -->
                <div class="mb-4">
                    <label for="caption" class="block text-sm font-medium text-gray-700 mb-2">
                        Caption
                    </label>
                    <textarea id="caption"
                              name="caption"
                              rows="3"
                              maxlength="1000"
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent"
                              placeholder="Optional caption or description">{{ old('caption', $asset->caption) }}</textarea>
                    @error('caption')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- License Type -->
                <div class="mb-4">
                    <label for="license_type" class="block text-sm font-medium text-gray-700 mb-2">
                        License Type
                    </label>
                    <select id="license_type"
                            name="license_type"
                            class="w-full px-4 py-2 pr-dropdown border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <option value="">Select a license...</option>
                        <option value="public_domain" {{ old('license_type', $asset->license_type) == 'public_domain' ? 'selected' : '' }}>Public Domain</option>
                        <option value="cc0" {{ old('license_type', $asset->license_type) == 'cc0' ? 'selected' : '' }}>CC0 (No Rights Reserved)</option>
                        <option value="cc_by" {{ old('license_type', $asset->license_type) == 'cc_by' ? 'selected' : '' }}>CC BY (Attribution)</option>
                        <option value="cc_by_sa" {{ old('license_type', $asset->license_type) == 'cc_by_sa' ? 'selected' : '' }}>CC BY-SA (Attribution-ShareAlike)</option>
                        <option value="cc_by_nd" {{ old('license_type', $asset->license_type) == 'cc_by_nd' ? 'selected' : '' }}>CC BY-ND (Attribution-NoDerivs)</option>
                        <option value="cc_by_nc" {{ old('license_type', $asset->license_type) == 'cc_by_nc' ? 'selected' : '' }}>CC BY-NC (Attribution-NonCommercial)</option>
                        <option value="cc_by_nc_sa" {{ old('license_type', $asset->license_type) == 'cc_by_nc_sa' ? 'selected' : '' }}>CC BY-NC-SA (Attribution-NonCommercial-ShareAlike)</option>
                        <option value="cc_by_nc_nd" {{ old('license_type', $asset->license_type) == 'cc_by_nc_nd' ? 'selected' : '' }}>CC BY-NC-ND (Attribution-NonCommercial-NoDerivs)</option>
                        <option value="fair_use" {{ old('license_type', $asset->license_type) == 'fair_use' ? 'selected' : '' }}>Fair Use</option>
                        <option value="all_rights_reserved" {{ old('license_type', $asset->license_type) == 'all_rights_reserved' ? 'selected' : '' }}>All Rights Reserved</option>
                        <option value="other" {{ old('license_type', $asset->license_type) == 'other' ? 'selected' : '' }}>Other</option>
                    </select>
                    @error('license_type')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- License Expiry Date -->
                <div class="mb-4">
                    <label for="license_expiry_date" class="block text-sm font-medium text-gray-700 mb-2">
                        License Expiry Date
                        <span class="text-gray-500 font-normal">(optional)</span>
                    </label>
                    <input type="date"
                           id="license_expiry_date"
                           name="license_expiry_date"
                           value="{{ old('license_expiry_date', $asset->license_expiry_date?->format('Y-m-d')) }}"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                    @error('license_expiry_date')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Copyright -->
                <div class="mb-4">
                    <label for="copyright" class="block text-sm font-medium text-gray-700 mb-2">
                        Copyright Information
                    </label>
                    <input type="text"
                           id="copyright"
                           name="copyright"
                           value="{{ old('copyright', $asset->copyright) }}"
                           maxlength="500"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent"
                           placeholder="e.g., © 2024 Company Name, or copyright holder information">
                    @error('copyright')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Copyright Source -->
                <div class="mb-4">
                    <label for="copyright_source" class="block text-sm font-medium text-gray-700 mb-2">
                        Copyright Source
                        <span class="text-gray-500 font-normal">(URL or reference)</span>
                    </label>
                    <input type="text"
                           id="copyright_source"
                           name="copyright_source"
                           value="{{ old('copyright_source', $asset->copyright_source) }}"
                           maxlength="500"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent"
                           placeholder="e.g., https://example.com/license or original source reference">
                    @error('copyright_source')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Tags -->
            <div class="mb-4 mt-6">
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
                                   @input="searchTags"
                                   @keydown.enter.prevent="addTagOrSelectSuggestion"
                                   @keydown.down.prevent="navigateDown"
                                   @keydown.up.prevent="navigateUp"
                                   @keydown.escape="hideSuggestions"
                                   @blur="hideSuggestions"
                                   placeholder="Add a tag..."
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">

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
                                class="px-4 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
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
                        class="px-6 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
                    <i class="fas fa-save mr-2"></i> Save Changes
                </button>
            </div>
        </form>

        <!-- AI Tags Section (outside main form) -->
        @if($asset->isImage())
            <div class="mb-6 p-4 bg-purple-50 border border-purple-200 rounded-lg">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-700">
                        <i class="fas fa-robot mr-2"></i>AI-Generated Tags
                    </h3>
                    <form action="{{ route('assets.ai-tag', $asset) }}" method="POST" x-data="{ generating: false }" @submit="generating = true">
                        @csrf
                        <button type="submit"
                                :disabled="generating"
                                :class="generating ? 'bg-purple-400 cursor-not-allowed' : 'bg-purple-600 hover:bg-purple-700'"
                                class="text-sm px-3 py-1 text-white rounded-lg transition">
                            <i :class="generating ? 'fas fa-spinner fa-spin' : 'fas fa-wand-magic-sparkles'" class="mr-1"></i>
                            <span x-text="generating ? 'Generating...' : 'Generate AI Tags'"></span>
                        </button>
                    </form>
                </div>

                @if($asset->aiTags->count() > 0)
                    <div class="flex flex-wrap gap-2" x-data="aiTagManager()">
                        @foreach($asset->aiTags as $tag)
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-purple-100 text-purple-700">
                    {{ $tag->name }}
                    <button type="button"
                            @click="removeAiTag({{ $tag->id }}, '{{ addslashes($tag->name) }}')"
                            class="ml-2 hover:text-purple-900"
                            title="Remove this AI tag">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </span>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-600 italic">No AI tags yet. Click "Generate AI Tags" to analyze this image.</p>
                @endif
            </div>
        @endif

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
            this.showSuggestions = false;
            this.selectedIndex = -1;
        },

        addTagOrSelectSuggestion() {
            // If a suggestion is highlighted, select it
            if (this.selectedIndex >= 0 && this.suggestions[this.selectedIndex]) {
                this.selectSuggestion(this.suggestions[this.selectedIndex].name);
            } else {
                this.addTag();
            }
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
            if (this.showSuggestions && this.suggestions.length > 0) {
                this.selectedIndex = Math.min(this.selectedIndex + 1, this.suggestions.length - 1);
            }
        },

        navigateUp() {
            if (this.showSuggestions && this.selectedIndex > 0) {
                this.selectedIndex--;
            }
        },

        hideSuggestions() {
            // Small delay to allow click events on suggestions to fire first
            setTimeout(() => {
                this.showSuggestions = false;
                this.selectedIndex = -1;
            }, 150);
        }
    };
}

function aiTagManager() {
    return {
        async removeAiTag(tagId, tagName) {
            if (!confirm(`Are you sure you want to remove the AI tag "${tagName}" from this asset?`)) {
                return;
            }

            try {
                const response = await fetch(`/assets/{{ $asset->id }}/tags/${tagId}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    }
                });

                const data = await response.json();

                if (response.ok) {
                    window.showToast('AI tag removed successfully');
                    window.location.reload();
                } else {
                    window.showToast(data.message || 'Failed to remove AI tag', 'error');
                }
            } catch (error) {
                console.error('Remove AI tag error:', error);
                window.showToast('Failed to remove AI tag', 'error');
            }
        }
    };
}
</script>
@endpush
@endsection
