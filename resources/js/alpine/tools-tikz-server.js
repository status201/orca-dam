function tikzServer() {
    const pageData = window.__pageData || {};

    return {
        tikzCode: '',
        templateName: '',
        pngDpi: 300,
        borderPt: 5,
        fontPackage: 'arev',
        rendering: false,
        renderError: '',
        renderLog: '',
        showLog: false,
        showExamples: false,
        compilerAvailable: pageData.compilerAvailable || false,
        results: [],
        uploadFolder: pageData.rootFolder || '',

        // Output variant toggles
        enabledVariants: {
            svg_standard: true,
            svg_embedded: true,
            svg_paths: true,
            png: true,
        },
        extraLibraries: false,
        extraLibrariesText: 'pgfplots,automata,mindmap,circuits.ee.IEC',

        // Template browser
        templateSearchQuery: '',
        templateSearchResults: [],
        templateSearchLoading: false,
        templateBrowserOpen: false,
        templateLoadingId: null,
        savingTemplate: false,

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
                label: 'Neural network',
                code: '\\begin{tikzpicture}[x=1.8cm,y=1.2cm]\n  \\foreach \\i in {1,...,4}\n    \\node[circle,draw,fill=blue!20,minimum size=20pt] (I\\i) at (0,-\\i) {};\n  \\foreach \\j in {1,...,5}\n    \\node[circle,draw,fill=orange!30,minimum size=20pt] (H\\j) at (2,-\\j+0.5) {};\n  \\foreach \\k in {1,...,3}\n    \\node[circle,draw,fill=green!25,minimum size=20pt] (O\\k) at (4,-\\k-0.5) {};\n  \\foreach \\i in {1,...,4}\n    \\foreach \\j in {1,...,5}\n      \\draw[->,gray!60] (I\\i) -- (H\\j);\n  \\foreach \\j in {1,...,5}\n    \\foreach \\k in {1,...,3}\n      \\draw[->,gray!60] (H\\j) -- (O\\k);\n  \\node[above] at (0,-0.5) {\\footnotesize Input};\n  \\node[above] at (2,0) {\\footnotesize Hidden};\n  \\node[above] at (4,-1) {\\footnotesize Output};\n\\end{tikzpicture}',
            },
            {
                label: 'Clock face',
                code: '\\begin{tikzpicture}\n  \\draw[thick] (0,0) circle (2.2);\n  \\draw[fill=white] (0,0) circle (2.1);\n  \\foreach \\a/\\l in {90/12,60/1,30/2,0/3,-30/4,-60/5,-90/6,-120/7,-150/8,180/9,150/10,120/11}\n    \\node[font=\\small] at (\\a:1.75) {\\l};\n  \\foreach \\a in {0,30,...,330}\n    \\draw (\\a:1.95) -- (\\a:2.05);\n  \\foreach \\a in {0,90,180,270}\n    \\draw[thick] (\\a:1.85) -- (\\a:2.05);\n  \\draw[very thick,cap=round] (0,0) -- (120:1.2);\n  \\draw[thick,cap=round] (0,0) -- (60:1.6);\n  \\draw[red,thin,cap=round] (0,0) -- (-30:1.7);\n  \\fill (0,0) circle (0.06);\n\\end{tikzpicture}',
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

        loadExample(code) {
            this.tikzCode = this.tikzCode.trim()
                ? this.tikzCode + '\n\n' + code
                : code;
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

        async saveToOrca() {
            if (!this.tikzCode.trim() || this.savingTemplate) return;

            var defaultName = this.templateName || 'template.tex';
            if (!defaultName.endsWith('.tex')) {
                defaultName = defaultName.replace(/\.[^.]+$/, '') + '.tex';
            }
            var filename = window.prompt(pageData.saveTemplatePrompt || 'Template name:', defaultName);
            if (!filename) return;

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
                        filename: filename,
                        folder: this.uploadFolder,
                    }),
                });
                var data = await res.json();
                if (!res.ok) {
                    window.showToast(data.error || 'Save failed', 'error');
                    return;
                }
                this.templateName = data.filename;
                window.showToast(data.filename, 'success');
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

        formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        },

        async render() {
            var snippets = this.parseSnippets();
            if (snippets.length === 0) {
                window.showToast('No \\begin{tikzpicture} blocks found', 'warning');
                return;
            }

            this.rendering = true;
            this.results = [];
            this.renderError = '';
            this.renderLog = '';

            var preamble = this.parsePreamble();

            for (var i = 0; i < snippets.length; i++) {
                try {
                    var body = {
                        tikz_code: snippets[i],
                        png_dpi: this.pngDpi,
                        border_pt: this.borderPt,
                        font_package: this.fontPackage,
                        variants: this.enabledVariants,
                    };
                    if (preamble) {
                        body.preamble = preamble;
                    }
                    if (this.extraLibraries && this.extraLibrariesText.trim() && !this.isFullDocument()) {
                        body.extra_libraries = this.extraLibrariesText.trim();
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
                            selected: v.type === 'svg_embedded',
                            uploading: false,
                            uploaded: null,
                            name: 'diagram-' + (i + 1) + this.variantNameSuffix(v.type) + this.variantExtension(v.type),
                        };
                    }.bind(this));

                    this.results.push({
                        snippetIndex: i,
                        activeTab: variants.svg_embedded ? 'svg_embedded' : (firstType || 'svg_standard'),
                        variants: variants,
                    });

                    if (data.log) this.renderLog = data.log;
                } catch (e) {
                    this.renderError = 'Request failed: ' + e.message;
                    break;
                }
            }

            this.rendering = false;
        },

        variantTypes(result) {
            return Object.keys(result.variants);
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
            this.results.forEach(function (r) {
                Object.values(r.variants).forEach(function (v) {
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
            this.results.forEach(function (r) {
                Object.keys(r.variants).forEach(function (type) {
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

                    if (isSvg) {
                        body = {
                            content: v.content,
                            filename: v.name,
                            folder: this.uploadFolder,
                            caption: '',
                        };
                    } else {
                        body = {
                            content: v.content,
                            filename: v.name,
                            folder: this.uploadFolder,
                            width: v.width,
                            height: v.height,
                            caption: '',
                        };
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
