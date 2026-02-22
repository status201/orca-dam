@extends('layouts.app')

@section('title', __('Export Assets'))

@section('content')
<div x-data="exportAssets()">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">{{ __('Export Assets') }}</h1>
        <p class="text-gray-600 mt-2">{{ __('Export asset metadata to CSV with optional filters') }}</p>
    </div>

    <!-- Export Form -->
    <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
        <form action="{{ route('export.download') }}" method="POST">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <!-- Folder Filter -->
                <div>
                    <label for="folder" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-folder mr-2"></i>{{ __('Folder') }}
                    </label>
                    <select id="folder"
                            name="folder"
                            x-model="folder"
                            class="w-full rounded-lg border-gray-300 focus:border-transparent focus:ring-orca-black font-mono text-sm">
                        <option value="">{{ __('All Folders') }}</option>
                        <x-folder-tree-options :folders="$folders" :root-folder="$rootFolder" />
                    </select>
                    <p class="text-xs text-gray-500 mt-1">{{ __('Filter by S3 folder (leave empty for all)') }}</p>
                </div>

                <!-- File Type Filter -->
                <div>
                    <label for="file_type" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-file mr-2"></i>{{ __('File Type') }}
                    </label>
                    <select id="file_type"
                            name="file_type"
                            x-model="fileType"
                            class="w-full rounded-lg border-gray-300 focus:border-transparent focus:ring-orca-black">
                        <option value="">{{ __('All File Types') }}</option>
                        @foreach($fileTypes as $type)
                            <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">{{ __('Filter by file type (e.g., image, video, application)') }}</p>
                </div>

                <!-- Tags Filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-tags mr-2"></i>{{ __('Tags') }}
                    </label>
                    <div class="border border-gray-300 rounded-lg">
                        <div class="p-2 border-b border-gray-300">
                            <div class="relative">
                                <input type="text"
                                       x-model="tagSearch"
                                       placeholder="{{ __('Search tags...') }}"
                                       class="w-full text-sm pl-8 pr-3 py-1 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                                <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                            </div>
                        </div>
                        <div class="max-h-48 overflow-y-auto invert-scrollbar-colors p-2">
                            <div class="grid grid-cols-1 gap-1">
                                <template x-for="tag in allTagsData" :key="tag.id">
                                    <label x-show="shouldShowTag(tag)"
                                           class="flex items-center space-x-2 p-1.5 hover:bg-gray-50 rounded cursor-pointer">
                                        <input type="checkbox"
                                               :value="tag.id"
                                               x-model="selectedTags"
                                               @click="handleTagClick($event, tag)"
                                               class="rounded text-blue-600 focus:ring-orca-black flex-shrink-0">
                                        <span class="text-sm truncate" x-text="tag.name"></span>
                                        <span :class="tag.type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'"
                                              class="tag attention text-xs px-1.5 py-0.5 rounded-full flex-shrink-0"
                                              x-text="tag.type"></span>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </div>
                    <template x-for="id in selectedTags" :key="'hidden-' + id">
                        <input type="hidden" name="tags[]" :value="id">
                    </template>
                    <p class="text-xs text-gray-500 mt-1">{{ __('Select tags to filter (leave empty for all)') }}</p>
                </div>
            </div>

            <!-- Preview Summary -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <h3 class="text-sm font-semibold text-blue-900 mb-2">
                    <i class="fas fa-info-circle mr-2"></i>{{ __('Export Information') }}
                </h3>
                <div class="text-sm text-blue-800 space-y-1">
                    <p><strong>{{ __('Export Format:') }}</strong> {{ __('CSV (Comma-Separated Values)') }}</p>
                    <p><strong>{{ __('Included Fields:') }}</strong> {{ __('id, s3_key, filename, mime_type, size, etag, dimensions, thumbnails, metadata, user info, tags, URLs, timestamps') }}</p>
                    <p><strong>{{ __('Tags Format:') }}</strong> {{ __('Comma-separated tag names (not IDs)') }}</p>
                    <p x-show="folder || fileType || selectedTags.length > 0" class="text-blue-900 font-semibold">
                        <i class="fas fa-filter mr-1"></i>
                        {{ __('Filters active:') }}
                        <span x-show="folder" x-text="'{{ __('Folder:') }} ' + folder"></span>
                        <span x-show="folder && (fileType || selectedTags.length > 0)">, </span>
                        <span x-show="fileType" x-text="'{{ __('File Type:') }} ' + fileType"></span>
                        <span x-show="fileType && selectedTags.length > 0">, </span>
                        <span x-show="selectedTags.length > 0" x-text="selectedTags.length + ' {{ __('tag(s) selected') }}'"></span>
                    </p>
                    <p x-show="!folder && !fileType && selectedTags.length === 0" class="text-blue-900 font-semibold">
                        <i class="fas fa-globe mr-1"></i>{{ __('All assets will be exported') }}
                    </p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center justify-between">
                <button type="button"
                        @click="resetFilters"
                        class="px-4 py-2 text-sm border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                    <i class="fas fa-undo mr-2"></i>{{ __('Reset Filters') }}
                </button>

                <button type="submit"
                        class="download-export px-6 py-3 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center">
                    <i class="fas fa-download mr-2"></i>{{ __('Download CSV Export') }}
                </button>
            </div>
        </form>
    </div>

    <!-- Information Section -->
    <div class="bg-gray-50 rounded-lg border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">
            <i class="fas fa-question-circle mr-2"></i>{{ __('About CSV Export') }}
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-gray-700">
            <div>
                <h4 class="font-semibold mb-2">{{ __('What\'s included:') }}</h4>
                <ul class="space-y-1 list-disc list-inside">
                    <li>{{ __('Asset ID and database keys') }}</li>
                    <li>{{ __('File information (name, type, size)') }}</li>
                    <li>{{ __('S3 storage details (keys, ETag)') }}</li>
                    <li>{{ __('Image dimensions (if applicable)') }}</li>
                    <li>{{ __('Metadata (alt text, captions, license, copyright)') }}</li>
                    <li>{{ __('Uploader and last modified by information') }}</li>
                    <li>{{ __('All User and AI tags (as text)') }}</li>
                    <li>{{ __('Public URLs for assets') }}</li>
                    <li>{{ __('Creation and update timestamps') }}</li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold mb-2">{{ __('Use cases:') }}</h4>
                <ul class="space-y-1 list-disc list-inside">
                    <li>{{ __('Backup asset metadata') }}</li>
                    <li>{{ __('Audit and reporting') }}</li>
                    <li>{{ __('Import into other systems') }}</li>
                    <li>{{ __('Data analysis and visualization') }}</li>
                    <li>{{ __('Integration with spreadsheet tools') }}</li>
                </ul>
                <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
                    <p class="text-xs text-yellow-800">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <strong>{{ __('Note:') }}</strong> {{ __('CSV exports contain metadata only. Actual files remain in S3 storage.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.__pageData = window.__pageData || {};
    window.__pageData.allTagsData = @json($tags->map(fn($t) => ['id' => (string)$t->id, 'name' => $t->name, 'type' => $t->type]));
</script>
@endsection
