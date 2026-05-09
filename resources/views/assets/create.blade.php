@extends('layouts.app')

@section('title', __('Upload Assets'))

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">{{ __('Upload Assets') }}</h1>
        <p class="text-gray-600 mt-2">{{ __('Upload images and files to your S3 bucket') }}</p>
    </div>

    <div x-data="assetUploader()" class="bg-white rounded-lg shadow-lg p-6">
        <!-- Folder selector -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Upload to Folder') }}</label>
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <select x-model="selectedFolder"
                        class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-mono text-sm">
                    <x-folder-tree-options :folders="$folders" :root-folder="$rootFolder" />
                </select>

                @can('discover', App\Models\Asset::class)
                <!-- Admin-only: Scan for folders -->
                <button @click="scanFolders"
                        :disabled="scanningFolders"
                        type="button"
                        class="px-3 py-2 text-sm font-medium text-gray-600 bg-gray-50 border border-gray-200 rounded-lg hover:bg-gray-100 disabled:opacity-50"
                        title="{{ __('Scan S3 for folders') }}">
                    <i :class="scanningFolders ? 'fa-spinner fa-spin' : 'fa-sync'" class="fas"></i>
                </button>

                <!-- Admin-only: Create new folder -->
                <template x-if="!showNewFolderInput">
                    <button @click="showNewFolderInput = true; $nextTick(() => $refs.newFolderInput.focus())"
                            type="button"
                            class="px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 whitespace-nowrap">
                        <i class="fas fa-folder-plus mr-1"></i> {{ __('New Folder') }}
                    </button>
                </template>
                <template x-if="showNewFolderInput">
                    <div class="flex items-center space-x-2">
                        <span class="text-gray-500 text-sm" x-text="selectedFolder + '/'"></span>
                        <input type="text"
                               x-model="newFolderName"
                               x-ref="newFolderInput"
                               @keydown.enter="createFolder"
                               @keydown.escape="showNewFolderInput = false; newFolderName = ''"
                               placeholder="{{ __('subfolder-name') }}"
                               class="w-40 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-blue-500">
                        <button @click="createFolder"
                                :disabled="creatingFolder || !newFolderName.trim()"
                                type="button"
                                class="px-3 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <template x-if="!creatingFolder">
                                <span>{{ __('Create') }}</span>
                            </template>
                            <template x-if="creatingFolder">
                                <i class="fas fa-spinner fa-spin"></i>
                            </template>
                        </button>
                        <button @click="showNewFolderInput = false; newFolderName = ''"
                                type="button"
                                class="px-3 py-2 text-sm text-gray-600 hover:text-gray-800">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </template>
                @endcan
            </div>
            <p class="mt-1 text-xs text-gray-500">{{ __('Files will be uploaded to:') }} <span class="font-mono" x-text="selectedFolder"></span></p>
        </div>

        <!-- Keep original filename option -->
        <div class="mb-6">
            <label class="inline-flex items-center cursor-pointer">
                <input type="checkbox"
                       :checked="keepOriginalFilename"
                       @change="toggleKeepOriginalFilename($event)"
                       :disabled="uploading"
                       class="rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500">
                <span class="ml-2 text-sm font-medium text-gray-700">{{ __('Keep original filename') }}</span>
            </label>
            <p class="mt-1 text-xs text-gray-500">{{ __('Use the original filename in the URL instead of a generated name. Useful for download links.') }}</p>
        </div>

        @include('partials.upload-metadata')

        <!-- Drag and drop area -->
        <div @drop.prevent="handleDrop"
             @dragover.prevent="dragActive = true"
             @dragleave.prevent="dragActive = false"
             :class="dragActive ? 'border-blue-500 bg-blue-50' : 'border-gray-300'"
             class="border-2 border-dashed rounded-lg p-12 text-center transition-colors">

            <input type="file"
                   x-ref="fileInput"
                   @change="handleFiles"
                   multiple
                   accept="image/*,video/*,application/pdf"
                   class="hidden">

            <div class="space-y-4">
                <i class="fas fa-cloud-upload-alt text-6xl"
                   :class="dragActive ? 'text-blue-500' : 'text-gray-400'"></i>

                <div>
                    <p class="text-lg font-medium text-gray-700">
                        {{ __('Drop files here or') }}
                        <button @click="$refs.fileInput.click()"
                                class="text-blue-600 hover:text-blue-700 underline">
                            {{ __('browse') }}
                        </button>
                    </p>
                    <p class="text-sm text-gray-500 mt-2">
                        {{ __('Maximum file size: 500MB') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Selected files list -->
        <div x-show="selectedFiles.length > 0" class="mt-6" x-cloak>
            <h3 class="text-lg font-semibold mb-3">{{ __('Selected Files') }} (<span x-text="selectedFiles.length"></span>)</h3>

            <div class="invert-scrollbar-colors space-y-2 max-h-96 overflow-y-auto">
                <template x-for="(file, index) in selectedFiles" :key="index">
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center space-x-3 flex-1 min-w-0">
                            <template x-if="filePreviews[index]">
                                <img :src="filePreviews[index]" class="w-10 h-10 object-cover rounded flex-shrink-0" alt="">
                            </template>
                            <template x-if="!filePreviews[index]">
                                <i class="fas fa-file text-gray-400"></i>
                            </template>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate" x-text="file.name"></p>
                                <p class="text-xs text-gray-500" x-text="formatFileSize(file.size)"></p>
                                <template x-if="fileWarnings[index]">
                                    <p class="attention text-xs text-amber-600 font-medium">
                                        <i class="fas fa-exclamation-triangle mr-1"></i>
                                        <span x-text="fileWarnings[index]"></span>
                                    </p>
                                </template>
                            </div>
                        </div>

                        <!-- Progress / outcome -->
                        <template x-if="uploadResults[index]?.status === 'uploading' || (uploadProgress[index] !== undefined && !uploadResults[index])">
                            <div class="w-32 mx-4">
                                <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div class="h-full bg-blue-600 transition-all duration-300"
                                         :style="`width: ${uploadProgress[index] ?? 0}%`"></div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1 text-center" x-text="`${uploadProgress[index] ?? 0}%`"></p>
                            </div>
                        </template>

                        <template x-if="uploadResults[index]?.status === 'uploaded'">
                            <span class="mx-4 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                <i class="fas fa-check mr-1"></i> {{ __('Uploaded') }}
                            </span>
                        </template>

                        <template x-if="uploadResults[index]?.status === 'duplicate'">
                            <span class="mx-4 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-amber-100 text-amber-800"
                                  :title="{{ "'" }}{{ __('Already in library') }}{{ "'" }}">
                                <i class="fas fa-clone mr-1"></i> {{ __('Duplicate file') }}
                            </span>
                        </template>

                        <template x-if="uploadResults[index]?.status === 'failed'">
                            <span class="mx-4 inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800"
                                  :title="uploadResults[index].error">
                                <i class="fas fa-exclamation-triangle mr-1"></i> {{ __('Failed') }}
                            </span>
                        </template>

                        <button @click="removeFile(index)"
                                :disabled="uploading"
                                x-show="!batchComplete"
                                class="attention text-red-600 hover:text-red-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </template>
            </div>

            <!-- Upload button -->
            <div class="mt-6 flex justify-end space-x-3" x-show="!batchComplete">
                <button @click="clearAll()"
                        :disabled="uploading"
                        class="px-6 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                    {{ __('Clear All') }}
                </button>

                <button @click="uploadFiles"
                        :disabled="uploading || selectedFiles.length === 0"
                        class="px-6 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover disabled:opacity-50 disabled:cursor-not-allowed flex items-center">
                    <template x-if="!uploading">
                        <span><i class="fas fa-upload mr-2"></i> {{ __('Upload') }} <span x-text="selectedFiles.length"></span> {{ __('File(s)') }}</span>
                    </template>
                    <template x-if="uploading">
                        <span><i class="fas fa-spinner fa-spin mr-2"></i> {{ __('Uploading...') }}</span>
                    </template>
                </button>
            </div>
        </div>

        <!-- Duplicates results panel -->
        <div x-show="batchComplete && duplicateEntries().length > 0" x-cloak class="mt-8">
            <div class="border border-amber-200 bg-amber-50 rounded-lg overflow-hidden">
                <div class="px-4 py-3 bg-amber-100 border-b border-amber-200 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-amber-900 flex items-center">
                        <i class="fas fa-clone mr-2"></i>
                        <span x-text="duplicateEntries().length"></span>
                        <span class="ml-1">{{ __('Duplicate(s) skipped — already in library') }}</span>
                    </h3>
                    <label class="inline-flex items-center text-xs text-amber-900 cursor-pointer">
                        <input type="checkbox"
                               :checked="duplicateEntries().length > 0 && duplicateEntries().every(d => selectedDuplicates[d.index])"
                               @change="toggleAllDuplicates()"
                               class="mr-2 rounded border-amber-400 text-amber-700 focus:ring-amber-500">
                        {{ __('Select all') }}
                    </label>
                </div>
                <ul class="divide-y divide-amber-200">
                    <template x-for="dupe in duplicateEntries()" :key="dupe.index">
                        <li class="p-4 flex items-start gap-4">
                            <input type="checkbox"
                                   :checked="selectedDuplicates[dupe.index]"
                                   @change="selectedDuplicates[dupe.index] ? delete selectedDuplicates[dupe.index] : selectedDuplicates[dupe.index] = true"
                                   class="mt-1 rounded border-amber-400 text-amber-700 focus:ring-amber-500">

                            <!-- Existing asset thumbnail -->
                            <a :href="duplicateShowUrl(dupe) || '#'"
                               :target="dupe.show_url ? '_blank' : '_self'"
                               :class="dupe.show_url ? 'cursor-pointer' : 'cursor-not-allowed pointer-events-none'"
                               class="flex-shrink-0 w-20 h-20 bg-white rounded border border-amber-200 overflow-hidden flex items-center justify-center">
                                <template x-if="dupe.thumbnail_url">
                                    <img :src="dupe.thumbnail_url" class="w-full h-full object-cover" alt="">
                                </template>
                                <template x-if="!dupe.thumbnail_url">
                                    <i class="fas fa-file text-3xl text-gray-400"></i>
                                </template>
                            </a>

                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate" x-text="dupe.existing_filename"></p>
                                <template x-if="dupe.filename && dupe.filename !== dupe.existing_filename">
                                    <p class="text-xs text-gray-500 truncate">
                                        <span class="text-amber-700">{{ __('Uploaded as') }}:</span>
                                        <span x-text="dupe.filename"></span>
                                    </p>
                                </template>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    <span>{{ __('in folder') }}:</span>
                                    <span class="font-mono" x-text="dupe.existing_folder"></span>
                                </p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    <span x-text="formatFileSize(dupe.size)"></span>
                                    <template x-if="dupe.uploaded_at">
                                        <span>· <span>{{ __('uploaded') }}</span> <span x-text="new Date(dupe.uploaded_at).toLocaleDateString()"></span></span>
                                    </template>
                                </p>

                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <template x-if="dupe.show_url">
                                        <a :href="duplicateShowUrl(dupe)"
                                           target="_blank"
                                           class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-amber-900 bg-white border border-amber-300 rounded hover:bg-amber-100">
                                            <i class="fas fa-external-link-alt mr-1.5"></i>
                                            {{ __('View existing') }}
                                        </a>
                                    </template>

                                    <button @click="copyUrl(dupe.index, dupe.public_url)"
                                            type="button"
                                            class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-amber-900 bg-white border border-amber-300 rounded hover:bg-amber-100">
                                        <template x-if="!uploadResults[dupe.index]?.copied">
                                            <span><i class="fas fa-link mr-1.5"></i> {{ __('Copy URL') }}</span>
                                        </template>
                                        <template x-if="uploadResults[dupe.index]?.copied">
                                            <span class="text-green-700"><i class="fas fa-check mr-1.5"></i> {{ __('Copied') }}</span>
                                        </template>
                                    </button>

                                    <template x-if="dupe.is_trashed">
                                        <span class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-red-800 bg-red-100 rounded">
                                            <i class="fas fa-trash mr-1.5"></i> {{ __('In trash') }}
                                        </span>
                                    </template>
                                    <template x-if="dupe.is_trashed && dupe.can_restore">
                                        <button @click="restoreDuplicate(dupe.index)"
                                                type="button"
                                                class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-white bg-amber-600 rounded hover:bg-amber-700">
                                            <i class="fas fa-trash-restore mr-1.5"></i> {{ __('Restore from trash') }}
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </li>
                    </template>
                </ul>

                <!-- Bulk actions footer -->
                <div class="px-4 py-3 bg-amber-100 border-t border-amber-200 flex flex-wrap items-center gap-2 justify-end">
                    <button @click="copySelectedUrls()"
                            type="button"
                            :title="bulkCopyLabel()"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-amber-900 bg-white border border-amber-300 rounded hover:bg-amber-50">
                        <i class="fas fa-copy mr-1.5"></i>
                        <span x-text="bulkCopyLabel()"></span>
                    </button>

                    <button @click="revealDuplicatesInLibrary()"
                            type="button"
                            :title="{{ "'" }}{{ __('Reveal in library') }}{{ "'" }}"
                            class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-amber-700 rounded hover:bg-amber-800">
                        <i class="fas fa-search-plus mr-1.5"></i>
                        {{ __('Reveal in library') }}
                        <i class="fas fa-external-link-alt ml-1.5 text-[10px]"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Failed results panel -->
        <div x-show="batchComplete && failedEntries().length > 0" x-cloak class="mt-6">
            <div class="border border-red-200 bg-red-50 rounded-lg overflow-hidden">
                <div class="px-4 py-3 bg-red-100 border-b border-red-200">
                    <h3 class="text-sm font-semibold text-red-900 flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <span x-text="failedEntries().length"></span>
                        <span class="ml-1">{{ __('Failed file(s)') }}</span>
                    </h3>
                </div>
                <ul class="divide-y divide-red-200">
                    <template x-for="failure in failedEntries()" :key="failure.index">
                        <li class="p-4 flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate"
                                   x-text="selectedFiles[failure.index]?.name"></p>
                                <p class="text-xs text-red-700 mt-0.5" x-text="failure.error"></p>
                            </div>
                            <button @click="retryFailed(failure.index)"
                                    type="button"
                                    class="inline-flex items-center px-2.5 py-1 text-xs font-medium text-white bg-red-600 rounded hover:bg-red-700 flex-shrink-0">
                                <i class="fas fa-redo mr-1.5"></i> {{ __('Retry') }}
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>

        <!-- Continue button (after batch with duplicates / failures) -->
        <div x-show="batchComplete && (duplicateEntries().length > 0 || failedEntries().length > 0)"
             x-cloak
             class="mt-6 flex justify-end">
            <button @click="goToLibrary()"
                    type="button"
                    class="px-6 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
                {{ __('Continue to library') }} <i class="fas fa-arrow-right ml-2"></i>
            </button>
        </div>
    </div>
</div>

@push('scripts')
<script>
    window.__pageData = {
        selectedFolder: @json(request('folder', $rootFolder)),
        routes: {
            foldersScan: '{{ route('folders.scan') }}',
            foldersStore: '{{ route('folders.store') }}',
            assetsStore: '{{ route('assets.store') }}',
            assetsIndex: '{{ route('assets.index') }}',
            assetsShow: '{{ route('assets.show', ['asset' => ':id']) }}',
            assetsRestore: '{{ route('assets.restore', ['asset' => ':id']) }}',
            tagsSearch: '{{ route('tags.search') }}',
        },
        translations: {
            failedToScanFolders: @js(__('Failed to scan folders')),
            foldersRefreshed: @js(__('Folders refreshed from S3')),
            failedToCreateFolder: @js(__('Failed to create folder')),
            folderCreated: @js(__('Folder created successfully!')),
            allFilesUploaded: @js(__('All files uploaded successfully!')),
            uploadFailed: @js(__('Upload failed. Please try again.')),
            networkError: @js(__('Network error. Please check your connection.')),
            failedToInitUpload: @js(__('Failed to initialize upload')),
            chunkUploadFailed: @js(__('Chunk upload failed')),
            failedToCompleteUpload: @js(__('Failed to complete upload')),
            keepOriginalFilenameWarning: @js(__("Warning: When using original filenames, the filename becomes part of the permanent URL and cannot be easily changed later. Files with the same name in the same folder will be overwritten.\n\nDo you want to continue?")),
            serverError: @js(__('Server error occurred. Please try a smaller file.')),
            fileTooLarge: @js(__('File is too large. Maximum size is 500MB per file.')),
            invalidFormat: @js(__('Invalid file format or validation error.')),
            imageDimensionWarning: @js(__('Large image (:widthx:height) — may fail during processing')),
            uploadSummary: @js(__(':uploaded uploaded, :duplicates duplicate(s), :failed failed')),
            urlCopied: @js(__('URL copied to clipboard')),
            urlsCopied: @js(__(':count URL(s) copied to clipboard')),
            copyCountUrls: @js(__('Copy :count URL(s)')),
            failedToCopy: @js(__('Failed to copy')),
            restoreFailed: @js(__('Restore failed')),
            restored: @js(__('Restored')),
        },
    };
</script>
@endpush
@endsection
