function latexMathml() {
    const pageData = window.__pageData || {};

    return {
        latex: '',
        displayMode: true,
        addMmlSemantics: true,
        mathmlOutput: '',
        previewError: '',
        uploadFilename: '',
        uploadFilenameError: '',
        uploadCaption: '',
        uploadFolder: pageData.rootFolder || '',
        uploading: false,
        uploadedAsset: null,

        examples: [
            { label: 'Pythagoras', tex: 'a^2 + b^2 = c^2' },
            { label: 'Quadratic', tex: 'x = \\frac{-b \\pm \\sqrt{b^2-4ac}}{2a}' },
            { label: 'Euler', tex: 'e^{i\\pi} + 1 = 0' },
            { label: 'Integral', tex: '\\int_0^\\infty e^{-x^2}\\,dx = \\frac{\\sqrt{\\pi}}{2}' },
            { label: 'Matrix', tex: '\\begin{pmatrix}a & b\\\\c & d\\end{pmatrix}' },
            { label: 'Sum', tex: '\\sum_{n=1}^{\\infty} \\frac{1}{n^2} = \\frac{\\pi^2}{6}' },
            { label: 'Limit', tex: '\\lim_{x \\to 0} \\frac{\\sin x}{x} = 1' },
            { label: 'Binomial', tex: '\\binom{n}{k} = \\frac{n!}{k!(n-k)!}' },
            { label: 'Maxwell', tex: '\\nabla \\cdot \\mathbf{E} = \\frac{\\rho}{\\varepsilon_0}' },
            { label: 'Einstein', tex: 'G_{\\mu\\nu} + \\Lambda g_{\\mu\\nu} = \\frac{8\\pi G}{c^4} T_{\\mu\\nu}' },
            { label: 'Fourier', tex: '\\hat{f}(\\xi) = \\int_{-\\infty}^{\\infty} f(x)\\, e^{-2\\pi i x \\xi}\\,dx' },
            { label: 'Schrödinger', tex: 'i\\hbar\\frac{\\partial}{\\partial t}\\Psi(\\mathbf{r},t) = \\left[-\\frac{\\hbar^2}{2m}\\nabla^2 + V(\\mathbf{r},t)\\right]\\Psi(\\mathbf{r},t)' },
        ],

        init() {
            this.$watch('latex', () => this.render());
            this.$watch('displayMode', () => this.render());
            this.$watch('addMmlSemantics', () => this.render());
        },

        render() {
            const preview = document.getElementById('mathml-preview');
            if (!preview) return;

            const src = this.latex.trim();
            if (!src) {
                preview.innerHTML = '';
                this.mathmlOutput = '';
                this.previewError = '';
                return;
            }

            if (typeof window.temml === 'undefined') {
                this.previewError = 'Temml not loaded yet';
                return;
            }

            const opts = {
                displayMode: this.displayMode,
                throwOnError: false,
                annotate: this.addMmlSemantics,
            };

            try {
                // Render into preview div
                window.temml.render(src, preview, opts);
                this.previewError = '';

                // Get MathML string
                this.mathmlOutput = window.temml.renderToString(src, opts);
            } catch (e) {
                this.previewError = e.message || 'Render error';
                this.mathmlOutput = '';
                preview.innerHTML = '';
            }
        },

        loadExample(tex) {
            this.latex = tex;
        },

        copyMathml() {
            if (!this.mathmlOutput) return;
            window.copyToClipboard(this.mathmlOutput, 'MathML copied to clipboard!', 'Failed to copy MathML');
        },

        async uploadToOrca() {
            if (!this.mathmlOutput || this.uploading) return;

            this.uploadedAsset = null;
            this.uploadFilenameError = '';

            const filename = this.uploadFilename.trim();
            if (!filename) {
                this.uploadFilenameError = 'Filename is required.';
                return;
            }
            if (!filename.endsWith('.mml')) {
                this.uploadFilenameError = 'Filename must end in .mml';
                return;
            }

            this.uploading = true;

            try {
                const res = await fetch(pageData.uploadUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': pageData.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        content: this.mathmlOutput,
                        filename: filename,
                        folder: this.uploadFolder,
                        latex: this.latex,
                        caption: this.uploadCaption,
                    }),
                });

                const data = await res.json();

                if (!res.ok) {
                    window.showToast(data.error || 'Upload failed', 'error');
                    return;
                }

                this.uploadedAsset = data;
                window.showToast('MathML uploaded successfully!');
            } catch (e) {
                window.showToast('Upload failed: ' + e.message, 'error');
            } finally {
                this.uploading = false;
            }
        },
    };
}

window.latexMathml = latexMathml;

export default latexMathml;
