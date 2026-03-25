function tikzSvgFonts() {
    const pageData = window.__pageData || {};

    return {
        tikzCode: '',
        viewBoxPadding: 5,
        rendering: false,
        renderError: '',
        snippetCount: 0,
        results: [],
        uploadFolder: pageData.rootFolder || '',
        uploading: false,
        _timeoutHandle: null,

        examples: [
            {
                label: 'Circle & axes',
                code: '\\begin{tikzpicture}\n  \\draw[->] (-2,0) -- (2,0) node[right] {$x$};\n  \\draw[->] (0,-2) -- (0,2) node[above] {$y$};\n  \\draw[thick,blue] (0,0) circle (1.5);\n\\end{tikzpicture}',
            },
            {
                label: 'Triangle',
                code: '\\begin{tikzpicture}\n  \\draw[thick] (0,0) -- (4,0) -- (2,3) -- cycle;\n  \\node[below left] at (0,0) {$A$};\n  \\node[below right] at (4,0) {$B$};\n  \\node[above] at (2,3) {$C$};\n\\end{tikzpicture}',
            },
            {
                label: 'Simple graph',
                code: '\\begin{tikzpicture}\n  \\node[circle,draw] (A) at (0,0) {A};\n  \\node[circle,draw] (B) at (2,1) {B};\n  \\node[circle,draw] (C) at (2,-1) {C};\n  \\draw (A) -- (B);\n  \\draw (A) -- (C);\n  \\draw (B) -- (C);\n\\end{tikzpicture}',
            },
            {
                label: 'Binary tree',
                code: '\\begin{tikzpicture}[level distance=1.2cm,\n  level 1/.style={sibling distance=3cm},\n  level 2/.style={sibling distance=1.5cm}]\n  \\node[circle,draw] {1}\n    child { node[circle,draw] {2}\n      child { node[circle,draw] {4} }\n      child { node[circle,draw] {5} }\n    }\n    child { node[circle,draw] {3}\n      child { node[circle,draw] {6} }\n      child { node[circle,draw] {7} }\n    };\n\\end{tikzpicture}',
            },
            {
                label: 'Sine curve',
                code: '\\begin{tikzpicture}\n  \\draw[->] (-0.2,0) -- (6.5,0) node[right] {$x$};\n  \\draw[->] (0,-1.3) -- (0,1.3) node[above] {$y$};\n  \\draw[thick,red,domain=0:6.28,samples=100]\n    plot (\\x, {sin(\\x r)});\n\\end{tikzpicture}',
            },
            {
                label: 'Force diagram',
                code: '\\begin{tikzpicture}\n  \\draw[fill=gray!30] (0,0) rectangle (2,1);\n  \\draw[->,very thick,red] (2,0.5) -- (3.5,0.5) node[right] {$F$};\n  \\draw[->,very thick,blue] (1,0) -- (1,-1) node[below] {$mg$};\n  \\draw[->,very thick,green!60!black] (1,1) -- (1,2) node[above] {$N$};\n\\end{tikzpicture}',
            },
            {
                label: 'Venn',
                code: '\\begin{tikzpicture}\n  \\fill[blue!40,opacity=0.6] (0,0) circle (1.2);\n  \\fill[red!40,opacity=0.6] (1.4,0) circle (1.2);\n  \\draw (0,0) circle (1.2);\n  \\draw (1.4,0) circle (1.2);\n  \\node at (-0.55,0) {$A$};\n  \\node at (1.95,0) {$B$};\n  \\node[font=\\tiny] at (0.7,0) {$A\\cap B$};\n\\end{tikzpicture}',
            },
            {
                label: 'AMS symbols',
                code: '\\begin{tikzpicture}\n  \\node[draw,rounded corners,fill=blue!10,inner sep=8pt] at (0,0)\n    {$\\mathbb{R}^n \\subseteq \\mathbb{C}^n$};\n  \\node[draw,rounded corners,fill=red!10,inner sep=8pt] at (4,0)\n    {$\\sum_{i=1}^{\\infty} \\frac{1}{i^2} = \\frac{\\pi^2}{6}$};\n\\end{tikzpicture}',
            },
            {
                label: 'Pentagram',
                code: '\\begin{tikzpicture}\n  \\draw[thick,blue!70!black,fill=blue!10]\n    (90:2) -- (162:2) -- (234:2) -- (306:2) -- (18:2) -- cycle;\n  \\draw[thick,red!80!black]\n    (90:2) -- (234:2) -- (18:2) -- (162:2) -- (306:2) -- cycle;\n\\end{tikzpicture}',
            },
            {
                label: 'Spiral',
                code: '\\begin{tikzpicture}\n  \\draw[thick,blue!70!black,domain=0:720,samples=300,variable=\\t]\n    plot ({(\\t/720)*2.5*cos(\\t)}, {(\\t/720)*2.5*sin(\\t)});\n\\end{tikzpicture}',
            },
            {
                label: 'Neural network',
                code: '\\begin{tikzpicture}[x=1.8cm,y=1.2cm]\n  \\foreach \\i in {1,...,4}\n    \\node[circle,draw,fill=blue!20,minimum size=20pt] (I\\i) at (0,-\\i) {};\n  \\foreach \\j in {1,...,5}\n    \\node[circle,draw,fill=orange!30,minimum size=20pt] (H\\j) at (2,-\\j+0.5) {};\n  \\foreach \\k in {1,...,3}\n    \\node[circle,draw,fill=green!25,minimum size=20pt] (O\\k) at (4,-\\k-0.5) {};\n  \\foreach \\i in {1,...,4}\n    \\foreach \\j in {1,...,5}\n      \\draw[->,gray!60] (I\\i) -- (H\\j);\n  \\foreach \\j in {1,...,5}\n    \\foreach \\k in {1,...,3}\n      \\draw[->,gray!60] (H\\j) -- (O\\k);\n  \\node[above] at (0,-0.5) {\\footnotesize Input};\n  \\node[above] at (2,0) {\\footnotesize Hidden};\n  \\node[above] at (4,-1) {\\footnotesize Output};\n\\end{tikzpicture}',
            },
            {
                label: 'Clock face',
                code: '\\begin{tikzpicture}\n  \\draw[thick] (0,0) circle (2.2);\n  \\draw[fill=white] (0,0) circle (2.1);\n  \\foreach \\a/\\l in {90/12,60/1,30/2,0/3,-30/4,-60/5,-90/6,-120/7,-150/8,180/9,150/10,120/11}\n    \\node[font=\\small] at (\\a:1.75) {\\l};\n  \\foreach \\a in {0,30,...,330}\n    \\draw (\\a:1.95) -- (\\a:2.05);\n  \\foreach \\a in {0,90,180,270}\n    \\draw[thick] (\\a:1.85) -- (\\a:2.05);\n  \\draw[very thick,cap=round] (0,0) -- (120:1.2);\n  \\draw[thick,cap=round] (0,0) -- (60:1.6);\n  \\draw[red,thin,cap=round] (0,0) -- (-30:1.7);\n  \\fill (0,0) circle (0.06);\n\\end{tikzpicture}',
            },
        ],

        init() {
            window.addEventListener('message', this._onMessage.bind(this));
        },

        parseSnippets() {
            const re = /\\begin\{tikzpicture\}[\s\S]*?\\end\{tikzpicture\}/g;
            return this.tikzCode.match(re) || [];
        },

        loadExample(code) {
            this.tikzCode = this.tikzCode.trim()
                ? this.tikzCode + '\n\n' + code
                : code;
        },

        clearCode() {
            this.tikzCode = '';
            this.results = [];
            this.renderError = '';
        },

        render() {
            const snippets = this.parseSnippets();
            if (snippets.length === 0) {
                window.showToast('No \\begin{tikzpicture} blocks found', 'warning');
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

        selectAll() {
            this.results.forEach(r => { r.selected = true; });
        },

        deselectAll() {
            this.results.forEach(r => { r.selected = false; });
        },

        async uploadSelected() {
            if (this.anyUploading) return;

            const toUpload = this.results.filter(r => r.selected && !r.uploaded);
            if (toUpload.length === 0) {
                window.showToast('No diagrams selected', 'warning');
                return;
            }

            let successCount = 0;
            let failCount = 0;

            for (const result of toUpload) {
                result.uploading = true;
                try {
                    const res = await fetch(pageData.uploadUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': pageData.csrfToken,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({
                            content: result.svg,
                            filename: result.name,
                            folder: this.uploadFolder,
                            caption: '',
                        }),
                    });

                    const data = await res.json();

                    if (!res.ok) {
                        window.showToast((data.error || 'Upload failed') + ': ' + result.name, 'error');
                        failCount++;
                    } else {
                        result.uploaded = data;
                        successCount++;
                    }
                } catch (e) {
                    window.showToast('Upload failed: ' + e.message, 'error');
                    failCount++;
                } finally {
                    result.uploading = false;
                }
            }

            if (successCount > 0) {
                const msg = successCount === 1
                    ? '1 diagram uploaded successfully!'
                    : successCount + ' diagrams uploaded successfully!';
                window.showToast(msg);
            }
        },
    };
}

window.tikzSvgFonts = tikzSvgFonts;

export default tikzSvgFonts;
