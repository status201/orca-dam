<?php

use App\Models\Setting;
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

    expect($doc)->toContain('\\documentclass[tikz]{standalone}');
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
    expect($doc)->toContain('\\documentclass[tikz]{standalone}');
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

    expect($doc)->toContain('\\documentclass[tikz]{standalone}');
});

// ---------------------------------------------------------------------------
// uniquifySvgIds() — prevent inline SVG ID collisions
// ---------------------------------------------------------------------------

test('uniquifySvgIds prefixes all IDs and references', function () {
    $service = app(TikzCompilerService::class);

    $svg = '<svg><defs><path id="g0-48" d="M1 2"/><path id="g0-49" d="M3 4"/></defs>'
        .'<use xlink:href="#g0-48"/><use xlink:href="#g0-49"/></svg>';

    // Use reflection to call private method
    $method = new ReflectionMethod($service, 'uniquifySvgIds');
    $result = $method->invoke($service, $svg);

    // Original IDs should no longer exist
    expect($result)->not->toContain('id="g0-48"');
    expect($result)->not->toContain('id="g0-49"');
    expect($result)->not->toContain('"#g0-48"');
    expect($result)->not->toContain('"#g0-49"');

    // Prefixed IDs should exist and be consistent
    preg_match('/id="([^"]+)"/', $result, $match);
    $prefix = substr($match[1], 0, -strlen('g0-48'));
    expect($result)->toContain('id="'.$prefix.'g0-48"');
    expect($result)->toContain('"#'.$prefix.'g0-48"');
    expect($result)->toContain('id="'.$prefix.'g0-49"');
    expect($result)->toContain('"#'.$prefix.'g0-49"');
});

test('uniquifySvgIds handles url() references', function () {
    $service = app(TikzCompilerService::class);

    $svg = '<svg><defs><clipPath id="clip1"><rect/></clipPath></defs>'
        .'<g clip-path="url(#clip1)"><path d="M0 0"/></g></svg>';

    $method = new ReflectionMethod($service, 'uniquifySvgIds');
    $result = $method->invoke($service, $svg);

    expect($result)->not->toContain('id="clip1"');
    expect($result)->not->toContain('(#clip1)');

    // Both the id and url() reference should use the same prefix
    preg_match('/id="([^"]+)"/', $result, $match);
    $prefixedId = $match[1];
    expect($result)->toContain('(#'.$prefixedId.')');
});

test('uniquifySvgIds returns SVG unchanged when no IDs present', function () {
    $service = app(TikzCompilerService::class);
    $svg = '<svg><rect x="0" y="0" width="10" height="10"/></svg>';

    $method = new ReflectionMethod($service, 'uniquifySvgIds');
    expect($method->invoke($service, $svg))->toBe($svg);
});

test('uniquifySvgIds produces different prefixes on each call', function () {
    $service = app(TikzCompilerService::class);
    $svg = '<svg><defs><path id="g0-48" d="M1 2"/></defs></svg>';

    $method = new ReflectionMethod($service, 'uniquifySvgIds');
    $result1 = $method->invoke($service, $svg);
    $result2 = $method->invoke($service, $svg);

    expect($result1)->not->toBe($result2);
});

