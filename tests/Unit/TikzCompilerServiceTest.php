<?php

use App\Services\TikzCompilerService;

// ---------------------------------------------------------------------------
// fontPackages()
// ---------------------------------------------------------------------------

test('fontPackages returns all available fonts as key-label map', function () {
    $packages = TikzCompilerService::fontPackages();

    expect($packages)->toBeArray();
    expect($packages)->toHaveKey('arev');
    expect($packages)->toHaveKey('default');
    expect($packages['arev'])->toBe('Arev Sans');
    expect($packages['default'])->toBe('Computer Modern (default)');
});

test('fontPackages includes both sans-serif and serif options', function () {
    $packages = TikzCompilerService::fontPackages();

    // Sans-serif
    expect($packages)->toHaveKey('helvet');
    expect($packages)->toHaveKey('roboto');
    // Serif
    expect($packages)->toHaveKey('palatino');
    expect($packages)->toHaveKey('charter');
});

// ---------------------------------------------------------------------------
// fontLatex()
// ---------------------------------------------------------------------------

test('fontLatex returns LaTeX preamble for known font', function () {
    $latex = TikzCompilerService::fontLatex('arev');

    expect($latex)->toContain('\\usepackage{arev}');
});

test('fontLatex returns empty string for default font', function () {
    expect(TikzCompilerService::fontLatex('default'))->toBe('');
});

test('fontLatex returns empty string for unknown font key', function () {
    expect(TikzCompilerService::fontLatex('nonexistent-font'))->toBe('');
});

test('fontLatex helvetica includes sfdefault renewal', function () {
    $latex = TikzCompilerService::fontLatex('helvet');

    expect($latex)->toContain('\\usepackage{helvet}');
    expect($latex)->toContain('\\sfdefault');
});

// ---------------------------------------------------------------------------
// sanitizeInput()
// ---------------------------------------------------------------------------

test('sanitizeInput allows safe TikZ code', function () {
    $service = app(TikzCompilerService::class);
    $code = '\\begin{tikzpicture}\n\\draw (0,0) -- (1,1);\n\\end{tikzpicture}';

    expect($service->sanitizeInput($code))->toBe($code);
});

test('sanitizeInput blocks write18 shell escape', function () {
    $service = app(TikzCompilerService::class);

    expect($service->sanitizeInput('\\write18{rm -rf /}'))->toBeNull();
});

test('sanitizeInput blocks immediate write', function () {
    $service = app(TikzCompilerService::class);

    expect($service->sanitizeInput('\\immediate\\write something'))->toBeNull();
});

test('sanitizeInput blocks input with absolute path', function () {
    $service = app(TikzCompilerService::class);

    expect($service->sanitizeInput('\\input{/etc/passwd}'))->toBeNull();
    expect($service->sanitizeInput('\\input /etc/passwd'))->toBeNull();
});

test('sanitizeInput blocks file IO commands', function () {
    $service = app(TikzCompilerService::class);

    expect($service->sanitizeInput('\\openin 1=file'))->toBeNull();
    expect($service->sanitizeInput('\\openout 1=file'))->toBeNull();
    expect($service->sanitizeInput('\\newwrite\\myfile'))->toBeNull();
    expect($service->sanitizeInput('\\closeout 1'))->toBeNull();
    expect($service->sanitizeInput('\\closein 1'))->toBeNull();
    expect($service->sanitizeInput('\\read 1 to \\line'))->toBeNull();
});

