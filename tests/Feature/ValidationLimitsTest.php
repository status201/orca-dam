<?php

use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\Tools\StoreGifRequest;
use App\Http\Requests\Tools\ToolUploadRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Models\Asset;
use App\Models\User;
use App\Support\ColumnLimits;
use Illuminate\Foundation\Http\FormRequest;
use Tests\Security\Support\SourceScanner;

/**
 * The audit that keeps a validation rule from outrunning the column it writes into.
 *
 * This exists because the suite structurally cannot catch that by writing to the database: tests
 * run in-memory SQLite (ADR-008) and SQLite does not enforce varchar length, so an over-length
 * insert succeeds here and fails only in production. `assets.copyright` shipped as varchar(255)
 * with four rule sites allowing 500 and no test could tell.
 *
 * So the comparison is made against the *rules*, not the database. Four legs:
 *   1. no character-capped rule permits more than its column accepts;
 *   2. every character-capped rule is mapped to a column or explained (and the explanations are
 *      checked for staleness);
 *   3. no controller inline-validates a column-backed field with a literal cap;
 *   4. one character over the limit is a 422 over real HTTP — plus a canary proving the classifier
 *      underneath legs 1-3 can still fail.
 *
 * @see specs/features/input-validation.md
 */

/**
 * What one rule set says about a character cap.
 *
 * The type-awareness is the whole trick. Laravel's `max` is unit-dependent: characters with
 * `string`, element count with `array`, kilobytes with `file`, a numeric ceiling with `integer`.
 * A blanket `max:\d+` scan would compare `max:512000` (500MB of upload) against a varchar width
 * and report nonsense, so only a genuine character cap is reported as one.
 *
 * @return array{charCapped: bool, max: int|null}
 */
function ruleFacts(string|array $rules): array
{
    $parts = is_string($rules) ? explode('|', $rules) : $rules;

    $isString = false;
    $countsSomethingElse = false;
    $max = null;

    foreach ($parts as $part) {
        // Rule objects and closures are opaque and never carry a `max:`.
        if (! is_string($part)) {
            continue;
        }

        [$name, $argument] = array_pad(explode(':', $part, 2), 2, null);
        $name = strtolower($name);

        if ($name === 'string') {
            $isString = true;
        }

        if (in_array($name, ['array', 'file', 'image', 'mimetypes', 'integer', 'numeric', 'boolean'], true)) {
            $countsSomethingElse = true;
        }

        if ($name === 'max') {
            $max = (int) $argument;
        }
    }

    return [
        'charCapped' => $isString && ! $countsSomethingElse && $max !== null,
        'max' => $max,
    ];
}

/** Forward slashes throughout, so a path can be compared or split on one separator. */
function normalizePath(string $path): string
{
    return str_replace('\\', '/', $path);
}

/**
 * Every concrete FormRequest in the app. Abstract bases (ToolUploadRequest) are skipped — their
 * rules reach the audit through their subclasses.
 *
 * @return list<class-string<FormRequest>>
 */
