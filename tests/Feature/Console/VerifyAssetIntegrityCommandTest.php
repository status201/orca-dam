<?php

use App\Jobs\VerifyAssetIntegrity;
use App\Models\Asset;
use Illuminate\Support\Facades\Queue;

test('assets:verify-integrity dispatches a job per asset', function () {
    Queue::fake();
    Asset::factory()->count(3)->create();

    $this->artisan('assets:verify-integrity')
        ->expectsOutputToContain('Dispatched 3 integrity check(s).')
        ->assertExitCode(0);

    Queue::assertPushed(VerifyAssetIntegrity::class, 3);
});

test('assets:verify-integrity is a no-op when there are no assets', function () {
    Queue::fake();

    $this->artisan('assets:verify-integrity')
        ->expectsOutputToContain('No assets to verify.')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});
