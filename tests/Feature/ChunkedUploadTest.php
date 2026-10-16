<?php

use App\Exceptions\DuplicateAssetException;
use App\Models\Asset;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\ChunkedUploadService;
use App\Services\S3Service;
use App\Support\ColumnLimits;
use Aws\Command;
use Aws\History;
use Aws\Middleware;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

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
 * A real ChunkedUploadService whose AWS client answers from $handler (FIFO), with every command
 * it sends recorded in the returned History. An exhausted MockHandler queue throws, so an empty
 * one doubles as an assertion that no S3 call is made.
 *
 * @return array{0: ChunkedUploadService, 1: History}
 */
function chunkedServiceWith(MockHandler $handler, ?S3Service $s3 = null): array
{
    config([
        'filesystems.disks.s3.region' => 'us-east-1',
        'filesystems.disks.s3.bucket' => 'test-bucket',
        'filesystems.disks.s3.key' => 'test',
        'filesystems.disks.s3.secret' => 'test',
    ]);

    $client = new S3Client([
        'version' => 'latest',
        'region' => 'us-east-1',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => $handler,
    ]);
    $history = new History;
    $client->getHandlerList()->appendSign(Middleware::history($history));

    $service = new ChunkedUploadService($s3 ?? Mockery::mock(S3Service::class));
    (new ReflectionProperty(ChunkedUploadService::class, 's3Client'))->setValue($service, $client);

    return [$service, $history];
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
    $s3Mock = Mockery::mock(S3Service::class);
    $s3Mock->shouldReceive('sanitizeStoredSvg')->once()
        ->with('chunked-staging/tok.svg', 'assets/drawing.svg')->andReturnNull();
    $s3Mock->shouldReceive('deleteFile')->once()->with('chunked-staging/tok.svg')->andReturnTrue();

    [$service, $history] = chunkedServiceWith($handler, $s3Mock);

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

    // No deleteAssetFiles() and no deleteFile('assets/logo.svg') expectation: either call fails.
    $s3Mock = Mockery::mock(S3Service::class);
    $s3Mock->shouldReceive('sanitizeStoredSvg')->once()
        ->with('chunked-staging/tok.svg', 'assets/logo.svg')->andReturnNull();
    $s3Mock->shouldReceive('deleteFile')->once()->with('chunked-staging/tok.svg')->andReturnTrue();

    [$service] = chunkedServiceWith($handler, $s3Mock);

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

    $s3Mock = Mockery::mock(S3Service::class);
    $s3Mock->shouldReceive('sanitizeStoredSvg')->once()
        ->with('assets/drawing.svg', 'assets/drawing.svg')->andReturnNull();
    $s3Mock->shouldReceive('deleteFile')->once()->with('assets/drawing.svg')->andReturnTrue();

    [$service, $history] = chunkedServiceWith($handler, $s3Mock);

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

    $handler = new MockHandler;
    $handler->append(new Result(['ETag' => '"raw-etag"']));                    // CompleteMultipartUpload
    $handler->append(new Result(['Body' => '<svg xmlns="http://www.w3.org/2000/svg"/>'])); // dimensions read

    $s3Mock = Mockery::mock(S3Service::class);
    $s3Mock->shouldReceive('sanitizeStoredSvg')->once()
        ->with('chunked-staging/tok.svg', 'assets/drawing.svg')->andReturn('clean-etag');
    $s3Mock->shouldReceive('deleteFile')->once()->with('chunked-staging/tok.svg')->andReturnTrue();

    [$service, $history] = chunkedServiceWith($handler, $s3Mock);

    $asset = $service->completeUpload($session);

    [$complete, $read] = array_map(fn ($entry) => $entry['command'], $history->toArray());
    expect($complete->getName())->toBe('CompleteMultipartUpload')
        ->and($complete['Key'])->toBe('chunked-staging/tok.svg')
        ->and(array_column($complete['MultipartUpload']['Parts'], 'PartNumber'))->toBe([1, 2])
        ->and($read['Key'])->toBe('assets/drawing.svg')
        ->and($asset->s3_key)->toBe('assets/drawing.svg')
        ->and($asset->etag)->toBe('clean-etag')
        ->and($session->fresh()->status)->toBe('completed');
});

test('chunked upload init opens an SVG multipart on a staging key', function () {
    $user = User::factory()->create();
    [$service, $history] = chunkedServiceWith(new MockHandler([new Result(['UploadId' => 'up-svg'])]));
    $this->app->instance(ChunkedUploadService::class, $service);

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
        ->and($session->staging_s3_key)->toBe(ChunkedUploadService::STAGING_PREFIX.$session->session_token.'.svg')
        ->and($history->getLastCommand()['Key'])->toBe($session->staging_s3_key);
});

// ─── Session lifecycle: initiate / chunk / abort, ownership, REQ-2 / REQ-6 ────

test('chunked upload init opens a multipart upload and stores a pending session', function () {
    $user = User::factory()->create();
    [$service, $history] = chunkedServiceWith(new MockHandler([new Result(['UploadId' => 'up-1'])]));
    $this->app->instance(ChunkedUploadService::class, $service);

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
        ->and($params['ContentDisposition'])->toBe('attachment');
});

test('chunked upload init with keep_original_filename keys the session by the original name', function () {
    $user = User::factory()->create();
    [$service] = chunkedServiceWith(new MockHandler([new Result(['UploadId' => 'up-2'])]));
    $this->app->instance(ChunkedUploadService::class, $service);

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
    $this->app->instance(ChunkedUploadService::class, $service);

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
    $this->app->instance(ChunkedUploadService::class, $service);

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
    $this->app->instance(ChunkedUploadService::class, $service);

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
    $this->app->instance(ChunkedUploadService::class, $service);

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
    $this->app->instance(ChunkedUploadService::class, $service);

    $this->actingAs($user)->postJson(route('chunked-upload.abort'), [
        'session_token' => (string) Str::uuid(),
    ])->assertNotFound()->assertJsonPath('message', 'Upload session not found.');
});

test('aborting a session aborts the S3 multipart upload', function () {
    $user = User::factory()->create();
    $session = makeUploadSession($user);
    [$service, $history] = chunkedServiceWith(new MockHandler([new Result([])]));
    $this->app->instance(ChunkedUploadService::class, $service);

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
    $this->app->instance(ChunkedUploadService::class, $service);

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
    $this->app->instance(ChunkedUploadService::class, $service);

    $this->actingAs($user)->postJson(route('chunked-upload.complete'), [
        'session_token' => $session->session_token,
    ])->assertStatus(500);

    expect(sentCommands($history))->toBe([])
        ->and($session->fresh()->status)->toBe('uploading');
});
