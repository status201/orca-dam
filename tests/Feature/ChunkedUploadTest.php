<?php

use App\Exceptions\DuplicateAssetException;
use App\Models\Asset;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\AssetIngestionService;
use App\Services\ChunkedUploadService;
use App\Services\ImageProcessingService;
use App\Services\S3Service;
use App\Support\ColumnLimits;
use Aws\Command;
use Aws\History;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Mockery\MockInterface;

function makeUploadSession(User $user, array $overrides = []): UploadSession
{
    return UploadSession::create(array_merge([
        'upload_id' => 'fake-upload-id',
        'session_token' => Str::uuid()->toString(),
        'filename' => 'big-photo.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 20 * 1024 * 1024,
        's3_key' => 'assets/big-photo.jpg',
        'chunk_size' => 10 * 1024 * 1024,
        'total_chunks' => 2,
        'uploaded_chunks' => 2,
        'part_etags' => [],
        'status' => 'uploading',
        'user_id' => $user->id,
        'last_activity_at' => now(),
    ], $overrides));
}

/**
 * A real ChunkedUploadService over a real S3Service whose AWS client answers from $handler, with
 * every command it sends recorded in the returned History. The S3Service is a partial mock, so a
 * test can stub single methods (sanitizeStoredSvg, deleteFile) while the multipart calls run for
 * real. The chunked service is bound into the container for controller tests.
 *
 * @return array{0: ChunkedUploadService, 1: History, 2: S3Service&MockInterface}
 */
function chunkedServiceWith(MockHandler $handler): array
{
    config([
        'filesystems.disks.s3.region' => 'us-east-1',
        'filesystems.disks.s3.bucket' => 'test-bucket',
        'filesystems.disks.s3.key' => 'test',
        'filesystems.disks.s3.secret' => 'test',
    ]);

    $history = new History;
    $s3 = Mockery::mock(S3Service::class, [app(ImageProcessingService::class)])->makePartial();
    $s3->setS3Client(fakeS3Client($handler, $history));

    $service = new ChunkedUploadService($s3, new AssetIngestionService($s3));
    app()->instance(ChunkedUploadService::class, $service);

    return [$service, $history, $s3];
}

/** @return list<string> */
function sentCommands(History $history): array
{
    return array_map(fn ($entry) => $entry['command']->getName(), $history->toArray());
}

test('chunked upload complete applies batch metadata to created asset', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);

    $asset = Asset::factory()->create([
        's3_key' => 'assets/big-photo.jpg',
        'mime_type' => 'image/jpeg',
        'user_id' => $user->id,
    ]);

    $chunkedMock = Mockery::mock(ChunkedUploadService::class);
    $chunkedMock->shouldReceive('completeUpload')->once()->andReturn($asset);
    $this->app->instance(ChunkedUploadService::class, $chunkedMock);

    $s3Mock = Mockery::mock(S3Service::class);
    $s3Mock->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Mock->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3Mock);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
        'metadata_tags' => ['Architecture', 'urban'],
        'metadata_license_type' => 'cc_by_nc',
        'metadata_copyright' => '© 2026 Studio',
        'metadata_copyright_source' => 'https://example.com/src',
        'metadata_date_obtained' => '2026-05-01',
    ]);

    $response->assertOk();

    $asset->refresh()->load('tags');
    expect($asset->license_type)->toBe('cc_by_nc');
    expect($asset->copyright)->toBe('© 2026 Studio');
    expect($asset->copyright_source)->toBe('https://example.com/src');
    expect($asset->date_obtained->format('Y-m-d'))->toBe('2026-05-01');

    $tagNames = $asset->tags->pluck('name')->all();
    expect($tagNames)->toContain('architecture');
    expect($tagNames)->toContain('urban');

    foreach ($asset->tags as $tag) {
        expect($tag->type)->toBe('user');
        expect($tag->pivot->attached_by)->toBe('user');
    }
});

