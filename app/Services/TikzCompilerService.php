<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class TikzCompilerService
{
    /**
     * Dangerous LaTeX commands that could be used for shell escape or file access.
     */
    private const DANGEROUS_PATTERNS = [
        '/\\\\write18\b/',
        '/\\\\immediate\s*\\\\write/',
        '/\\\\input\s*\{?\s*[\/\\\\]/',
        '/\\\\openin\b/',
        '/\\\\openout\b/',
        '/\\\\newwrite\b/',
        '/\\\\closeout\b/',
        '/\\\\closein\b/',
        '/\\\\read\b/',
    ];

    /**
     * Available font packages for the document preamble.
     */
    /**
     * Available font packages: key => [label, latex preamble lines].
     * Sans-serif fonts listed first, then serif, then default.
     */
    private const FONT_PACKAGES = [
        // Sans-serif
        'arev' => ['label' => 'Arev Sans', 'latex' => '\\usepackage{arev}'],
        'cmbright' => ['label' => 'CM Bright', 'latex' => '\\usepackage{cmbright}'],
        'helvet' => ['label' => 'Helvetica', 'latex' => "\\usepackage{helvet}\n\\renewcommand{\\familydefault}{\\sfdefault}"],
        'avant' => ['label' => 'Avant Garde', 'latex' => "\\usepackage{avant}\n\\renewcommand{\\familydefault}{\\sfdefault}"],
        'opensans' => ['label' => 'Open Sans', 'latex' => '\\usepackage[default]{opensans}'],
        'firasans' => ['label' => 'Fira Sans', 'latex' => '\\usepackage[sfdefault]{FiraSans}'],
        'sourcesanspro' => ['label' => 'Source Sans Pro', 'latex' => '\\usepackage[default]{sourcesanspro}'],
        'roboto' => ['label' => 'Roboto', 'latex' => '\\usepackage[sfdefault]{roboto}'],
        'cabin' => ['label' => 'Cabin', 'latex' => '\\usepackage[sfdefault]{cabin}'],
        'iwona' => ['label' => 'Iwona', 'latex' => '\\usepackage[math]{iwona}'],
        'kurier' => ['label' => 'Kurier', 'latex' => '\\usepackage[math]{kurier}'],
        'raleway' => ['label' => 'Raleway', 'latex' => '\\usepackage[default]{raleway}'],
        // Serif
        'lmodern' => ['label' => 'Latin Modern (serif)', 'latex' => '\\usepackage{lmodern}'],
        'palatino' => ['label' => 'Palatino (serif)', 'latex' => '\\usepackage{palatino}'],
        'charter' => ['label' => 'Charter (serif)', 'latex' => '\\usepackage{charter}'],
        'bookman' => ['label' => 'Bookman (serif)', 'latex' => '\\usepackage{bookman}'],
        // Default
        'default' => ['label' => 'Computer Modern (default)', 'latex' => ''],
    ];

    /**
     * TikZ libraries to include in the document preamble.
     */
    private const TIKZ_LIBRARIES = [
        'calc',
        'arrows.meta',
        'positioning',
        'decorations.pathreplacing',
        'decorations.markings',
        'patterns',
        'shapes.geometric',
        'angles',
        'quotes',
        'intersections',
        'fit',
        'backgrounds',
        'matrix',
        'trees',
    ];

    /**
     * Check if TeX Live binaries are available on the system.
     */
    public function isAvailable(): bool
    {
        $latexPath = config('tikz.latex_path', 'latex');
        $dvisvgmPath = config('tikz.dvisvgm_path', 'dvisvgm');

        return $this->binaryExists($latexPath) && $this->binaryExists($dvisvgmPath);
    }

    /**
     * Get the available font packages for the UI dropdown (key => label).
     */
    public static function fontPackages(): array
    {
        return array_map(fn ($v) => $v['label'], self::FONT_PACKAGES);
    }

    /**
     * Get the LaTeX preamble lines for a given font package key.
     */
    public static function fontLatex(string $key): string
    {
        return self::FONT_PACKAGES[$key]['latex'] ?? '';
    }

    /**
     * Compile TikZ code into four output variants.
     *
     * @return array{success: bool, variants?: array, log?: string, error?: string}
     */
    public function compile(string $tikzCode, int $pngDpi = 300, int $borderPt = 5, string $fontPackage = 'arev', string $preamble = ''): array
    {
        $sanitized = $this->sanitizeInput($tikzCode);
        if ($sanitized === null) {
            return ['success' => false, 'error' => 'Input contains potentially dangerous LaTeX commands.'];
        }

        // Sanitize preamble too if provided
        if ($preamble !== '') {
            $sanitizedPreamble = $this->sanitizeInput($preamble);
            if ($sanitizedPreamble === null) {
                return ['success' => false, 'error' => 'Preamble contains potentially dangerous LaTeX commands.'];
            }
            $preamble = $sanitizedPreamble;
        }

        $tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orca_tikz_'.uniqid();
        if (! mkdir($tmpDir, 0700, true)) {
            return ['success' => false, 'error' => 'Failed to create temporary directory.'];
        }

        try {
            $texContent = $this->buildTexDocument($sanitized, $borderPt, $fontPackage, $preamble);
            $texFile = $tmpDir.DIRECTORY_SEPARATOR.'input.tex';
            file_put_contents($texFile, $texContent);

            // Step 1: Compile LaTeX to DVI
            $latexResult = $this->runLatex($tmpDir);
            $log = $latexResult['output'] ?? '';

            $dviFile = $tmpDir.DIRECTORY_SEPARATOR.'input.dvi';

            // In nonstop mode LaTeX may return non-zero even when it produced
            // usable DVI output (e.g. recoverable errors). Only fail when no
            // DVI was written at all.
            if (! file_exists($dviFile)) {
                return [
                    'success' => false,
                    'error' => 'LaTeX compilation failed.',
                    'log' => $log,
                ];
            }

            // Step 2: Generate all four variants
            $variants = [];

            // SVG standard (dvisvgm default: fonts as SVG path data in <defs>)
            $svgStandard = $this->runDvisvgm($tmpDir, $dviFile, 'standard.svg', [], $borderPt);
            if ($svgStandard !== null) {
                $variants[] = [
                    'type' => 'svg_standard',
                    'content' => $svgStandard,
                    'size' => strlen($svgStandard),
                    'mime' => 'image/svg+xml',
                ];
            }

            // SVG with embedded WOFF2 fonts
            $svgEmbedded = $this->runDvisvgm($tmpDir, $dviFile, 'embedded.svg', ['--font-format=woff2'], $borderPt);
            if ($svgEmbedded !== null) {
                $variants[] = [
                    'type' => 'svg_embedded',
                    'content' => $svgEmbedded,
                    'size' => strlen($svgEmbedded),
                    'mime' => 'image/svg+xml',
                ];
            }

            // SVG with text as paths (no font dependencies)
            $svgPaths = $this->runDvisvgm($tmpDir, $dviFile, 'paths.svg', ['--no-fonts'], $borderPt);
            if ($svgPaths !== null) {
                $variants[] = [
                    'type' => 'svg_paths',
                    'content' => $svgPaths,
                    'size' => strlen($svgPaths),
                    'mime' => 'image/svg+xml',
                ];
            }

            // PNG — convert from SVG (paths variant preferred, most portable)
            $svgForPng = $svgPaths ?? $svgStandard ?? $svgEmbedded;
            if ($svgForPng !== null) {
                $pngResult = $this->convertSvgToPng($tmpDir, $svgForPng, $pngDpi);
                if ($pngResult !== null) {
                    $variants[] = $pngResult;
                }
            }

            if (empty($variants)) {
                return [
                    'success' => false,
                    'error' => 'All output conversions failed.',
                    'log' => $log,
                ];
            }

            return [
                'success' => true,
                'variants' => $variants,
                'log' => $log,
            ];
        } finally {
            $this->cleanup($tmpDir);
        }
    }

    /**
     * Sanitize TikZ input for security. Returns null if dangerous commands found.
     */
    public function sanitizeInput(string $code): ?string
    {
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                Log::warning('TikZ input rejected: matched dangerous pattern', ['pattern' => $pattern]);

                return null;
            }
        }

        return $code;
    }

    /**
     * Build a complete LaTeX document wrapping the TikZ snippet.
     */
    public function buildTexDocument(string $tikzSnippet, int $borderPt = 5, string $fontPackage = 'arev', string $preamble = ''): string
    {
        $libraries = implode(',', self::TIKZ_LIBRARIES);

        // Check if the snippet already contains a full document
        if (str_contains($tikzSnippet, '\\documentclass')) {
            return $tikzSnippet;
        }

        // When a user preamble is provided, use it (it already contains usepackage lines, colors, etc.)
        if ($preamble !== '') {
            return <<<LATEX
\\documentclass[tikz]{standalone}
{$preamble}
\\begin{document}
{$tikzSnippet}
\\end{document}
LATEX;
        }

        // Get font preamble lines from whitelist
        $fontLine = self::fontLatex($fontPackage);
        if ($fontLine !== '') {
            $fontLine .= "\n";
        }

        return <<<LATEX
\\documentclass[tikz]{standalone}
\\usepackage{amsmath,amssymb,amsfonts}
{$fontLine}\\usepackage{tikz}
\\usetikzlibrary{{$libraries}}
\\begin{document}
{$tikzSnippet}
\\end{document}
LATEX;
    }

    /**
     * Run the LaTeX compiler on input.tex in the given directory.
     */
    private function runLatex(string $tmpDir): array
    {
        $latexPath = escapeshellarg(config('tikz.latex_path', 'latex'));
        $timeout = config('tikz.timeout', 30);

        $command = $latexPath
            .' --no-shell-escape'
            .' --interaction=nonstopmode'
            .' -output-directory='.escapeshellarg($tmpDir)
            .' '.escapeshellarg($tmpDir.DIRECTORY_SEPARATOR.'input.tex');

        return $this->runProcess($command, $tmpDir, $timeout, [
            'openout_any' => 'p',
            'openin_any' => 'p',
            'TEXMFOUTPUT' => $tmpDir,
        ]);
    }

    /**
     * Run dvisvgm to convert DVI to SVG.
     */
    private function runDvisvgm(string $tmpDir, string $dviFile, string $outputName, array $extraArgs, int $borderPt = 5): ?string
    {
        $dvisvgmPath = escapeshellarg(config('tikz.dvisvgm_path', 'dvisvgm'));
        $timeout = config('tikz.timeout', 30);
        $outputFile = $tmpDir.DIRECTORY_SEPARATOR.$outputName;

        $args = array_map('escapeshellarg', $extraArgs);
        $command = $dvisvgmPath
            .' --bbox='.escapeshellarg($borderPt.'pt')
            .' '.implode(' ', $args)
            .' '.escapeshellarg($dviFile)
            .' -o '.escapeshellarg($outputFile);

        $result = $this->runProcess($command, $tmpDir, $timeout);

        if (file_exists($outputFile)) {
            return file_get_contents($outputFile);
        }

        Log::warning("dvisvgm failed for {$outputName}", [
            'exit_code' => $result['exit_code'] ?? -1,
            'output' => substr($result['output'] ?? '', 0, 500),
        ]);

        return null;
    }

    /**
     * Convert SVG content to PNG using rsvg-convert or inkscape.
     *
     * dvipng cannot handle PostScript specials from TikZ (dvips backend),
     * so we convert from the already-generated SVG instead.
     */
    private function convertSvgToPng(string $tmpDir, string $svgContent, int $dpi): ?array
    {
        $timeout = config('tikz.timeout', 30);
        $svgFile = $tmpDir.DIRECTORY_SEPARATOR.'for-png.svg';
        $outputFile = $tmpDir.DIRECTORY_SEPARATOR.'output.png';

        file_put_contents($svgFile, $svgContent);

        // Try rsvg-convert first (fast, lightweight)
        if ($this->binaryExists('rsvg-convert')) {
            $command = escapeshellarg('rsvg-convert')
                .' -d '.escapeshellarg((string) $dpi)
                .' -p '.escapeshellarg((string) $dpi)
                .' -b white'
                .' -o '.escapeshellarg($outputFile)
                .' '.escapeshellarg($svgFile);

            $this->runProcess($command, $tmpDir, $timeout);
        }

        // Fallback to inkscape
        if (! file_exists($outputFile) && $this->binaryExists('inkscape')) {
            $command = escapeshellarg('inkscape')
                .' '.escapeshellarg($svgFile)
                .' --export-type=png'
                .' --export-filename='.escapeshellarg($outputFile)
                .' --export-dpi='.escapeshellarg((string) $dpi);

            $this->runProcess($command, $tmpDir, $timeout);
        }

        if (! file_exists($outputFile)) {
            Log::warning('SVG to PNG conversion failed: neither rsvg-convert nor inkscape produced output');

            return null;
        }

        $pngData = file_get_contents($outputFile);
        $imageSize = @getimagesize($outputFile);

        return [
            'type' => 'png',
            'content' => base64_encode($pngData),
            'size' => strlen($pngData),
            'mime' => 'image/png',
            'width' => $imageSize[0] ?? null,
            'height' => $imageSize[1] ?? null,
        ];
    }

    /**
     * Run a shell command with timeout support.
     *
     * @return array{success: bool, exit_code: int, output: string}
     */
    private function runProcess(string $command, string $cwd, int $timeout, array $extraEnv = []): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge($this->getBaseEnv(), $extraEnv);
        $env = array_filter($env, fn ($value) => is_string($value));

        $process = proc_open($command, $descriptors, $pipes, $cwd, $env);

        if (! is_resource($process)) {
            return ['success' => false, 'exit_code' => -1, 'output' => 'Failed to start process'];
        }

        fclose($pipes[0]);

        // Set streams to non-blocking for timeout support
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = microtime(true);
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                // Process finished — drain remaining output
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                break;
            }

            if ((microtime(true) - $startTime) > $timeout) {
                $timedOut = true;
                // Kill the process tree
                if (PHP_OS_FAMILY === 'Windows') {
                    exec('taskkill /F /T /PID '.$status['pid'].' 2>NUL');
                } else {
                    posix_kill($status['pid'], 9);
                }
                break;
            }

            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            usleep(50000); // 50ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($timedOut) {
            return [
                'success' => false,
                'exit_code' => -1,
                'output' => "Process timed out after {$timeout} seconds.\n".$stdout.$stderr,
            ];
        }

        $output = $stdout.$stderr;

        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $output,
        ];
    }

    /**
     * Check if a binary exists and is executable.
     */
    private function binaryExists(string $binary): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            exec('where '.escapeshellarg($binary).' 2>NUL', $output, $exitCode);
        } else {
            exec('which '.escapeshellarg($binary).' 2>/dev/null', $output, $exitCode);
        }

        return $exitCode === 0;
    }

    /**
     * Get base environment variables for subprocesses.
     */
    private function getBaseEnv(): array
    {
        $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

        // Add common TeX Live paths
        $texPaths = [
            '/usr/local/texlive/2024/bin/x86_64-linux',
            '/usr/local/texlive/2025/bin/x86_64-linux',
            '/usr/local/texlive/2024/bin/universal-darwin',
            '/usr/local/texlive/2025/bin/universal-darwin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
        ];

        $allPaths = array_unique(array_merge($texPaths, explode(PATH_SEPARATOR, $path)));

        return [
            'PATH' => implode(PATH_SEPARATOR, $allPaths),
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
        ];
    }

    /**
     * Recursively delete a temporary directory.
     */
    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }

            @rmdir($dir);
        } catch (\Exception $e) {
            Log::warning('TikZ temp directory cleanup failed: '.$e->getMessage());
        }
    }
}