test('sanitizeInput allows relative input commands', function () {
    $service = app(TikzCompilerService::class);

    // Relative \input (no leading slash) should be allowed
    expect($service->sanitizeInput('\\input{myfile.tex}'))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// buildTexDocument()
// ---------------------------------------------------------------------------

test('buildTexDocument wraps snippet in standalone document', function () {
    $service = app(TikzCompilerService::class);
    $snippet = '\\begin{tikzpicture}\\draw (0,0) circle (1);\\end{tikzpicture}';
    $doc = $service->buildTexDocument($snippet);

    expect($doc)->toContain('\\documentclass{standalone}');
    expect($doc)->toContain('\\def\\pgfsysdriver{pgfsys-dvisvgm.def}');
    expect($doc)->toContain('\\begin{document}');
    expect($doc)->toContain($snippet);
    expect($doc)->toContain('\\end{document}');
});

test('buildTexDocument includes default TikZ libraries', function () {
    $service = app(TikzCompilerService::class);
    $doc = $service->buildTexDocument('\\begin{tikzpicture}\\end{tikzpicture}');

    expect($doc)->toContain('\\usetikzlibrary{');
    expect($doc)->toContain('calc');
    expect($doc)->toContain('arrows.meta');
    expect($doc)->toContain('positioning');
});

test('buildTexDocument includes selected font package', function () {
    $service = app(TikzCompilerService::class);
    $doc = $service->buildTexDocument('\\begin{tikzpicture}\\end{tikzpicture}', fontPackage: 'palatino');

    expect($doc)->toContain('\\usepackage{palatino}');
});

test('buildTexDocument returns full document as-is when documentclass present', function () {
    $service = app(TikzCompilerService::class);
    $fullDoc = "\\documentclass{article}\n\\begin{document}\nHello\n\\end{document}";
    $result = $service->buildTexDocument($fullDoc);

    expect($result)->toBe($fullDoc);
});

test('buildTexDocument uses custom preamble when provided', function () {
    $service = app(TikzCompilerService::class);
    $preamble = "\\usepackage{xcolor}\n\\definecolor{myblue}{RGB}{0,100,200}";
    $snippet = '\\begin{tikzpicture}\\end{tikzpicture}';
    $doc = $service->buildTexDocument($snippet, preamble: $preamble);

    expect($doc)->toContain($preamble);
    expect($doc)->toContain('\\documentclass{standalone}');
    expect($doc)->toContain('\\def\\pgfsysdriver{pgfsys-dvisvgm.def}');
    // When preamble is provided, the font/library block should NOT be included
    expect($doc)->not->toContain('\\usetikzlibrary');
});

test('buildTexDocument adds extra libraries', function () {
    $service = app(TikzCompilerService::class);
    $doc = $service->buildTexDocument('\\begin{tikzpicture}\\end{tikzpicture}', extraLibraries: 'pgfplots,automata');

    expect($doc)->toContain('pgfplots');
    expect($doc)->toContain('automata');
});

test('buildTexDocument rejects invalid library names', function () {
    $service = app(TikzCompilerService::class);
    $doc = $service->buildTexDocument('\\begin{tikzpicture}\\end{tikzpicture}', extraLibraries: 'valid,../bad,ok.lib');

    expect($doc)->toContain('valid');
    expect($doc)->not->toContain('../bad');
    expect($doc)->toContain('ok.lib');
});

test('buildTexDocument includes AMS math packages', function () {
    $service = app(TikzCompilerService::class);
    $doc = $service->buildTexDocument('\\begin{tikzpicture}\\end{tikzpicture}');

    expect($doc)->toContain('\\usepackage{amsmath,amssymb,amsfonts}');
});

test('buildTexDocument applies border padding', function () {
    $service = app(TikzCompilerService::class);
    // borderPt is used in compile(), but buildTexDocument doesn't embed it —
    // it's passed to dvisvgm. Verify the parameter is accepted without error.
    $doc = $service->buildTexDocument('\\begin{tikzpicture}\\end{tikzpicture}', borderPt: 10);

    expect($doc)->toContain('\\documentclass{standalone}');
});

// ---------------------------------------------------------------------------
// compile() — dangerous input rejection (no TeX Live required)
// ---------------------------------------------------------------------------

test('compile rejects dangerous tikz code', function () {
    $service = app(TikzCompilerService::class);
    $result = $service->compile('\\write18{whoami}');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('dangerous');
});

test('compile rejects dangerous preamble', function () {
    $service = app(TikzCompilerService::class);
    $result = $service->compile(
        '\\begin{tikzpicture}\\end{tikzpicture}',
        preamble: '\\openin 1=file'
    );

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('dangerous');
});
