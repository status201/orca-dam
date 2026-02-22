export function assetEditor() {
    const pageData = window.__pageData || {};
    return {
        newTag: '',
        userTags: pageData.userTags || [],
        suggestions: [],
        showSuggestions: false,
        selectedIndex: -1,
        searchTimeout: null,

        addTag() {
            const tag = this.newTag.trim().toLowerCase();

            if (!tag) return;

            if (this.userTags.includes(tag)) {
                window.showToast(pageData.translations.tagAlreadyExists, 'error');
                return;
            }

            this.userTags.push(tag);
            this.newTag = '';
            this.showSuggestions = false;
            this.selectedIndex = -1;
        },

        addTagOrSelectSuggestion() {
            // If a suggestion is highlighted, select it
            if (this.selectedIndex >= 0 && this.suggestions[this.selectedIndex]) {
                this.selectSuggestion(this.suggestions[this.selectedIndex].name);
            } else {
                this.addTag();
            }
        },

        removeTag(index) {
            this.userTags.splice(index, 1);
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
                    const response = await fetch(`${pageData.tagsSearchUrl}?q=${encodeURIComponent(this.newTag)}&type=user`);
                    const data = await response.json();

                    // Filter out tags that are already added
                    this.suggestions = data.filter(tag => !this.userTags.includes(tag.name));
                    this.showSuggestions = this.suggestions.length > 0;
                    this.selectedIndex = -1;
                } catch (error) {
                    console.error('Tag search failed:', error);
                }
            }, 300);
        },

        selectSuggestion(tagName) {
            this.newTag = tagName;
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
                    window.location.reload();
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
