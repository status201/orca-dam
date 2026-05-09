import { generatePdfThumbnail, generateVideoThumbnail, uploadThumbnail } from './thumbnail-generator';
import { uploadMetadata } from './upload-metadata';

export function assetUploader() {
    const pageData = window.__pageData || {};

    return {
        ...uploadMetadata(),
        dragActive: false,
        selectedFiles: [],
        uploading: false,
        uploadProgress: {},
        // uploadResults[i] = { status: 'uploading'|'uploaded'|'duplicate'|'failed', payload?, error?, copied? }
        uploadResults: {},
        batchComplete: false,
        // selectedDuplicates[i] = true when the user has ticked that duplicate row
        selectedDuplicates: {},
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
            const result = await generatePdfThumbnail(file);
            if (result) {
                this.filePreviews[index] = result.dataUrl;
                this.fileThumbnails[index] = result.base64;
            }
        },

        async generateVideoPreview(file, index) {
            const result = await generateVideoThumbnail(file);
            if (result) {
                this.filePreviews[index] = result.dataUrl;
                this.fileThumbnails[index] = result.base64;
            }
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
            this.batchComplete = false;
            this.uploadResults = {};
            this.selectedDuplicates = {};

            for (let i = 0; i < this.selectedFiles.length; i++) {
                const file = this.selectedFiles[i];
                this.uploadProgress[i] = 0;
                this.uploadResults[i] = { status: 'uploading' };

                try {
                    const result = file.size >= this.CHUNKED_THRESHOLD
                        ? await this.uploadFileChunked(file, i)
                        : await this.uploadFileDirect(file, i);

                    if (result && result.duplicate) {
                        this.uploadResults[i] = { status: 'duplicate', payload: result.duplicate };
                    } else {
                        this.uploadResults[i] = { status: 'uploaded', payload: result };
                        if (this.fileThumbnails[i]) {
                            const assetId = result?.assets?.[0]?.id || result?.asset?.id;
                            if (assetId) {
                                this.uploadThumbnailForAsset(assetId, this.fileThumbnails[i]);
                            }
                        }
                    }
                } catch (error) {
                    console.error('Upload error:', error);
                    this.uploadResults[i] = {
                        status: 'failed',
                        error: error.message || pageData.translations.uploadFailed,
                    };
                }
            }

            this.batchComplete = true;
            this.uploading = false;

            const counts = this.outcomeCounts();
            const summary = pageData.translations.uploadSummary
                .replace(':uploaded', counts.uploaded)
                .replace(':duplicates', counts.duplicate)
                .replace(':failed', counts.failed);
            const level = counts.failed > 0 ? 'error' : counts.duplicate > 0 ? 'warning' : 'success';
            window.showToast(summary, level);

            // Auto-redirect only when every file uploaded cleanly. Any duplicate
            // or failure keeps the user on the page so they can act on the panel.
            if (counts.duplicate === 0 && counts.failed === 0) {
                setTimeout(() => {
                    window.location.href = pageData.routes.assetsIndex + '?folder=' + encodeURIComponent(this.selectedFolder);
                }, 1000);
            }
        },

        outcomeCounts() {
            const counts = { uploaded: 0, duplicate: 0, failed: 0 };
            Object.values(this.uploadResults).forEach(r => {
                if (counts[r.status] !== undefined) counts[r.status]++;
            });
            return counts;
        },

        duplicateEntries() {
            return Object.entries(this.uploadResults)
                .filter(([, r]) => r.status === 'duplicate')
                .map(([index, r]) => ({ index: parseInt(index), ...r.payload }));
        },

        failedEntries() {
            return Object.entries(this.uploadResults)
                .filter(([, r]) => r.status === 'failed')
                .map(([index, r]) => ({ index: parseInt(index), error: r.error }));
        },

        toggleAllDuplicates() {
            const dupes = this.duplicateEntries();
            const allSelected = dupes.every(d => this.selectedDuplicates[d.index]);
            if (allSelected) {
                dupes.forEach(d => { delete this.selectedDuplicates[d.index]; });
            } else {
                dupes.forEach(d => { this.selectedDuplicates[d.index] = true; });
            }
        },

        selectedDuplicateCount() {
            return Object.values(this.selectedDuplicates).filter(Boolean).length;
        },

        bulkCopyLabel() {
            const selected = this.selectedDuplicateCount();
            const count = selected > 0 ? selected : this.duplicateEntries().length;
            return (pageData.translations.copyCountUrls || 'Copy :count URL(s)').replace(':count', count);
        },

        // Append the full set of non-trashed duplicate ids to the show URL so
        // the asset show page activates its prev/next cycle nav across the batch.
        duplicateShowUrl(dupe) {
            if (!dupe.show_url) return null;
            const ids = this.viewableDuplicateIds();
            if (ids.length <= 1) return dupe.show_url;
            return `${dupe.show_url}?${this.buildIdsContextQuery(ids)}`;
        },

        async copyUrl(index, url) {
            try {
                await navigator.clipboard.writeText(url);
                // Per-row "Copied" affordance — auto-clears after a couple seconds.
                this.uploadResults[index] = { ...this.uploadResults[index], copied: true };
                setTimeout(() => {
                    if (this.uploadResults[index]) {
                        this.uploadResults[index] = { ...this.uploadResults[index], copied: false };
                    }
                }, 2000);
                window.showToast(pageData.translations.urlCopied, 'success');
            } catch (e) {
                window.showToast(pageData.translations.failedToCopy || 'Failed to copy', 'error');
            }
        },

        async copySelectedUrls() {
            const dupes = this.duplicateEntries();
            const selected = this.selectedDuplicateCount();
            // If nothing selected, default to copying every duplicate's URL.
            const target = selected > 0
                ? dupes.filter(d => this.selectedDuplicates[d.index])
                : dupes;
            const urls = target.map(d => d.public_url).filter(Boolean);
            if (urls.length === 0) return;

            try {
                await navigator.clipboard.writeText(urls.join('\n'));
                window.showToast(
                    pageData.translations.urlsCopied.replace(':count', urls.length),
                    'success'
                );
            } catch (e) {
                window.showToast(pageData.translations.failedToCopy || 'Failed to copy', 'error');
            }
        },

        // Reachable (non-trashed) duplicates with a server-issued asset id.
        viewableDuplicateIds(filterFn = null) {
            return this.duplicateEntries()
                .filter(d => !d.is_trashed && d.existing_asset_id && (!filterFn || filterFn(d)))
                .map(d => d.existing_asset_id);
        },

        // Build the `?ids[]=…&folder=` query string used by both Reveal-in-library
        // and the per-row View-existing link to activate cycle nav across the batch.
        // Empty folder => "all folders", so duplicates outside the user's home folder
        // remain visible.
        buildIdsContextQuery(ids) {
            const params = new URLSearchParams();
            ids.forEach(id => params.append('ids[]', id));
            params.set('folder', '');
            return params.toString();
        },

        revealDuplicatesInLibrary() {
            const selected = this.selectedDuplicateCount();
            const ids = this.viewableDuplicateIds(
                selected > 0 ? d => this.selectedDuplicates[d.index] : null
            );
            if (ids.length === 0) return;
            window.open(`${pageData.routes.assetsIndex}?${this.buildIdsContextQuery(ids)}`, '_blank');
        },

        async restoreDuplicate(index) {
            const result = this.uploadResults[index];
            if (!result || result.status !== 'duplicate' || !result.payload?.can_restore) return;

            const assetId = result.payload.existing_asset_id;
            const url = pageData.routes.assetsRestore.replace(':id', assetId);

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error(pageData.translations.restoreFailed);
                }

                // Mutate the row in place: clear trash flags, surface a working show URL.
                const updatedPayload = {
                    ...result.payload,
                    is_trashed: false,
                    can_restore: false,
                    show_url: pageData.routes.assetsShow.replace(':id', assetId),
                };
                this.uploadResults[index] = { ...result, payload: updatedPayload };
                window.showToast(pageData.translations.restored, 'success');
            } catch (error) {
                console.error('Restore error:', error);
                window.showToast(error.message || pageData.translations.restoreFailed, 'error');
            }
        },

        async retryFailed(index) {
            const file = this.selectedFiles[index];
            if (!file) return;
            this.uploadProgress[index] = 0;
            this.uploadResults[index] = { status: 'uploading' };
            try {
                const result = file.size >= this.CHUNKED_THRESHOLD
                    ? await this.uploadFileChunked(file, index)
                    : await this.uploadFileDirect(file, index);
                if (result && result.duplicate) {
                    this.uploadResults[index] = { status: 'duplicate', payload: result.duplicate };
                } else {
                    this.uploadResults[index] = { status: 'uploaded', payload: result };
                }
            } catch (error) {
                this.uploadResults[index] = {
                    status: 'failed',
                    error: error.message || pageData.translations.uploadFailed,
                };
            }
        },

        goToLibrary() {
            window.location.href = pageData.routes.assetsIndex + '?folder=' + encodeURIComponent(this.selectedFolder);
        },

        clearAll() {
            this.revokeAllPreviews();
            this.selectedFiles = [];
            this.uploadProgress = {};
            this.uploadResults = {};
            this.selectedDuplicates = {};
            this.batchComplete = false;
            this.fileWarnings = {};
        },

        async uploadFileDirect(file, index) {
            const formData = new FormData();
            formData.append('files[]', file);
            formData.append('folder', this.selectedFolder);
            if (this.keepOriginalFilename) {
                formData.append('keep_original_filename', '1');
            }

            const meta = this.getMetadataPayload();
            if (meta.metadata_tags) {
                meta.metadata_tags.forEach(tag => formData.append('metadata_tags[]', tag));
            }
            if (meta.metadata_reference_tag_ids) {
                meta.metadata_reference_tag_ids.forEach(id => formData.append('metadata_reference_tag_ids[]', id));
            }
            if (meta.metadata_license_type) formData.append('metadata_license_type', meta.metadata_license_type);
            if (meta.metadata_copyright) formData.append('metadata_copyright', meta.metadata_copyright);
            if (meta.metadata_copyright_source) formData.append('metadata_copyright_source', meta.metadata_copyright_source);

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
                        let payload = { filename: file.name };
                        try {
                            const data = JSON.parse(xhr.responseText);
                            if (data.duplicates && data.duplicates.length > 0) {
                                payload = data.duplicates[0];
                            }
                        } catch (e) { /* fall back to filename-only payload */ }
                        resolve({ duplicate: payload });
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
                body: JSON.stringify({
                    session_token: sessionToken,
                    ...this.getMetadataPayload(),
                }),
            });

            if (response.status === 409) {
                let payload = { filename };
                try {
                    const data = await response.json();
                    if (data.duplicates && data.duplicates.length > 0) {
                        payload = data.duplicates[0];
                    }
                } catch (e) { /* fall back to filename-only payload */ }
                return { duplicate: payload };
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
            await uploadThumbnail(assetId, thumbnailBase64);
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
