async function cacheBustFetch(url) {
    const response = await fetch(url, {
        cache: 'reload',
        headers: {
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
            'Expires': '0'
        }
    });
    if (!response.ok) return null;
    const blob = await response.blob();
    return URL.createObjectURL(blob);
}

export function assetDetail(cycleNav = null) {
    const t = window.__pageData?.translations || {};

    return {
        cycleNav,
        copiedStates: {
            main: false,
            thumb: false,
            resize_s: false,
            resize_m: false,
            resize_l: false
        },
        downloading: false,
        _cycleKeyHandler: null,

        init() {
            if (!this.cycleNav) return;
            this.prefetchNeighbours();
            this._cycleKeyHandler = (e) => this.handleCycleKey(e);
            window.addEventListener('keydown', this._cycleKeyHandler);
        },

        destroy() {
            if (this._cycleKeyHandler) {
                window.removeEventListener('keydown', this._cycleKeyHandler);
                this._cycleKeyHandler = null;
            }
        },

        prefetchNeighbours() {
            for (const side of ['prev', 'next']) {
                const target = this.cycleNav?.[side];
                if (!target?.thumb) continue;
                const img = new Image();
                img.decoding = 'async';
                img.src = target.thumb;
            }
        },

        handleCycleKey(e) {
            if (e.metaKey || e.ctrlKey || e.altKey || e.shiftKey) return;
            const target = e.target;
            if (!target) return;
            const tag = (target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable) return;

            if (e.key === 'ArrowLeft' && this.cycleNav?.prev) {
                e.preventDefault();
                window.location.href = this.cycleNav.prev.url;
            } else if (e.key === 'ArrowRight' && this.cycleNav?.next) {
                e.preventDefault();
                window.location.href = this.cycleNav.next.url;
            }
        },

        async downloadAsset(url) {
            this.downloading = true;
            try {
                // Trigger the download
                window.location.href = url;

                // Show success state briefly
                setTimeout(() => {
                    this.downloading = false;
                }, 2000);
            } catch (error) {
                console.error('Download failed:', error);
                this.downloading = false;
                window.showToast(t.downloadFailed || 'Download failed', 'error');
            }
        },

        copyUrl(url, type) {
            // Try modern clipboard API first
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(() => {
                    this.copiedStates[type] = true;
                    window.showToast(t.urlCopied || 'URL copied to clipboard!');
                    setTimeout(() => {
                        this.copiedStates[type] = false;
                    }, 2000);
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    window.showToast(t.failedToCopy || 'Failed to copy URL', 'error');
                });
            } else {
                // Fallback for HTTP/older browsers
                const textArea = document.createElement('textarea');
                textArea.value = url;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    this.copiedStates[type] = true;
                    window.showToast(t.urlCopied || 'URL copied to clipboard!');
                    setTimeout(() => {
                        this.copiedStates[type] = false;
                    }, 2000);
                } catch (err) {
                    console.error('Failed to copy:', err);
                    window.showToast(t.failedToCopy || 'Failed to copy URL', 'error');
                }
                textArea.remove();
            }
        }
    };
}
window.assetDetail = assetDetail;

export function imageRefresher(url, alt) {
    return {
        baseImageUrl: url,
        imageAlt: alt,
        imageSrc: url,
        isRefreshing: false,
        isBlurred: false,

        async refresh() {
            this.isRefreshing = true;
            this.isBlurred = true;

            // Wait for the blur-in transition to visually complete (matches CSS duration)
            const blurInDelay = new Promise(resolve => setTimeout(resolve, 500));

            try {
                const [objectUrl] = await Promise.all([
                    cacheBustFetch(this.baseImageUrl),
                    blurInDelay
                ]);

                if (objectUrl) {
                    this.imageSrc = objectUrl;

                    await new Promise(resolve => {
                        this.$refs.mainImage.onload = resolve;
                    });

                    URL.revokeObjectURL(objectUrl);
                    this.imageSrc = this.baseImageUrl;
                }
            } catch (error) {
                console.error('Failed to refresh image:', error);
            } finally {
                this.isRefreshing = false;
                this.isBlurred = false;
            }
        }
    }
}
window.imageRefresher = imageRefresher;

export function videoRefresher(url, mimeType) {
    return {
        baseVideoUrl: url,
        videoMimeType: mimeType,
        videoSrc: url,
        isRefreshing: false,

        async refresh() {
            this.isRefreshing = true;

            try {
                const objectUrl = await cacheBustFetch(this.baseVideoUrl);

                if (objectUrl) {
                    this.videoSrc = objectUrl;
                    const video = this.$refs.mainVideo;

                    await new Promise(resolve => {
                        video.oncanplay = resolve;
                        video.load();
                    });

                    URL.revokeObjectURL(objectUrl);
                    this.videoSrc = this.baseVideoUrl;
                    video.load();
                }
            } catch (error) {
                console.error('Failed to refresh video:', error);
            } finally {
                this.isRefreshing = false;
            }
        }
    }
}
window.videoRefresher = videoRefresher;