test('uniquifySvgIds handles single-quoted attributes from dvisvgm', function () {
    $service = app(TikzCompilerService::class);

    // dvisvgm uses single quotes for all attributes
    $svg = "<svg version='1.1'><defs><path id='g1-4' d='M1 2'/><path id='g1-5' d='M3 4'/></defs>"
        ."<use xlink:href='#g1-4'/><use xlink:href='#g1-5'/></svg>";

    $method = new ReflectionMethod($service, 'uniquifySvgIds');
    $result = $method->invoke($service, $svg);

    // Original IDs should no longer exist
    expect($result)->not->toContain("id='g1-4'");
    expect($result)->not->toContain("id='g1-5'");
    expect($result)->not->toContain("'#g1-4'");
    expect($result)->not->toContain("'#g1-5'");

    // Prefixed IDs should exist and be consistent
    preg_match("/id='([^']+)'/", $result, $match);
    $prefix = substr($match[1], 0, -strlen('g1-4'));
    expect($result)->toContain("id='".$prefix."g1-4'");
    expect($result)->toContain("'#".$prefix."g1-4'");
    expect($result)->toContain("id='".$prefix."g1-5'");
    expect($result)->toContain("'#".$prefix."g1-5'");
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

// ---------------------------------------------------------------------------
// Color package injection via settings
// ---------------------------------------------------------------------------

test('buildTexDocument includes color package usepackage when settings configured', function () {
    Setting::set('tikz_color_package_name', 'studyflow-colors', 'string', 'general');
    Setting::set('tikz_color_package', "\\RequirePackage{xcolor}\n\\definecolor{SF_primair}{HTML}{1B4D8E}", 'string', 'general');

    $service = app(TikzCompilerService::class);
    $doc = $service->buildTexDocument('\\begin{tikzpicture}\\draw (0,0) circle (1);\\end{tikzpicture}');

    expect($doc)->toContain('\\usepackage{studyflow-colors}');
});

test('buildTexDocument omits color package when settings empty', function () {
    Setting::set('tikz_color_package_name', '', 'string', 'general');
    Setting::set('tikz_color_package', '', 'string', 'general');

    $service = app(TikzCompilerService::class);
    $doc = $service->buildTexDocument('\\begin{tikzpicture}\\draw (0,0) circle (1);\\end{tikzpicture}');

    expect($doc)->not->toContain('studyflow-colors');
});

test('buildTexDocument omits color package when name set but content empty', function () {
    Setting::set('tikz_color_package_name', 'studyflow-colors', 'string', 'general');
    Setting::set('tikz_color_package', '', 'string', 'general');

    $service = app(TikzCompilerService::class);
    $doc = $service->buildTexDocument('\\begin{tikzpicture}\\draw (0,0) circle (1);\\end{tikzpicture}');

    expect($doc)->not->toContain('studyflow-colors');
});

test('buildTexDocument does not inject color package in preamble mode', function () {
    Setting::set('tikz_color_package_name', 'studyflow-colors', 'string', 'general');
    Setting::set('tikz_color_package', '\\RequirePackage{xcolor}', 'string', 'general');

    $service = app(TikzCompilerService::class);
    $preamble = "\\usepackage{xcolor}\n\\usepackage{studyflow-colors}";
    $doc = $service->buildTexDocument('\\begin{tikzpicture}\\end{tikzpicture}', preamble: $preamble);

    // The preamble itself contains the usepackage, but buildTexDocument should not add another one
    $count = substr_count($doc, '\\usepackage{studyflow-colors}');
    expect($count)->toBe(1);
});

test('buildTexDocument does not inject color package in full document mode', function () {
    Setting::set('tikz_color_package_name', 'studyflow-colors', 'string', 'general');
    Setting::set('tikz_color_package', '\\RequirePackage{xcolor}', 'string', 'general');

    $service = app(TikzCompilerService::class);
    $fullDoc = "\\documentclass{article}\n\\begin{document}\nHello\n\\end{document}";
    $result = $service->buildTexDocument($fullDoc);

    expect($result)->toBe($fullDoc);
    expect($result)->not->toContain('\\usepackage{studyflow-colors}');
});

test('compile rejects dangerous content in color package setting', function () {
    Setting::set('tikz_color_package_name', 'evil-colors', 'string', 'general');
    Setting::set('tikz_color_package', '\\write18{rm -rf /}', 'string', 'general');

    $service = app(TikzCompilerService::class);
    $result = $service->compile('\\begin{tikzpicture}\\draw (0,0) circle (1);\\end{tikzpicture}');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Color package');
    expect($result['error'])->toContain('dangerous');
});
