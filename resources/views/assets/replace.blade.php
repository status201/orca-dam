@extends('layouts.app')

@section('title', __('Replace Asset'))

@section('content')
<div class="max-w-5xl mx-auto" x-data="assetReplacer()">
    @php
        $extension = strtolower(pathinfo($asset->s3_key, PATHINFO_EXTENSION));
    @endphp

    <!-- Back button and breadcrumb -->
    <div class="mb-6 flex items-center justify-between">
        <a href="{{ route('assets.edit', $asset) }}" class="inline-flex items-center text-orca-black hover:text-orca-black-hover">
            <i class="fas fa-arrow-left mr-2"></i> {{ __('Back to Edit') }}
        </a>

        <x-asset-breadcrumb :asset="$asset" />
    </div>

    <div class="bg-white rounded-lg shadow-lg p-6">
        <h1 class="text-3xl font-bold mb-6">{{ __('Replace Asset File') }}</h1>

        <!-- Success Message -->
        <div x-show="success" x-cloak class="attention mb-6 p-4 bg-green-50 border border-green-200 text-green-800 rounded-lg">
            <i class="fas fa-check-circle mr-2"></i>
            <span x-text="successMessage"></span>
            <span class="ml-2 text-green-600">{{ __('Redirecting in') }} <span x-text="redirectCountdown"></span> {{ __('seconds...') }}</span>
        </div>

        <!-- Error Message -->
        <div x-show="error" x-cloak class="attention mb-6 p-4 bg-red-50 border border-red-200 text-red-800 rounded-lg">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <span x-text="error"></span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Left: Current Asset Preview -->
            <div>
                <h2 class="text-lg font-semibold mb-4 text-gray-700">{{ __('Current Asset') }}</h2>
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
                            <span class="text-gray-500">{{ __('Filename:') }}</span>
                            <span class="font-medium text-gray-700 truncate ml-2" title="{{ $asset->filename }}">{{ $asset->filename }}</span>
                        </div>
                        @if($asset->width && $asset->height)
                        <div class="flex justify-between">
                            <span class="text-gray-500">{{ __('Dimensions:') }}</span>
                            <span class="font-medium text-gray-700">{{ $asset->width }} x {{ $asset->height }}px</span>
                        </div>
                        @endif
                        <div class="flex justify-between">
                            <span class="text-gray-500">{{ __('File size:') }}</span>
                            <span class="font-medium text-gray-700">{{ $asset->formatted_size }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">{{ __('Extension:') }}</span>
                            <span class="font-medium text-gray-700 uppercase">.{{ $extension }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Upload Area -->
            <div>
                <h2 class="text-lg font-semibold mb-4 text-gray-700">{{ __('Upload Replacement') }}</h2>

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
                                    {{ __('Drop your') }} .{{ $extension }} {{ __('file here') }}
                                </p>
                                <p class="text-gray-500 mt-1">{{ __('or') }}</p>
                                <button @click="$refs.fileInput.click()"
                                        type="attention button"
                                        class="mt-2 px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors">
                                    {{ __('Browse Files') }}
                                </button>
                            </div>
                            <p class="text-sm text-gray-500">
                                {{ __('Only') }} <span class="font-semibold uppercase">.{{ $extension }}</span> {{ __('files accepted (max 500MB)') }}
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
                                    <i class="fas fa-times mr-1"></i> {{ __('Clear') }}
                                </button>
                                <button @click="showConfirmation = true"
                                        type="button"
                                        class="attention px-4 py-2 bg-amber-600 text-white rounded-lg hover:bg-amber-700 transition-colors">
                                    <i class="fas fa-shuffle mr-1"></i> {{ __('Replace File') }}
                                </button>
                            </div>
                        </div>
                    </template>

                    <template x-if="uploading">
                        <div class="space-y-4">
                            <i class="fas fa-spinner fa-spin text-5xl text-amber-500"></i>
                            <div>
                                <p class="text-lg font-medium text-gray-700">{{ __('Uploading...') }}</p>
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
                        {{ __('Cancel') }}
                    </a>
                </div>
            </div>
        </div>

        <!-- Info Box -->
        <div class="mt-8 p-6 bg-amber-50 border border-amber-200 rounded-lg">
            <h3 class="font-semibold text-amber-800 mb-3">
                <i class="fas fa-info-circle mr-2"></i>{{ __('About Asset Replacement') }}
            </h3>
            <div class="text-sm text-amber-700 space-y-2">
                <p>
                    <strong>{{ __('How to use:') }}</strong> {{ __('This feature allows you to replace the file while keeping the same URL.') }}
                    {{ __('Use this to upload a draft or placeholder first, link to it in your content, then replace with the final version later.') }}
                </p>
                <p>
                    <strong>{{ __('What stays the same:') }}</strong> {{ __('The S3 URL, all metadata (alt text, caption, tags, license, copyright).') }}
                </p>
                <p>
                    <strong>{{ __('What changes:') }}</strong> {{ __('The file itself, filename, dimensions, and file size.') }}
                </p>
                <p class="pt-2 border-t border-amber-300">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <strong>{{ __('Warning:') }}</strong> {{ __('Whether previous versions are retained depends on your S3 bucket\'s versioning settings.') }}
                    {{ __('Without versioning enabled, the previous file will be permanently deleted and cannot be recovered.') }}
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
                    <div class="warning mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-amber-100 sm:mx-0 sm:h-10 sm:w-10">
                        <i class="fas fa-exclamation-triangle text-amber-600"></i>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                            {{ __('Confirm File Replacement') }}
                        </h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-500">
                                {{ __('Are you sure you want to replace this file? The original file will be overwritten.') }}
                                <strong>{{ __('This action cannot be undone') }}</strong> {{ __('unless S3 versioning is enabled on your bucket.') }}
                            </p>
                        </div>
                    </div>
                </div>
                <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                    <button @click="uploadFile()"
                            type="button"
                            class="attention w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-amber-600 text-base font-medium text-white hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 sm:ml-3 sm:w-auto sm:text-sm">
                        <i class="fas fa-shuffle mr-2"></i> {{ __('Yes, Replace') }}
                    </button>
                    <button @click="showConfirmation = false"
                            type="button"
                            class="attention mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 sm:mt-0 sm:w-auto sm:text-sm">
                        {{ __('Cancel') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    window.__pageData = {
        allowedExtension: @js($extension),
        csrfToken: '{{ csrf_token() }}',
        replaceUrl: '{{ route('assets.replace.store', $asset) }}',
        editUrl: '{{ route('assets.edit', $asset) }}',
        translations: {
            fileMustHaveSameExtension: @js(__('The file must have the same extension.')),
            fileTooLarge: @js(__('The file may not be greater than 500MB.')),
            assetReplacedSuccessfully: @js(__('Asset replaced successfully')),
            failedToReplace: @js(__('Failed to replace asset. Please try again.')),
            networkError: @js(__('Network error. Please check your connection and try again.')),
            unexpectedError: @js(__('An unexpected error occurred. Please try again.')),
        },
    };
</script>
@endpush
@endsection
