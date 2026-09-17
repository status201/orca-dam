<?php

use App\Services\S3Service;
use Aws\S3\S3Client;

/**
 * Read the private client back off a freshly constructed service — the
 * constructor is the behaviour under test, so setS3Client() can't be used.
 */
function constructedS3Client(): S3Client
{
    $service = app()->makeWith(S3Service::class, []);
    $property = new ReflectionProperty(S3Service::class, 's3Client');
    $property->setAccessible(true);

    return $property->getValue($service);
}

beforeEach(function () {
    config([
        'filesystems.disks.s3.region' => 'eu-west-1',
        'filesystems.disks.s3.bucket' => 'test-bucket',
        'filesystems.disks.s3.key' => 'test',
        'filesystems.disks.s3.secret' => 'test',
        'filesystems.disks.s3.endpoint' => null,
        'filesystems.disks.s3.use_path_style_endpoint' => false,
    ]);
});

test('a configured endpoint points the client at an S3-compatible service', function () {
    config([
        'filesystems.disks.s3.endpoint' => 'http://127.0.0.1:9100',
        'filesystems.disks.s3.use_path_style_endpoint' => true,
    ]);

    $client = constructedS3Client();

    expect((string) $client->getEndpoint())->toBe('http://127.0.0.1:9100');
    expect($client->getConfig('use_path_style_endpoint'))->toBeTrue();
});

test('an endpoint without path style keeps virtual-host addressing', function () {
    config([
        'filesystems.disks.s3.endpoint' => 'https://s3.example.test',
        'filesystems.disks.s3.use_path_style_endpoint' => false,
    ]);

    $client = constructedS3Client();

    expect((string) $client->getEndpoint())->toBe('https://s3.example.test');
    expect($client->getConfig('use_path_style_endpoint'))->toBeFalse();
});

test('no endpoint config leaves AWS addressing untouched', function () {
    $client = constructedS3Client();

    expect((string) $client->getEndpoint())->toContain('amazonaws.com');
    expect($client->getConfig('use_path_style_endpoint'))->toBeFalse();
});

test('an endpoint that accepts the connection and never answers fails in seconds', function () {
    // A listening socket that is never accept()ed: the kernel completes the
    // handshake, so `connect_timeout` is satisfied and nothing ever replies —
    // the shape of an unrelated service sitting on the configured port. Without
    // a response timeout the SDK waits here until PHP's max_execution_time
    // (s3-storage.md REQ-8), which is minutes of a held worker.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) explode(':', stream_socket_get_name($server, false))[1];

    config([
        'filesystems.disks.s3.endpoint' => "http://127.0.0.1:{$port}",
        'filesystems.disks.s3.use_path_style_endpoint' => true,
        // Seconds, not the 120s default, so the assertion below costs a test run
        // a moment rather than a coffee break. Overriding it is also the proof
        // that the bound is configurable.
        'filesystems.disks.s3.timeout' => 1,
        'filesystems.disks.s3.connect_timeout' => 1,
    ]);

    $started = microtime(true);
    $content = app()->makeWith(S3Service::class, [])->getObjectContent('never-answered.png');
    $elapsed = microtime(true) - $started;

    fclose($server);

    // Swallowed and logged, per ADR-010 — the point is that it gets there at all.
    expect($content)->toBeNull();
    expect($elapsed)->toBeLessThan(30.0);
});
