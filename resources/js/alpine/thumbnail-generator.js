/**
 * Shared thumbnail generation utilities for PDFs and videos.
 * Returns base64-encoded JPEG strings (without data URL prefix).
 */

export async function generatePdfThumbnail(file) {
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
        return {
            dataUrl,
            base64: dataUrl.replace(/^data:image\/jpeg;base64,/, ''),
        };
    } catch (e) {
        console.warn('PDF preview generation failed:', e);
        return null;
    }
}

export function generateVideoThumbnail(file) {
    return new Promise((resolve) => {
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
                resolve({
                    dataUrl,
                    base64: dataUrl.replace(/^data:image\/jpeg;base64,/, ''),
                });
            } catch (e) {
                console.warn('Video preview generation failed:', e);
                resolve(null);
            }

            URL.revokeObjectURL(url);
            video.src = '';
            video.load();
        });

        video.addEventListener('error', () => {
            URL.revokeObjectURL(url);
            resolve(null);
        });

        video.src = url;
    });
}

export async function uploadThumbnail(assetId, base64) {
    try {
        await fetch(`/assets/${assetId}/thumbnail`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ thumbnail: base64 }),
        });
    } catch (e) {
        console.warn('Failed to upload thumbnail for asset', assetId, e);
    }
}
