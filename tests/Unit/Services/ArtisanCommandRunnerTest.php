<?php

use App\Services\ArtisanCommandRunner;
use Illuminate\Support\Facades\Artisan;

/**
 * Pins the command-line → Artisan::call() mapping, which used to be three hand-written loops.
 */
function expectArtisanCall(string $command, array $arguments): void
{
    Artisan::shouldReceive('call')->once()->with($command, $arguments)->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('done');
}

test('a positional command collects its bare words under its argument name', function () {
    expectArtisanCall('queue:retry', ['--queue' => 'default', 'id' => ['all']]);

    expect(app(ArtisanCommandRunner::class)->executeCommand('queue:retry all --queue=default')['success'])->toBeTrue();
});

test('reference-tag:create passes every name', function () {
    expectArtisanCall('reference-tag:create', ['names' => ['alpha', 'beta']]);

    app(ArtisanCommandRunner::class)->executeCommand('reference-tag:create alpha beta');
});

test('other commands get options and short flags, and drop bare words', function () {
    expectArtisanCall('migrate', ['--force' => true, '-v' => true]);

    app(ArtisanCommandRunner::class)->executeCommand('migrate --force stray -v');
});

test('a command off the allowlist is refused without running', function () {
    Artisan::shouldReceive('call')->never();

    expect(app(ArtisanCommandRunner::class)->executeCommand('db:wipe --force'))
        ->toMatchArray(['success' => false]);
});