test('chunked upload complete works without metadata fields', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);

    $asset = Asset::factory()->create([
        's3_key' => 'assets/big-photo.jpg',
        'mime_type' => 'image/jpeg',
        'user_id' => $user->id,
        'license_type' => null,
        'copyright' => null,
    ]);

    $chunkedMock = Mockery::mock(ChunkedUploadService::class);
    $chunkedMock->shouldReceive('completeUpload')->once()->andReturn($asset);
    $this->app->instance(ChunkedUploadService::class, $chunkedMock);

    $s3Mock = Mockery::mock(S3Service::class);
    $s3Mock->shouldReceive('generateThumbnail')->andReturn(null);
    $s3Mock->shouldReceive('generateResizedImages')->andReturn([]);
    $this->app->instance(S3Service::class, $s3Mock);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
    ]);

    $response->assertOk();

    $asset->refresh();
    expect($asset->license_type)->toBeNull();
    expect($asset->copyright)->toBeNull();
    expect($asset->tags()->count())->toBe(0);
});

test('chunked upload complete rejects invalid license type', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
        'metadata_license_type' => 'bogus',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('metadata_license_type');
});

test('chunked upload complete rejects a metadata_copyright over the column limit', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
        'metadata_copyright' => str_repeat('a', ColumnLimits::for('assets', 'copyright') + 1),
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('metadata_copyright');
});

test('chunked upload complete rejects a metadata_date_obtained that is not a date', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
        'metadata_date_obtained' => 'not-a-date',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('metadata_date_obtained');
});

test('chunked upload complete returns 409 on duplicate', function () {
    $user = User::factory()->create();
    $existing = Asset::factory()->create();
    $session = makeUploadSession($user);

    $chunkedMock = Mockery::mock(ChunkedUploadService::class);
    $chunkedMock->shouldReceive('completeUpload')->once()
        ->andThrow(new DuplicateAssetException($existing));
    $this->app->instance(ChunkedUploadService::class, $chunkedMock);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
    ]);

    $response->assertStatus(409);
    $response->assertJsonFragment(['existing_asset_id' => $existing->id]);
});

test('chunked SVG that fails sanitization is deleted and creates no asset', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user, [
        'filename' => 'drawing.svg',
        'mime_type' => 'image/svg+xml',
        's3_key' => 'assets/drawing.svg',
        'staging_s3_key' => 'chunked-staging/tok.svg',
    ]);

    // completeMultipartUpload succeeds; the post-failure abort is rejected the
    // way real S3 rejects aborting an already-completed upload.
    $handler = new MockHandler;
    $handler->append(new Result(['ETag' => '"raw-etag"']));
    $handler->append(new S3Exception('NoSuchUpload', new Command('AbortMultipartUpload')));

    // Strict expectations: deleting (or writing) anything but the staging key fails the test.
    [$service, $history, $s3Mock] = chunkedServiceWith($handler);
    $s3Mock->shouldReceive('sanitizeStoredSvg')->once()
        ->with('chunked-staging/tok.svg', 'assets/drawing.svg')->andReturnNull();
    $s3Mock->shouldReceive('deleteFile')->once()->with('chunked-staging/tok.svg')->andReturnTrue();

    expect(fn () => $service->completeUpload($session))
        ->toThrow(RuntimeException::class, 'SVG could not be sanitized.');

    expect($history->toArray()[0]['command']['Key'])->toBe('chunked-staging/tok.svg');
    expect(Asset::where('s3_key', 'assets/drawing.svg')->exists())->toBeFalse();
    expect($session->fresh()->status)->toBe('failed');
});

