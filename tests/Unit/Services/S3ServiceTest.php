<?php

use App\Models\Asset;
use App\Models\Setting;
use App\Services\S3Service;
use Aws\Command;
use Aws\History;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;

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

    return app(S3Service::class)->setS3Client(fakeS3Client($handler));
}

/**
 * Build an S3Service whose AWS client answers every request with this raw HTTP
 * response. Unlike a MockHandler Result, it goes through the SDK's real response
 * parsers, so the PHP types are the ones S3 actually produces.
 */
function s3ServiceRespondingWith(Response $response): S3Service
{
    $client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'http_handler' => fn () => Create::promiseFor($response),
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

test('listObjects returns an integer size, though the SDK parses ListObjectsV2 Size as a string', function () {
    // The SDK's XML parser has no 'long' mapping, so <Size> arrives as "512".
    $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
            <Name>test-bucket</Name>
            <Prefix>assets/</Prefix>
            <KeyCount>1</KeyCount>
            <MaxKeys>1000</MaxKeys>
            <IsTruncated>false</IsTruncated>
            <Contents>
                <Key>assets/tiny.svg</Key>
                <LastModified>2026-01-01T00:00:00.000Z</LastModified>
                <ETag>"abc"</ETag>
                <Size>512</Size>
                <StorageClass>STANDARD</StorageClass>
            </Contents>
        </ListBucketResult>
        XML;

    $objects = s3ServiceRespondingWith(new Response(200, [], $xml))->listObjects('assets/');

    expect($objects)->toHaveCount(1)
        ->and($objects[0]['size'])->toBe(512);
});

test('getObjectMetadata returns an integer size from the HeadObject Content-Length header', function () {
    // Header-bound longs are cast by the SDK's REST parser, unlike <Size> in a
    // listing body — this pins that the import path's size stays an integer.
    $metadata = s3ServiceRespondingWith(new Response(200, [
        'Content-Length' => '512',
        'Content-Type' => 'image/svg+xml',
        'ETag' => '"abc"',
        'Last-Modified' => 'Thu, 01 Jan 2026 00:00:00 GMT',
    ]))->getObjectMetadata('assets/tiny.svg');

    expect($metadata['size'])->toBe(512)
        ->and($metadata['mime_type'])->toBe('image/svg+xml')
        ->and($metadata['etag'])->toBe('abc');
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

test('sanitizeSvg throws for an SVG that declares a custom DTD entity', function () {
    $service = app(S3Service::class);

    // The entity expands to a script element once a browser resolves it.
    $svg = '<?xml version="1.0"?>'
        .'<!DOCTYPE svg [<!ENTITY payload "&#60;script&#62;alert(1)&#60;/script&#62;">]>'
        .'<svg xmlns="http://www.w3.org/2000/svg">&payload;<rect width="1" height="1"/></svg>';

    expect(fn () => $service->sanitizeSvg($svg))
        ->toThrow(RuntimeException::class, 'SVG could not be sanitized.');
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

test('findUnmappedObjects never reports a key under the chunked-staging prefix', function () {
    $service = s3ServiceReturning([
        [
            'Contents' => [
                ['Key' => 'chunked-staging/0b5c-left-behind.svg', 'Size' => 100, 'LastModified' => '2026-01-01'],
                ['Key' => 'logo.svg', 'Size' => 100, 'LastModified' => '2026-01-01'],
            ],
            'IsTruncated' => false,
        ],
    ]);

    // Bucket-root scan: the case where the staging prefix is inside the scanned range.
    $keys = collect($service->findUnmappedObjects(''))->pluck('key')->all();

    expect($keys)->toBe(['logo.svg']);
});

test('listFolders never lists the chunked-staging prefix', function () {
    Setting::set('s3_root_folder', '', 'string', 'aws');

    // Only marketing/ is recursed into; a recursion into chunked-staging/ would hit the empty queue.
    $service = s3ServiceReturning([
        [
            'CommonPrefixes' => [
                ['Prefix' => 'chunked-staging/'],
                ['Prefix' => 'marketing/'],
                ['Prefix' => 'thumbnails/'],
            ],
            'IsTruncated' => false,
        ],
        ['CommonPrefixes' => [], 'IsTruncated' => false],   // recursion into marketing/
    ]);

    expect($service->listFolders())->toBe(['marketing']);
});

test('sanitizeStoredSvg reads the source key and writes the sanitized markup to the destination', function () {
    $handler = new MockHandler;
    $handler->append(new Result([
        'Body' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>',
    ]));
    $handler->append(new Result(['ETag' => '"clean-etag"']));

    $history = new History;
    $service = app(S3Service::class)->setS3Client(fakeS3Client($handler, $history));

    $etag = $service->sanitizeStoredSvg('chunked-staging/tok.svg', 'assets/logo.svg');

    [$get, $put] = array_map(fn ($entry) => $entry['command'], $history->toArray());
    expect($etag)->toBe('clean-etag')
        ->and($get->getName())->toBe('GetObject')
        ->and($get['Key'])->toBe('chunked-staging/tok.svg')
        ->and($put->getName())->toBe('PutObject')
        ->and($put['Key'])->toBe('assets/logo.svg')
        ->and((string) $put['Body'])->not->toContain('<script')
        ->and((string) $put['Body'])->toContain('<rect');
});

test('extractImageDimensions streams a stored image and sizes it from its header', function () {
    $img = imagecreatetruecolor(24, 12);
    ob_start();
    imagejpeg($img);
    $jpeg = ob_get_clean();
    imagedestroy($img);

    $service = s3ServiceReturning([['Body' => $jpeg]]);

    expect($service->extractImageDimensions('assets/photo.jpg', 'image/jpeg'))
        ->toBe(['width' => 24, 'height' => 12]);
});

test('extractImageDimensions skips non-images and SVGs without downloading them', function () {
    $history = new History;
    $service = app(S3Service::class)->setS3Client(fakeS3Client(new MockHandler, $history));

    expect($service->extractImageDimensions('assets/clip.mp4', 'video/mp4'))->toBeNull()
        ->and($service->extractImageDimensions('assets/logo.svg', 'image/svg+xml'))->toBeNull()
        ->and($history)->toHaveCount(0);
});

test('completeMultipartUpload sends the parts in ascending PartNumber order', function () {
    $handler = new MockHandler([new Result(['ETag' => '"whole"'])]);
    $history = new History;
    $service = app(S3Service::class)->setS3Client(fakeS3Client($handler, $history));

    $etag = $service->completeMultipartUpload('assets/big.zip', 'up-1', [
        ['PartNumber' => 3, 'ETag' => 'c'],
        ['PartNumber' => 1, 'ETag' => 'a'],
        ['PartNumber' => 2, 'ETag' => 'b'],
    ]);

    $parts = $history->getLastCommand()['MultipartUpload']['Parts'];
    expect($etag)->toBe('whole')
        ->and(array_column($parts, 'PartNumber'))->toBe([1, 2, 3]);
});

test('uploadFile types the object by its extension, not by sniffing its bytes', function () {
    // Regression: the direct path stored $file->getMimeType(), sniffed from bytes the uploader
    // controls, so an HTML page saved as pixel.png went up as text/html and, png being an inline
    // type, was served as a page from the asset domain.
    $history = new History;
    $service = app(S3Service::class)
        ->setS3Client(fakeS3Client(new MockHandler([new Result(['ETag' => '"e"'])]), $history));

    $path = tempnam(sys_get_temp_dir(), 'html_');
    file_put_contents($path, '<!doctype html><html><body><script>alert(document.domain)</script></body></html>');
    $file = new UploadedFile($path, 'pixel.png', 'text/html', null, true);
    expect($file->getMimeType())->toBe('text/html');   // what used to be stored

    $service->uploadFile($file, 'assets');

    $put = $history->getLastCommand();
    expect($put->getName())->toBe('PutObject')
        ->and($put['ContentType'])->toBe('image/png')
        ->and($put->hasParam('ContentDisposition'))->toBeFalse();

    @unlink($path);
});

test('uploadFile forces a non-inline type to download', function () {
    $history = new History;
    $service = app(S3Service::class)
        ->setS3Client(fakeS3Client(new MockHandler([new Result(['ETag' => '"e"'])]), $history));

    $service->uploadFile(UploadedFile::fake()->createWithContent('notes.txt', 'plain'), 'assets');

    $put = $history->getLastCommand();
    expect($put['ContentType'])->toBe('text/plain')
        ->and($put['ContentDisposition'])->toBe('attachment');
});

test('getObjectHeaders reads each object\'s served headers and maps a missing one to null', function () {
    $handler = new MockHandler([
        new Result(['ContentType' => 'text/html', 'ContentDisposition' => null]),
        new S3Exception('Not Found', new Command('HeadObject'), [
            'response' => new Response(404),
        ]),
    ]);
    $service = app(S3Service::class)->setS3Client(fakeS3Client($handler));

    // Concurrency 1 keeps the MockHandler's FIFO answers lined up with the keys.
    expect($service->getObjectHeaders(['assets/photo.jpg', 'assets/gone.zip'], concurrency: 1))->toBe([
        'assets/photo.jpg' => ['content_type' => 'text/html', 'content_disposition' => null],
        'assets/gone.zip' => null,
    ]);
});

test('repairUploadHeaders copies the object onto itself with the headers an upload sets today', function () {
    $history = new History;
    $handler = new MockHandler([new Result(['CopyObjectResult' => ['ETag' => '"plainmd5"']])]);
    $service = app(S3Service::class)->setS3Client(fakeS3Client($handler, $history));

    expect($service->repairUploadHeaders('assets/notes.txt'))->toBe('plainmd5');

    $copy = $history->getLastCommand();
    expect($copy->getName())->toBe('CopyObject')
        ->and($copy['Key'])->toBe('assets/notes.txt')
        ->and($copy['CopySource'])->toBe('test-bucket/assets/notes.txt')
        ->and($copy['MetadataDirective'])->toBe('REPLACE')
        ->and($copy['ContentType'])->toBe('text/plain')
        ->and($copy['ContentDisposition'])->toBe('attachment');
});
