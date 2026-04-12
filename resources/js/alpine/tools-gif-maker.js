import GIF from 'gif.js';
import Sortable from 'sortablejs';

function gifMaker() {
    const pageData = window.__pageData || {};

    let nextId = 0;

    return {
        frames: [],
        globalDelay: 500,
        preDelay: 0,
        postDelay: 0,
        transition: 'none',
        transitionDuration: 300,
        loopMode: 'forever',
        loopCount: 1,
        bgColor: '#ffffff',
        outputWidth: 480,
        outputHeight: 320,
        lockAspectRatio: false,
        generating: false,
        generateProgress: 0,
        generatedGif: null,
        uploadFolder: pageData.rootFolder || '',
        uploadFilename: 'animation.gif',
        uploading: false,
        uploadedAsset: null,
        _sortable: null,

        init() {
            this.$nextTick(() => {
                const container = this.$refs.frameList;
                if (container) {
                    this._sortable = Sortable.create(container, {
                        animation: 150,
                        handle: '.drag-handle',
                        ghostClass: 'opacity-30',
                        onEnd: (evt) => {
                            const moved = this.frames.splice(evt.oldIndex, 1)[0];
                            this.frames.splice(evt.newIndex, 0, moved);
                        },
                    });
                }
            });
        },

        addImages(fileList) {
            const files = Array.from(fileList).filter(f => f.type.startsWith('image/'));
            if (files.length === 0) {
                window.showToast('No image files selected', 'warning');
                return;
            }

            for (const file of files) {
                const id = ++nextId;
                const objectUrl = URL.createObjectURL(file);
                const img = new Image();
                const frame = {
                    id,
                    file,
                    objectUrl,
                    img,
                    delay: null,
                    naturalWidth: 0,
                    naturalHeight: 0,
                    loaded: false,
                };
                img.src = objectUrl;
                this.frames.push(frame);
                img.onload = () => {
                    const f = this.frames.find(f => f.id === id);
                    if (f) {
                        f.naturalWidth = img.naturalWidth;
                        f.naturalHeight = img.naturalHeight;
                        f.loaded = true;
                    }
                };
            }

            this.generatedGif = null;
            this.uploadedAsset = null;
        },

        removeFrame(id) {
            const idx = this.frames.findIndex(f => f.id === id);
            if (idx !== -1) {
                URL.revokeObjectURL(this.frames[idx].objectUrl);
                this.frames.splice(idx, 1);
            }
            this.generatedGif = null;
            this.uploadedAsset = null;
        },

        duplicateFrame(id) {
            const idx = this.frames.findIndex(f => f.id === id);
            if (idx === -1) return;
            const src = this.frames[idx];
            const newId = ++nextId;
            const objectUrl = URL.createObjectURL(src.file);
            const img = new Image();
            const frame = {
                id: newId,
                file: src.file,
                objectUrl,
                img,
                delay: src.delay,
                naturalWidth: src.naturalWidth,
                naturalHeight: src.naturalHeight,
                loaded: false,
            };
            img.src = objectUrl;
            this.frames.splice(idx + 1, 0, frame);
            img.onload = () => {
                const f = this.frames.find(f => f.id === newId);
                if (f) {
                    f.naturalWidth = img.naturalWidth;
                    f.naturalHeight = img.naturalHeight;
                    f.loaded = true;
                }
            };
            this.generatedGif = null;
            this.uploadedAsset = null;
        },

        fitToFrames() {
            if (this.frames.length === 0) return;
            let maxW = 0;
            let maxH = 0;
            for (const f of this.frames) {
                if (f.naturalWidth > maxW) maxW = f.naturalWidth;
                if (f.naturalHeight > maxH) maxH = f.naturalHeight;
            }
            if (maxW > 0 && maxH > 0) {
                this.outputWidth = Math.min(maxW, 2000);
                this.outputHeight = Math.round(this.outputWidth * (maxH / maxW));
            }
        },

        updateWidth() {
            if (this.lockAspectRatio && this.frames.length > 0) {
                const ratio = this._aspectRatio();
                if (ratio) {
                    this.outputHeight = Math.round(this.outputWidth / ratio);
                }
            }
        },

        updateHeight() {
            if (this.lockAspectRatio && this.frames.length > 0) {
                const ratio = this._aspectRatio();
                if (ratio) {
                    this.outputWidth = Math.round(this.outputHeight * ratio);
                }
            }
        },

        _aspectRatio() {
            for (const f of this.frames) {
                if (f.naturalWidth > 0 && f.naturalHeight > 0) {
                    return f.naturalWidth / f.naturalHeight;
                }
            }
            return null;
        },

        get allFramesLoaded() {
            return this.frames.length >= 2 && this.frames.every(f => f.loaded);
        },

        _drawFrame(ctx, frame, w, h) {
            const imgW = frame.img.naturalWidth;
            const imgH = frame.img.naturalHeight;
            const scale = Math.min(w / imgW, h / imgH);
            const drawW = imgW * scale;
            const drawH = imgH * scale;
            const x = (w - drawW) / 2;
            const y = (h - drawH) / 2;
            ctx.drawImage(frame.img, x, y, drawW, drawH);
        },

        _drawFrameOffset(ctx, frame, w, h, offsetX, offsetY) {
            const imgW = frame.img.naturalWidth;
            const imgH = frame.img.naturalHeight;
            const scale = Math.min(w / imgW, h / imgH);
            const drawW = imgW * scale;
            const drawH = imgH * scale;
            const x = (w - drawW) / 2 + offsetX;
            const y = (h - drawH) / 2 + offsetY;
            ctx.drawImage(frame.img, x, y, drawW, drawH);
        },

        _addTransitionFrames(gif, ctx, fromFrame, toFrame, w, h, steps, stepDelay, bg) {
            const type = this.transition;

            for (let s = 1; s <= steps; s++) {
                const t = s / (steps + 1);
                ctx.fillStyle = bg;
                ctx.fillRect(0, 0, w, h);

                if (type === 'fade') {
                    ctx.globalAlpha = 1 - t;
                    this._drawFrame(ctx, fromFrame, w, h);
                    ctx.globalAlpha = t;
                    this._drawFrame(ctx, toFrame, w, h);
                    ctx.globalAlpha = 1;
                } else if (type === 'slide-left') {
                    this._drawFrameOffset(ctx, fromFrame, w, h, -t * w, 0);
                    this._drawFrameOffset(ctx, toFrame, w, h, (1 - t) * w, 0);
                } else if (type === 'slide-right') {
                    this._drawFrameOffset(ctx, fromFrame, w, h, t * w, 0);
                    this._drawFrameOffset(ctx, toFrame, w, h, -(1 - t) * w, 0);
                } else if (type === 'slide-up') {
                    this._drawFrameOffset(ctx, fromFrame, w, h, 0, -t * h);
                    this._drawFrameOffset(ctx, toFrame, w, h, 0, (1 - t) * h);
                } else if (type === 'slide-down') {
                    this._drawFrameOffset(ctx, fromFrame, w, h, 0, t * h);
                    this._drawFrameOffset(ctx, toFrame, w, h, 0, -(1 - t) * h);
                }

                gif.addFrame(ctx, { copy: true, delay: stepDelay });
            }
        },

        async generateGif() {
            if (this.frames.length < 2) {
                window.showToast('At least 2 frames required', 'warning');
                return;
            }

            this.generating = true;
            this.generateProgress = 0;
            this.generatedGif = null;
            this.uploadedAsset = null;

            const w = Number(this.outputWidth) || 480;
            const h = Number(this.outputHeight) || 320;
            const bg = this.bgColor || '#ffffff';

            let repeat;
            if (this.loopMode === 'forever') repeat = 0;
            else if (this.loopMode === 'once') repeat = -1;
            else repeat = Math.max(1, Number(this.loopCount) || 1);

            const gif = new GIF({
                workers: 2,
                quality: 10,
                width: w,
                height: h,
                background: bg,
                repeat,
                workerScript: '/js/gif.worker.js',
            });

            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');

            const hasTransition = this.transition !== 'none';
            const transDuration = Number(this.transitionDuration) || 300;
            const transSteps = hasTransition ? Math.max(2, Math.round(transDuration / 40)) : 0;
            const transStepDelay = hasTransition ? Math.round(transDuration / transSteps) : 0;
            const preDelay = Number(this.preDelay) || 0;
            const postDelay = Number(this.postDelay) || 0;

            for (let i = 0; i < this.frames.length; i++) {
                const frame = this.frames[i];
                const isFirst = i === 0;
                const isLast = i === this.frames.length - 1;

                // Draw content frame
                ctx.fillStyle = bg;
                ctx.fillRect(0, 0, w, h);
                this._drawFrame(ctx, frame, w, h);
                let delay = frame.delay !== null ? Number(frame.delay) : Number(this.globalDelay);
                delay = delay || 500;
                if (isFirst) delay += preDelay;
                if (isLast) delay += postDelay;
                gif.addFrame(ctx, { copy: true, delay });

                // Add transition frames between this frame and the next
                if (hasTransition && !isLast) {
                    this._addTransitionFrames(gif, ctx, frame, this.frames[i + 1], w, h, transSteps, transStepDelay, bg);
                }
            }

            gif.on('progress', (p) => {
                this.generateProgress = Math.round(p * 100);
            });

            gif.on('finished', (blob) => {
                this.generating = false;
                this.generateProgress = 100;
                this.generatedGif = {
                    blob,
                    objectUrl: URL.createObjectURL(blob),
                    width: w,
                    height: h,
                    size: blob.size,
                };
            });

            gif.render();
        },

        downloadGif() {
            if (!this.generatedGif) return;
            const a = document.createElement('a');
            a.href = this.generatedGif.objectUrl;
            a.download = this.uploadFilename || 'animation.gif';
            a.click();
        },

        async uploadToOrca() {
            if (!this.generatedGif || this.uploading) return;

            this.uploading = true;
            this.uploadedAsset = null;

            try {
                // Convert blob to base64
                const base64 = await new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onloadend = () => resolve(reader.result);
                    reader.onerror = reject;
                    reader.readAsDataURL(this.generatedGif.blob);
                });

                const res = await fetch(pageData.uploadUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': pageData.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        content: base64,
                        filename: this.uploadFilename || 'animation.gif',
                        folder: this.uploadFolder,
                        width: this.generatedGif.width,
                        height: this.generatedGif.height,
                    }),
                });

                const data = await res.json();

                if (!res.ok) {
                    window.showToast(data.error || 'Upload failed', 'error');
                    return;
                }

                this.uploadedAsset = data;
                window.showToast('GIF uploaded successfully!');
            } catch (e) {
                window.showToast('Upload failed: ' + e.message, 'error');
            } finally {
                this.uploading = false;
            }
        },

        clearAll() {
            for (const f of this.frames) {
                URL.revokeObjectURL(f.objectUrl);
            }
            if (this.generatedGif) {
                URL.revokeObjectURL(this.generatedGif.objectUrl);
            }
            this.frames = [];
            this.generatedGif = null;
            this.uploadedAsset = null;
            this.generateProgress = 0;
        },

        formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },
    };
}

window.gifMaker = gifMaker;

export default gifMaker;
