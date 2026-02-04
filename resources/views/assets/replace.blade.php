@extends('layouts.app')

@section('title', 'Replace Asset')

@section('content')
<div class="max-w-5xl mx-auto" x-data="assetReplacer()">
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

        $extension = strtolower(pathinfo($asset->filename, PATHINFO_EXTENSION));
    @endphp

    <!-- Back button and breadcrumb -->
    <div class="mb-6 flex items-center justify-between">
        <a href="{{ route('assets.edit', $asset) }}" class="inline-flex items-center text-orca-black hover:text-orca-black-hover">
            <i class="fas fa-arrow-left mr-2"></i> Back to Edit
        </a>

        @if(count($breadcrumbSegments) > 0)
        <nav class="text-sm text-gray-500 flex items-center">
            <span class="hidden sm:flex items-center">
                @foreach($breadcrumbSegments as $index => $segment)
                    <span class="mx-1 text-gray-400">/</span>
                    <a href="{{ route('assets.index', ['folder' => $breadcrumbPaths[$index]]) }}"
                       class="hover:text-orca-black transition-colors {{ $loop->last ? 'font-medium text-gray-700' : '' }}">
                        {{ $segment }}
                    </a>
                @endforeach
            </span>
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
        <h1 class="text-3xl font-bold mb-6">Replace Asset File</h1>

        <!-- Success Message -->
        <div x-show="success" x-cloak class="attention mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
            <i class="fas fa-check-circle mr-2"></i>
            <span x-text="successMessage"></span>
            <span class="ml-2 text-green-600">Redirecting in <span x-text="redirectCountdown"></span> seconds...</span>
        </div>

        <!-- Error Message -->
        <div x-show="error" x-cloak class="attention mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <span x-text="error"></span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Left: Current Asset Preview -->
            <div>
                <h2 class="text-lg font-semibold mb-4 text-gray-700">Current Asset</h2>
                <div class="bg-gray-50 rounded-lg p-6">
                    <!-- Preview -->
                    <div class="flex justify-center mb-4">
                        @if($asset->isImage())
                            <img src="{{ $asset->thumbnail_url ?? $asset->url }}"
                                 alt="{{ $asset->filename }}"
                                 class="max-w-full max-h-64 rounded-lg shadow">
                        @else
                            <div class="w-32 h-32 bg-gray-100 rounded-lg flex items-center justify-center">
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
                                <i class="fas {{ $icon }} {{ $colorClass }} opacity-60" style="font-size: 4rem;"></i>
                            </div>
                        @endif
                    </div>

                    <!-- File Info -->
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Filename:</span>
                            <span class="font-medium text-gray-700 truncate ml-2" title="{{ $asset->filename }}">{{ $asset->filename }}</span>
                        </div>
                        @if($asset->width && $asset->height)
                        <div class="flex justify-between">
                            <span class="text-gray-500">Dimensions:</span>
                            <span class="font-medium text-gray-700">{{ $asset->width }} x {{ $asset->height }}px</span>
                        </div>
                        @endif
                        <div class="flex justify-between">
                            <span class="text-gray-500">File size:</span>
                            <span class="font-medium text-gray-700">{{ $asset->formatted_size }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Extension:</span>
                            <span class="font-medium text-gray-700 uppercase">.{{ $extension }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Upload Area -->
            <div>
                <h2 class="text-lg font-semibold mb-4 text-gray-700">Upload Replacement</h2>

                <!-- Drag and Drop Area -->
                <div @drop.prevent="handleDrop($event)"
                     @dragover.prevent="dragActive = true"
                     @dragleave.prevent="dragActive = false"
                     :class="dragActive ? 'border-amber-500 bg-amber-50' : 'border-gray-300'"
                     class="border-2 border-dashed rounded-lg p-8 text-center transition-colors mb-6">

                    <input type="file"
                           x-ref="fileInput"
                           @change="handleFiles($event)"
                           accept=".{{ $extension }}"
                           class="hidden">

                    <template x-if="!selectedFile">
                        <div class="space-y-4">
                            <i class="attention fas fa-cloud-upload-alt text-5xl"
                               :class="dragActive ? 'text-amber-500' : 'text-gray-400'"></i>
                            <div>
                                <p class="text-lg font-medium text-gray-700">
                                    Drop your .{{ $extension }} file here
                                </p>
                                <p class="text-gray-500 mt-1">or</p>
                                <button @click="$refs.fileInput.click()"
                                        type="attention button"
                                        class="mt-2 px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors">
                                    Browse Files
                                </button>
                            </div>
                            <p class="text-sm text-gray-500">
                                Only <span class="font-semibold uppercase">.{{ $extension }}</span> files accepted (max 500MB)
                            </p>
                        </div>
                    </template>

                    <template x-if="selectedFile && !uploading">
                        <div class="space-y-4">
                            <i class="attention fas fa-file-check text-5xl text-green-500"></i>
                            <div>
                                <p class="text-lg font-medium text-gray-700" x-text="selectedFile.name"></p>
                                <p class="text-sm text-gray-500" x-text="formatFileSize(selectedFile.size)"></p>
                            </div>
                            <div class="flex justify-center space-x-3">
                                <button @click="clearSelection()"
                                        type="button"
                                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                                    <i class="fas fa-times mr-1"></i> Clear
                                </button>
                                <button @click="showConfirmation = true"
                                        type="button"
                                        class="attention px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors">
                                    <i class="fas fa-sync-alt mr-1"></i> Replace File
                                </button>
                            </div>
                        </div>
                    </template>

                    <template x-if="uploading">
                        <div class="space-y-4">
                            <i class="fas fa-spinner fa-spin text-5xl text-amber-500"></i>
                            <div>
                                <p class="text-lg font-medium text-gray-700">Uploading...</p>
                                <div class="w-full bg-gray-200 rounded-full h-2.5 mt-3">
                                    <div class="bg-amber-600 h-2.5 rounded-full transition-all duration-300"
                                         :style="'width: ' + uploadProgress + '%'"></div>
                                </div>
                                <p class="text-sm text-gray-500 mt-2" x-text="uploadProgress + '%'"></p>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end space-x-3">
                    <a href="{{ route('assets.edit', $asset) }}"
                       class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                        Cancel
                    </a>
                </div>
            </div>
        </div>

        <!-- Info Box -->
        <div class="mt-8 p-6 bg-amber-50 border border-amber-200 rounded-lg">
            <h3 class="font-semibold text-amber-800 mb-3">
                <i class="fas fa-info-circle mr-2"></i>About Asset Replacement
            </h3>
            <div class="text-sm text-amber-700 space-y-2">
                <p>
                    <strong>How to use:</strong> This feature allows you to replace the file while keeping the same URL.
                    Use this to upload a draft or placeholder first, link to it in your content, then replace with the final version later.
                </p>
                <p>
                    <strong>What stays the same:</strong> The S3 URL, all metadata (alt text, caption, tags, license, copyright).
                </p>
                <p>
                    <strong>What changes:</strong> The file itself, filename, dimensions, and file size.
                </p>
                <p class="pt-2 border-t border-amber-300">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <strong>Warning:</strong> Whether previous versions are retained depends on your S3 bucket's versioning settings.
                    Without versioning enabled, the previous file will be permanently deleted and cannot be recovered.
                </p>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div x-show="showConfirmation"
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto"
         aria-labelledby="modal-title"
         role="dialog"
         aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <!-- Backdrop -->
            <div x-show="showConfirmation"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
                 @click="showConfirmation = false"></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <!-- Modal Panel -->
            <div x-show="showConfirmation"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full sm:p-6">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-amber-100 sm:mx-0 sm:h-10 sm:w-10">
                        <i class="fas fa-exclamation-triangle text-amber-600"></i>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                            Confirm File Replacement
                        </h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-500">
                                Are you sure you want to replace this file? The original file will be overwritten.
                                <strong>This action cannot be undone</strong> unless S3 versioning is enabled on your bucket.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                    <button @click="uploadFile()"
                            type="button"
                            class="attention w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-amber-600 text-base font-medium text-white hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 sm:ml-3 sm:w-auto sm:text-sm">
                        <i class="fas fa-sync-alt mr-2"></i> Yes, Replace
                    </button>
                    <button @click="showConfirmation = false"
                            type="button"
                            class="attention mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 sm:mt-0 sm:w-auto sm:text-sm">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function assetReplacer() {
    return {
        // State
        dragActive: false,
        selectedFile: null,
        uploading: false,
        uploadProgress: 0,
        showConfirmation: false,
        error: null,
        success: false,
        successMessage: '',
        redirectCountdown: 2,

        // Config
        allowedExtension: '{{ $extension }}',
        maxSize: 512 * 1024 * 1024, // 500MB
        csrfToken: '{{ csrf_token() }}',
        replaceUrl: '{{ route('assets.replace.store', $asset) }}',
        editUrl: '{{ route('assets.edit', $asset) }}',

        handleDrop(e) {
            this.dragActive = false;
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                this.processFile(files[0]);
            }
        },

        handleFiles(e) {
            const files = e.target.files;
            if (files.length > 0) {
                this.processFile(files[0]);
            }
        },

        processFile(file) {
            this.error = null;

            // Validate extension
            const fileExtension = file.name.split('.').pop().toLowerCase();
            if (fileExtension !== this.allowedExtension) {
                this.error = `The file must have the same extension (.${this.allowedExtension}).`;
                return;
            }

            // Validate size
            if (file.size > this.maxSize) {
                this.error = 'The file may not be greater than 500MB.';
                return;
            }

            this.selectedFile = file;
        },

        clearSelection() {
            this.selectedFile = null;
            this.error = null;
            this.$refs.fileInput.value = '';
        },

        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        async uploadFile() {
            this.showConfirmation = false;
            this.uploading = true;
            this.uploadProgress = 0;
            this.error = null;

            const formData = new FormData();
            formData.append('file', this.selectedFile);

            try {
                const xhr = new XMLHttpRequest();

                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        this.uploadProgress = Math.round((e.loaded / e.total) * 100);
                    }
                });

                xhr.addEventListener('load', () => {
                    this.uploading = false;

                    if (xhr.status >= 200 && xhr.status < 300) {
                        const response = JSON.parse(xhr.responseText);
                        this.success = true;
                        this.successMessage = response.message || 'Asset replaced successfully';
                        this.startRedirectCountdown();
                    } else {
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.errors && errorResponse.errors.file) {
                                this.error = errorResponse.errors.file[0];
                            } else {
                                this.error = errorResponse.message || 'Failed to replace asset. Please try again.';
                            }
                        } catch {
                            this.error = 'Failed to replace asset. Please try again.';
                        }
                    }
                });

                xhr.addEventListener('error', () => {
                    this.uploading = false;
                    this.error = 'Network error. Please check your connection and try again.';
                });

                xhr.open('POST', this.replaceUrl);
                xhr.setRequestHeader('X-CSRF-TOKEN', this.csrfToken);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.send(formData);

            } catch (err) {
                this.uploading = false;
                this.error = 'An unexpected error occurred. Please try again.';
            }
        },

        startRedirectCountdown() {
            this.redirectCountdown = 2;
            const interval = setInterval(() => {
                this.redirectCountdown--;
                if (this.redirectCountdown <= 0) {
                    clearInterval(interval);
                    window.location.href = this.editUrl + '?replaced=1';
                }
            }, 1000);
        }
    }
}
</script>
@endpush
@endsection
