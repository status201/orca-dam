@extends('layouts.app')

@section('title', 'Upload Assets')

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Upload Assets</h1>
        <p class="text-gray-600 mt-2">Upload images and files to your S3 bucket</p>
    </div>
    
    <div x-data="assetUploader()" class="bg-white rounded-lg shadow-lg p-6">
        <!-- Drag and drop area -->
        <div @drop.prevent="handleDrop"
             @dragover.prevent="dragActive = true"
             @dragleave.prevent="dragActive = false"
             :class="dragActive ? 'border-blue-500 bg-blue-50' : 'border-gray-300'"
             class="border-2 border-dashed rounded-lg p-12 text-center transition-colors">
            
            <input type="file" 
                   ref="fileInput"
                   @change="handleFiles"
                   multiple
                   accept="image/*,video/*,application/pdf"
                   class="hidden">
            
            <div class="space-y-4">
                <i class="fas fa-cloud-upload-alt text-6xl" 
                   :class="dragActive ? 'text-blue-500' : 'text-gray-400'"></i>
                
                <div>
                    <p class="text-lg font-medium text-gray-700">
                        Drop files here or 
                        <button @click="$refs.fileInput.click()" 
                                class="text-blue-600 hover:text-blue-700 underline">
                            browse
                        </button>
                    </p>
                    <p class="text-sm text-gray-500 mt-2">
                        Maximum file size: 100MB
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Selected files list -->
        <div x-show="selectedFiles.length > 0" class="mt-6" x-cloak>
            <h3 class="text-lg font-semibold mb-3">Selected Files (<span x-text="selectedFiles.length"></span>)</h3>
            
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
                                class="text-red-600 hover:text-red-700 disabled:opacity-50 disabled:cursor-not-allowed">
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
                    Clear All
                </button>
                
                <button @click="uploadFiles"
                        :disabled="uploading || selectedFiles.length === 0"
                        class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center">
                    <template x-if="!uploading">
                        <span><i class="fas fa-upload mr-2"></i> Upload <span x-text="selectedFiles.length"></span> File(s)</span>
                    </template>
                    <template x-if="uploading">
                        <span><i class="fas fa-spinner fa-spin mr-2"></i> Uploading...</span>
                    </template>
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function assetUploader() {
    return {
        dragActive: false,
        selectedFiles: [],
        uploading: false,
        uploadProgress: {},
        
        handleDrop(e) {
            this.dragActive = false;
            const files = Array.from(e.dataTransfer.files);
            this.addFiles(files);
        },
        
        handleFiles(e) {
            const files = Array.from(e.target.files);
            this.addFiles(files);
        },
        
        addFiles(files) {
            this.selectedFiles.push(...files);
        },
        
        removeFile(index) {
            this.selectedFiles.splice(index, 1);
        },
        
        formatFileSize(bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            let size = bytes;
            let unitIndex = 0;
            
            while (size >= 1024 && unitIndex < units.length - 1) {
                size /= 1024;
                unitIndex++;
            }
            
            return `${size.toFixed(2)} ${units[unitIndex]}`;
        },
        
        async uploadFiles() {
            if (this.selectedFiles.length === 0) return;
            
            this.uploading = true;
            const formData = new FormData();
            
            this.selectedFiles.forEach((file, index) => {
                formData.append('files[]', file);
                this.uploadProgress[index] = 0;
            });
            
            try {
                const xhr = new XMLHttpRequest();
                
                // Track upload progress
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        const percentComplete = Math.round((e.loaded / e.total) * 100);
                        // Update all file progress bars
                        this.selectedFiles.forEach((_, index) => {
                            this.uploadProgress[index] = percentComplete;
                        });
                    }
                });
                
                xhr.addEventListener('load', () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        // Success - could be 200, 201, or redirect
                        window.showToast('Files uploaded successfully!');
                        setTimeout(() => {
                            window.location.href = '{{ route('assets.index') }}';
                        }, 1000);
                    } else if (xhr.status === 302) {
                        // Laravel redirect after success
                        window.location.href = '{{ route('assets.index') }}';
                    } else {
                        // Error - parse error message from response if available
                        let errorMessage = 'Upload failed. Please try again.';

                        // Try to parse JSON response
                        if (xhr.responseText && xhr.responseText.trim()) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.message) {
                                    errorMessage = response.message;
                                } else if (response.errors) {
                                    errorMessage = Object.values(response.errors).flat().join(', ');
                                }
                            } catch (e) {
                                // Not JSON, check for specific error codes
                                if (xhr.status === 500) {
                                    errorMessage = 'Server error occurred. The file might be too large or corrupt. Please try a smaller file.';
                                } else if (xhr.status === 413) {
                                    errorMessage = 'Files are too large. Maximum size is 100MB per file.';
                                } else if (xhr.status === 422) {
                                    errorMessage = 'Invalid file format or validation error.';
                                } else if (xhr.status === 0) {
                                    errorMessage = 'Network error or request timeout. Please try again.';
                                }
                            }
                        } else {
                            // Empty response
                            errorMessage = 'Server error with no response. The file might be too large or the server is unreachable.';
                        }

                        console.error('Upload failed:', {
                            status: xhr.status,
                            statusText: xhr.statusText,
                            responseText: xhr.responseText
                        });

                        window.showToast(errorMessage, 'error');
                        this.uploading = false;
                        this.uploadProgress = {};
                    }
                });
                
                xhr.addEventListener('error', () => {
                    window.showToast('Network error. Please check your connection and try again.', 'error');
                    this.uploading = false;
                    this.uploadProgress = {};
                });
                
                xhr.open('POST', '{{ route('assets.store') }}');
                xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
                xhr.send(formData);
                
            } catch (error) {
                console.error('Upload error:', error);
                window.showToast('Upload failed. Please try again.', 'error');
                this.uploading = false;
                this.uploadProgress = {};
            }
        }
    };
}
</script>
@endpush
@endsection
