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

            <div class="space-y-2 max-h-96 overflow-y-auto">
                <template x-for="(file, index) in selectedFiles" :key="index">
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center space-x-3 flex-1 min-w-0">
                            <i class="fas fa-file text-gray-400"></i>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate" x-text="file.name"></p>
                                <p class="text-xs text-gray-500" x-text="formatFileSize(file.size)"></p>
                            </div>
                        </div>

                        <!-- Progress bar -->
                        <template x-if="uploadProgress[index] !== undefined">
                            <div class="w-32 mx-4">
                                <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div class="h-full bg-blue-600 transition-all duration-300"
                                         :style="`width: ${uploadProgress[index]}%`"></div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1 text-center" x-text="`${uploadProgress[index]}%`"></p>
                            </div>
                        </template>

                        <button @click="removeFile(index)"
                                :disabled="uploading"
                                class="attention text-red-600 hover:text-red-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </template>
            </div>

            <!-- Upload button -->
            <div class="mt-6 flex justify-end space-x-3">
                <button @click="selectedFiles = []; uploadProgress = {}"
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
            serverError: @js(__('Server error occurred. Please try a smaller file.')),
            fileTooLarge: @js(__('File is too large. Maximum size is 500MB per file.')),
            invalidFormat: @js(__('Invalid file format or validation error.')),
        },
    };
</script>
@endpush
@endsection
