<?php

namespace Tests\Security\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static source-scanning helpers shared by the security suite.
 *
 * Several invariants in specs/features/security-invariants.md are enforced by reading the
 * project's own source rather than by exercising it — "no `User::create` leaves the role
 * implicit", "no controller ships without an authorization check", "no `$request->all()`
 * reaches a model's `fill()`". Those questions have no runtime surface to assert against:
 * the failure is the *absence* of a line, and only the text shows that.
 *
 * A source scan is a blunt instrument — it sees text, not semantics, and cannot follow a
 * variable or a helper. That is why every scanner built on this class is paired with a
 * self-check asserting it still finds the call sites we know exist: a scanner that silently
 * stops matching reads exactly like a codebase with nothing to report.
 *
 * Originally inlined in tests/Feature/Auth/RegistrationTest.php, which still uses it.
 */
class SourceScanner
{
    /**
     * Every *.php file under $directory, recursively.
     *
     * @return list<string>
     */
    public static function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every argument list passed to $needle in $source, matched by balancing parentheses so
     * multi-line array literals come back whole.
     *
     * @return list<string>
     */
    public static function callArgumentsFor(string $source, string $needle): array
    {
        $calls = [];
        $offset = 0;

        while (($position = strpos($source, $needle, $offset)) !== false) {
            $cursor = $position + strlen($needle);
            $depth = 1;

            while ($cursor < strlen($source) && $depth > 0) {
                $depth += match ($source[$cursor]) {
                    '(' => 1,
                    ')' => -1,
                    default => 0,
                };
                $cursor++;
            }

            $calls[] = substr($source, $position, $cursor - $position);
            $offset = $cursor;
        }

        return $calls;
    }

    /**
     * Every whole statement in $source that contains $needle — from the needle to the next `;`
     * outside any bracket.
     *
     * `callArgumentsFor()` stops at the matching close paren, which is wrong for a fluent chain:
     * for `User::factory()->create([...])` it returns just `User::factory()`, hiding the array
     * that says which role is being granted. This walks to the end of the statement instead.
     *
     * @return list<string>
     */
    public static function statementsContaining(string $source, string $needle): array
    {
        $statements = [];
        $offset = 0;
        $length = strlen($source);

        while (($position = strpos($source, $needle, $offset)) !== false) {
            $cursor = $position;
            $depth = 0;

            while ($cursor < $length) {
                $character = $source[$cursor];

                if ($character === '(' || $character === '[') {
                    $depth++;
                } elseif ($character === ')' || $character === ']') {
                    $depth--;
                } elseif ($character === ';' && $depth <= 0) {
                    break;
                }

                $cursor++;
            }

            $statements[] = substr($source, $position, $cursor - $position + 1);
            $offset = $cursor + 1;
        }

        return $statements;
    }

    /**
     * Every statement containing $needle across every PHP file under $directories.
     *
     * @param  list<string>  $directories
     * @return list<array{file: string, statement: string}>
     */
    public static function statementSitesUnder(array $directories, string $needle): array
    {
        $sites = [];

        foreach ($directories as $directory) {
            foreach (self::phpFilesUnder($directory) as $file) {
                foreach (self::statementsContaining(self::sourceOf($file), $needle) as $statement) {
                    $sites[] = ['file' => self::relative($file), 'statement' => $statement];
                }
            }
        }

        return $sites;
    }

    /** The path as written in the repo, with the base path and leading separator stripped. */
    public static function relative(string $file): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
    }

    /**
     * $file's source with comments removed.
     *
     * A commented-out call is not a call site, and counting one produces a finding that cannot
     * be fixed except by deleting the comment — which is how `// User::factory(10)->create();`
     * in DatabaseSeeder first showed up as an unroled creation path. Tokenising rather than
     * regexing so a `//` inside a string literal survives.
     */
    public static function sourceOf(string $file): string
    {
        $source = file_get_contents($file);

        $kept = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return $kept;
    }

    /**
     * Every argument list for $needle across every PHP file under $directories, keyed so the
     * caller can report which file an offending call site lives in.
     *
     * @param  list<string>  $directories
     * @return list<array{file: string, call: string}>
     */
    public static function callSitesUnder(array $directories, string $needle): array
    {
        $sites = [];

        foreach ($directories as $directory) {
            foreach (self::phpFilesUnder($directory) as $file) {
                $source = self::sourceOf($file);

                foreach (self::callArgumentsFor($source, $needle) as $call) {
                    $sites[] = ['file' => self::relative($file), 'call' => $call];
                }
            }
        }

        return $sites;
    }
}
