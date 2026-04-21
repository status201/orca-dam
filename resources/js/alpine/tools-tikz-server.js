import GIF from 'gif.js';
import { uploadMetadata } from './upload-metadata';
import { applyFirefoxBgLocalPolyfill } from './firefox-bg-local-polyfill';

function tikzServer() {
    const pageData = window.__pageData || {};

    return {
        ...uploadMetadata(),
        tikzCode: '',
        templateName: '',
        pngDpi: 300,
        borderPt: 5,
        fontPackage: 'arev',
        forceCanvas: false,
        canvasWidthCm: 10,
        canvasHeightCm: 8,
        clipToCanvas: false,
        rendering: false,
        renderError: '',
        renderLog: '',
        showLog: false,
        showExamples: false,
        showSettings: false,
        compilerAvailable: pageData.compilerAvailable || false,
        results: [],
        uploadFolder: pageData.rootFolder || '',

        // Output variant toggles
        enabledVariants: {
            svg_standard: true,
            svg_embedded: true,
            svg_paths: true,
            png: true,
            animated_gif: false,
        },
        gifDelayMs: 200,
        gifLoopInfinite: true,
        gifFilename: 'animation.gif',
        gifEncoding: false,
        gifProgress: 0,
        animatedGif: null,
        gifUploading: false,
        gifUploadedAsset: null,
        gifHandoffInProgress: false,
        extraLibraries: false,
        extraLibrariesText: 'automata,mindmap,circuits.ee.IEC,pgfplots',
        namingTemplate: 'diagram-{count}-{variant}.{extension}',

        // Color palette
        colorPaletteOpen: false,
        paletteColors: [],
        colorSearchQuery: '',
        colorPaletteFloating: false,
        floatingX: 0,
        floatingY: 0,
        _paletteDrag: null,
        settingsNavUrl: '',

        // Template browser
        templateSearchQuery: '',
        templateSearchResults: [],
        templateSearchLoading: false,
        templateBrowserOpen: false,
        templateLoadingId: null,
        savingTemplate: false,
        saveTemplateName: '',
        saveTemplateFolder: '',

        _lastLineCount: 0,

        init() {
            this.$watch('tikzCode', () => this.updateLineNumbers());
            this.$nextTick(() => this.updateLineNumbers());
            this.parsePaletteColors();
        },

        updateLineNumbers() {
            var ta = this.$refs.tikzInput;
            if (!ta) return;

            var lines = (this.tikzCode.match(/\n/g) || []).length + 1;
            // Skip re-render if line count hasn't changed
            if (lines === this._lastLineCount) return;
            this._lastLineCount = lines;

            var style = window.getComputedStyle(ta);
            var fontSize = parseFloat(style.fontSize);
            var lineHeight = parseFloat(style.lineHeight) || fontSize * 1.5;
            var paddingTop = parseFloat(style.paddingTop);
            var gutterWidth = 40;

            var texts = '';
            for (var i = 1; i <= lines; i++) {
                var y = paddingTop + (i - 0.3) * lineHeight;
                texts += '<text x="' + (gutterWidth - 8) + '" y="' + y + '">' + i + '</text>';
            }

            var svgHeight = paddingTop + lines * lineHeight + 20;
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + gutterWidth + '" height="' + svgHeight + '">' +
                '<style>text{font-family:monospace;font-size:' + fontSize + 'px;fill:#9ca3af;text-anchor:end}</style>' +
                '<line x1="' + (gutterWidth - 0.5) + '" y1="0" x2="' + (gutterWidth - 0.5) + '" y2="' + svgHeight + '" stroke="#e5e7eb" stroke-width="1"/>' +
                texts +
                '</svg>';

            ta.style.backgroundImage = 'url("data:image/svg+xml,' + encodeURIComponent(svg) + '")';
            ta.style.backgroundAttachment = 'local';
            ta.style.backgroundRepeat = 'no-repeat';
            applyFirefoxBgLocalPolyfill(ta);
        },

        parsePaletteColors() {
            var content = (pageData.colorPackage || '').toString();
            if (!content.trim()) return;

            var lines = content.split('\n');
            var hexPattern = /\\definecolor\{([^}]+)\}\{html\}\{([0-9a-f]{3,8})\}/i;
            var rgbPattern = /\\definecolor\{([^}]+)\}\{rgb\}\{(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\}/i;
            var colorletPattern = /\\colorlet\{([^}]+)\}\{([^}]+)\}/;
            var colors = [];
            var colorMap = {};

            for (var i = 0; i < lines.length; i++) {
                var hexMatch = lines[i].match(hexPattern);
                var rgbMatch = lines[i].match(rgbPattern);

                if (hexMatch) {
                    var hex = '#' + hexMatch[2];
                    colors.push({ name: hexMatch[1], hex: hex, cssColor: hex, isAlias: false, source: null });
                    colorMap[hexMatch[1]] = hex;
                } else if (rgbMatch) {
                    var hex = '#' + [rgbMatch[2], rgbMatch[3], rgbMatch[4]].map(function (v) {
                        return ('0' + parseInt(v, 10).toString(16)).slice(-2);
                    }).join('');
                    colors.push({ name: rgbMatch[1], hex: hex, cssColor: hex, isAlias: false, source: null });
                    colorMap[rgbMatch[1]] = hex;
                }
            }

            for (var i = 0; i < lines.length; i++) {
                var letMatch = lines[i].match(colorletPattern);
                if (!letMatch) continue;
                var name = letMatch[1];
                var sourceExpr = letMatch[2].trim();
                var baseName = sourceExpr.indexOf('!') === -1 ? sourceExpr : sourceExpr.split('!')[0];
                var resolvedHex = colorMap[baseName] || null;
                colors.push({
                    name: name,
                    hex: resolvedHex || '?',
                    cssColor: resolvedHex || '#ccc',
                    isAlias: true,
                    source: sourceExpr,
                });
                if (resolvedHex) colorMap[name] = resolvedHex;
            }

            this.paletteColors = colors;
        },

        get filteredPaletteColors() {
            var q = this.colorSearchQuery.toLowerCase().trim();
            if (!q) return this.paletteColors;
            return this.paletteColors.filter(function (c) {
                return c.name.toLowerCase().includes(q) || c.hex.toLowerCase().includes(q)
                    || (c.source && c.source.toLowerCase().includes(q));
            });
        },

        openColorPalette() {
            if (!this.paletteColors.length) return;
            this.colorPaletteOpen = true;
            this.$nextTick(() => {
                if (this.$refs.colorSearch) this.$refs.colorSearch.focus();
            });
        },

        toggleFloatingPalette() {
            if (this.colorPaletteFloating) {
                this.colorPaletteFloating = false;
                return;
            }
            if (this.floatingX === 0 && this.floatingY === 0) {
                var anchor = this.$refs.colorButton;
                if (anchor) {
                    var rect = anchor.getBoundingClientRect();
                    this.floatingX = Math.max(8, rect.right - 288);
                    this.floatingY = rect.bottom + 8;
                } else {
                    this.floatingX = 80;
                    this.floatingY = 80;
                }
            }
            this.colorPaletteFloating = true;
            this.colorPaletteOpen = true;
        },

        startPaletteDrag(event) {
            if (!this.colorPaletteFloating) return;
            this._paletteDrag = {
                mouseX: event.clientX,
                mouseY: event.clientY,
                startX: this.floatingX,
                startY: this.floatingY,
            };
            event.preventDefault();
        },

        onPaletteDrag(event) {
            if (!this._paletteDrag) return;
            var nextX = this._paletteDrag.startX + (event.clientX - this._paletteDrag.mouseX);
            var nextY = this._paletteDrag.startY + (event.clientY - this._paletteDrag.mouseY);
            var maxX = window.innerWidth - 40;
            var maxY = window.innerHeight - 40;
            this.floatingX = Math.min(Math.max(-248, nextX), maxX);
            this.floatingY = Math.min(Math.max(0, nextY), maxY);
        },

        endPaletteDrag() {
            this._paletteDrag = null;
        },

        confirmSettingsNavigation(url) {
            this.settingsNavUrl = url;
            if (!this.tikzCode.trim()) {
                window.location.href = url;
                return;
            }
            this.colorPaletteOpen = false;
            this.$dispatch('open-modal', 'settings-nav-confirm');
        },

        navigateSettingsSameTab() {
            if (this.settingsNavUrl) window.location.href = this.settingsNavUrl;
        },

        navigateSettingsNewTab() {
            if (this.settingsNavUrl) window.open(this.settingsNavUrl, '_blank', 'noopener');
            this.$dispatch('close');
        },

        copyColorName(name) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(name);
            } else {
                var ta = document.createElement('textarea');
                ta.value = name;
                ta.style.position = 'fixed';
                ta.style.top = '-999999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                ta.remove();
            }
            window.showToast('Copied: ' + name, 'success');
            if (!this.colorPaletteFloating) {
                this.colorPaletteOpen = false;
            }
        },

        generateColorStyleguide() {
            var packageName = (pageData.colorPackageName || '').toString().trim();
            var content = (pageData.colorPackage || '').toString().trim();
            if (!content || !packageName) {
                window.showToast('No color package configured', 'warning');
                return;
            }
            if (this.tikzCode.trim()) {
                this.colorPaletteOpen = false;
                this.$dispatch('open-modal', 'styleguide-confirm');
                return;
            }
            this._loadStyleguideCode();
        },

        confirmLoadStyleguide() {
            this.$dispatch('close');
            this._loadStyleguideCode();
        },

        _loadStyleguideCode() {
            var packageName = (pageData.colorPackageName || '').toString().trim();
            var content = (pageData.colorPackage || '').toString().trim();

            var lines = content.split('\n');
            var separatorPattern = /^%\s*={3,}\s*$/;
            var headerPattern = /^%\s+(.+)$/;
            var hexPattern = /\\definecolor\{([^}]+)\}\{html\}\{([0-9a-f]{3,8})\}/i;
            var rgbPattern = /\\definecolor\{([^}]+)\}\{rgb\}\{(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\}/i;
            var colorletPattern = /\\colorlet\{([^}]+)\}\{([^}]+)\}/;

            var sections = [];
            var current = { header: '', items: [] };

            for (var i = 0; i < lines.length; i++) {
                if (separatorPattern.test(lines[i].trim()) && i + 2 < lines.length) {
                    var hm = lines[i + 1].trim().match(headerPattern);
                    if (hm && separatorPattern.test((lines[i + 2] || '').trim())) {
                        if (current.items.length > 0) sections.push(current);
                        current = { header: hm[1].trim(), items: [] };
                        i += 2;
                        continue;
                    }
                }

                var hexMatch = lines[i].match(hexPattern);
                var rgbMatch = lines[i].match(rgbPattern);
                var letMatch = lines[i].match(colorletPattern);
                if (hexMatch) {
                    current.items.push({ type: 'define', name: hexMatch[1], hex: '#' + hexMatch[2].toUpperCase() });
                } else if (rgbMatch) {
                    var hex = '#' + [rgbMatch[2], rgbMatch[3], rgbMatch[4]].map(function (v) {
                        return ('0' + parseInt(v, 10).toString(16)).slice(-2);
                    }).join('').toUpperCase();
                    current.items.push({ type: 'define', name: rgbMatch[1], hex: hex });
                } else if (letMatch) {
                    current.items.push({ type: 'alias', name: letMatch[1], source: letMatch[2].trim() });
                }
            }
            if (current.items.length > 0) sections.push(current);

            function esc(s) { return s.replace(/_/g, '\\_'); }

            var colW = 9;
            var rowH = 1.0;
            var swW = 1.2;
            var swH = 0.6;
            var cols = 2;

            var allAliases = [];
            sections.forEach(function (sec) {
                sec.items.forEach(function (it) {
                    if (it.type === 'alias') allAliases.push(it);
                });
            });

            var d = '\\documentclass[border=10pt]{standalone}\n';
            d += '\\usepackage[T1]{fontenc}\n';
            d += '\\usepackage{arev}\n';
            d += '\\usepackage{tikz}\n';
            d += '\\usepackage{' + packageName + '}\n';

            if (allAliases.length > 0) {
                d += '\n% Alias definitions from ' + packageName + ':\n';
                for (var i = 0; i < allAliases.length; i++) {
                    d += '% \\colorlet{' + allAliases[i].name + '}{' + allAliases[i].source + '}\n';
                }
            }

            d += '\n\\begin{document}\n';
            d += '\\begin{tikzpicture}\n\n';

            d += '  \\node[anchor=west, font=\\Large\\bfseries] at (0, 0)\n';
            d += '    {' + esc(packageName) + ' \\textcolor{gray}{\\normalsize--- Color Styleguide}};\n\n';

            var y = -1.2;

            for (var s = 0; s < sections.length; s++) {
                var sec = sections[s];
                var heading = sec.header || 'Colors';

                d += '  \\node[anchor=west, font=\\large\\bfseries] at (0, ' + y.toFixed(1) + ') {' + esc(heading) + '};\n';
                y -= 0.8;

                for (var i = 0; i < sec.items.length; i++) {
                    var col = i % cols;
                    var row = Math.floor(i / cols);
                    var x = col * colW;
                    var cy = y - row * rowH;
                    var it = sec.items[i];
                    var label = it.type === 'alias' ? '= ' + esc(it.source) : it.hex;

                    d += '  \\fill[' + it.name + '] (' + x.toFixed(1) + ', ' + cy.toFixed(1) + ') rectangle +(' + swW + ', -' + swH + ');\n';
                    d += '  \\draw[gray!40] (' + x.toFixed(1) + ', ' + cy.toFixed(1) + ') rectangle +(' + swW + ', -' + swH + ');\n';
                    d += '  \\node[anchor=west, font=\\small\\ttfamily] at (' + (x + swW + 0.2).toFixed(1) + ', ' + (cy - swH / 2).toFixed(1) + ') {' + esc(it.name) + '};\n';
                    d += '  \\node[anchor=east, font=\\tiny\\ttfamily, text=gray] at (' + (x + colW - 0.3).toFixed(1) + ', ' + (cy - swH / 2).toFixed(1) + ') {' + label + '};\n';
                }

                y -= Math.ceil(sec.items.length / cols) * rowH + 0.6;
            }

            d += '\n\\end{tikzpicture}\n';
            d += '\\end{document}\n';

            this.tikzCode = d;
            this.templateName = packageName + '-styleguide.tex';
            this.results = [];
            this.renderError = '';
            this.renderLog = '';
            this.colorPaletteOpen = false;
        },

        examples: [
            {
                label: 'GIF frames',
                code:
                    '% GIF frames demo: growing circle. Without Force Canvas each frame\n' +
                    '% would re-fit to the circle\'s own bounding box, hiding the growth.\n' +
                    '% With Force Canvas 8\u00d75 cm + Clip, every frame shares the same\n' +
                    '% dimensions and the last frames clip cleanly at the canvas edges.\n' +
                    '\\begin{tikzpicture}\n  \\fill[blue] (4,2.5) circle (0.3);\n\\end{tikzpicture}\n' +
                    '\\begin{tikzpicture}\n  \\fill[blue] (4,2.5) circle (0.9);\n\\end{tikzpicture}\n' +
                    '\\begin{tikzpicture}\n  \\fill[blue] (4,2.5) circle (1.5);\n\\end{tikzpicture}\n' +
                    '\\begin{tikzpicture}\n  \\fill[blue] (4,2.5) circle (2.1);\n\\end{tikzpicture}\n' +
                    '\\begin{tikzpicture}\n  \\fill[blue] (4,2.5) circle (2.7);\n\\end{tikzpicture}\n' +
                    '\\begin{tikzpicture}\n  \\fill[blue] (4,2.5) circle (3.3);\n\\end{tikzpicture}',
                settings: {
                    forceCanvas: true,
                    canvasWidthCm: 8,
                    canvasHeightCm: 5,
                    clipToCanvas: true,
                    gifDelayMs: 200,
                    gifLoopInfinite: true,
                    enabledVariants: { animated_gif: true, png: true },
                    revealSettings: true,
                },
            },
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
                label: 'Neural network',
                code: '\\begin{tikzpicture}[x=1.8cm,y=1.2cm]\n  \\foreach \\i in {1,...,4}\n    \\node[circle,draw,fill=blue!20,minimum size=20pt] (I\\i) at (0,-\\i) {};\n  \\foreach \\j in {1,...,5}\n    \\node[circle,draw,fill=orange!30,minimum size=20pt] (H\\j) at (2,-\\j+0.5) {};\n  \\foreach \\k in {1,...,3}\n    \\node[circle,draw,fill=green!25,minimum size=20pt] (O\\k) at (4,-\\k-0.5) {};\n  \\foreach \\i in {1,...,4}\n    \\foreach \\j in {1,...,5}\n      \\draw[->,gray!60] (I\\i) -- (H\\j);\n  \\foreach \\j in {1,...,5}\n    \\foreach \\k in {1,...,3}\n      \\draw[->,gray!60] (H\\j) -- (O\\k);\n  \\node[above] at (0,-0.5) {\\footnotesize Input};\n  \\node[above] at (2,0) {\\footnotesize Hidden};\n  \\node[above] at (4,-1) {\\footnotesize Output};\n\\end{tikzpicture}',
            },
            {
                label: 'Clock face',
                code: '\\begin{tikzpicture}\n  \\draw[thick] (0,0) circle (2.2);\n  \\draw[fill=white] (0,0) circle (2.1);\n  \\foreach \\a/\\l in {90/12,60/1,30/2,0/3,-30/4,-60/5,-90/6,-120/7,-150/8,180/9,150/10,120/11}\n    \\node[font=\\small] at (\\a:1.75) {\\l};\n  \\foreach \\a in {0,30,...,330}\n    \\draw (\\a:1.95) -- (\\a:2.05);\n  \\foreach \\a in {0,90,180,270}\n    \\draw[thick] (\\a:1.85) -- (\\a:2.05);\n  \\draw[very thick,cap=round] (0,0) -- (120:1.2);\n  \\draw[thick,cap=round] (0,0) -- (60:1.6);\n  \\draw[red,thin,cap=round] (0,0) -- (-30:1.7);\n  \\fill (0,0) circle (0.06);\n\\end{tikzpicture}',
            },
            {
                label: 'Plot (pgfplots)',
                code: '% Requires extra package: pgfplots\n\\begin{tikzpicture}\n  \\begin{axis}[xlabel=$x$, ylabel=$y$]\n    \\addplot {x^2};\n  \\end{axis}\n\\end{tikzpicture}',
            },
        ],

        isFullDocument() {
            return /\\documentclass[\s\[{]/.test(this.tikzCode);
        },

        parsePreamble() {
            if (!this.isFullDocument()) return '';

            var code = this.tikzCode;

            // Extract everything between \documentclass line and \begin{document}
            var beginDocMatch = code.match(/\\begin\{document\}/);
            if (!beginDocMatch) return '';

            var beforeBeginDoc = code.substring(0, beginDocMatch.index);

            // Split into lines and filter out lines we handle ourselves
            var lines = beforeBeginDoc.split(/\r?\n/);
            var preambleLines = [];
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].trim();
                // Skip documentclass, standalone-specific, and empty lines
                if (!line) continue;
                if (/^\\documentclass/.test(line)) continue;
                // Skip preview-package lines (incompatible with standalone)
                if (/\\usepackage.*\{preview\}/.test(line)) continue;
                if (/\\PreviewEnvironment/.test(line)) continue;
                if (/\\setlength\s*\{\\PreviewBorder\}/.test(line)) continue;
                if (/^%/.test(line)) {
                    preambleLines.push(lines[i]);
                    continue;
                }
                // Keep everything else (usepackage, definecolor, newcommand, tikzset, etc.)
                preambleLines.push(lines[i]);
            }

            var preamble = preambleLines.join('\n').trim();

            // Also extract command definitions from the document body
            var bodyDefs = this.parseBodyDefinitions();
            if (bodyDefs) {
                preamble = preamble ? preamble + '\n' + bodyDefs : bodyDefs;
            }
            return preamble;
        },

        parseBodyDefinitions() {
            if (!this.isFullDocument()) return '';

            var code = this.tikzCode;
            var beginDocMatch = code.match(/\\begin\{document\}/);
            var endDocMatch = code.match(/\\end\{document\}/);
            if (!beginDocMatch || !endDocMatch) return '';

            var body = code.substring(
                beginDocMatch.index + beginDocMatch[0].length,
                endDocMatch.index
            );

            // Strip comment lines and tikzpicture blocks
            body = body.replace(/^[ \t]*%.*$/gm, '');
            body = body.replace(/\\begin\{tikzpicture\}[\s\S]*?\\end\{tikzpicture\}/g, '');

            // Find command definitions with multi-group brace matching
            var defPattern = /\\(?:newcommand|renewcommand|def|newlength|tikzset|pgfmathsetmacro)\b/g;
            var definitions = [];
            var match;

            while ((match = defPattern.exec(body)) !== null) {
                var lineStart = body.lastIndexOf('\n', match.index) + 1;
                var pos = match.index + match[0].length;
                var end = pos;

                // Skip optional star variant
                while (pos < body.length && ' \t\r\n'.indexOf(body[pos]) >= 0) pos++;
                if (pos < body.length && body[pos] === '*') pos++;

                // Skip bare command name (e.g., \newcommand\foo)
                while (pos < body.length && ' \t\r\n'.indexOf(body[pos]) >= 0) pos++;
                if (pos < body.length && body[pos] === '\\') {
                    pos++;
                    while (pos < body.length && /[a-zA-Z@]/.test(body[pos])) pos++;
                    end = pos;
                }

                // Consume brace groups {…} and bracket groups […]
                while (pos < body.length) {
                    while (pos < body.length && ' \t\r\n'.indexOf(body[pos]) >= 0) pos++;
                    if (pos >= body.length) break;

                    if (body[pos] === '{') {
                        var depth = 0;
                        while (pos < body.length) {
                            if (body[pos] === '{') depth++;
                            else if (body[pos] === '}') depth--;
                            pos++;
                            if (depth === 0) break;
                        }
                        end = pos;
                    } else if (body[pos] === '[') {
                        var bd = 0;
                        while (pos < body.length) {
                            if (body[pos] === '[') bd++;
                            else if (body[pos] === ']') bd--;
                            pos++;
                            if (bd === 0) break;
                        }
                        end = pos;
                    } else {
                        break;
                    }
                }

                definitions.push(body.substring(lineStart, end).trim());
                // Advance regex past consumed text to avoid re-matching inside bodies
                defPattern.lastIndex = end;
            }

            return definitions.join('\n');
        },

        parseSnippets() {
            // Strip comment-only lines so commented-out tikzpicture blocks aren't matched
            var code = this.tikzCode.replace(/^[ \t]*%.*$/gm, '');
            const re = /\\begin\{tikzpicture\}[\s\S]*?\\end\{tikzpicture\}/g;
            var matches = code.match(re) || [];
            // Collapse blank lines left by comment stripping — they become \par in LaTeX
            return matches.map(function(s) { return s.replace(/\n([ \t]*\n)+/g, '\n'); });
        },

        loadExample(exampleOrCode) {
            // Accept either a string (legacy) or an example object { code, settings? }.
            var example = typeof exampleOrCode === 'string' ? { code: exampleOrCode } : (exampleOrCode || {});
            var code = example.code || '';
            var current = this.tikzCode.trim();
            if (!current) {
                this.tikzCode = code;
            } else if (this.isFullDocument()) {
                var endDocPos = this.tikzCode.lastIndexOf('\\end{document}');
                if (endDocPos !== -1) {
                    this.tikzCode = this.tikzCode.substring(0, endDocPos) + code + '\n\n' + this.tikzCode.substring(endDocPos);
                } else {
                    this.tikzCode = this.tikzCode + '\n\n' + code;
                }
            } else {
                this.tikzCode = this.tikzCode + '\n\n' + code;
            }

            // Apply any example-provided render settings (e.g. force canvas, animated GIF).
            if (example.settings && typeof example.settings === 'object') {
                var s = example.settings;
                if (typeof s.forceCanvas === 'boolean') this.forceCanvas = s.forceCanvas;
                if (typeof s.canvasWidthCm === 'number') this.canvasWidthCm = s.canvasWidthCm;
                if (typeof s.canvasHeightCm === 'number') this.canvasHeightCm = s.canvasHeightCm;
                if (typeof s.clipToCanvas === 'boolean') this.clipToCanvas = s.clipToCanvas;
                if (typeof s.gifDelayMs === 'number') this.gifDelayMs = s.gifDelayMs;
                if (typeof s.gifLoopInfinite === 'boolean') this.gifLoopInfinite = s.gifLoopInfinite;
                if (s.enabledVariants && typeof s.enabledVariants === 'object') {
                    Object.assign(this.enabledVariants, s.enabledVariants);
                    this.onAnimatedGifToggle();
                }
                // Auto-expand the settings panel so the user sees what got applied.
                if (s.revealSettings) this.showSettings = true;
            }
        },

        loadTemplateFile(event) {
            var file = event.target.files[0];
            if (!file) return;

            var reader = new FileReader();
            reader.onload = function (e) {
                this.tikzCode = e.target.result;
                this.templateName = file.name;
                this.results = [];
                this.renderError = '';
                this.renderLog = '';
            }.bind(this);
            reader.readAsText(file);

            // Reset input so the same file can be re-loaded
            event.target.value = '';
        },

        clearCode() {
            this.tikzCode = '';
            this.templateName = '';
            this.results = [];
            this.renderError = '';
            this.renderLog = '';
            this._lastLineCount = 0;
        },

        openTemplateBrowser() {
            this.templateBrowserOpen = true;
            this.templateSearchQuery = '';
            this.searchTemplates();
        },

        closeTemplateBrowser() {
            this.templateBrowserOpen = false;
            this.templateSearchResults = [];
            this.templateSearchQuery = '';
        },

        async searchTemplates() {
            this.templateSearchLoading = true;
            try {
                var url = pageData.templateSearchUrl + '?search=' + encodeURIComponent(this.templateSearchQuery);
                var res = await fetch(url, {
                    headers: { 'Accept': 'application/json' },
                });
                if (res.ok) {
                    this.templateSearchResults = await res.json();
                }
            } catch (e) {
                // Silently fail — results just stay empty
            } finally {
                this.templateSearchLoading = false;
            }
        },

        async loadFromOrca(id) {
            this.templateLoadingId = id;
            try {
                var res = await fetch(pageData.templateLoadUrl + '/' + id, {
                    headers: { 'Accept': 'application/json' },
                });
                var data = await res.json();
                if (!res.ok) {
                    window.showToast(data.error || 'Failed to load template', 'error');
                    return;
                }
                this.tikzCode = data.content;
                this.templateName = data.filename;
                this.results = [];
                this.renderError = '';
                this.renderLog = '';
                this.closeTemplateBrowser();
                window.showToast(data.filename, 'success');
            } catch (e) {
                window.showToast('Failed to load template: ' + e.message, 'error');
            } finally {
                this.templateLoadingId = null;
            }
        },

        saveToOrca() {
            if (!this.tikzCode.trim() || this.savingTemplate) return;

            var defaultName = this.templateName || 'template.tex';
            if (!defaultName.endsWith('.tex')) {
                defaultName = defaultName.replace(/\.[^.]+$/, '') + '.tex';
            }
            this.saveTemplateName = defaultName;
            this.saveTemplateFolder = this.uploadFolder;
            this.$dispatch('open-modal', 'save-template');
        },

        async confirmSaveToOrca() {
            if (!this.saveTemplateName.trim() || this.savingTemplate) return;

            this.savingTemplate = true;
            try {
                var res = await fetch(pageData.templateUploadUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': pageData.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        content: this.tikzCode,
                        filename: this.saveTemplateName,
                        folder: this.saveTemplateFolder,
                    }),
                });
                var data = await res.json();
                if (!res.ok) {
                    window.showToast(data.error || 'Save failed', 'error');
                    return;
                }
                this.templateName = data.filename;
                window.showToast(data.filename, 'success');
                this.$dispatch('close-modal', 'save-template');
            } catch (e) {
                window.showToast('Save failed: ' + e.message, 'error');
            } finally {
                this.savingTemplate = false;
            }
        },

        variantLabel(type) {
            var labels = {
                svg_standard: 'SVG',
                svg_embedded: 'SVG (embedded fonts)',
                svg_paths: 'SVG (text as paths)',
                png: 'PNG',
            };
            return labels[type] || type;
        },

        variantExtension(type) {
            return type === 'png' ? '.png' : '.svg';
        },

        variantNameSuffix(type) {
            var suffixes = {
                svg_standard: '',
                svg_embedded: '-embedded',
                svg_paths: '-paths',
                png: '',
            };
            return suffixes[type] || '';
        },

        buildFilename(index, type) {
            var variantNames = {
                svg_standard: 'standard',
                svg_embedded: 'embedded',
                svg_paths: 'paths',
                png: 'png',
            };
            var ext = type === 'png' ? 'png' : 'svg';
            var count = String(index + 1).padStart(2, '0');
            return this.namingTemplate
                .replace('{count}', count)
                .replace('{variant}', variantNames[type] || type)
                .replace('{extension}', ext);
        },

        formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        },

        canvasPixelPreview() {
            var w = Math.round((this.canvasWidthCm || 0) * (this.pngDpi || 0) / 2.54);
            var h = Math.round((this.canvasHeightCm || 0) * (this.pngDpi || 0) / 2.54);
            return w + ' \u00D7 ' + h + ' px @ ' + this.pngDpi + ' DPI';
        },

        // Keep PNG enabled while the GIF variant is active — we need PNG frames to encode the GIF.
        onAnimatedGifToggle() {
            if (this.enabledVariants.animated_gif) {
                this.enabledVariants.png = true;
            }
        },

        collectPngFrames() {
            var frames = [];
            for (var i = 0; i < this.results.length; i++) {
                var v = this.results[i].variants && this.results[i].variants.png;
                if (!v || !v.content) continue;
                frames.push({
                    dataUrl: 'data:image/png;base64,' + v.content,
                    width: v.width || 0,
                    height: v.height || 0,
                });
            }
            return frames;
        },

        get canEncodeGif() {
            return this.collectPngFrames().length >= 2;
        },

        async encodeAnimatedGif() {
            var frames = this.collectPngFrames();
            if (frames.length < 2) return;

            this.gifEncoding = true;
            this.gifProgress = 0;
            if (this.animatedGif && this.animatedGif.objectUrl) {
                URL.revokeObjectURL(this.animatedGif.objectUrl);
            }
            this.animatedGif = null;
            this.gifUploadedAsset = null;

            // Load all frames as Image objects first so we know dimensions.
            var images = await Promise.all(frames.map(function (f) {
                return new Promise(function (resolve, reject) {
                    var img = new Image();
                    img.onload = function () { resolve(img); };
                    img.onerror = reject;
                    img.src = f.dataUrl;
                });
            }));

            // Use first frame's dimensions as the canvas. With Force Canvas enabled
            // upstream, every frame already matches; otherwise frames are center-drawn
            // onto the first frame's size.
            var w = images[0].naturalWidth;
            var h = images[0].naturalHeight;

            var gif = new GIF({
                workers: 2,
                quality: 10,
                width: w,
                height: h,
                background: '#ffffff',
                repeat: this.gifLoopInfinite ? 0 : -1,
                workerScript: '/js/gif.worker.js',
            });

            var canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            var ctx = canvas.getContext('2d');
            var delay = Math.max(20, Number(this.gifDelayMs) || 200);

            for (var i = 0; i < images.length; i++) {
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, w, h);
                var img = images[i];
                var scale = Math.min(w / img.naturalWidth, h / img.naturalHeight);
                var drawW = img.naturalWidth * scale;
                var drawH = img.naturalHeight * scale;
                var x = (w - drawW) / 2;
                var y = (h - drawH) / 2;
                ctx.drawImage(img, x, y, drawW, drawH);
                gif.addFrame(ctx, { copy: true, delay: delay });
            }

            var self = this;
            gif.on('progress', function (p) {
                self.gifProgress = Math.round(p * 100);
            });

            return new Promise(function (resolve) {
                gif.on('finished', function (blob) {
                    self.gifEncoding = false;
                    self.gifProgress = 100;
                    self.animatedGif = {
                        blob: blob,
                        objectUrl: URL.createObjectURL(blob),
                        width: w,
                        height: h,
                        size: blob.size,
                    };
                    resolve();
                });
                gif.render();
            });
        },

        async downloadAnimatedGif() {
            if (!this.animatedGif) return;
            var a = document.createElement('a');
            a.href = this.animatedGif.objectUrl;
            a.download = this.gifFilename || 'animation.gif';
            a.click();
        },

        async uploadAnimatedGif() {
            if (!this.animatedGif || this.gifUploading) return;
            if (!pageData.gifUploadUrl) {
                window.showToast('GIF upload endpoint unavailable', 'error');
                return;
            }

            this.gifUploading = true;
            this.gifUploadedAsset = null;

            try {
                var base64 = await new Promise(function (resolve, reject) {
                    var reader = new FileReader();
                    reader.onloadend = function () { resolve(reader.result); };
                    reader.onerror = reject;
                    reader.readAsDataURL(this.animatedGif.blob);
                }.bind(this));

                var body = Object.assign({
                    content: base64,
                    filename: this.gifFilename || 'animation.gif',
                    folder: this.uploadFolder,
                    width: this.animatedGif.width,
                    height: this.animatedGif.height,
                }, this.getMetadataPayload());

                var res = await fetch(pageData.gifUploadUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': pageData.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(body),
                });

                var data = await res.json();
                if (!res.ok) {
                    window.showToast(data.error || 'GIF upload failed', 'error');
                    return;
                }
                this.gifUploadedAsset = data;
                window.showToast('GIF uploaded successfully!');
            } catch (e) {
                window.showToast('GIF upload failed: ' + e.message, 'error');
            } finally {
                this.gifUploading = false;
            }
        },

        async continueInGifMaker() {
            if (this.gifHandoffInProgress) return;
            var frames = this.collectPngFrames();
            if (frames.length < 2) {
                window.showToast('Need at least 2 rendered PNG frames', 'warning');
                return;
            }
            if (!pageData.gifMakerUrl) {
                window.showToast('GIF Maker is unavailable', 'error');
                return;
            }

            this.gifHandoffInProgress = true;
            try {
                var payload = {
                    frames: frames,
                    delayMs: Math.max(20, Number(this.gifDelayMs) || 200),
                    loopInfinite: !!this.gifLoopInfinite,
                    filename: this.gifFilename || 'animation.gif',
                    metadata: this.getMetadataPayload(),
                    uploadFolder: this.uploadFolder,
                    createdAt: Date.now(),
                };
                sessionStorage.setItem('orca:gif-handoff', JSON.stringify(payload));
                window.location.href = pageData.gifMakerUrl;
            } catch (e) {
                this.gifHandoffInProgress = false;
                window.showToast('Handoff failed: ' + e.message, 'error');
            }
        },

        async render() {
            var snippets = this.parseSnippets();
            if (snippets.length === 0) {
                window.showToast('No \\begin{tikzpicture} blocks found', 'warning');
                return;
            }

            // animated_gif is a client-side variant — the backend only knows the image variants.
            var serverVariants = Object.assign({}, this.enabledVariants);
            delete serverVariants.animated_gif;

            this.rendering = true;
            this.results = [];
            this.renderError = '';
            this.renderLog = '';
            if (this.animatedGif && this.animatedGif.objectUrl) {
                URL.revokeObjectURL(this.animatedGif.objectUrl);
            }
            this.animatedGif = null;
            this.gifUploadedAsset = null;

            var preamble = this.parsePreamble();

            for (var i = 0; i < snippets.length; i++) {
                try {
                    var body = {
                        tikz_code: snippets[i],
                        png_dpi: this.pngDpi,
                        border_pt: this.borderPt,
                        font_package: this.fontPackage,
                        variants: serverVariants,
                    };
                    if (preamble) {
                        body.preamble = preamble;
                    }
                    if (this.extraLibraries && this.extraLibrariesText.trim() && !this.isFullDocument()) {
                        body.extra_libraries = this.extraLibrariesText.trim();
                    }
                    if (this.forceCanvas) {
                        body.force_canvas = Boolean(this.forceCanvas);
                        body.canvas_width_cm = this.canvasWidthCm;
                        body.canvas_height_cm = this.canvasHeightCm;
                        body.clip_to_canvas = Boolean(this.clipToCanvas);
                    }

                    var res = await fetch(pageData.renderUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': pageData.csrfToken,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify(body),
                    });

                    var data = await res.json();

                    if (!res.ok) {
                        this.renderError = data.error || 'Compilation failed';
                        if (data.log) this.renderLog = data.log;
                        break;
                    }

                    // Build variants object keyed by type
                    var variants = {};
                    var firstType = null;
                    data.variants.forEach(function (v) {
                        if (!firstType) firstType = v.type;
                        variants[v.type] = {
                            content: v.content,
                            size: v.size,
                            mime: v.mime,
                            width: v.width || null,
                            height: v.height || null,
                            selected: v.type === 'svg_paths',
                            uploading: false,
                            uploaded: null,
                            name: this.buildFilename(i, v.type),
                        };
                    }.bind(this));

                    this.results.push({
                        snippetIndex: i,
                        activeTab: variants.svg_paths ? 'svg_paths' : (firstType || 'svg_standard'),
                        variants: variants,
                    });

                    if (data.log) this.renderLog = data.log;
                } catch (e) {
                    this.renderError = 'Request failed: ' + e.message;
                    break;
                }
            }

            this.rendering = false;

            // If the Animated GIF variant is active and we have enough PNG frames, auto-encode it.
            if (this.enabledVariants.animated_gif && !this.renderError && this.canEncodeGif) {
                try {
                    await this.encodeAnimatedGif();
                } catch (e) {
                    window.showToast('GIF encoding failed: ' + e.message, 'error');
                    this.gifEncoding = false;
                }
            }
        },

        variantTypes(result) {
            return Object.keys(result.variants);
        },

        // When the Animated GIF variant is active, hide per-frame PNG upload controls —
        // the GIF is the only asset that ends up in the DAM.
        shouldShowVariantUpload(type) {
            return !(this.enabledVariants.animated_gif && type === 'png');
        },

        activeVariant(result) {
            return result.variants[result.activeTab] || null;
        },

        previewHtml(result) {
            var variant = this.activeVariant(result);
            if (!variant) return '';
            if (result.activeTab === 'png') {
                return '<img src="data:image/png;base64,' + variant.content + '" alt="PNG preview" style="max-width:100%;height:auto;">';
            }
            return variant.content;
        },

        get anyUploading() {
            return this.results.some(function (r) {
                return Object.values(r.variants).some(function (v) { return v.uploading; });
            });
        },

        get selectedCount() {
            var count = 0;
            var self = this;
            this.results.forEach(function (r) {
                Object.keys(r.variants).forEach(function (type) {
                    if (!self.shouldShowVariantUpload(type)) return;
                    var v = r.variants[type];
                    if (v.selected && !v.uploaded) count++;
                });
            });
            return count;
        },

        selectAll() {
            this.results.forEach(function (r) {
                Object.values(r.variants).forEach(function (v) {
                    v.selected = true;
                });
            });
        },

        deselectAll() {
            this.results.forEach(function (r) {
                Object.values(r.variants).forEach(function (v) {
                    v.selected = false;
                });
            });
        },

        async uploadSelected() {
            if (this.anyUploading) return;

            var toUpload = [];
            var self = this;
            this.results.forEach(function (r) {
                Object.keys(r.variants).forEach(function (type) {
                    if (!self.shouldShowVariantUpload(type)) return;
                    var v = r.variants[type];
                    if (v.selected && !v.uploaded) {
                        toUpload.push({ type: type, variant: v });
                    }
                });
            });

            if (toUpload.length === 0) {
                window.showToast('No variants selected', 'warning');
                return;
            }

            var successCount = 0;
            var failCount = 0;

            for (var i = 0; i < toUpload.length; i++) {
                var item = toUpload[i];
                var v = item.variant;
                v.uploading = true;

                try {
                    var isSvg = item.type !== 'png';
                    var uploadUrl = isSvg ? pageData.svgUploadUrl : pageData.pngUploadUrl;
                    var body = {};

                    var meta = this.getMetadataPayload();

                    if (isSvg) {
                        body = Object.assign({
                            content: v.content,
                            filename: v.name,
                            folder: this.uploadFolder,
                            caption: '',
                        }, meta);
                    } else {
                        body = Object.assign({
                            content: v.content,
                            filename: v.name,
                            folder: this.uploadFolder,
                            width: v.width,
                            height: v.height,
                            caption: '',
                        }, meta);
                    }

                    var res = await fetch(uploadUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': pageData.csrfToken,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify(body),
                    });

                    var data = await res.json();

                    if (!res.ok) {
                        window.showToast((data.error || 'Upload failed') + ': ' + v.name, 'error');
                        failCount++;
                    } else {
                        v.uploaded = data;
                        successCount++;
                    }
                } catch (e) {
                    window.showToast('Upload failed: ' + e.message, 'error');
                    failCount++;
                } finally {
                    v.uploading = false;
                }
            }

            if (successCount > 0) {
                var msg = successCount === 1
                    ? '1 variant uploaded successfully!'
                    : successCount + ' variants uploaded successfully!';
                window.showToast(msg);
            }
        },
    };
}

window.tikzServer = tikzServer;

export default tikzServer;
