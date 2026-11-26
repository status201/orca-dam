<?php

use Illuminate\Support\Facades\Lang;

/*
 * Guards against two translation failure modes:
 *
 * 1. laravel-lang's `lang:update` overwriting project translations in
 *    lang/nl.json (it merges official values over existing ones with no way
 *    to opt out). The sentinel test pins a sample of translations that a raw
 *    run is known to stomp — if it fails, someone ran `lang:update` instead
 *    of `php artisan lang:safe-update`.
 *
 * 2. New __() strings shipping without a Dutch translation. The completeness
 *    test extracts every __()/@lang() string literal from app/ and
 *    resources/views/ and asserts it resolves for locale `nl`.
 */

test('project translations are not overwritten by laravel-lang', function () {
    $nl = json_decode((string) file_get_contents(lang_path('nl.json')), true);

    expect($nl)->toBeArray();

    // Canary keys: raw `lang:update` replaces these with the official
    // laravel-lang values (right-hand comments). Keep ORCA's wording.
    $sentinels = [
        'Tags' => 'Tags', // laravel-lang: "Labels"
        'Email' => 'E-mail', // laravel-lang: "E-mailadres"
        'Preview' => 'Voorbeeld', // laravel-lang: "Voorvertoning"
        'Done' => 'Klaar', // laravel-lang: "Voltooien"
        'Reset Password' => 'Wachtwoord resetten', // laravel-lang: "Wachtwoord herstellen"
        'to' => 'naar', // laravel-lang: "tot"
        'Invalid credential format.' => 'Ongeldig credential-formaat.', // laravel-lang: English
        'Passkey verification session expired. Please try again.' => 'Passkey-verificatiesessie is verlopen. Probeer het opnieuw.', // laravel-lang: English
    ];

    foreach ($sentinels as $key => $expected) {
        expect($nl[$key] ?? null)->toBe(
            $expected,
            "Project translation for '{$key}' was overwritten — run `php artisan lang:safe-update` instead of `lang:update`, or restore lang/nl.json from git."
        );
    }
});

test('every translatable string has a Dutch translation', function () {
    // Strings that are intentionally English-only may be listed here.
    $exceptions = [];

    $keys = [];
    $patterns = [
        '/(?:__|@lang)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/s',
        '/(?:__|@lang)\(\s*"((?:[^"\\\\]|\\\\.)*)"/s',
    ];

    foreach ([app_path(), resource_path('views')] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $source, $matches)) {
                    foreach ($matches[1] as $match) {
                        $keys[stripcslashes($match)] = true;
                    }
                }
            }
        }
    }

    expect(count($keys))->toBeGreaterThan(1000); // sanity: extraction still works

    $missing = [];
    foreach (array_keys($keys) as $key) {
        if (in_array($key, $exceptions, true)) {
            continue;
        }

        if (! Lang::has($key, 'nl', false)) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe(
        [],
        'Untranslated string(s) found — add Dutch entries to lang/nl.json: '.implode(' | ', array_slice($missing, 0, 20))
    );
});

test('every translation key a JS module reads is injected by a view', function () {
    $filesIn = function (string $dir, string $suffix): array {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    };

    // Keys the views hand to JS: `key: @js(__(…))` object entries and `….key = @js(…)` assignments.
    $injected = [];
    foreach ($filesIn(resource_path('views'), '.blade.php') as $view) {
        $source = (string) file_get_contents($view);
        preg_match_all('/(\w+)\s*:\s*@(?:js|json)\(/', $source, $entries);
        preg_match_all('/\.(\w+)\s*=\s*@(?:js|json)\(/', $source, $assignments);
        foreach ([...$entries[1], ...$assignments[1]] as $key) {
            $injected[$key] = true;
        }
    }

    $missing = [];
    foreach ($filesIn(resource_path('js'), '.js') as $module) {
        if (str_contains(str_replace('\\', '/', $module), '/vendor/')) {
            continue;
        }

        $source = (string) file_get_contents($module);
        // `t` counts only where the module binds it to a translations object.
        $holders = 'translations|assetTranslations|appTranslations';
        if (preg_match('/const t = [^;]*[tT]ranslations/', $source)) {
            $holders .= '|t';
        }

        preg_match_all('/\b(?:'.$holders.')\??\.(\w+)/', $source, $reads);
        foreach (array_unique($reads[1]) as $key) {
            // `translations` is the holder itself (pageData.translations); `id` is a `t => t.id` callback.
            if (in_array($key, ['translations', 'id'], true) || isset($injected[$key])) {
                continue;
            }
            $missing[] = basename($module).": {$key}";
        }
    }

    expect($missing)->toBe([]);
});

test('the asset delete confirm puts the filename in a placeholder', function () {
    $show = (string) file_get_contents(resource_path('views/assets/show.blade.php'));

    expect($show)->toContain("__('Are you sure you want to delete :filename?', ['filename' => \$asset->filename])")
        ->and(__('Are you sure you want to delete :filename?', ['filename' => 'logo.png'], 'nl'))
        ->toBe('Weet je zeker dat je logo.png wilt verwijderen?');
});
