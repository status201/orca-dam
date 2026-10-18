import { tikzResults } from './tikz-results';

function tikzSvgFonts() {
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
            }, 120000);

            const tikzScriptTags = snippets.map(function (snippet) {
                const escaped = snippet.replace(/<\/script>/gi, '<\\/script>');
                return '<script type="text/tikz">' + escaped + '<\/script>';
            }).join('\n');

            const srcdoc = '<!DOCTYPE html><html><head>' +
                '<link rel="stylesheet" type="text/css" href="https://tikzjax.com/v1/fonts.css">' +
                '<script>' +
                'var FONT_PROXY_BASE = "' + pageData.fontProxyBase + '";' +

                // fetchFontAsDataUri — fetch from same-origin proxy to avoid CORS/ORB
                'function fetchFontAsDataUri(name) {' +
                '  return fetch(FONT_PROXY_BASE + name)' +
                '    .then(function(r) {' +
                '      if (!r.ok) throw new Error("HTTP " + r.status);' +
                '      return r.blob();' +
                '    })' +
                '    .then(function(blob) {' +
                '      return new Promise(function(resolve, reject) {' +
                '        var reader = new FileReader();' +
                '        reader.onloadend = function() { resolve(reader.result); };' +
                '        reader.onerror = reject;' +
                '        reader.readAsDataURL(blob);' +
                '      });' +
                '    });' +
                '}' +

                // collectUsedFonts — scan SVG elements for font-family references
                'function collectUsedFonts(svgs) {' +
                '  var used = new Set();' +
                '  svgs.forEach(function(svg) {' +
                '    svg.querySelectorAll("[font-family]").forEach(function(el) {' +
                '      var f = el.getAttribute("font-family");' +
                '      if (f) used.add(f);' +
                '    });' +
                '    svg.querySelectorAll("[style]").forEach(function(el) {' +
                '      var m = el.getAttribute("style").match(/font-family:\\s*([^;\'\"]+)/);' +
                '      if (m) used.add(m[1].trim());' +
                '    });' +
                '  });' +
                '  return used;' +
                '}' +

                // buildEmbeddedFontCSS — fetch all used fonts and build @font-face rules
                'function buildEmbeddedFontCSS(svgs) {' +
                '  var used = collectUsedFonts(svgs);' +
                '  if (!used.size) return Promise.resolve("");' +
                '  return Promise.all(Array.from(used).map(function(name) {' +
                '    return fetchFontAsDataUri(name)' +
                '      .then(function(dataUri) {' +
                '        return "@font-face { font-family: \'" + name + "\'; src: url(\'" + dataUri + "\') format(\'truetype\'); }";' +
                '      })' +
                '      .catch(function() { return null; });' +
                '  })).then(function(rules) {' +
                '    return rules.filter(Boolean).join("\\n");' +
                '  });' +
                '}' +

                // injectFontCSS — add <defs><style> with font CSS into an SVG element
                'function injectFontCSS(svg, fontCSS) {' +
                '  if (!fontCSS) return;' +
                '  var defs = svg.querySelector("defs");' +
                '  if (!defs) {' +
                '    defs = document.createElementNS("http://www.w3.org/2000/svg", "defs");' +
                '    svg.insertBefore(defs, svg.firstChild);' +
                '  }' +
                '  var style = document.createElementNS("http://www.w3.org/2000/svg", "style");' +
                '  style.textContent = fontCSS;' +
                '  defs.insertBefore(style, defs.firstChild);' +
                '}' +

                'window.addEventListener(\'load\', function() {' +
                '  var expected = ' + snippets.length + ';' +
                '  var deadline = Date.now() + 120000;' +
                '  var obs = new MutationObserver(function() {' +
                '    var svgs = document.body.querySelectorAll(\'svg\');' +
                '    if (svgs.length >= expected) {' +
                '      obs.disconnect();' +
                '      var svgArray = Array.from(svgs);' +
                '      buildEmbeddedFontCSS(svgArray).then(function(fontCSS) {' +
                '        svgArray.forEach(function(svg) {' +
                '          injectFontCSS(svg, fontCSS);' +
                '        });' +
                '        var data = svgArray.map(function(s) {' +
                '          return new XMLSerializer().serializeToString(s);' +
                '        });' +
                '        window.parent.postMessage({ type: \'tikz-svgs-fonts\', svgs: data }, \'*\');' +
                '      }).catch(function(err) {' +
                '        window.parent.postMessage({ type: \'tikz-error-fonts\', message: \'Font embedding failed: \' + err.message }, \'*\');' +
                '      });' +
                '    } else if (Date.now() > deadline) {' +
                '      obs.disconnect();' +
                '      window.parent.postMessage({ type: \'tikz-error-fonts\', message: \'Timeout\' }, \'*\');' +
                '    }' +
                '  });' +
                '  obs.observe(document.body, { childList: true, subtree: true });' +
                '});' +
                '<\/script>' +
                '<script src="https://tikzjax.com/v1/tikzjax.js"><\/script>' +
                '</head><body>' +
                tikzScriptTags +
                '</body></html>';

            const iframe = document.getElementById('tikz-fonts-iframe');
            if (iframe) {
                iframe.srcdoc = srcdoc;
            }
        },

        _onMessage(event) {
            // Only our own render iframe may drive this handler — see
            // specs/features/client-side-tools.md REQ-7. event.data.svgs is rendered through
            // x-html (innerHTML), so a message from an opener or from a page framing ORCA would
            // be DOM XSS. This iframe carries allow-same-origin, so its srcdoc document inherits
            // the app's origin: an origin check alone would also admit any other same-origin
            // window, including the top-level page. The source check is what actually pins it.
            if (event.origin !== window.location.origin && event.origin !== 'null') return;

            const renderFrame = document.getElementById('tikz-fonts-iframe');
            if (! renderFrame || event.source !== renderFrame.contentWindow) return;

            if (event.data?.type !== 'tikz-svgs-fonts' && event.data?.type !== 'tikz-error-fonts') return;

            if (this._timeoutHandle) {
                clearTimeout(this._timeoutHandle);
                this._timeoutHandle = null;
            }

            this.rendering = false;

            if (event.data.type === 'tikz-error-fonts') {
                this.renderError = event.data.message || 'TikZJax render error.';
                return;
            }

            const PAD = Number(this.viewBoxPadding) || 0;
            this.results = event.data.svgs.map(function (svg, i) {
                const scalable = svg.replace(/<svg\b([^>]*)>/, function (match, attrs) {
                    let cleaned = attrs
                        .replace(/\s+width="[^"]*"/, '')
                        .replace(/\s+height="[^"]*"/, '')
                        .replace(/\s+style="[^"]*"/, '');
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

window.tikzSvgFonts = tikzSvgFonts;

export default tikzSvgFonts;
