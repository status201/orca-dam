@extends('layouts.app')

@section('title', 'Upload Assets')

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Upload Assets</h1>
        <p class="text-gray-600 mt-2">Upload images and files to your S3 bucket</p>
    </div>

    <div x-data="assetUploader()" class="bg-white rounded-lg shadow-lg p-6">
        <!-- Folder selector -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Upload to Folder</label>
            <div class="flex items-center space-x-3">
                <select x-model="selectedFolder"
                        class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-mono text-sm">
                    @foreach($folders as $folder)
                        @php
                            $relativePath = $folder === 'assets' ? '' : str_replace('assets/', '', $folder);
                            $depth = $relativePath ? substr_count($relativePath, '/') + 1 : 0;
                            $label = $folder === 'assets' ? '/ (root)' : str_repeat('│  ', max(0, $depth - 1)) . '├─ ' . basename($folder);
                        @endphp
                        <option value="{{ $folder }}">{{ $label }}</option>
                    @endforeach
                </select>

                @can('discover', App\Models\Asset::class)
                <!-- Admin-only: Scan for folders -->
                <button @click="scanFolders"
                        :disabled="scanningFolders"
                        type="button"
                        class="px-3 py-2 text-sm font-medium text-gray-600 bg-gray-50 border border-gray-200 rounded-lg hover:bg-gray-100 disabled:opacity-50"
                        title="Scan S3 for folders">
                    <i :class="scanningFolders ? 'fa-spinner fa-spin' : 'fa-sync'" class="fas"></i>
                </button>

                <!-- Admin-only: Create new folder -->
                <template x-if="!showNewFolderInput">
                    <button @click="showNewFolderInput = true; $nextTick(() => $refs.newFolderInput.focus())"
                            type="button"
                            class="px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 whitespace-nowrap">
                        <i class="fas fa-folder-plus mr-1"></i> New Folder
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
                               placeholder="subfolder-name"
                               class="w-40 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-blue-500">
                        <button @click="createFolder"
                                :disabled="creatingFolder || !newFolderName.trim()"
                                type="button"
                                class="px-3 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed">
                            <template x-if="!creatingFolder">
                                <span>Create</span>
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
            <p class="mt-1 text-xs text-gray-500">Files will be uploaded to: <span class="font-mono" x-text="selectedFolder"></span></p>
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
                        Drop files here or 
                        <button @click="$refs.fileInput.click()" 
                                class="text-blue-600 hover:text-blue-700 underline">
                            browse
                        </button>
                    </p>
                    <p class="text-sm text-gray-500 mt-2">
                        Maximum file size: 500MB
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
        CHUNK_SIZE: 10 * 1024 * 1024, // 10MB chunks
        CHUNKED_THRESHOLD: 10 * 1024 * 1024, // Use chunked upload for files >= 10MB
        selectedFolder: 'assets',
        showNewFolderInput: false,
        newFolderName: '',
        creatingFolder: false,
        scanningFolders: false,

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

        async scanFolders() {
            if (this.scanningFolders) return;

            this.scanningFolders = true;
            try {
                const response = await fetch('{{ route('folders.scan') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to scan folders');
                }

                window.showToast('Folders refreshed from S3');
                setTimeout(() => window.location.reload(), 500);
            } catch (error) {
                console.error('Scan folders error:', error);
                window.showToast(error.message || 'Failed to scan folders', 'error');
            } finally {
                this.scanningFolders = false;
            }
        },

        async createFolder() {
            if (!this.newFolderName.trim() || this.creatingFolder) return;

            this.creatingFolder = true;
            try {
                const response = await fetch('{{ route('folders.store') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        name: this.newFolderName.trim(),
                        parent: this.selectedFolder,
                    }),
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to create folder');
                }

                const data = await response.json();
                this.selectedFolder = data.folder;
                this.showNewFolderInput = false;
                this.newFolderName = '';
                window.showToast('Folder created successfully!');

                // Reload page to refresh folder list
                setTimeout(() => window.location.reload(), 500);
            } catch (error) {
                console.error('Create folder error:', error);
                window.showToast(error.message || 'Failed to create folder', 'error');
            } finally {
                this.creatingFolder = false;
            }
        },

        async uploadFiles() {
            if (this.selectedFiles.length === 0) return;

            this.uploading = true;

            try {
                for (let i = 0; i < this.selectedFiles.length; i++) {
                    const file = this.selectedFiles[i];
                    this.uploadProgress[i] = 0;

                    // Use chunked upload for large files
                    if (file.size >= this.CHUNKED_THRESHOLD) {
                        await this.uploadFileChunked(file, i);
                    } else {
                        await this.uploadFileDirect(file, i);
                    }
                }

                window.showToast('All files uploaded successfully!');
                setTimeout(() => {
                    window.location.href = '{{ route('assets.index') }}';
                }, 1000);

            } catch (error) {
                console.error('Upload error:', error);
                window.showToast(error.message || 'Upload failed. Please try again.', 'error');
                this.uploading = false;
            }
        },

        async uploadFileDirect(file, index) {
            const formData = new FormData();
            formData.append('files[]', file);
            formData.append('folder', this.selectedFolder);

            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();

                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        this.uploadProgress[index] = Math.round((e.loaded / e.total) * 100);
                    }
                });

                xhr.addEventListener('load', () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        resolve();
                    } else {
                        reject(new Error(this.parseErrorMessage(xhr)));
                    }
                });

                xhr.addEventListener('error', () => {
                    reject(new Error('Network error. Please check your connection.'));
                });

                xhr.open('POST', '{{ route('assets.store') }}');
                xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
                xhr.send(formData);
            });
        },

        async uploadFileChunked(file, index) {
            const totalChunks = Math.ceil(file.size / this.CHUNK_SIZE);

            // Step 1: Initialize chunked upload
            const session = await this.initiateChunkedUpload(file);

            try {
                // Step 2: Upload chunks with retry logic
                for (let chunkNumber = 1; chunkNumber <= totalChunks; chunkNumber++) {
                    const start = (chunkNumber - 1) * this.CHUNK_SIZE;
                    const end = Math.min(start + this.CHUNK_SIZE, file.size);
                    const chunk = file.slice(start, end);

                    await this.uploadChunkWithRetry(session.session_token, chunk, chunkNumber);

                    // Update progress (reserve 5% for completion)
                    const progress = Math.min(95, Math.round((chunkNumber / totalChunks) * 95));
                    this.uploadProgress[index] = progress;
                }

                // Step 3: Complete upload
                await this.completeChunkedUpload(session.session_token);
                this.uploadProgress[index] = 100;

            } catch (error) {
                // Abort upload on failure
                await this.abortChunkedUpload(session.session_token);
                throw error;
            }
        },

        async initiateChunkedUpload(file) {
            const response = await fetch('/api/chunked-upload/init', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    filename: file.name,
                    mime_type: file.type,
                    file_size: file.size,
                    folder: this.selectedFolder,
                }),
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Failed to initialize upload');
            }

            return await response.json();
        },

        async uploadChunkWithRetry(sessionToken, chunk, chunkNumber, retries = 3) {
            for (let attempt = 1; attempt <= retries; attempt++) {
                try {
                    return await this.uploadChunk(sessionToken, chunk, chunkNumber);
                } catch (error) {
                    if (attempt === retries) {
                        throw new Error(`Failed to upload chunk ${chunkNumber} after ${retries} attempts`);
                    }
                    // Exponential backoff: 1s, 2s, 4s
                    await new Promise(resolve => setTimeout(resolve, Math.pow(2, attempt - 1) * 1000));
                }
            }
        },

        async uploadChunk(sessionToken, chunk, chunkNumber) {
            const formData = new FormData();
            formData.append('session_token', sessionToken);
            formData.append('chunk_number', chunkNumber);
            formData.append('chunk', chunk);

            const response = await fetch('/api/chunked-upload/chunk', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: formData,
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Chunk upload failed');
            }

            return await response.json();
        },

        async completeChunkedUpload(sessionToken) {
            const response = await fetch('/api/chunked-upload/complete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ session_token: sessionToken }),
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Failed to complete upload');
            }

            return await response.json();
        },

        async abortChunkedUpload(sessionToken) {
            try {
                await fetch('/api/chunked-upload/abort', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ session_token: sessionToken }),
                });
            } catch (error) {
                console.error('Failed to abort upload:', error);
            }
        },

        parseErrorMessage(xhr) {
            let errorMessage = 'Upload failed. Please try again.';

            if (xhr.responseText && xhr.responseText.trim()) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response.message) {
                        errorMessage = response.message;
                    } else if (response.errors) {
                        errorMessage = Object.values(response.errors).flat().join(', ');
                    }
                } catch (e) {
                    if (xhr.status === 500) {
                        errorMessage = 'Server error occurred. Please try a smaller file.';
                    } else if (xhr.status === 413) {
                        errorMessage = 'File is too large. Maximum size is 500MB per file.';
                    } else if (xhr.status === 422) {
                        errorMessage = 'Invalid file format or validation error.';
                    }
                }
            }

            return errorMessage;
        }
    };
}
</script>
@endpush
@endsection