test('an overwrite-mode SVG that fails sanitization keeps the existing original and row', function () {
    $user = User::factory()->create();
    $existing = Asset::factory()->create([
        's3_key' => 'assets/logo.svg',
        'filename' => 'logo.svg',
        'mime_type' => 'image/svg+xml',
        'etag' => 'original-etag',
    ]);
    $session = makeUploadSession($user, [
        'filename' => 'logo.svg',
        'mime_type' => 'image/svg+xml',
        's3_key' => 'assets/logo.svg',
        'staging_s3_key' => 'chunked-staging/tok.svg',
    ]);

    $handler = new MockHandler;
    $handler->append(new Result(['ETag' => '"raw-etag"']));
    $handler->append(new S3Exception('NoSuchUpload', new Command('AbortMultipartUpload')));

    // deleteFile() is expected for the staging key only, so a delete of assets/logo.svg fails the
    // test, and the overwrite branch's derived-file cleanup must never be reached.
    [$service, , $s3Mock] = chunkedServiceWith($handler);
    $s3Mock->shouldReceive('sanitizeStoredSvg')->once()
        ->with('chunked-staging/tok.svg', 'assets/logo.svg')->andReturnNull();
    $s3Mock->shouldReceive('deleteFile')->once()->with('chunked-staging/tok.svg')->andReturnTrue();
    $s3Mock->shouldNotReceive('deleteAssetFiles');

    expect(fn () => $service->completeUpload($session))
        ->toThrow(RuntimeException::class, 'SVG could not be sanitized.');

    $existing->refresh();
    expect($existing->etag)->toBe('original-etag')
        ->and($existing->s3_key)->toBe('assets/logo.svg')
        ->and($existing->trashed())->toBeFalse()
        ->and($session->fresh()->status)->toBe('failed');
});

test('a session from before staging keys still sanitizes its SVG in place', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user, [
        'filename' => 'drawing.svg',
        'mime_type' => 'image/svg+xml',
        's3_key' => 'assets/drawing.svg',
        'staging_s3_key' => null,
    ]);

    $handler = new MockHandler;
    $handler->append(new Result(['ETag' => '"raw-etag"']));
    $handler->append(new S3Exception('NoSuchUpload', new Command('AbortMultipartUpload')));

    [$service, $history, $s3Mock] = chunkedServiceWith($handler);
    $s3Mock->shouldReceive('sanitizeStoredSvg')->once()
        ->with('assets/drawing.svg', 'assets/drawing.svg')->andReturnNull();
    $s3Mock->shouldReceive('deleteFile')->once()->with('assets/drawing.svg')->andReturnTrue();

    expect(fn () => $service->completeUpload($session))
        ->toThrow(RuntimeException::class, 'SVG could not be sanitized.');

    expect($history->toArray()[0]['command']['Key'])->toBe('assets/drawing.svg');
    expect($session->fresh()->status)->toBe('failed');
});

test('completing a chunked SVG writes the sanitized content to the final key and drops staging', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user, [
        'filename' => 'drawing.svg',
        'mime_type' => 'image/svg+xml',
        's3_key' => 'assets/drawing.svg',
        'staging_s3_key' => 'chunked-staging/tok.svg',
        'part_etags' => [['PartNumber' => 2, 'ETag' => 'e2'], ['PartNumber' => 1, 'ETag' => 'e1']],
    ]);

    // Only CompleteMultipartUpload reaches S3: an SVG has no raster dimensions, so nothing is
    // downloaded to size it.
    [$service, $history, $s3Mock] = chunkedServiceWith(new MockHandler([new Result(['ETag' => '"raw-etag"'])]));
    $s3Mock->shouldReceive('sanitizeStoredSvg')->once()
        ->with('chunked-staging/tok.svg', 'assets/drawing.svg')->andReturn('clean-etag');
    $s3Mock->shouldReceive('deleteFile')->once()->with('chunked-staging/tok.svg')->andReturnTrue();

    $asset = $service->completeUpload($session);

    $complete = $history->getLastCommand();
    expect(sentCommands($history))->toBe(['CompleteMultipartUpload'])
        ->and($complete['Key'])->toBe('chunked-staging/tok.svg')
        ->and(array_column($complete['MultipartUpload']['Parts'], 'PartNumber'))->toBe([1, 2])
        ->and($asset->s3_key)->toBe('assets/drawing.svg')
        ->and($asset->width)->toBeNull()
        ->and($asset->etag)->toBe('clean-etag')
        ->and($session->fresh()->status)->toBe('completed');
});

