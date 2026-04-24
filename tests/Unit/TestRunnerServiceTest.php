<?php

use App\Services\TestRunnerService;

function parseOutput(string $output): array
{
    $service = new TestRunnerService;
    $method = new ReflectionMethod(TestRunnerService::class, 'parseTestOutput');
    $method->setAccessible(true);

    return $method->invoke($service, $output);
}

function invokeToUtf8(string $input): string
{
    $service = new TestRunnerService;
    $method = new ReflectionMethod(TestRunnerService::class, 'toUtf8');
    $method->setAccessible(true);

    return $method->invoke($service, $input);
}

// ─── toUtf8() ────────────────────────────────────────────────────────────────

test('toUtf8 scrubs truncated multi-byte sequence so json_encode succeeds', function () {
    // Lone UTF-8 lead byte — the exact shape produced when a 32KiB read
    // window starts mid-character.
    $dirty = "ok\xC3";

    $clean = invokeToUtf8($dirty);

    expect(json_encode(['output' => $clean]))->not->toBeFalse();
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
});

test('toUtf8 preserves valid UTF-8 content', function () {
    $input = "passed ✓ 日本語";

    expect(invokeToUtf8($input))->toBe($input);
});

// ─── parseTestOutput() ───────────────────────────────────────────────────────

test('parseTestOutput returns all zeros and empty tests for empty string', function () {
    $stats = parseOutput('');

    expect($stats['passed'])->toBe(0);
    expect($stats['failed'])->toBe(0);
    expect($stats['skipped'])->toBe(0);
    expect($stats['assertions'])->toBe(0);
    expect($stats['tests'])->toBe([]);
});

test('parseTestOutput extracts passed count', function () {
    $stats = parseOutput('10 passed');

    expect($stats['passed'])->toBe(10);
});

test('parseTestOutput extracts failed count', function () {
    $stats = parseOutput('2 failed');

    expect($stats['failed'])->toBe(2);
});

test('parseTestOutput extracts skipped count', function () {
    $stats = parseOutput('1 skipped');

    expect($stats['skipped'])->toBe(1);
});

test('parseTestOutput extracts assertions count', function () {
    $stats = parseOutput('(15 assertions)');

    expect($stats['assertions'])->toBe(15);
});

test('parseTestOutput calculates total as passed plus failed plus skipped', function () {
    $stats = parseOutput("8 passed\n2 failed\n1 skipped");

    expect($stats['total'])->toBe(11);
});

test('parseTestOutput sets currentSuite from PASS line', function () {
    $output = "  PASS Tests\\Unit\\SomeSuite\n  ✓ my test 0.10s";
    $stats = parseOutput($output);

    expect($stats['tests'])->toHaveCount(1);
    expect($stats['tests'][0]['suite'])->toBe('Tests\\Unit\\SomeSuite');
});

test('parseTestOutput marks checkmark lines as passed', function () {
    $output = "  PASS Tests\\Unit\\SomeSuite\n  ✓ it does something 0.05s";
    $stats = parseOutput($output);

    expect($stats['tests'][0]['status'])->toBe('passed');
    expect($stats['tests'][0]['name'])->toBe('it does something');
});

test('parseTestOutput marks x mark lines as failed', function () {
    $output = "  FAIL Tests\\Unit\\SomeSuite\n  ✗ it fails hard 0.03s";
    $stats = parseOutput($output);

    expect($stats['tests'][0]['status'])->toBe('failed');
    expect($stats['tests'][0]['name'])->toBe('it fails hard');
});

test('parseTestOutput parses FAILED suite > test name line', function () {
    $output = '  FAILED Tests\\Feature\\FooSuite > bar test name';
    $stats = parseOutput($output);

    expect($stats['tests'])->toHaveCount(1);
    expect($stats['tests'][0]['name'])->toBe('bar test name');
    expect($stats['tests'][0]['suite'])->toBe('Tests\\Feature\\FooSuite');
    expect($stats['tests'][0]['status'])->toBe('failed');
});

test('parseTestOutput replaces suite name for tests in second suite', function () {
    $output = implode("\n", [
        '  PASS Tests\\Unit\\Suite1',
        '  ✓ first test 0.01s',
        '  PASS Tests\\Unit\\Suite2',
        '  ✓ second test 0.01s',
    ]);
    $stats = parseOutput($output);

    expect($stats['tests'][0]['suite'])->toBe('Tests\\Unit\\Suite1');
    expect($stats['tests'][1]['suite'])->toBe('Tests\\Unit\\Suite2');
});
