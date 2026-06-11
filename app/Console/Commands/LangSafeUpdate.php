<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Wraps laravel-lang's `lang:update` so it can never overwrite project
 * translations in lang/nl.json: the publisher merges official values over
 * existing ones unconditionally (no protect/exclusion config exists), which
 * once reverted 26 ORCA translations ("Tags" -> "Labels", passkey strings
 * back to English, ...).
 *
 * Only lang/nl.json is project-owned; the lang/nl/*.php files are fully
 * package-managed and may be freely updated by laravel-lang (their aligned
 * formatting is canonical — lang/ is excluded from pint).
 *
 * lang:update also publishes English files (lang/en.json, lang/en/), which
 * ORCA does not ship — English source strings live in the code. They are
 * removed after each run.
 */
class LangSafeUpdate extends Command
{
    protected $signature = 'lang:safe-update';

    protected $description = 'Run laravel-lang lang:update while protecting project translations in lang/nl.json';

    public function handle(): int
    {
        $path = lang_path('nl.json');

        if (! is_file($path)) {
            $this->error("Missing {$path} — refusing to run lang:update without a baseline.");

            return self::FAILURE;
        }

        $before = json_decode((string) file_get_contents($path), true);

        if (! is_array($before)) {
            $this->error("Could not parse {$path} — fix the JSON before updating.");

            return self::FAILURE;
        }

        $exitCode = $this->call('lang:update');

        if ($exitCode !== self::SUCCESS) {
            $this->error('lang:update failed; lang/nl.json was not post-processed.');

            return $exitCode;
        }

        $after = json_decode((string) file_get_contents($path), true);

        if (! is_array($after)) {
            $this->error("lang:update left {$path} unparseable — restore it from git.");

            return self::FAILURE;
        }

        $protected = [];
        foreach ($before as $key => $value) {
            if (! array_key_exists($key, $after) || $after[$key] !== $value) {
                $after[$key] = $value;
                $protected[] = $key;
            }
        }

        $added = array_keys(array_diff_key($after, $before));

        $this->removeEnglishLangFiles();

        uksort($after, fn (string $a, string $b) => strcasecmp($a, $b) ?: strcmp($a, $b));

        file_put_contents(
            $path,
            json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
        );

        if ($protected !== []) {
            $this->info(count($protected).' project translation(s) protected from being overwritten:');
            foreach ($protected as $key) {
                $this->line("  - {$key}");
            }
        } else {
            $this->info('No project translations needed protecting.');
        }

        if ($added !== []) {
            $this->info(count($added).' new key(s) added by laravel-lang — verify their Dutch values:');
            foreach ($added as $key) {
                $this->line("  + {$key}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * ORCA ships no English lang files; lang:update publishes them anyway.
     */
    protected function removeEnglishLangFiles(): void
    {
        if (is_file(lang_path('en.json'))) {
            unlink(lang_path('en.json'));
        }

        $dir = lang_path('en');

        if (is_dir($dir)) {
            foreach (glob($dir.'/*.php') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($dir);
        }

        $this->info('Removed published English lang files (lang/en.json, lang/en/) — ORCA keeps English in code.');
    }
}