test('chunked upload init opens an SVG multipart on a staging key', function () {
    $user = User::factory()->create();
    [$service, $history] = chunkedServiceWith(new MockHandler([new Result(['UploadId' => 'up-svg'])]));

    $response = $this->actingAs($user)->postJson(route('chunked-upload.init'), [
        'filename' => 'drawing.svg',
        'mime_type' => 'image/svg+xml',
        'file_size' => 12 * 1024 * 1024,
        'folder' => 'assets',
        'keep_original_filename' => true,
    ]);

    $response->assertCreated();
    $session = UploadSession::where('session_token', $response->json('session_token'))->firstOrFail();

    expect($session->s3_key)->toBe('assets/drawing.svg')
        ->and($session->staging_s3_key)->toBe(S3Service::CHUNKED_STAGING_PREFIX.$session->session_token.'.svg')
        ->and($history->getLastCommand()['Key'])->toBe($session->staging_s3_key);
});

// ─── Session lifecycle: initiate / chunk / abort, ownership, REQ-2 / REQ-6 ────

test('chunked upload init opens a multipart upload and stores a pending session', function () {
    $user = User::factory()->create();
    [$service, $history] = chunkedServiceWith(new MockHandler([new Result(['UploadId' => 'up-1'])]));

    $response = $this->actingAs($user)->postJson(route('chunked-upload.init'), [
        'filename' => 'archive.zip',
        'mime_type' => 'application/zip',
        'file_size' => 25 * 1024 * 1024,
        'folder' => 'assets',
    ]);

    $response->assertCreated()
        ->assertJsonPath('upload_id', 'up-1')
        ->assertJsonPath('chunk_size', 10 * 1024 * 1024)
        ->assertJsonPath('total_chunks', 3);

    $session = UploadSession::where('session_token', $response->json('session_token'))->firstOrFail();
    expect($session->user_id)->toBe($user->id)
        ->and($session->status)->toBe('pending')
        ->and($session->s3_key)->toMatch('#^assets/[0-9a-f-]{36}\.zip$#');

    $params = $history->getLastCommand()->toArray();
    expect(sentCommands($history))->toBe(['CreateMultipartUpload'])
        ->and($params['Key'])->toBe($session->s3_key)
        ->and($params['ContentType'])->toBe('application/zip')
        ->and($params['ContentDisposition'])->toBe('attachment');
});

test('chunked upload init types the object by its extension, not the declared mime_type', function () {
    // Regression: init stored the client's mime_type, so photo.jpg declared as text/html was
    // served inline as an HTML page from the asset domain — stored XSS for any uploader.
    $user = User::factory()->create();
    [, $history] = chunkedServiceWith(new MockHandler([new Result(['UploadId' => 'up-xss'])]));

    $response = $this->actingAs($user)->postJson(route('chunked-upload.init'), [
        'filename' => 'photo.jpg',
        'mime_type' => 'text/html',
        'file_size' => 12 * 1024 * 1024,
        'folder' => 'assets',
    ]);

    $response->assertCreated();
    $params = $history->getLastCommand()->toArray();
    expect($params['ContentType'])->toBe('image/jpeg')
        ->and($params)->not->toHaveKey('ContentDisposition')
        ->and(UploadSession::where('session_token', $response->json('session_token'))->value('mime_type'))
        ->toBe('image/jpeg');
});

test('chunked upload init accepts a missing mime_type, as browsers send for some extensions', function () {
    $user = User::factory()->create();
    [, $history] = chunkedServiceWith(new MockHandler([new Result(['UploadId' => 'up-eps'])]));

    $this->actingAs($user)->postJson(route('chunked-upload.init'), [
        'filename' => 'poster.eps',
        'mime_type' => '',
        'file_size' => 12 * 1024 * 1024,
        'folder' => 'assets',
    ])->assertCreated();

    $params = $history->getLastCommand()->toArray();
    expect($params['ContentType'])->toBe('application/postscript')
        ->and($params['ContentDisposition'])->toBe('attachment');
});

