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
        'filesystems.disks.s3.endpoint' => 'http://127.0.0.1:9000',
        'filesystems.disks.s3.use_path_style_endpoint' => true,
    ]);

    $client = constructedS3Client();

    expect((string) $client->getEndpoint())->toBe('http://127.0.0.1:9000');
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
