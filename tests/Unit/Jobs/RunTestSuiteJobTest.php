<?php

use App\Jobs\RunTestSuiteJob;
use App\Services\TestRunnerService;

test('job delegates to TestRunnerService::runStreaming with correct args', function () {
    $service = Mockery::mock(TestRunnerService::class);
    $service->shouldReceive('runStreaming')
        ->once()
        ->with('run-123', 'Feature', 'AssetTest');

    (new RunTestSuiteJob('run-123', 'Feature', 'AssetTest'))->handle($service);
});

test('job marks run as failed and re-throws on exception', function () {
    $service = Mockery::mock(TestRunnerService::class);
    $service->shouldReceive('runStreaming')->andThrow(new \RuntimeException('pest exploded'));
    $service->shouldReceive('markFailed')->once()->with('run-xyz', 'pest exploded');

    expect(fn () => (new RunTestSuiteJob('run-xyz'))->handle($service))
        ->toThrow(\RuntimeException::class, 'pest exploded');
});
