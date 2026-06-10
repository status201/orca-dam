<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Services\S3Service;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;

/**
 * Build an S3Service whose underlying AWS client returns the given results
 * (FIFO, one per listObjectsV2 call) via the SDK's MockHandler.
 */
function s3ServiceReturning(array $results): S3Service
{
    $handler = new MockHandler;
    foreach ($results as $result) {
        $handler->append(new Result($result));
    }

    $client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => $handler,
    ]);

    return app(S3Service::class)->setS3Client($client);
}

beforeEach(function () {
    // Keep S3Service construction valid regardless of the machine's .env.
    config([
        'filesystems.disks.s3.region' => 'us-east-1',
        'filesystems.disks.s3.bucket' => 'test-bucket',
        'filesystems.disks.s3.key' => 'test',
        'filesystems.disks.s3.secret' => 'test',
    ]);
});

test('findUnmappedObjects paginates through every page of results', function () {
    $service = s3ServiceReturning([
        // Page 1 — root-level files, more results to come.
        [
            'Contents' => [
                ['Key' => 'assets/root-1.jpg', 'Size' => 100, 'LastModified' => '2026-01-01'],
                ['Key' => 'assets/root-2.jpg', 'Size' => 100, 'LastModified' => '2026-01-01'],
            ],
            'IsTruncated' => true,
            'NextContinuationToken' => 'PAGE2',
        ],
        // Page 2 — the subfolder object that the old single-page code dropped.
        [
            'Contents' => [
                ['Key' => 'assets/marketing/sub-1.jpg', 'Size' => 100, 'LastModified' => '2026-01-02'],
            ],
            'IsTruncated' => false,
        ],
    ]);

    $keys = collect($service->findUnmappedObjects('assets/'))->pluck('key')->all();

    expect($keys)->toContain('assets/root-1.jpg')
        ->and($keys)->toContain('assets/marketing/sub-1.jpg')
        ->and($keys)->toHaveCount(3);
});

test('listObjects with a maxKeys cap issues a single bounded request (no pagination)', function () {
    // Only one page is queued. The result is marked truncated, so if the code
    // tried to follow the continuation token the MockHandler queue would be
    // empty and throw — proving the bounded probe stays a single request.
    $service = s3ServiceReturning([
        [
            'Contents' => [
                ['Key' => 'assets/a.jpg', 'Size' => 1, 'LastModified' => '2026-01-01'],
            ],
            'IsTruncated' => true,
            'NextContinuationToken' => 'SHOULD-NOT-BE-FOLLOWED',
        ],
    ]);

    expect($service->listObjects('', 1))->toHaveCount(1);
});

test('findUnmappedObjects filters out already-mapped, thumbnail and zero-byte keys', function () {
    Asset::factory()->image()->create(['s3_key' => 'assets/mapped.jpg']);

    $service = s3ServiceReturning([
        [
            'Contents' => [
                ['Key' => 'assets/mapped.jpg', 'Size' => 100, 'LastModified' => '2026-01-01'],
                ['Key' => 'thumbnails/assets/x_thumb.jpg', 'Size' => 100, 'LastModified' => '2026-01-01'],
                ['Key' => 'assets/empty/', 'Size' => 0, 'LastModified' => '2026-01-01'],
                ['Key' => 'assets/keep.jpg', 'Size' => 100, 'LastModified' => '2026-01-01'],
            ],
            'IsTruncated' => false,
        ],
    ]);

    $keys = collect($service->findUnmappedObjects('assets/'))->pluck('key')->all();

    expect($keys)->toBe(['assets/keep.jpg']);
});

test('sanitizeSvg strips script tags and event handlers', function () {
    $service = app(S3Service::class);

    $dirty = '<svg xmlns="http://www.w3.org/2000/svg" onload="evil()">'
        .'<script>alert(document.cookie)</script>'
        .'<rect x="0" y="0" width="10" height="10" onclick="steal()"/>'
        .'</svg>';

    $clean = $service->sanitizeSvg($dirty);

    expect($clean)->not->toContain('<script');
    expect($clean)->not->toContain('alert(');
    expect(strtolower($clean))->not->toContain('onload');
    expect(strtolower($clean))->not->toContain('onclick');
    expect($clean)->toContain('<svg');
});

test('sanitizeSvg preserves benign vector markup', function () {
    $service = app(S3Service::class);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
        .'<rect x="0" y="0" width="10" height="10" fill="#1B4D8E"/></svg>';

    $clean = $service->sanitizeSvg($svg);

    expect($clean)->toContain('<rect');
    expect($clean)->toContain('#1B4D8E');
});

test('listFolders paginates common prefixes across pages', function () {
    Setting::set('s3_root_folder', 'assets', 'string', 'aws');

    // Call order (FIFO): level page 1, recurse into alpha, level page 2, recurse into marketing.
    $service = s3ServiceReturning([
        [
            'CommonPrefixes' => [['Prefix' => 'assets/alpha/']],
            'IsTruncated' => true,
            'NextContinuationToken' => 'PAGE2',
        ],
        ['CommonPrefixes' => [], 'IsTruncated' => false],   // recursion into assets/alpha/
        [
            'CommonPrefixes' => [['Prefix' => 'assets/marketing/']],
            'IsTruncated' => false,
        ],
        ['CommonPrefixes' => [], 'IsTruncated' => false],   // recursion into assets/marketing/
    ]);

    $folders = $service->listFolders();

    // 'assets/marketing' is only reachable via page 2 — its presence proves pagination.
    expect($folders)->toContain('assets/alpha')
        ->and($folders)->toContain('assets/marketing')
        ->and($folders)->toContain('assets');
});
