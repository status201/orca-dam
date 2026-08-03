<?php

use App\Http\Controllers\AssetBulkController;
use App\Http\Controllers\AssetController;
use App\Services\ChunkedUploadService;
use App\Services\SystemService;
use App\Services\TestRunnerService;
use App\Services\TikzCompilerService;
use App\Services\ToolUploadService;

/**
 * Static analysis, layer A — see specs/features/static-analysis.md.
 *
 * Pest's arch plugin was already installed and entirely unused. It gives one thing the rest of
 * this suite cannot: a ban on *language-level* constructs, enforced across the whole namespace
 * rather than per-file. `RouteExposureTest` knows about routes and `PolicyMatrixTest` about
 * abilities; neither would notice `eval()` appearing in a service.
 *
 * Pest's own `preset()->security()` is deliberately not used as-is. It reports one violation and
 * stops, and it takes a single flat ignore list, so a class excused for `md5` is also excused for
 * `eval`. The same 20 functions are grouped below by *why* they are banned, which keeps every
 * exemption as narrow as the reason for it — five functions across eight files, each named.
 *
 * The groups with no ignores are the load-bearing ones: nothing in this codebase uses `eval`,
 * `unserialize`, `shell_exec`, `mt_rand` or `dd` today, and these assertions are what keep it
 * that way.
 */

// ─── code execution and deserialization: never acceptable, no exemptions ───────

/**
 * `unserialize` and `extract` are here as injection sinks, not stylistic complaints: the first
 * turns attacker-controlled bytes into live objects, the second turns an array into local
 * variables and will happily overwrite ones already in scope.
 */
arch('no dynamic code execution or unsafe deserialization')
    ->expect([
        'eval',
        'create_function',
        'shell_exec',
        'system',
        'passthru',
        'unserialize',
        'extract',
        'mb_parse_str',
        'dl',
        'assert',
    ])
    ->not->toBeUsed();

/**
 * `exec` is the exception, because ORCA genuinely shells out. All three call sites were reviewed:
 * every command is a fixed string, an `escapeshellarg()`-wrapped binary name, or an int-cast PID
 * from `proc_get_status()`. None interpolates user input.
 *
 * The exemption is per-class rather than global so a *fourth* class calling `exec` still fails —
 * that is the point. Adding one here is a claim that its arguments cannot come from a request.
 */
arch('process execution is confined to the three services that need it')
    ->expect('exec')
    ->not->toBeUsed()
    ->ignoring([
        SystemService::class,          // supervisorctl status / which supervisorctl
        TikzCompilerService::class,    // locating pdflatex/dvisvgm, killing a timed-out compile
        TestRunnerService::class,      // killing a timed-out test run by PID
    ]);

// ─── weak hashing and randomness ──────────────────────────────────────────────

/**
 * These are banned because reaching for them *when a security property is needed* is the classic
 * mistake — `md5` for a token, `uniqid` for a session id, `mt_rand` for a reset code. The
 * exemptions below are all non-security uses (cache keys and temp paths); anything security-shaped
 * must use `Str::random()`, `random_bytes()` or `Hash::`.
 *
 * `rand`, `mt_rand`, `str_shuffle`, `shuffle` and `array_rand` have no exemptions because nothing
 * uses them at all.
 */
arch('weak hashes and non-cryptographic randomness are not used for security')
    ->expect([
        'md5',
        'sha1',
        'uniqid',
        'rand',
        'mt_rand',
        'str_shuffle',
        'shuffle',
        'array_rand',
    ])
    ->not->toBeUsed()
    ->ignoring([
        // md5 over a suite name + filter, and over TikZ source, to build cache keys.
        TestRunnerService::class,
        // md5 for a compile cache key; uniqid for a scratch directory name.
        TikzCompilerService::class,
        // sha1 of the JSON-encoded filter context, as an asset-cycle cache key (AssetController).
        AssetController::class,
    ]);

/**
 * `tempnam` creates the file before you open it, so it carries a TOCTOU race in principle. Every
 * use here is in a private temp directory for a file ORCA itself just produced, so it is accepted
 * — but listed, so a new one gets looked at.
 */
arch('temp-file creation stays in the paths that already do it')
    ->expect('tempnam')
    ->not->toBeUsed()
    ->ignoring([
        AssetBulkController::class,   // zip staging for bulk download
        ChunkedUploadService::class,  // reassembling uploaded chunks
        ToolUploadService::class,     // client-tool output staging
        TestRunnerService::class,     // capturing test-run output
    ]);

// ─── debugging output must never ship ─────────────────────────────────────────

/**
 * A stray `dd()` or `var_dump()` in a controller is an information-disclosure bug: it dumps
 * application state, and in ORCA's case potentially S3 keys, user rows or config, straight into a
 * response. None exist today. No exemptions.
 */
arch('no debugging output reaches production code')
    ->expect([
        'dd',
        'dump',
        'var_dump',
        'var_export',
        'print_r',
        'ray',
        'phpinfo',
    ])
    ->not->toBeUsed();

// ─── structural conventions ───────────────────────────────────────────────────

arch('controllers and policies are named for what they are')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller')
    ->and('App\Policies')->toHaveSuffix('Policy');

/**
 * Belt to `PolicyMatrixTest`'s brace: that file reflects over `app/Policies` to enumerate
 * abilities, so a policy placed outside that namespace would silently escape the role matrix.
 */
arch('every policy lives where the matrix audit looks for it')
    ->expect('App\Policies')
    ->toBeClasses();
