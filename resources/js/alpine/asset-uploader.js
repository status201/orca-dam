export function assetUploader() {
    const pageData = window.__pageData || {};

    return {
        dragActive: false,
        selectedFiles: [],
        uploading: false,
        uploadProgress: {},
        fileWarnings: {},
        filePreviews: {},
        fileThumbnails: {},
        CHUNK_SIZE: 10 * 1024 * 1024, // 10MB chunks
        CHUNKED_THRESHOLD: 10 * 1024 * 1024, // Use chunked upload for files >= 10MB
        selectedFolder: pageData.selectedFolder,
        keepOriginalFilename: false,
        keepOriginalFilenameConfirmed: false,
        showNewFolderInput: false,
        newFolderName: '',
        creatingFolder: false,
        scanningFolders: false,

        toggleKeepOriginalFilename(event) {
            if (!this.keepOriginalFilename) {
                // Turning on: show confirmation
                if (confirm(pageData.translations.keepOriginalFilenameWarning)) {
                    this.keepOriginalFilename = true;
                    this.keepOriginalFilenameConfirmed = true;
                } else {
                    // Cancel: uncheck the checkbox that the browser already checked
                    event.target.checked = false;
                }
            } else {
                // Turning off: no confirmation needed
                this.keepOriginalFilename = false;
                this.keepOriginalFilenameConfirmed = false;
            }
        },

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
            const startIndex = this.selectedFiles.length;
            this.selectedFiles.push(...files);
            files.forEach((file, i) => {
                this.checkImageDimensions(file, startIndex + i);
                this.generatePreview(file, startIndex + i);
            });
        },

        generatePreview(file, index) {
            if (file.type.startsWith('image/')) {
                this.filePreviews[index] = URL.createObjectURL(file);
                return;
            }

            if (file.type === 'application/pdf') {
                this.generatePdfPreview(file, index);
                return;
            }

            if (file.type.startsWith('video/')) {
                this.generateVideoPreview(file, index);
            }
        },

        async generatePdfPreview(file, index) {
            try {
                const pdfjsLib = await import('pdfjs-dist');
                const PdfWorker = (await import('pdfjs-dist/build/pdf.worker.mjs?worker')).default;
                pdfjsLib.GlobalWorkerOptions.workerPort = new PdfWorker();

                const arrayBuffer = await file.arrayBuffer();
                const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
                const page = await pdf.getPage(1);
                const viewport = page.getViewport({ scale: 1 });

                const maxSize = 300;
                const scale = Math.min(maxSize / viewport.width, maxSize / viewport.height, 1);
                const scaledViewport = page.getViewport({ scale });

                const canvas = document.createElement('canvas');
                canvas.width = Math.round(scaledViewport.width);
                canvas.height = Math.round(scaledViewport.height);
                const ctx = canvas.getContext('2d');

                await page.render({ canvasContext: ctx, viewport: scaledViewport }).promise;

                const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
                this.filePreviews[index] = dataUrl;
                this.fileThumbnails[index] = dataUrl.replace(/^data:image\/jpeg;base64,/, '');
            } catch (e) {
                console.warn('PDF preview generation failed:', e);
            }
        },

        generateVideoPreview(file, index) {
            const url = URL.createObjectURL(file);
            const video = document.createElement('video');
            video.muted = true;
            video.preload = 'auto';

            const maxSize = 300;

            video.addEventListener('loadeddata', () => {
                video.currentTime = Math.min(1, Math.max(0, video.duration - 0.1));
            });

            video.addEventListener('seeked', () => {
                const canvas = document.createElement('canvas');
                const vw = video.videoWidth;
                const vh = video.videoHeight;
                const scale = Math.min(maxSize / vw, maxSize / vh, 1);
                canvas.width = Math.round(vw * scale);
                canvas.height = Math.round(vh * scale);
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                try {
                    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
                    this.filePreviews[index] = dataUrl;
                    this.fileThumbnails[index] = dataUrl.replace(/^data:image\/jpeg;base64,/, '');
                } catch (e) {
                    console.warn('Video preview generation failed:', e);
                }

                URL.revokeObjectURL(url);
                video.src = '';
                video.load();
            });

            video.addEventListener('error', () => {
                URL.revokeObjectURL(url);
            });

            video.src = url;
        },

        revokeAllPreviews() {
            Object.values(this.filePreviews).forEach(url => {
                if (typeof url === 'string' && url.startsWith('blob:')) {
                    URL.revokeObjectURL(url);
                }
            });
            this.filePreviews = {};
            this.fileThumbnails = {};
        },

        checkImageDimensions(file, index) {
            if (!file.type.startsWith('image/')) return;
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                if (img.width > 6000 || img.height > 6000) {
                    this.fileWarnings[index] = pageData.translations.imageDimensionWarning
                        .replace(':width', img.width)
                        .replace(':height', img.height);
                }
                URL.revokeObjectURL(url);
            };
            img.onerror = () => URL.revokeObjectURL(url);
            img.src = url;
        },

        removeFile(index) {
            this.selectedFiles.splice(index, 1);

            // Revoke removed preview
            if (this.filePreviews[index]) {
                const preview = this.filePreviews[index];
                if (typeof preview === 'string' && preview.startsWith('blob:')) {
                    URL.revokeObjectURL(preview);
                }
            }

            // Shift fileWarnings
            const newWarnings = {};
            Object.keys(this.fileWarnings).forEach(key => {
                const k = parseInt(key);
                if (k < index) newWarnings[k] = this.fileWarnings[k];
                else if (k > index) newWarnings[k - 1] = this.fileWarnings[k];
            });
            this.fileWarnings = newWarnings;

            // Shift filePreviews
            const newPreviews = {};
            Object.keys(this.filePreviews).forEach(key => {
                const k = parseInt(key);
                if (k < index) newPreviews[k] = this.filePreviews[k];
                else if (k > index) newPreviews[k - 1] = this.filePreviews[k];
            });
            this.filePreviews = newPreviews;

            // Shift fileThumbnails
            const newThumbnails = {};
            Object.keys(this.fileThumbnails).forEach(key => {
                const k = parseInt(key);
                if (k < index) newThumbnails[k] = this.fileThumbnails[k];
                else if (k > index) newThumbnails[k - 1] = this.fileThumbnails[k];
            });
            this.fileThumbnails = newThumbnails;
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
            const duplicates = [];

            try {
                for (let i = 0; i < this.selectedFiles.length; i++) {
                    const file = this.selectedFiles[i];
                    this.uploadProgress[i] = 0;

                    let result;
                    // Use chunked upload for large files
                    if (file.size >= this.CHUNKED_THRESHOLD) {
                        result = await this.uploadFileChunked(file, i);
                    } else {
                        result = await this.uploadFileDirect(file, i);
                    }

                    if (result && result.duplicate) {
                        duplicates.push(result.duplicate.filename);
                    } else if (this.fileThumbnails[i]) {
                        // Upload client-generated thumbnail (fire-and-forget)
                        const assetId = result?.assets?.[0]?.id || result?.asset?.id;
                        if (assetId) {
                            this.uploadThumbnailForAsset(assetId, this.fileThumbnails[i]);
                        }
                    }
                }

                const successCount = this.selectedFiles.length - duplicates.length;
                const duplicateNames = duplicates.join(', ');

                if (duplicates.length === this.selectedFiles.length) {
                    // All duplicates
                    window.showToast(
                        pageData.translations.skippedDuplicates
                            .replace(':count', duplicates.length)
                            .replace(':names', duplicateNames),
                        'warning'
                    );
                    this.uploading = false;
                } else if (duplicates.length > 0) {
                    // Mixed: some succeeded, some duplicates
                    window.showToast(
                        pageData.translations.uploadedWithDuplicates
                            .replace(':success', successCount)
                            .replace(':count', duplicates.length)
                            .replace(':names', duplicateNames),
                        'warning'
                    );
                    setTimeout(() => {
                        window.location.href = pageData.routes.assetsIndex + '?folder=' + encodeURIComponent(this.selectedFolder);
                    }, 3000);
                } else {
                    // All success
                    window.showToast(pageData.translations.allFilesUploaded);
                    setTimeout(() => {
                        window.location.href = pageData.routes.assetsIndex + '?folder=' + encodeURIComponent(this.selectedFolder);
                    }, 1000);
                }

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
            if (this.keepOriginalFilename) {
                formData.append('keep_original_filename', '1');
            }

            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest();

                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        this.uploadProgress[index] = Math.round((e.loaded / e.total) * 100);
                    }
                });

                xhr.addEventListener('load', () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            const data = JSON.parse(xhr.responseText);
                            resolve(data);
                        } catch (e) {
                            resolve();
                        }
                    } else if (xhr.status === 409) {
                        resolve({ duplicate: { filename: file.name } });
                    } else {
                        reject(new Error(this.parseErrorMessage(xhr)));
                    }
                });

                xhr.addEventListener('error', () => {
                    reject(new Error(pageData.translations.networkError));
                });

                xhr.open('POST', pageData.routes.assetsStore);
                xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').content);
                xhr.setRequestHeader('Accept', 'application/json');
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
                const result = await this.completeChunkedUpload(session.session_token, file.name);
                this.uploadProgress[index] = 100;
                return result;

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
                    keep_original_filename: this.keepOriginalFilename,
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

        async completeChunkedUpload(sessionToken, filename) {
            const response = await fetch('/api/chunked-upload/complete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ session_token: sessionToken }),
            });

            if (response.status === 409) {
                return { duplicate: { filename } };
            }

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

        async uploadThumbnailForAsset(assetId, thumbnailBase64) {
            try {
                await fetch(`/assets/${assetId}/thumbnail`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ thumbnail: thumbnailBase64 }),
                });
            } catch (e) {
                console.warn('Failed to upload thumbnail for asset', assetId, e);
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
