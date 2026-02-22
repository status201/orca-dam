export function assetUploader() {
    const pageData = window.__pageData || {};

    return {
        dragActive: false,
        selectedFiles: [],
        uploading: false,
        uploadProgress: {},
        CHUNK_SIZE: 10 * 1024 * 1024, // 10MB chunks
        CHUNKED_THRESHOLD: 10 * 1024 * 1024, // Use chunked upload for files >= 10MB
        selectedFolder: pageData.selectedFolder,
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
                const response = await fetch(pageData.routes.foldersScan, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error(pageData.translations.failedToScanFolders);
                }

                window.showToast(pageData.translations.foldersRefreshed);
                setTimeout(() => window.location.reload(), 500);
            } catch (error) {
                console.error('Scan folders error:', error);
                window.showToast(error.message || pageData.translations.failedToScanFolders, 'error');
            } finally {
                this.scanningFolders = false;
            }
        },

        async createFolder() {
            if (!this.newFolderName.trim() || this.creatingFolder) return;

            this.creatingFolder = true;
            try {
                const response = await fetch(pageData.routes.foldersStore, {
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
                    throw new Error(error.message || pageData.translations.failedToCreateFolder);
                }

                const data = await response.json();
                this.selectedFolder = data.folder;
                this.showNewFolderInput = false;
                this.newFolderName = '';
                window.showToast(pageData.translations.folderCreated);

                // Reload page to refresh folder list
                setTimeout(() => window.location.reload(), 500);
            } catch (error) {
                console.error('Create folder error:', error);
                window.showToast(error.message || pageData.translations.failedToCreateFolder, 'error');
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

                window.showToast(pageData.translations.allFilesUploaded);
                setTimeout(() => {
                    window.location.href = pageData.routes.assetsIndex + '?folder=' + encodeURIComponent(this.selectedFolder);
                }, 1000);

            } catch (error) {
                console.error('Upload error:', error);
                window.showToast(error.message || pageData.translations.uploadFailed, 'error');
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
                    reject(new Error(pageData.translations.networkError));
                });

                xhr.open('POST', pageData.routes.assetsStore);
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
                throw new Error(error.message || pageData.translations.failedToInitUpload);
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
                throw new Error(error.message || pageData.translations.chunkUploadFailed);
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
                throw new Error(error.message || pageData.translations.failedToCompleteUpload);
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
            let errorMessage = pageData.translations.uploadFailed;

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
                        errorMessage = pageData.translations.serverError;
                    } else if (xhr.status === 413) {
                        errorMessage = pageData.translations.fileTooLarge;
                    } else if (xhr.status === 422) {
                        errorMessage = pageData.translations.invalidFormat;
                    }
                }
            }

            return errorMessage;
        }
    };
}

window.assetUploader = assetUploader;
