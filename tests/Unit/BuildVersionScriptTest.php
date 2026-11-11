<?php

/*
 * scripts/build-version.mjs, run for real against a throwaway git repo
 * (specs/features/system-admin.md REQ-orca-version-shown).
 */

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $finder = new ExecutableFinder;
    if (! $finder->find('git') || ! $finder->find('node')) {
        $this->markTestSkipped('git and node are required to run scripts/build-version.mjs');
    }

    $this->repo = sys_get_temp_dir().'/orca-build-version-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->repo.'/scripts');
    File::copy(base_path('scripts/build-version.mjs'), $this->repo.'/scripts/build-version.mjs');
    File::put($this->repo.'/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n## [v1.1.0] — 2026-11 — Eye Patch\n\n## [v1.0.0] — 2026-10 — Saddle Patch\n");

    $this->git = function (string ...$args): string {
        $process = new Process(['git', ...$args], $this->repo);
        $process->mustRun();

        return trim($process->getOutput());
    };
    $this->commit = fn (string $message) => ($this->git)('commit', '-q', '--allow-empty', '-m', $message);
    $this->stamp = function (): ?array {
        (new Process(['node', 'scripts/build-version.mjs'], $this->repo))->mustRun();
        $path = $this->repo.'/bootstrap/cache/orca-version.json';

        return File::exists($path) ? json_decode(File::get($path), true) : null;
    };

    ($this->git)('init', '-q', '-b', 'main');
    ($this->git)('config', 'user.email', 'test@example.com');
    ($this->git)('config', 'user.name', 'Test');
    ($this->git)('config', 'commit.gpgsign', 'false');
    ($this->git)('add', '.');
    ($this->commit)('first');
});

afterEach(function () {
    if (isset($this->repo)) {
        File::deleteDirectory($this->repo);
    }
});

test('it stamps the tag, its codename and the commits after it, ignoring wp-v* tags', function () {
    ($this->git)('tag', 'v1.1.0');
    ($this->commit)('second');
    ($this->git)('tag', 'wp-v9.9.9');
    ($this->commit)('third');

    expect(($this->stamp)())->toMatchArray(['tag' => 'v1.1.0', 'codename' => 'Eye Patch', 'ahead' => 2, 'branch' => null]);
});

test('it shows the branch only off main', function () {
    ($this->git)('tag', 'v1.1.0');
    ($this->git)('checkout', '-q', '-b', 'feature/x');

    expect(($this->stamp)()['branch'])->toBe('feature/x');
});

test('a local master is treated as main', function () {
    ($this->git)('tag', 'v1.1.0');
    ($this->git)('branch', '-q', '-m', 'master');

    expect(($this->stamp)()['branch'])->toBeNull();
});

test('stale local tags show the CHANGELOG release with no commit count (regression)', function () {
    // The local tags stop at v1.0.0 while the CHANGELOG already records v1.1.0
    ($this->git)('tag', 'v1.0.0');
    ($this->commit)('second');

    expect(($this->stamp)())->toMatchArray(['tag' => 'v1.1.0', 'codename' => 'Eye Patch', 'ahead' => null])
        ->and(($this->stamp)()['sha'])->toMatch('/^[0-9a-f]{7}$/');
});
