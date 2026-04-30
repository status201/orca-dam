async function refreshPreviewThumbnail(thumbnailUrl) {
    const previewImg = document.getElementById('asset-preview');
    const placeholder = document.getElementById('asset-preview-placeholder');

    try {
        const response = await fetch(thumbnailUrl, {
            cache: 'reload',
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            }
        });

        if (!response.ok) return;

        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);

        const updateImg = (img) => {
            img.src = objectUrl;
            img.onload = () => {
                URL.revokeObjectURL(objectUrl);
                img.src = thumbnailUrl;
                img.onload = null;
            };
        };

        if (previewImg) {
            updateImg(previewImg);
        } else if (placeholder) {
            const img = document.createElement('img');
            img.id = 'asset-preview';
            img.className = 'max-w-sm rounded-lg';
            placeholder.replaceWith(img);
            updateImg(img);
        }
    } catch (error) {
        console.error('Failed to refresh thumbnail:', error);
    }
}

export function assetEditor() {
    const pageData = window.__pageData || {};
    return {
        newTag: '',
        userTags: pageData.userTags || [],
        referenceTags: pageData.referenceTags || [],
        suggestions: [],
        showSuggestions: false,
        selectedIndex: -1,
        searchTimeout: null,

        addTag() {
            const tag = this.newTag.trim().toLowerCase();

            if (!tag) return;

            if (this.userTags.includes(tag) || this.referenceTags.some(t => t.name === tag)) {
                window.showToast(pageData.translations.tagAlreadyExists, 'error');
                return;
            }

            this.userTags.push(tag);
            this.newTag = '';
            this.showSuggestions = false;
            this.selectedIndex = -1;
        },

        addTagOrSelectSuggestion() {
            if (this.selectedIndex >= 0 && this.suggestions[this.selectedIndex]) {
                this.selectSuggestion(this.suggestions[this.selectedIndex]);
            } else {
                this.addTag();
            }
        },

        removeTag(index) {
            this.userTags.splice(index, 1);
        },

        removeReferenceTag(index) {
            this.referenceTags.splice(index, 1);
        },

        searchTags() {
            clearTimeout(this.searchTimeout);

            if (this.newTag.trim().length < 1) {
                this.suggestions = [];
                this.showSuggestions = false;
                return;
            }

            this.searchTimeout = setTimeout(async () => {
                try {
                    const response = await fetch(`${pageData.tagsSearchUrl}?q=${encodeURIComponent(this.newTag)}&types=user,reference`);
                    const data = await response.json();

                    const attachedRefIds = new Set(this.referenceTags.map(t => t.id));
                    this.suggestions = data.filter(tag => {
                        if (tag.type === 'reference') return !attachedRefIds.has(tag.id);
                        return !this.userTags.includes(tag.name);
                    });
                    this.showSuggestions = this.suggestions.length > 0;
                    this.selectedIndex = -1;
                } catch (error) {
                    console.error('Tag search failed:', error);
                }
            }, 300);
        },

        selectSuggestion(suggestion) {
            // Backwards-compat: callers used to pass a string name
            if (typeof suggestion === 'string') {
                this.newTag = suggestion;
                this.addTag();
                return;
            }

            if (suggestion.type === 'reference') {
                if (!this.referenceTags.some(t => t.id === suggestion.id)) {
                    this.referenceTags.push({ id: suggestion.id, name: suggestion.name });
                }
                this.newTag = '';
                this.showSuggestions = false;
                this.selectedIndex = -1;
                return;
            }

            this.newTag = suggestion.name;
            this.addTag();
        },

        navigateDown() {
            if (this.showSuggestions && this.suggestions.length > 0) {
                this.selectedIndex = Math.min(this.selectedIndex + 1, this.suggestions.length - 1);
            }
        },

        navigateUp() {
            if (this.showSuggestions && this.selectedIndex > 0) {
                this.selectedIndex--;
            }
        },

        hideSuggestions() {
            // Small delay to allow click events on suggestions to fire first
            setTimeout(() => {
                this.showSuggestions = false;
                this.selectedIndex = -1;
            }, 150);
        }
    };
}
window.assetEditor = assetEditor;

