import { requestHeaders } from '../http';

/**
 * Shared by the three client-side TikZ tools (tikz-svg, tikz-svg-fonts, tikz-png): the example
 * snippets, snippet parsing, the result list's selection, and uploading the selected results.
 * Each tool keeps only what differs — how it renders (its iframe) and what one upload carries.
 *
 * Spread tikzResults() into the tool's x-data object. The tools keep `anyUploading` and
 * `selectedCount` as their own getters: spreading an object copies a getter's current value, not
 * the getter, so a getter here would freeze.
 *
 * @see specs/features/client-side-tools.md
 */

export const TIKZ_EXAMPLES = [
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
];

/**
 * @param {object} config
 * @param {(result: object) => object} config.uploadPayload  the tool-specific fields of one upload
 *   body (`content`, and for PNG `width`/`height`); filename, folder and caption are added here
 */
export function tikzResults({ uploadPayload }) {
    const pageData = window.__pageData || {};
    const t = pageData.translations || {};

    return {
        examples: TIKZ_EXAMPLES,

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
                window.showToast(t.noDiagramsSelected || 'No diagrams selected', 'warning');
                return;
            }

            let successCount = 0;
            let failCount = 0;

            for (const result of toUpload) {
                result.uploading = true;
                try {
                    const res = await fetch(pageData.uploadUrl, {
                        method: 'POST',
                        headers: requestHeaders(),
                        body: JSON.stringify({
                            ...uploadPayload(result),
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
                    window.showToast((t.uploadFailed || 'Upload failed: :error').replace(':error', e.message), 'error');
                    failCount++;
                } finally {
                    result.uploading = false;
                }
            }

            if (successCount > 0) {
                window.showToast(successCount === 1
                    ? (t.diagramUploaded || '1 diagram uploaded successfully!')
                    : (t.diagramsUploaded || ':count diagrams uploaded successfully!').replace(':count', successCount));
            }
        },
    };
}