function formRequestClasses(): array
{
    $classes = [];

    foreach (SourceScanner::phpFilesUnder(app_path('Http/Requests')) as $file) {
        // Separators come back mixed on Windows — app_path('Http/Requests') keeps the forward
        // slash it was handed while the iterator appends backslashes. Normalise before deriving
        // a class name, or every class silently fails to resolve and the whole audit goes green
        // on an empty set.
        $relative = str_replace([normalizePath(app_path()), '.php'], '', normalizePath($file));
        $class = 'App\\'.trim(str_replace('/', '\\', $relative), '\\');

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(FormRequest::class)) {
            continue;
        }

        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

/**
 * A request's rules, with just enough scaffolding to let rules() run.
 */
function rulesOf(string $class): array
{
    /** @var FormRequest $request */
    $request = $class::create('/', 'POST');

    $request->setContainer(app())->setRedirector(app('redirect'));
    // ProfileUpdateRequest::rules() dereferences $this->user()->id for its unique-ignore rule.
    $request->setUserResolver(fn () => User::factory()->create(['role' => 'editor']));

    return $request->rules();
}

/**
 * Field path => [table, column], for every character-capped rule that lands in a DB column.
 *
 * Keyed by bare field name because this codebase names a field after its column everywhere.
 * A `Class::field` key wins over a bare one, for the day that stops being true.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function schemaBackedFields(): array
{
    return [
        'filename' => ['assets', 'filename'],
        'license_type' => ['assets', 'license_type'],
        'copyright' => ['assets', 'copyright'],
        'copyright_source' => ['assets', 'copyright_source'],
        'metadata_copyright' => ['assets', 'copyright'],
        'metadata_copyright_source' => ['assets', 'copyright_source'],
        'alt_text' => ['assets', 'alt_text'],
        'caption' => ['assets', 'caption'],
        'tags.*' => ['tags', 'name'],
        'metadata_tags.*' => ['tags', 'name'],
        'name' => ['users', 'name'],
        'email' => ['users', 'email'],
    ];
}

/**
 * Character-capped fields that deliberately back no column. Each carries the reason, because an
 * unexplained entry here is indistinguishable from a field somebody forgot to map.
 *
 * @return array<string, string>
 */
function unboundStringFields(): array
{
    return [
        'folder' => 'An S3 path segment, not a column. It contributes to assets.s3_key along with the filename; capped at 100 to match FolderController\'s creation rule.',
        'content' => 'A base64 payload streamed to S3 by the /tools endpoints. Never persisted to a column; the cap bounds the request body.',
        'latex' => 'LaTeX source compiled to MathML and discarded. Never persisted.',
    ];
}

/**
 * Whether one line of an inline `$request->validate([...])` array caps a column-backed field with
 * a hand-written number.
 *
 * A `metadata_` prefix counts: the batch-upload fields write into the same columns.
 */
function inlineLiteralCapOffends(string $line): bool
{
    $columns = ['filename', 'license_type', 'copyright', 'copyright_source', 'alt_text', 'caption'];
    // copyright_source before copyright, or the alternation matches the prefix and leaves `_source`
    // dangling before the closing quote.
    usort($columns, fn ($a, $b) => strlen($b) <=> strlen($a));

    $namesAColumn = preg_match("/'(?:metadata_)?(".implode('|', $columns).")(?:\.\*)?'\s*=>/", $line);

    return (bool) $namesAColumn && (bool) preg_match('/max:\d/', $line);
}

/** Resolve a field to its column, honouring a class-qualified override. */
function columnForField(string $class, string $field): ?array
{
    $map = schemaBackedFields();

    return $map[$class.'::'.$field] ?? $map[$field] ?? null;
}

test('no validation rule permits more characters than its column accepts', function () {
    $violations = [];

    foreach (formRequestClasses() as $class) {
        foreach (rulesOf($class) as $field => $rules) {
            $facts = ruleFacts($rules);

            if (! $facts['charCapped']) {
                continue;
            }

            $target = columnForField($class, $field);

            if ($target === null) {
                continue; // Leg 2 owns unmapped fields.
            }

            [$table, $column] = $target;

            // A TEXT column has no character width — compare worst-case utf8mb4 bytes instead.
            if (isset(ColumnLimits::TEXT_BYTES[$table][$column])) {
                if (! ColumnLimits::fitsText($table, $column, $facts['max'])) {
                    $violations[] = "{$class}::{$field} allows max:{$facts['max']} characters, which at 4 bytes each overflows the TEXT column {$table}.{$column}";
                }

                continue;
            }

            $limit = ColumnLimits::for($table, $column);

            if ($facts['max'] > $limit) {
                $violations[] = "{$class}::{$field} allows max:{$facts['max']} but {$table}.{$column} accepts only {$limit} — widen the column via a migration, or read the cap from ColumnLimits::for('{$table}', '{$column}')";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('every character-capped rule is either mapped to a column or explained', function () {
    $unmapped = [];

    foreach (formRequestClasses() as $class) {
        foreach (rulesOf($class) as $field => $rules) {
            if (! ruleFacts($rules)['charCapped']) {
                continue;
            }

            if (columnForField($class, $field) !== null) {
                continue;
            }

            if (isset(unboundStringFields()[$class.'::'.$field]) || isset(unboundStringFields()[$field])) {
                continue;
            }

            $unmapped[] = $class.'::'.$field;
        }
    }

    expect($unmapped)->toBe(
        [],
        'A string field gained a character cap that this audit cannot check. Map it in schemaBackedFields(), or declare it column-free in unboundStringFields() with a reason: '.implode(', ', $unmapped)
    );
});

test('the audit maps name no field that has stopped existing', function () {
    $live = [];

    foreach (formRequestClasses() as $class) {
        foreach (array_keys(rulesOf($class)) as $field) {
            $live[$field] = true;
            $live[$class.'::'.$field] = true;
        }
    }

    // ColumnLimits declares columns the FormRequests do not all reach (s3_key is validated inline
    // in the API controller), so only the two audit maps are checked here.
    $stale = array_values(array_filter(
        [...array_keys(schemaBackedFields()), ...array_keys(unboundStringFields())],
        fn (string $field) => ! isset($live[$field])
    ));

    expect($stale)->toBe(
        [],
        'These entries no longer match any rule. Remove them — a stale entry silently pre-approves whatever field takes that name next: '.implode(', ', $stale)
    );
});

test('no controller inline-validates a column-backed field with a literal cap', function () {
    // The danger is not the inline array itself but a hand-written number in it: complete() in
    // ChunkedUploadController carried a copy of the shared trait's rules, and that copy is where
    // the copyright cap drifted 245 characters past its column. An inline rule that reads
    // ColumnLimits (as initiate() does for `filename`) cannot drift and passes.
    $offenders = [];

    foreach (SourceScanner::callSitesUnder([app_path('Http/Controllers')], '->validate(') as $site) {
        foreach (preg_split('/\R/', $site['call']) as $line) {
            if (inlineLiteralCapOffends($line)) {
                $offenders[] = normalizePath($site['file']).': '.trim($line);
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Move these into a FormRequest or the HasUploadMetadataRules trait, or read the cap from ColumnLimits — a hand-written number here is outside this audit: '.implode(' | ', $offenders)
    );
});

test('the audit still sees the requests and call sites it is meant to read', function () {
    // Both halves of this audit are reflection/text scans, and both fail *open*: a class-name
    // derivation that stops resolving, or a scanner that stops matching, produces exactly the
    // same empty result as a clean codebase. This is what tells the two apart.
    $classes = formRequestClasses();

    expect($classes)->toContain(
        UpdateAssetRequest::class,
        StoreAssetRequest::class,
        StoreGifRequest::class,
    )->and($classes)->not->toContain(ToolUploadRequest::class);

    // And the rules really come back — a request whose rules() returned [] would sail through
    // legs 1 and 2 unnoticed.
    expect(rulesOf(UpdateAssetRequest::class))->toHaveKey('copyright');

    $files = collect(SourceScanner::callSitesUnder([app_path('Http/Controllers')], '->validate('))
        ->pluck('file')
        ->map(fn (string $file) => normalizePath($file))
        ->unique()
        ->values()
        ->all();

    expect($files)->toContain('app/Http/Controllers/ChunkedUploadController.php');

    // And the literal-cap detector still recognises the line it was built to catch — the one that
    // stood in ChunkedUploadController::complete() and allowed 500 into a varchar(255).
    expect(inlineLiteralCapOffends("            'metadata_copyright' => 'nullable|string|max:500',"))->toBeTrue()
        ->and(inlineLiteralCapOffends("            'copyright_source' => 'nullable|string|max:500',"))->toBeTrue()
        // …while the shapes that are fine stay fine: a derived cap, and an uncapped field.
        ->and(inlineLiteralCapOffends("            'filename' => ['required', 'string', 'max:'.ColumnLimits::for('assets', 'filename')],"))->toBeFalse()
        ->and(inlineLiteralCapOffends("            'folder' => 'nullable|string|max:100',"))->toBeFalse()
        ->and(inlineLiteralCapOffends("            'session_token' => 'required|string',"))->toBeFalse();
});

test('ruleFacts tells a character cap apart from an array, file or integer cap', function () {
    // The canary: an audit that can no longer fail is worse than no audit, because it reads as
    // coverage. Each of these would produce a bogus violation if the classifier lost its
    // type-awareness, and the first would be missed entirely if it lost its `max` parsing.
    expect(ruleFacts('nullable|string|max:501'))->toBe(['charCapped' => true, 'max' => 501])
        ->and(ruleFacts('required|array|max:500')['charCapped'])->toBeFalse()          // element count
        ->and(ruleFacts(['required', 'file', 'max:512000'])['charCapped'])->toBeFalse() // kilobytes
        ->and(ruleFacts('required|integer|max:524288000')['charCapped'])->toBeFalse()   // a ceiling
        ->and(ruleFacts('required|string')['charCapped'])->toBeFalse()                  // uncapped
        ->and(ruleFacts(['nullable', 'string', 'max:'.ColumnLimits::for('assets', 'copyright')])['max'])->toBe(500);

    // And the TEXT comparison is in bytes, not characters.
    expect(ColumnLimits::fitsText('assets', 'caption', 1000))->toBeTrue()
        ->and(ColumnLimits::fitsText('assets', 'caption', 20000))->toBeFalse();
});

test('ColumnLimits refuses to invent a limit for an undeclared column', function () {
    // Returning a default of 255 here would have been exactly the wrong number in exactly the
    // case that caused the original bug.
    expect(fn () => ColumnLimits::for('assets', 'not_a_column'))
        ->toThrow(InvalidArgumentException::class);
});

test('one character over the column limit is a 422, never a 500', function (string $field, string $column) {
    $user = User::factory()->create(['role' => 'editor']);
    $asset = Asset::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->patchJson(route('assets.update', $asset), [
        $field => str_repeat('a', ColumnLimits::for('assets', $column) + 1),
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'copyright' => ['copyright', 'copyright'],
    'copyright_source' => ['copyright_source', 'copyright_source'],
    'filename' => ['filename', 'filename'],
]);

test('a value at exactly the column limit is accepted and stored in full', function (string $field, string $column) {
    $user = User::factory()->create(['role' => 'editor']);
    $asset = Asset::factory()->create(['user_id' => $user->id]);

    $limit = ColumnLimits::for('assets', $column);

    $this->actingAs($user)
        ->patchJson(route('assets.update', $asset), [$field => str_repeat('a', $limit)])
        ->assertSuccessful();

    // Length, not equality: a truncating column would still "match" a prefix comparison.
    expect(mb_strlen($asset->fresh()->{$field}))->toBe($limit);
})->with([
    'copyright' => ['copyright', 'copyright'],
    'copyright_source' => ['copyright_source', 'copyright_source'],
]);
