function tikzPng() {
    const pageData = window.__pageData || {};

    return {
        tikzCode: '',
        viewBoxPadding: 5,
        pngWidth: 1200,
        pixelDensity: 1,
        rendering: false,
        renderError: '',
        snippetCount: 0,
        results: [],
        uploadFolder: pageData.rootFolder || '',
        uploading: false,
        _timeoutHandle: null,
        _fontCSSCache: null,

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

            const targetWidth = Number(this.pngWidth) || 1200;
            const density = Number(this.pixelDensity) || 2;
            const pad = Number(this.viewBoxPadding) || 0;

            const tikzScriptTags = snippets.map(function (snippet) {
                const escaped = snippet.replace(/<\/script>/gi, '<\\/script>');
                return '<script type="text/tikz" data-tex-packages=\'{"amssymb":"","amsmath":""}\'>' + escaped + '<\/script>';
            }).join('\n');

            const FONT_CSS_URL = 'https://cdn.jsdelivr.net/npm/@drgrice1/tikzjax@latest/dist/fonts.css';
            const FONT_BASE_URL = 'https://cdn.jsdelivr.net/npm/@drgrice1/tikzjax@latest/dist/';

            const srcdoc = `<!DOCTYPE html><html><head>
<link rel="stylesheet" type="text/css" href="${FONT_CSS_URL}">
<script>
var TARGET_WIDTH = ${targetWidth};
var PIXEL_DENSITY = ${density};
var VIEWBOX_PAD = ${pad};
var FONT_BASE = "${FONT_BASE_URL}";

function svgToPng(svg, fontCSS) {
  return new Promise(function(resolve, reject) {
    var vb = svg.getAttribute("viewBox");
    var parts = vb ? vb.split(/[\\s,]+/).map(Number) : null;
    var svgW, svgH;
    if (parts && parts.length === 4 && parts.every(isFinite)) {
      // Apply edge padding to viewBox
      parts = [parts[0] - VIEWBOX_PAD, parts[1] - VIEWBOX_PAD, parts[2] + VIEWBOX_PAD * 2, parts[3] + VIEWBOX_PAD * 2];
      svgW = parts[2];
      svgH = parts[3];
    } else {
      var rect = svg.getBoundingClientRect();
      svgW = rect.width || 300;
      svgH = rect.height || 150;
    }

    var scale = TARGET_WIDTH / svgW;
    var canvasW = Math.round(TARGET_WIDTH * PIXEL_DENSITY);
    var canvasH = Math.round(svgH * scale * PIXEL_DENSITY);
    var logicalW = Math.round(TARGET_WIDTH);
    var logicalH = Math.round(svgH * scale);

    var clone = svg.cloneNode(true);
    clone.setAttribute("width", canvasW);
    clone.setAttribute("height", canvasH);
    if (parts && parts.length === 4) clone.setAttribute("viewBox", parts.join(" "));
    if (!clone.getAttribute("xmlns")) clone.setAttribute("xmlns", "http://www.w3.org/2000/svg");
    if (!clone.getAttribute("xmlns:xlink")) clone.setAttribute("xmlns:xlink", "http://www.w3.org/1999/xlink");

    // Embed font CSS into SVG for canvas rendering
    if (fontCSS) {
      var defs = clone.querySelector("defs") || clone.insertBefore(
        document.createElementNS("http://www.w3.org/2000/svg", "defs"), clone.firstChild);
      var style = document.createElementNS("http://www.w3.org/2000/svg", "style");
      style.textContent = fontCSS;
      defs.appendChild(style);
    }

    // Add white background
    var bg = document.createElementNS("http://www.w3.org/2000/svg", "rect");
    if (parts && parts.length === 4) {
      bg.setAttribute("x", parts[0]);
      bg.setAttribute("y", parts[1]);
      bg.setAttribute("width", parts[2]);
      bg.setAttribute("height", parts[3]);
    } else {
      bg.setAttribute("x", "0");
      bg.setAttribute("y", "0");
      bg.setAttribute("width", "100%");
      bg.setAttribute("height", "100%");
    }
    bg.setAttribute("fill", "white");
    var firstChild = clone.querySelector("defs") ? clone.querySelector("defs").nextSibling : clone.firstChild;
    clone.insertBefore(bg, firstChild);

    var svgData = new XMLSerializer().serializeToString(clone);
    var blob = new Blob([svgData], { type: "image/svg+xml;charset=utf-8" });
    var url = URL.createObjectURL(blob);

    var img = new Image();
    img.onload = function() {
      var canvas = document.createElement("canvas");
      canvas.width = canvasW;
      canvas.height = canvasH;
      var ctx = canvas.getContext("2d");
      ctx.drawImage(img, 0, 0, canvasW, canvasH);
      URL.revokeObjectURL(url);
      resolve({ dataUrl: canvas.toDataURL("image/png"), width: canvasW, height: canvasH, logicalW: logicalW, logicalH: logicalH });
    };
    img.onerror = function() {
      URL.revokeObjectURL(url);
      reject(new Error("Failed to load SVG as image"));
    };
    img.src = url;
  });
}

window.addEventListener("load", function() {
  var expected = ${snippets.length};
  var deadline = Date.now() + 120000;

  // Fetch a font file and return as base64 data URI
  function fetchFontAsDataUri(name) {
    return fetch(FONT_BASE + "fonts/" + name + ".woff2")
      .then(function(r) { return r.blob(); })
      .then(function(blob) {
        return new Promise(function(resolve, reject) {
          var reader = new FileReader();
          reader.onloadend = function() { resolve(reader.result); };
          reader.onerror = reject;
          reader.readAsDataURL(blob);
        });
      });
  }

  // Build self-contained @font-face CSS with base64-embedded font data
  function buildEmbeddedFontCSS(svgs) {
    var used = new Set();
    svgs.forEach(function(svg) {
      svg.querySelectorAll("[font-family]").forEach(function(el) {
        var f = el.getAttribute("font-family");
        if (f) used.add(f);
      });
      svg.querySelectorAll("[style]").forEach(function(el) {
        var m = el.getAttribute("style").match(/font-family:\\s*([^;'"]+)/);
        if (m) used.add(m[1].trim());
      });
    });
    if (!used.size) return Promise.resolve("");
    return Promise.all(Array.from(used).map(function(name) {
      return fetchFontAsDataUri(name)
        .then(function(dataUri) {
          return "@font-face { font-family: '" + name + "'; src: url('" + dataUri + "') format('woff2'); }";
        })
        .catch(function() { return null; });
    })).then(function(rules) {
      return rules.filter(Boolean).join("\\n");
    });
  }

  // The fork emits "tikzjax-load-finished" on each rendered SVG.
  // Count these events instead of using MutationObserver (which catches loading spinners).
  var finishedSvgs = [];
  var timeoutId = setTimeout(function() {
    if (finishedSvgs.length > 0) {
      processFinished(finishedSvgs);
    } else {
      window.parent.postMessage({ type: "tikz-error", message: "Timeout" }, "*");
    }
  }, deadline - Date.now());

  function processFinished(svgs) {
    clearTimeout(timeoutId);
    document.fonts.ready.then(function() {
      return buildEmbeddedFontCSS(svgs);
    }).then(function(fontCSS) {
      return Promise.all(svgs.map(function(s) {
        return svgToPng(s, fontCSS);
      }));
    }).then(function(pngs) {
      window.parent.postMessage({ type: "tikz-pngs", pngs: pngs }, "*");
    }).catch(function(err) {
      window.parent.postMessage({ type: "tikz-error", message: err.message || "PNG conversion failed" }, "*");
    });
  }

  document.addEventListener("tikzjax-load-finished", function(e) {
    var svg = e.target;
    if (svg && svg.tagName === "svg") {
      finishedSvgs.push(svg);
    } else {
      // The event target might be the container; find the SVG near it
      var nearby = svg ? svg.querySelector("svg") || svg.previousElementSibling : null;
      if (nearby && nearby.tagName === "svg") finishedSvgs.push(nearby);
    }
    if (finishedSvgs.length >= expected) {
      processFinished(finishedSvgs);
    }
  });
});
<\/script>
<script src="https://cdn.jsdelivr.net/npm/@drgrice1/tikzjax@latest/dist/tikzjax.js"><\/script>
</head><body>
${tikzScriptTags}
</body></html>`;

            const iframe = document.getElementById('tikz-png-iframe');
            if (iframe) {
                iframe.srcdoc = srcdoc;
            }
        },

        _onMessage(event) {
            if (event.data?.type !== 'tikz-pngs' && event.data?.type !== 'tikz-error') return;

            if (this._timeoutHandle) {
                clearTimeout(this._timeoutHandle);
                this._timeoutHandle = null;
            }

            this.rendering = false;

            if (event.data.type === 'tikz-error') {
                this.renderError = event.data.message || 'TikZJax render error.';
                return;
            }

            this.results = event.data.pngs.map(function (png, i) {
                return {
                    png: png.dataUrl,
                    width: png.width,
                    height: png.height,
                    logicalW: png.logicalW,
                    logicalH: png.logicalH,
                    name: 'diagram-' + (i + 1) + '.png',
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
                            content: result.png,
                            filename: result.name,
                            folder: this.uploadFolder,
                            width: result.width,
                            height: result.height,
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

window.tikzPng = tikzPng;

export default tikzPng;