test('chunked upload init with keep_original_filename keys the session by the original name', function () {
    $user = User::factory()->create();
    [$service] = chunkedServiceWith(new MockHandler([new Result(['UploadId' => 'up-2'])]));

    $response = $this->actingAs($user)->postJson(route('chunked-upload.init'), [
        'filename' => 'Annual Report.zip',
        'mime_type' => 'application/zip',
        'file_size' => 12 * 1024 * 1024,
        'folder' => 'assets',
        'keep_original_filename' => true,
    ]);

    $response->assertCreated();
    $session = UploadSession::where('session_token', $response->json('session_token'))->firstOrFail();
    expect($session->s3_key)->toBe('assets/'.S3Service::sanitizeFilename('Annual Report.zip'));
});

test('uploading a chunk records its part and progress', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user, ['uploaded_chunks' => 0, 'status' => 'pending']);
    [$service, $history] = chunkedServiceWith(new MockHandler([new Result(['ETag' => '"etag-1"'])]));

    $response = $this->actingAs($user)->postJson(route('chunked-upload.chunk'), [
        'session_token' => $session->session_token,
        'chunk_number' => 1,
        'chunk' => UploadedFile::fake()->create('chunk.bin', 64),
    ]);

    $response->assertOk()
        ->assertJsonPath('part_number', 1)
        ->assertJsonPath('etag', 'etag-1')
        ->assertJsonPath('uploaded_chunks', 1)
        ->assertJsonPath('total_chunks', 2);

    $session->refresh();
    expect($session->part_etags)->toBe([['PartNumber' => 1, 'ETag' => 'etag-1']])
        ->and($session->uploaded_chunks)->toBe(1)
        ->and($session->status)->toBe('uploading');

    $params = $history->getLastCommand()->toArray();
    expect(sentCommands($history))->toBe(['UploadPart'])
        ->and($params['PartNumber'])->toBe(1)
        ->and($params['UploadId'])->toBe('fake-upload-id');
});

test('re-sending a recorded chunk is a no-op that sends nothing to S3', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user, [
        'uploaded_chunks' => 1,
        'part_etags' => [['PartNumber' => 1, 'ETag' => 'etag-1']],
    ]);
    // Empty queue: any S3 call would throw.
    [$service, $history] = chunkedServiceWith(new MockHandler);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.chunk'), [
        'session_token' => $session->session_token,
        'chunk_number' => 1,
        'chunk' => UploadedFile::fake()->create('chunk.bin', 64),
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Chunk already uploaded')
        ->assertJsonPath('uploaded_chunks', 1)
        ->assertJsonPath('total_chunks', 2);

    expect(sentCommands($history))->toBe([])
        ->and($session->fresh()->part_etags)->toHaveCount(1);
});

test('chunks recorded through stale copies of a session are merged, not lost', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user, ['uploaded_chunks' => 0, 'part_etags' => null, 'status' => 'pending']);

    // All three copies are loaded before any chunk lands, as parallel requests would load them.
    [$first, $second, $third] = [$session->fresh(), $session->fresh(), $session->fresh()];

    [$service] = chunkedServiceWith(new MockHandler([
        new Result(['ETag' => '"etag-1"']),
        new Result(['ETag' => '"etag-2"']),
        new Result(['ETag' => '"etag-1"']),
    ]));
    $chunk = UploadedFile::fake()->create('chunk.bin', 64);

    $service->uploadChunk($first, $chunk, 1);
    $service->uploadChunk($second, $chunk, 2);   // must not drop part 1
    $service->uploadChunk($third, $chunk, 1);    // a concurrent duplicate: replace, don't append

    $session->refresh();
    expect($session->part_etags)->toBe([
        ['PartNumber' => 1, 'ETag' => 'etag-1'],
        ['PartNumber' => 2, 'ETag' => 'etag-2'],
    ])->and($session->uploaded_chunks)->toBe(2);
});

