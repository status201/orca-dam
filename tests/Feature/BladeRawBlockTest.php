<?php

use Illuminate\Support\Facades\File;

/**
 * Guards every view under resources/views/ against one Blade compile hazard, and pins the
 * regression scenario in specs/features/asset-cycle-navigation.md.
 *
 * Blade sets raw blocks aside before it compiles anything else, using a **non-greedy**
 * pattern — BladeCompiler::storePhpBlocks() runs /(?<!@)@php(.*?)@endphp/s. So the *first*
 * "@endphp" after an "@php" closes the block, including one sitting inside a PHP comment or
 * a string, where a reader sees inert prose rather than a directive. Everything past that
 * point falls out of PHP context and emits as markup.
 *
 * v1.6.0 shipped precisely that. grid-cards.blade.php carried a comment warning about this
 * hazard and wrote the literal closing directive while doing so, so the block ended inside
 * its own warning and the asset grid printed the tail of its own source above the results.
 * Escaping is not available as a remedy: the negative lookbehind guards the *opener* only,
 * so "@@endphp" closes a block just as surely as "@endphp" does.
 *
 * The rule for a view is therefore: never write either directive's literal text inside a raw
 * block, not even in a comment. This note lives in a .php file — which Blade never compiles
 * — rather than in the view it describes, because writing it there is the bug.
 *
 * Two checks, in the order the reasoning runs:
 *
 *   - the *cause* — a literal "@php" inside a block body, which is either a second opener
 *     or the kind of prose that ends up mentioning its closing counterpart next;
 *   - the *consequence* — a directive left over once every well-formed block is accounted
 *     for. This is the load-bearing one. A body can never contain "@endphp" (the pattern
 *     stops there by construction), so an early close is invisible from the inside; what it
 *     leaves behind is an "@endphp" that closes nothing, which is exactly what a reader
 *     believed was closing the block.
 */

/** @return array<string, string> path relative to the project root => file contents */
function bladeViewSources(): array
{
    static $sources = null;

    if ($sources !== null) {
        return $sources;
    }

    $sources = [];
    $root = resource_path('views');

    foreach (File::allFiles($root) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $sources['resources/views/'.$relative] = $file->getContents();
    }

    return $sources;
}

/**
 * Blank out every well-formed raw block, preserving length and newlines so byte offsets in
 * the result still map to lines in the original. Verbatim blocks go first because Blade
 * stores them first (storeVerbatimBlocks before storePhpBlocks), which makes a directive
 * inside @verbatim genuinely inert — this mirrors that order rather than guessing at it.
 */
function maskedRawBlocks(string $source): string
{
    $blank = fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]);

    return preg_replace_callback(
        '/(?<!@)@php(.*?)@endphp/s',
        $blank,
        preg_replace_callback('/@verbatim(.*?)@endverbatim/s', $blank, $source)
    );
}

/** 1-based line number of a byte offset. */
function lineAtOffset(string $source, int $offset): int
{
    return substr_count($source, "\n", 0, $offset) + 1;
}

test('no raw PHP block in a Blade view writes a directive literal in its body', function () {
    $offenders = [];

    foreach (bladeViewSources() as $path => $source) {
        preg_match_all('/(?<!@)@php(.*?)@endphp/s', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            [$body, $bodyOffset] = $match[1];
            $at = strpos($body, '@php');

            if ($at !== false) {
                $offenders[] = $path.':'.lineAtOffset($source, $bodyOffset + $at).' writes a literal @php inside a raw block';
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('every Blade directive that opens or closes a raw block is accounted for', function () {
    $offenders = [];

    foreach (bladeViewSources() as $path => $source) {
        $masked = maskedRawBlocks($source);

        // No lookbehind on the closer, deliberately: Blade has none either, so an escaped
        // "@@endphp" closes a block and a leftover one is just as much of a problem.
        preg_match_all('/@endphp/', $masked, $closers, PREG_OFFSET_CAPTURE);

        foreach ($closers[0] as [, $offset]) {
            $offenders[] = $path.':'.lineAtOffset($source, $offset).' has an @endphp that closes nothing';
        }

        // "@php(...)" is the shorthand and needs no closer; "@@php" is escaped output.
        preg_match_all('/(?<!@)@php(?!\()/', $masked, $openers, PREG_OFFSET_CAPTURE);

        foreach ($openers[0] as [, $offset]) {
            $offenders[] = $path.':'.lineAtOffset($source, $offset).' has an @php that is never closed';
        }
    }

    expect($offenders)->toBe([]);
});
