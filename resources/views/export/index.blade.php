@extends('layouts.app')

@section('title', 'Export Assets')

@section('content')
<div x-data="exportAssets()">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Export Assets</h1>
        <p class="text-gray-600 mt-2">Export asset metadata to CSV with optional filters</p>
    </div>

    <!-- Export Form -->
    <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
        <form action="{{ route('export.download') }}" method="POST">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <!-- Folder Filter -->
                <div>
                    <label for="folder" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-folder mr-2"></i>Folder
                    </label>
                    <select id="folder"
                            name="folder"
                            x-model="folder"
                            class="w-full rounded-lg border-gray-300 focus:border-transparent focus:ring-orca-black font-mono text-sm">
                        <option value="">All Folders</option>
                        @foreach($folders as $f)
                            @php
                                $rootPrefix = $rootFolder !== '' ? $rootFolder . '/' : '';
                                $relativePath = ($f === '' || ($rootFolder !== '' && $f === $rootFolder)) ? '' : ($rootPrefix !== '' ? str_replace($rootPrefix, '', $f) : $f);
                                $depth = $relativePath ? substr_count($relativePath, '/') + 1 : 0;
                                $label = ($f === '' || ($rootFolder !== '' && $f === $rootFolder)) ? '/ (root)' : str_repeat('╎  ', max(0, $depth - 1)) . '├─ ' . basename($f);
                            @endphp
                            <option value="{{ $f }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Filter by S3 folder (leave empty for all)</p>
                </div>

                <!-- File Type Filter -->
                <div>
                    <label for="file_type" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-file mr-2"></i>File Type
                    </label>
                    <select id="file_type"
                            name="file_type"
                            x-model="fileType"
                            class="w-full rounded-lg border-gray-300 focus:border-transparent focus:ring-orca-black">
                        <option value="">All File Types</option>
                        @foreach($fileTypes as $type)
                            <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Filter by file type (e.g., image, video, application)</p>
                </div>

                <!-- Tags Filter -->
                <div>
                    <label for="tags" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-tags mr-2"></i>Tags
                    </label>
                    <select id="tags"
                            name="tags[]"
                            x-model="selectedTags"
                            multiple
                            size="5"
                            class="invert-scrollbar-colors w-full rounded-lg border-gray-300 focus:border-transparent focus:ring-orca-black">
                        @foreach($tags as $tag)
                            <option value="{{ $tag->id }}">
                                {{ $tag->name }}
                                @if($tag->type === 'ai')
                                    <i class="fas fa-robot text-xs"></i>
                                @endif
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Hold Ctrl/Cmd to select multiple tags (leave empty for all)</p>
                </div>
            </div>

            <!-- Preview Summary -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <h3 class="text-sm font-semibold text-blue-900 mb-2">
                    <i class="fas fa-info-circle mr-2"></i>Export Information
                </h3>
                <div class="text-sm text-blue-800 space-y-1">
                    <p><strong>Export Format:</strong> CSV (Comma-Separated Values)</p>
                    <p><strong>Included Fields:</strong> id, s3_key, filename, mime_type, size, etag, dimensions, thumbnails, metadata, user info, tags, URLs, timestamps</p>
                    <p><strong>Tags Format:</strong> Comma-separated tag names (not IDs)</p>
                    <p x-show="folder || fileType || selectedTags.length > 0" class="text-blue-900 font-semibold">
                        <i class="fas fa-filter mr-1"></i>
                        Filters active:
                        <span x-show="folder" x-text="'Folder: ' + folder"></span>
                        <span x-show="folder && (fileType || selectedTags.length > 0)">, </span>
                        <span x-show="fileType" x-text="'File Type: ' + fileType"></span>
                        <span x-show="fileType && selectedTags.length > 0">, </span>
                        <span x-show="selectedTags.length > 0" x-text="selectedTags.length + ' tag(s) selected'"></span>
                    </p>
                    <p x-show="!folder && !fileType && selectedTags.length === 0" class="text-blue-900 font-semibold">
                        <i class="fas fa-globe mr-1"></i>All assets will be exported
                    </p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center justify-between">
                <button type="button"
                        @click="resetFilters"
                        class="px-4 py-2 text-sm border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                    <i class="fas fa-undo mr-2"></i>Reset Filters
                </button>

                <button type="submit"
                        class="download-export px-6 py-3 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center">
                    <i class="fas fa-download mr-2"></i>Download CSV Export
                </button>
            </div>
        </form>
    </div>

    <!-- Information Section -->
    <div class="bg-gray-50 rounded-lg border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">
            <i class="fas fa-question-circle mr-2"></i>About CSV Export
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-gray-700">
            <div>
                <h4 class="font-semibold mb-2">What's included:</h4>
                <ul class="space-y-1 list-disc list-inside">
                    <li>Asset ID and database keys</li>
                    <li>File information (name, type, size)</li>
                    <li>S3 storage details (keys, ETag)</li>
                    <li>Image dimensions (if applicable)</li>
                    <li>Metadata (alt text, captions, license, copyright)</li>
                    <li>Uploader information</li>
                    <li>All User and AI tags (as text)</li>
                    <li>Public URLs for assets</li>
                    <li>Creation and update timestamps</li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold mb-2">Use cases:</h4>
                <ul class="space-y-1 list-disc list-inside">
                    <li>Backup asset metadata</li>
                    <li>Audit and reporting</li>
                    <li>Import into other systems</li>
                    <li>Data analysis and visualization</li>
                    <li>Integration with spreadsheet tools</li>
                </ul>
                <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
                    <p class="text-xs text-yellow-800">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <strong>Note:</strong> CSV exports contain metadata only. Actual files remain in S3 storage.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportAssets() {
    return {
        folder: '',
        fileType: '',
        selectedTags: [],

        resetFilters() {
            this.folder = '';
            this.fileType = '';
            this.selectedTags = [];
        }
    }
}
</script>
@endsection