test('a chunk number beyond total_chunks is a 422 and sends nothing to S3', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user, ['uploaded_chunks' => 0]);
    [$service, $history] = chunkedServiceWith(new MockHandler);

    $response = $this->actingAs($user)->postJson(route('chunked-upload.chunk'), [
        'session_token' => $session->session_token,
        'chunk_number' => 3,
        'chunk' => UploadedFile::fake()->create('chunk.bin', 64),
    ]);

    $response->assertStatus(422);
    expect(sentCommands($history))->toBe([]);
});

test('another user\'s session token is a 404 on chunk, complete and abort', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $session = makeUploadSession($owner);
    [$service, $history] = chunkedServiceWith(new MockHandler);

    $this->actingAs($intruder)->postJson(route('chunked-upload.chunk'), [
        'session_token' => $session->session_token,
        'chunk_number' => 1,
        'chunk' => UploadedFile::fake()->create('chunk.bin', 64),
    ])->assertNotFound();

    $this->actingAs($intruder)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
    ])->assertNotFound();

    $this->actingAs($intruder)->postJson(route('chunked-upload.abort'), [
        'session_token' => $session->session_token,
    ])->assertNotFound();

    expect(sentCommands($history))->toBe([])
        ->and($session->fresh()->status)->toBe('uploading');
});

test('an unknown session token gets the same 404', function () {
    $user = User::factory()->create();
    [$service] = chunkedServiceWith(new MockHandler);

    $this->actingAs($user)->postJson(route('chunked-upload.abort'), [
        'session_token' => (string) Str::uuid(),
    ])->assertNotFound()->assertJsonPath('message', 'Upload session not found.');
});

test('aborting a session aborts the S3 multipart upload', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);
    [$service, $history] = chunkedServiceWith(new MockHandler([new Result([])]));

    $this->actingAs($user)->postJson(route('chunked-upload.abort'), [
        'session_token' => $session->session_token,
    ])->assertOk();

    $params = $history->getLastCommand()->toArray();
    expect(sentCommands($history))->toBe(['AbortMultipartUpload'])
        ->and($params['Key'])->toBe('assets/big-photo.jpg')
        ->and($params['UploadId'])->toBe('fake-upload-id')
        ->and($session->fresh()->status)->toBe('aborted');
});

test('a failed completion aborts the multipart upload', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user, [
        'part_etags' => [['PartNumber' => 1, 'ETag' => 'e1'], ['PartNumber' => 2, 'ETag' => 'e2']],
    ]);
    [$service, $history] = chunkedServiceWith(new MockHandler([
        new S3Exception('InvalidPart', new Command('CompleteMultipartUpload')),
        new Result([]),
    ]));

    $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
    ])->assertStatus(500);

    $params = $history->getLastCommand()->toArray();
    expect(sentCommands($history))->toBe(['CompleteMultipartUpload', 'AbortMultipartUpload'])
        ->and($params['Key'])->toBe('assets/big-photo.jpg')
        ->and($params['UploadId'])->toBe('fake-upload-id')
        ->and($session->fresh()->status)->toBe('aborted')
        ->and(Asset::where('s3_key', 'assets/big-photo.jpg')->exists())->toBeFalse();
});

test('completing a session with missing chunks leaves it resumable', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user, [
        'uploaded_chunks' => 1,
        'part_etags' => [['PartNumber' => 1, 'ETag' => 'e1']],
    ]);
    [$service, $history] = chunkedServiceWith(new MockHandler);

    $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
    ])->assertStatus(500);

    expect(sentCommands($history))->toBe([])
        ->and($session->fresh()->status)->toBe('uploading');
});
