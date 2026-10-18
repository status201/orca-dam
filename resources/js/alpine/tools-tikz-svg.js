import { tikzResults } from './tikz-results';

function tikzSvg() {
    const pageData = window.__pageData || {};
    const t = pageData.translations || {};

    return {
        ...tikzResults({ uploadPayload: (result) => ({ content: result.svg }) }),

        tikzCode: '',
        viewBoxPadding: 5,
        rendering: false,
        renderError: '',
        snippetCount: 0,
        results: [],
        uploadFolder: pageData.rootFolder || '',
        uploading: false,
        _timeoutHandle: null,

        render() {
            const snippets = this.parseSnippets();
            if (snippets.length === 0) {
                window.showToast(t.noTikzBlocks || 'No \\begin{tikzpicture} blocks found', 'warning');
                return;
            }

            this.rendering = true;
            this.results = [];
            this.renderError = '';
            this.snippetCount = snippets.length;

            if (this._timeoutHandle) {
                clearTimeout(this._timeoutHandle);
            }
            this._timeoutHandle = setTimeout(() => {
                if (this.rendering) {
                    this.rendering = false;
                    this.renderError = 'Timeout — TikZJax took too long.';
                }
            }, 90000);

            const tikzScriptTags = snippets.map(function (snippet) {
                const escaped = snippet.replace(/<\/script>/gi, '<\\/script>');
                return '<script type="text/tikz">' + escaped + '<\/script>';
            }).join('\n');

            const srcdoc = '<!DOCTYPE html><html><head>' +
                '<link rel="stylesheet" type="text/css" href="https://tikzjax.com/v1/fonts.css">' +
                '<script>' +
                'window.addEventListener(\'load\', function() {' +
                '  var expected = ' + snippets.length + ';' +
                '  var deadline = Date.now() + 90000;' +
                '  var obs = new MutationObserver(function() {' +
                '    var svgs = document.body.querySelectorAll(\'svg\');' +
                '    if (svgs.length >= expected) {' +
                '      obs.disconnect();' +
                '      var data = Array.from(svgs).map(function(s) {' +
                '        return new XMLSerializer().serializeToString(s);' +
                '      });' +
                '      window.parent.postMessage({ type: \'tikz-svgs\', svgs: data }, \'*\');' +
                '    } else if (Date.now() > deadline) {' +
                '      obs.disconnect();' +
                '      window.parent.postMessage({ type: \'tikz-error\', message: \'Timeout\' }, \'*\');' +
                '    }' +
                '  });' +
                '  obs.observe(document.body, { childList: true, subtree: true });' +
                '});' +
                '<\/script>' +
                '<script src="https://tikzjax.com/v1/tikzjax.js"><\/script>' +
                '</head><body>' +
                tikzScriptTags +
                '</body></html>';

            const iframe = document.getElementById('tikz-iframe');
            if (iframe) {
                iframe.srcdoc = srcdoc;
            }
        },

        _onMessage(event) {
            // Only our own render iframe may drive this handler — see
            // specs/features/client-side-tools.md REQ-7. event.data.svgs is rendered through
            // x-html (innerHTML), so a message from an opener or from a page framing ORCA would
            // be DOM XSS: <img src=x onerror=…> executes there even though <script> does not.
            // This iframe is sandboxed without allow-same-origin, so its origin is opaque and
            // reports the literal string "null". The source check is the load-bearing one.
            if (event.origin !== window.location.origin && event.origin !== 'null') return;

            const renderFrame = document.getElementById('tikz-iframe');
            if (! renderFrame || event.source !== renderFrame.contentWindow) return;

            if (event.data?.type !== 'tikz-svgs' && event.data?.type !== 'tikz-error') return;

            if (this._timeoutHandle) {
                clearTimeout(this._timeoutHandle);
                this._timeoutHandle = null;
            }

            this.rendering = false;

            if (event.data.type === 'tikz-error') {
                this.renderError = event.data.message || 'TikZJax render error.';
                return;
            }

            const PAD = Number(this.viewBoxPadding) || 0;
            this.results = event.data.svgs.map(function (svg, i) {
                // Strip fixed width/height and existing style so SVG scales via CSS (viewBox is preserved).
                // Also expand viewBox by a small padding so strokes/nodes at the edges are not clipped.
                const scalable = svg.replace(/<svg\b([^>]*)>/, function (match, attrs) {
                    let cleaned = attrs
                        .replace(/\s+width="[^"]*"/, '')
                        .replace(/\s+height="[^"]*"/, '')
                        .replace(/\s+style="[^"]*"/, '');
                    // Expand viewBox if present
                    cleaned = cleaned.replace(/viewBox="([^"]*)"/, function (vbMatch, vb) {
                        const parts = vb.trim().split(/[\s,]+/).map(Number);
                        if (parts.length === 4 && parts.every(isFinite)) {
                            const [x, y, w, h] = parts;
                            return 'viewBox="' + (x - PAD) + ' ' + (y - PAD) + ' ' + (w + PAD * 2) + ' ' + (h + PAD * 2) + '"';
                        }
                        return vbMatch;
                    });
                    return '<svg' + cleaned + ' style="width:100%;height:auto;display:block;">';
                });
                return {
                    svg: scalable,
                    name: 'diagram-' + (i + 1) + '.svg',
                    selected: true,
                    uploading: false,
                    uploaded: null,
                };
            });
        },

        get anyUploading() {
            return this.results.some(r => r.uploading);
        },

        get selectedCount() {
            return this.results.filter(r => r.selected && !r.uploaded).length;
        },

    };
}

window.tikzSvg = tikzSvg;

export default tikzSvg;
