function discoverObjects() {
    const pageData = window.__pageData?.discover || {};

    return {
        scanning: false,
        scanned: false,
        importing: false,
        unmappedObjects: [],
        selectedObjects: [],
        selectedFolder: pageData.rootFolder || '',
        scanningFolders: false,

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

        async scanBucket() {
            this.scanning = true;
            this.scanned = false;
            this.unmappedObjects = [];
            this.selectedObjects = [];

            try {
                const response = await fetch(pageData.routes.discoverScan, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ folder: this.selectedFolder }),
                });

                const data = await response.json();
                this.unmappedObjects = data.objects || [];
                this.scanned = true;

                window.showToast(pageData.translations.foundUnmapped.replace(':count', data.count));

            } catch (error) {
                console.error('Scan error:', error);
                window.showToast(pageData.translations.failedToScanBucket, 'error');
            } finally {
                this.scanning = false;
            }
        },

        selectAll() {
            this.selectedObjects = this.unmappedObjects.map(obj => obj.key);
        },

        deselectAll() {
            this.selectedObjects = [];
        },

        async importSelected() {
            if (this.selectedObjects.length === 0) return;

            const confirmed = confirm(pageData.translations.import + ` ${this.selectedObjects.length} ` + pageData.translations.objectsProcessing);
            if (!confirmed) return;

            this.importing = true;

            try {
                const response = await fetch(pageData.routes.discoverImport, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        keys: this.selectedObjects
                    }),
                });

                const data = await response.json();

                if (data.success) {
                    // Show success message with background processing info
                    let message = data.message;
                    if (data.imported > 0) {
                        message += ' ' + pageData.translations.thumbnailsBackground;
                    }
                    window.showToast(message, 'success');

                    // Remove imported objects from the list
                    this.unmappedObjects = this.unmappedObjects.filter(
                        obj => !this.selectedObjects.includes(obj.key)
                    );
                    this.selectedObjects = [];

                    // Refresh scan results after short delay
                    setTimeout(() => {
                        this.scanBucket();
                    }, 2000);
                } else {
                    window.showToast(pageData.translations.importFailed + ' ' + (data.message || pageData.translations.unknownError), 'error');
                }

            } catch (error) {
                console.error('Import error:', error);
                window.showToast(pageData.translations.failedToImportObjects + ' ' + error.message, 'error');
            } finally {
                this.importing = false;
            }
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

        formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        },

        getFileIcon(mimeType, filename) {
            const icons = {
                'application/pdf': 'fa-file-pdf',
                'application/msword': 'fa-file-word',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'fa-file-word',
                'application/vnd.ms-excel': 'fa-file-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'fa-file-excel',
                'application/vnd.ms-powerpoint': 'fa-file-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'fa-file-powerpoint',
                'application/zip': 'fa-file-zipper',
                'application/x-zip-compressed': 'fa-file-zipper',
                'application/x-rar-compressed': 'fa-file-zipper',
                'application/x-7z-compressed': 'fa-file-zipper',
                'text/plain': 'fa-file-lines',
                'text/csv': 'fa-file-csv',
                'application/json': 'fa-file-code',
                'text/html': 'fa-file-code',
                'text/css': 'fa-file-code',
                'text/javascript': 'fa-file-code',
                'application/javascript': 'fa-file-code',
                'video/mp4': 'fa-file-video',
                'video/mpeg': 'fa-file-video',
                'video/quicktime': 'fa-file-video',
                'video/x-msvideo': 'fa-file-video',
                'audio/mpeg': 'fa-file-audio',
                'audio/wav': 'fa-file-audio',
                'audio/ogg': 'fa-file-audio'
            };

            if (icons[mimeType]) {
                return icons[mimeType];
            }

            // Check by file extension as fallback
            const ext = filename.toLowerCase().split('.').pop();
            const extIcons = {
                'pdf': 'fa-file-pdf',
                'doc': 'fa-file-word',
                'docx': 'fa-file-word',
                'xls': 'fa-file-excel',
                'xlsx': 'fa-file-excel',
                'ppt': 'fa-file-powerpoint',
                'pptx': 'fa-file-powerpoint',
                'zip': 'fa-file-zipper',
                'rar': 'fa-file-zipper',
                '7z': 'fa-file-zipper',
                'txt': 'fa-file-lines',
                'csv': 'fa-file-csv',
                'json': 'fa-file-code',
                'html': 'fa-file-code',
                'css': 'fa-file-code',
                'js': 'fa-file-code',
                'mp4': 'fa-file-video',
                'mov': 'fa-file-video',
                'avi': 'fa-file-video',
                'mp3': 'fa-file-audio',
                'wav': 'fa-file-audio'
            };

            return extIcons[ext] || 'fa-file';
        },

        getFileIconColor(icon) {
            const colors = {
                'fa-file-pdf': 'text-red-500',
                'fa-file-word': 'text-blue-600',
                'fa-file-excel': 'text-green-600',
                'fa-file-powerpoint': 'text-orange-500',
                'fa-file-zipper': 'text-yellow-600',
                'fa-file-code': 'text-purple-600',
                'fa-file-video': 'text-pink-600',
                'fa-file-audio': 'text-indigo-600',
                'fa-file-csv': 'text-teal-600',
                'fa-file-lines': 'text-gray-500'
            };

            return colors[icon] || 'text-gray-400';
        }
    };
}

window.discoverObjects = discoverObjects;

export default discoverObjects;
