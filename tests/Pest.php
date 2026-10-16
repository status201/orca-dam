<?php

use Aws\History;
use Aws\Middleware;
use Aws\MockHandler;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit', 'Security');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * An S3Client that answers from $handler (FIFO) instead of the network. Pass a History to record
 * every command it sends. An exhausted MockHandler throws, so an empty one asserts "no S3 call".
 */
function fakeS3Client(MockHandler $handler, ?History $history = null): S3Client
{
    $client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => $handler,
    ]);

    if ($history !== null) {
        $client->getHandlerList()->appendSign(Middleware::history($history));
    }

    return $client;
}
