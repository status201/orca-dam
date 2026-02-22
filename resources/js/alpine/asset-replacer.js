export function assetReplacer() {
    const pageData = window.__pageData || {};

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
        allowedExtension: pageData.allowedExtension,
        maxSize: 512 * 1024 * 1024, // 500MB
        csrfToken: pageData.csrfToken,
        replaceUrl: pageData.replaceUrl,
        editUrl: pageData.editUrl,

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
                this.error = pageData.translations.fileMustHaveSameExtension + ` (.${this.allowedExtension})`;
                return;
            }

            // Validate size
            if (file.size > this.maxSize) {
                this.error = pageData.translations.fileTooLarge;
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
                        this.successMessage = response.message || pageData.translations.assetReplacedSuccessfully;
                        this.startRedirectCountdown();
                    } else {
                        try {
                            const errorResponse = JSON.parse(xhr.responseText);
                            if (errorResponse.errors && errorResponse.errors.file) {
                                this.error = errorResponse.errors.file[0];
                            } else {
                                this.error = errorResponse.message || pageData.translations.failedToReplace;
                            }
                        } catch {
                            this.error = pageData.translations.failedToReplace;
                        }
                    }
                });

                xhr.addEventListener('error', () => {
                    this.uploading = false;
                    this.error = pageData.translations.networkError;
                });

                xhr.open('POST', this.replaceUrl);
                xhr.setRequestHeader('X-CSRF-TOKEN', this.csrfToken);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.send(formData);

            } catch (err) {
                this.uploading = false;
                this.error = pageData.translations.unexpectedError;
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

window.assetReplacer = assetReplacer;
