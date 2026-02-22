export function assetDetail() {
    const t = window.__pageData?.translations || {};

    return {
        copiedStates: {
            main: false,
            thumb: false,
            resize_s: false,
            resize_m: false,
            resize_l: false
        },
        downloading: false,

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

        async refreshImage() {
            this.isRefreshing = true;

            try {
                // Fetch the image with no-cache headers
                const response = await fetch(this.baseImageUrl, {
                    cache: 'reload',
                    headers: {
                        'Cache-Control': 'no-cache, no-store, must-revalidate',
                        'Pragma': 'no-cache',
                        'Expires': '0'
                    }
                });

                if (response.ok) {
                    const blob = await response.blob();
                    const objectUrl = URL.createObjectURL(blob);

                    // Update to blob URL temporarily
                    this.imageSrc = objectUrl;

                    // After image loads, switch back to original URL
                    await new Promise(resolve => {
                        this.$refs.mainImage.onload = resolve;
                    });

                    // Clean up blob URL
                    URL.revokeObjectURL(objectUrl);

                    // Set back to original URL (now cached fresh)
                    this.imageSrc = this.baseImageUrl;
                }
            } catch (error) {
                console.error('Failed to refresh image:', error);
            } finally {
                this.isRefreshing = false;
            }
        }
    }
}
window.imageRefresher = imageRefresher;