export function videoThumbnailGenerator() {
    const pageData = window.__pageData || {};
    return {
        showModal: false,
        generating: false,
        uploading: false,
        frames: [],
        timestamps: [1, 3, 6],
        selectedIndex: null,
        error: null,

        openModal() {
            this.showModal = true;
            this.frames = [];
            this.selectedIndex = null;
            this.error = null;
            this.generateFrames();
        },

        generateFrames() {
            this.generating = true;
            this.error = null;

            const video = document.createElement('video');
            video.crossOrigin = 'anonymous';
            video.muted = true;
            video.preload = 'auto';

            const maxSize = 300;
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            const capturedFrames = [];
            let currentTimestampIndex = 0;
            let done = false;

            const captureFrame = () => {
                return new Promise((resolve, reject) => {
                    const targetTime = this.timestamps[currentTimestampIndex];
                    const seekTime = Math.min(targetTime, Math.max(0, video.duration - 0.1));

                    const onSeeked = () => {
                        video.removeEventListener('seeked', onSeeked);
                        // Scale down to max 300px, maintaining aspect ratio
                        const vw = video.videoWidth;
                        const vh = video.videoHeight;
                        const scale = Math.min(maxSize / vw, maxSize / vh, 1);
                        canvas.width = Math.round(vw * scale);
                        canvas.height = Math.round(vh * scale);
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                        try {
                            capturedFrames.push(canvas.toDataURL('image/jpeg', 0.85));
                        } catch (e) {
                            reject(e);
                            return;
                        }
                        resolve();
                    };

                    video.addEventListener('seeked', onSeeked);
                    video.currentTime = seekTime;
                });
            };

            const onError = () => {
                if (done) return;
                done = true;
                this.generating = false;
                this.error = pageData.translations.failedToLoadVideo;
            };

            video.addEventListener('loadeddata', async () => {
                if (done) return;
                try {
                    for (currentTimestampIndex = 0; currentTimestampIndex < this.timestamps.length; currentTimestampIndex++) {
                        await captureFrame();
                    }
                    this.frames = capturedFrames;
                    this.selectedIndex = 0;
                } catch (e) {
                    this.error = pageData.translations.failedToGeneratePreviews;
                    console.error('Frame capture error:', e);
                } finally {
                    done = true;
                    this.generating = false;
                    video.removeEventListener('error', onError);
                    video.src = '';
                    video.load();
                }
            });

            video.addEventListener('error', onError);

            video.src = pageData.assetUrl;
        },

        async confirm() {
            if (this.selectedIndex === null || !this.frames[this.selectedIndex]) return;

            this.uploading = true;
            this.error = null;

            // Strip the data URL prefix to get raw base64
            const base64 = this.frames[this.selectedIndex].replace(/^data:image\/jpeg;base64,/, '');

            try {
                const response = await fetch(pageData.thumbnailStoreUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ thumbnail: base64 }),
                });

                const data = await response.json();

                if (response.ok) {
                    window.showToast(data.message || pageData.translations.videoPreviewSuccess);
                    await refreshPreviewThumbnail(data.thumbnail_url);
                    this.showModal = false;
                } else {
                    this.error = data.message || pageData.translations.failedToUploadThumbnail;
                }
            } catch (e) {
                this.error = pageData.translations.networkError;
                console.error('Thumbnail upload error:', e);
            } finally {
                this.uploading = false;
            }
        }
    };
}
window.videoThumbnailGenerator = videoThumbnailGenerator;

export function pdfThumbnailGenerator() {
    const pageData = window.__pageData || {};
    return {
        showModal: false,
        generating: false,
        uploading: false,
        pages: [],
        pageLabels: [],
        selectedIndex: null,
        error: null,

        openModal() {
            this.showModal = true;
            this.pages = [];
            this.pageLabels = [];
            this.selectedIndex = null;
            this.error = null;
            this.renderPages();
        },

        async renderPages() {
            this.generating = true;
            this.error = null;

            try {
                const pdfjsLib = await import('pdfjs-dist');
                const PdfWorker = (await import('pdfjs-dist/build/pdf.worker.mjs?worker')).default;
                pdfjsLib.GlobalWorkerOptions.workerPort = new PdfWorker();

                const pdf = await pdfjsLib.getDocument(pageData.assetUrl).promise;
                const maxSize = 300;
                const numPages = Math.min(pdf.numPages, 3);
                const renderedPages = [];
                const labels = [];

                for (let i = 1; i <= numPages; i++) {
                    const page = await pdf.getPage(i);
                    const viewport = page.getViewport({ scale: 1 });

                    const scale = Math.min(maxSize / viewport.width, maxSize / viewport.height, 1);
                    const scaledViewport = page.getViewport({ scale });

                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(scaledViewport.width);
                    canvas.height = Math.round(scaledViewport.height);
                    const ctx = canvas.getContext('2d');

                    await page.render({ canvasContext: ctx, viewport: scaledViewport }).promise;

                    renderedPages.push(canvas.toDataURL('image/jpeg', 0.85));
                    labels.push(i);
                }

                this.pages = renderedPages;
                this.pageLabels = labels;
                this.selectedIndex = 0;
            } catch (e) {
                if (e.name === 'PasswordException') {
                    this.error = pageData.translations.pdfPasswordProtected;
                } else {
                    this.error = pageData.translations.failedToLoadPdf;
                    console.error('PDF render error:', e);
                }
            } finally {
                this.generating = false;
            }
        },

        async confirm() {
            if (this.selectedIndex === null || !this.pages[this.selectedIndex]) return;

            this.uploading = true;
            this.error = null;

            const base64 = this.pages[this.selectedIndex].replace(/^data:image\/jpeg;base64,/, '');

            try {
                const response = await fetch(pageData.thumbnailStoreUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ thumbnail: base64 }),
                });

                const data = await response.json();

                if (response.ok) {
                    window.showToast(data.message || pageData.translations.pdfPreviewSuccess);
                    await refreshPreviewThumbnail(data.thumbnail_url);
                    this.showModal = false;
                } else {
                    this.error = data.message || pageData.translations.failedToUploadThumbnail;
                }
            } catch (e) {
                this.error = pageData.translations.networkError;
                console.error('Thumbnail upload error:', e);
            } finally {
                this.uploading = false;
            }
        }
    };
}
window.pdfThumbnailGenerator = pdfThumbnailGenerator;

export function aiTagManager() {
    const pageData = window.__pageData || {};
    return {
        async removeAiTag(tagId, tagName) {
            if (!confirm(pageData.translations.confirmRemoveAiTag + ` "${tagName}" ` + pageData.translations.fromThisAsset)) {
                return;
            }

            try {
                const response = await fetch(`/assets/${pageData.assetId}/tags/${tagId}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    }
                });

                const data = await response.json();

                if (response.ok) {
                    window.showToast(pageData.translations.aiTagRemovedSuccess);
                    window.location.reload();
                } else {
                    window.showToast(data.message || pageData.translations.failedToRemoveAiTag, 'error');
                }
            } catch (error) {
                console.error('Remove AI tag error:', error);
                window.showToast(pageData.translations.failedToRemoveAiTag, 'error');
            }
        }
    };
}
window.aiTagManager = aiTagManager;
