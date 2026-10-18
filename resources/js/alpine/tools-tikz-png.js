import { tikzResults } from './tikz-results';

function tikzPng() {
    const pageData = window.__pageData || {};
    const t = pageData.translations || {};

    return {
        ...tikzResults({ uploadPayload: (result) => ({ content: result.png, width: result.width, height: result.height }) }),

        tikzCode: '',
        viewBoxPadding: 5,
        pngWidth: 1200,
        pixelDensity: 1,
        fontFamily: 'default',
        rendering: false,
        renderError: '',
        snippetCount: 0,
        results: [],
        uploadFolder: pageData.rootFolder || '',
        uploading: false,
        _timeoutHandle: null,
        _fontCSSCache: null,

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

            const targetWidth = Number(this.pngWidth) || 1200;
            const density = Number(this.pixelDensity) || 2;
            const pad = Number(this.viewBoxPadding) || 0;

            const SANS_PREFIX = '\\sffamily\n';
            const fontPrefix = this.fontFamily === 'sans' ? SANS_PREFIX : '';

            const tikzScriptTags = snippets.map(function (snippet) {
                const escaped = snippet.replace(/<\/script>/gi, '<\\/script>');
                return '<script type="text/tikz" data-tex-packages=\'{"amssymb":"","amsmath":""}\' data-tikz-libraries="calc,arrows.meta,positioning,decorations.pathreplacing,decorations.markings,patterns,shapes.geometric,angles,quotes,intersections,fit,backgrounds">' + fontPrefix + escaped + '<\/script>';
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
            // Only our own render iframe may drive this handler — see
            // specs/features/client-side-tools.md REQ-7. event.data.pngs[i].dataUrl is put into
            // an <img> and can be uploaded as an asset, so a message from an opener or from a
            // page framing ORCA is attacker-controlled data entering the page. This iframe has
            // no sandbox at all (the fork needs Workers + IndexedDB), so its srcdoc document
            // inherits the app's origin; the source check is what actually pins the sender.
            if (event.origin !== window.location.origin && event.origin !== 'null') return;

            const renderFrame = document.getElementById('tikz-png-iframe');
            if (! renderFrame || event.source !== renderFrame.contentWindow) return;

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

    };
}

window.tikzPng = tikzPng;

export default tikzPng;
