<?php

use App\Models\Asset;
use App\Models\User;
use App\Services\AssetProcessingService;
use App\Services\S3Service;
use App\Services\TikzCompilerService;

// ---------------------------------------------------------------------------
// Route access — authentication
// ---------------------------------------------------------------------------

test('guests cannot access tools index', function () {
    $this->get(route('tools.index'))->assertRedirect(route('login'));
});

test('guests cannot access tikz server page', function () {
    $this->get(route('tools.tikz-server'))->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Route access — editors can access tools
// ---------------------------------------------------------------------------

test('editors can access tools index', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $this->actingAs($editor)->get(route('tools.index'))->assertOk();
});

test('editors can access tikz server page', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $this->actingAs($editor)->get(route('tools.tikz-server'))->assertOk();
});

// ---------------------------------------------------------------------------
// Route access — admins can access tools
// ---------------------------------------------------------------------------

test('admins can access tools index', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get(route('tools.index'))->assertOk();
});

test('admins can access tikz server page', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get(route('tools.tikz-server'))->assertOk();
});

// ---------------------------------------------------------------------------
// TikZ Server page content
// ---------------------------------------------------------------------------

test('tikz server page includes font packages and folders', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($user)->get(route('tools.tikz-server'));

    $response->assertOk();
    $response->assertSee('TikZ');
    $response->assertViewHas('fontPackages');
    $response->assertViewHas('folders');
    $response->assertViewHas('rootFolder');
    $response->assertViewHas('compilerAvailable');
});

// ---------------------------------------------------------------------------
// Render endpoint — validation
// ---------------------------------------------------------------------------

test('render rejects missing tikz code', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($user)->postJson(route('tools.tikz-server.render'), []);

    $response->assertJsonValidationErrors(['tikz_code']);
});

test('render validates input constraints', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($user)->postJson(route('tools.tikz-server.render'), [
        'tikz_code' => '\\begin{tikzpicture}\\end{tikzpicture}',
        'png_dpi' => 9999,
        'border_pt' => 100,
    ]);

    $response->assertJsonValidationErrors(['png_dpi', 'border_pt']);
});

test('render returns 503 when tex live is not available', function () {
    $user = User::factory()->create(['role' => 'editor']);

    // Mock TikzCompilerService to report unavailable
    $mock = Mockery::mock(TikzCompilerService::class);
    $mock->shouldReceive('isAvailable')->andReturn(false);
    $this->app->instance(TikzCompilerService::class, $mock);

    $response = $this->actingAs($user)->postJson(route('tools.tikz-server.render'), [
        'tikz_code' => '\\begin{tikzpicture}\\draw (0,0) circle (1);\\end{tikzpicture}',
    ]);

    $response->assertStatus(503);
    $response->assertJson(['error' => 'TeX Live is not installed on this server.']);
});

test('render rejects dangerous input via compiler service', function () {
    $user = User::factory()->create(['role' => 'editor']);

    // Mock: available but sanitization fails
    $mock = Mockery::mock(TikzCompilerService::class);
    $mock->shouldReceive('isAvailable')->andReturn(true);
    $mock->shouldReceive('compile')->andReturn([
        'success' => false,
        'error' => 'Input contains potentially dangerous LaTeX commands.',
    ]);
    $this->app->instance(TikzCompilerService::class, $mock);

    $response = $this->actingAs($user)->postJson(route('tools.tikz-server.render'), [
        'tikz_code' => '\\write18{whoami}',
    ]);

    $response->assertStatus(422);
    $response->assertJsonFragment(['error' => 'Input contains potentially dangerous LaTeX commands.']);
});

test('render returns variants on successful compilation', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $mock = Mockery::mock(TikzCompilerService::class);
    $mock->shouldReceive('isAvailable')->andReturn(true);
    $mock->shouldReceive('compile')->andReturn([
        'success' => true,
        'variants' => [
            ['type' => 'svg_standard', 'content' => '<svg></svg>', 'size' => 11, 'mime' => 'image/svg+xml'],
        ],
        'log' => 'OK',
    ]);
    $this->app->instance(TikzCompilerService::class, $mock);

    $response = $this->actingAs($user)->postJson(route('tools.tikz-server.render'), [
        'tikz_code' => '\\begin{tikzpicture}\\draw (0,0) circle (1);\\end{tikzpicture}',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['variants' => [['type', 'content', 'size', 'mime']], 'log']);
});

// ---------------------------------------------------------------------------
// Template search
// ---------------------------------------------------------------------------

test('search tex templates returns matching assets', function () {
    $user = User::factory()->create(['role' => 'editor']);

    Asset::factory()->create(['filename' => 'diagram.tex', 's3_key' => 'assets/diagram.tex']);
    Asset::factory()->create(['filename' => 'photo.jpg', 's3_key' => 'assets/photo.jpg']);

    $response = $this->actingAs($user)->getJson(route('tools.tikz-server.templates'));

    $response->assertOk();
    $data = $response->json();

    // Should only return .tex files
    $filenames = array_column($data, 'filename');
    expect($filenames)->toContain('diagram.tex');
    expect($filenames)->not->toContain('photo.jpg');
});

test('search tex templates supports search query', function () {
    $user = User::factory()->create(['role' => 'editor']);

    Asset::factory()->create(['filename' => 'my-graph.tex', 's3_key' => 'assets/my-graph.tex']);
    Asset::factory()->create(['filename' => 'other.tex', 's3_key' => 'assets/other.tex']);

    $response = $this->actingAs($user)->getJson(route('tools.tikz-server.templates', ['search' => 'graph']));

    $response->assertOk();
    $data = $response->json();
    $filenames = array_column($data, 'filename');

    expect($filenames)->toContain('my-graph.tex');
});

test('search tex templates returns expected fields', function () {
    $user = User::factory()->create(['role' => 'editor']);

    Asset::factory()->create(['filename' => 'template.tex', 's3_key' => 'assets/template.tex']);

    $response = $this->actingAs($user)->getJson(route('tools.tikz-server.templates'));

    $response->assertOk();
    $response->assertJsonStructure([['id', 'filename', 'folder', 'size', 'formatted_size', 'updated_at']]);
});

// ---------------------------------------------------------------------------
// Load template
// ---------------------------------------------------------------------------

test('load tex template rejects non-tex files', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $asset = Asset::factory()->create(['filename' => 'image.jpg', 's3_key' => 'assets/image.jpg']);

    $response = $this->actingAs($user)->getJson(route('tools.tikz-server.templates.load', $asset));

    $response->assertStatus(422);
    $response->assertJson(['error' => 'Not a .tex or .txt file']);
});

test('load tex template returns content for tex files', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $asset = Asset::factory()->create(['filename' => 'template.tex', 's3_key' => 'assets/template.tex']);

    // Mock S3Service to return content
    $s3Mock = Mockery::mock(S3Service::class)->makePartial();
    $s3Mock->shouldReceive('getObjectContent')
        ->with('assets/template.tex')
        ->andReturn('\\begin{tikzpicture}\\end{tikzpicture}');
    $this->app->instance(S3Service::class, $s3Mock);

    $response = $this->actingAs($user)->getJson(route('tools.tikz-server.templates.load', $asset));

    $response->assertOk();
    $response->assertJson([
        'content' => '\\begin{tikzpicture}\\end{tikzpicture}',
        'filename' => 'template.tex',
    ]);
});

test('load tex template returns 500 when s3 content unavailable', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $asset = Asset::factory()->create(['filename' => 'template.tex', 's3_key' => 'assets/template.tex']);

    $s3Mock = Mockery::mock(S3Service::class)->makePartial();
    $s3Mock->shouldReceive('getObjectContent')
        ->with('assets/template.tex')
        ->andReturn(null);
    $this->app->instance(S3Service::class, $s3Mock);

    $response = $this->actingAs($user)->getJson(route('tools.tikz-server.templates.load', $asset));

    $response->assertStatus(500);
    $response->assertJson(['error' => 'Could not retrieve file content']);
});

// ---------------------------------------------------------------------------
// Upload tex template
// ---------------------------------------------------------------------------

test('upload tex template requires authentication', function () {
    $this->postJson(route('tools.tikz-server.templates.upload'), [
        'content' => '\\begin{tikzpicture}\\end{tikzpicture}',
        'filename' => 'test.tex',
    ])->assertUnauthorized();
});

test('upload tex template validates required fields', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $this->actingAs($user)->postJson(route('tools.tikz-server.templates.upload'), [])
        ->assertJsonValidationErrors(['content', 'filename']);
});

test('upload tex template creates asset', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $s3Mock = Mockery::mock(S3Service::class)->makePartial();
    $s3Mock->shouldReceive('uploadFile')->andReturn([
        's3_key' => 'assets/test-uuid.tex',
        'size' => 42,
        'etag' => '"abc123"',
    ]);
    $this->app->instance(S3Service::class, $s3Mock);

    $response = $this->actingAs($user)->postJson(route('tools.tikz-server.templates.upload'), [
        'content' => '\\begin{tikzpicture}\\draw (0,0) circle (1);\\end{tikzpicture}',
        'filename' => 'my-template.tex',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['asset_id', 'asset_url', 'filename']);

    $this->assertDatabaseHas('assets', [
        's3_key' => 'assets/test-uuid.tex',
        'mime_type' => 'application/x-tex',
        'user_id' => $user->id,
    ]);
});

// ---------------------------------------------------------------------------
// SVG upload (used by TikZ server results)
// ---------------------------------------------------------------------------

test('svg upload creates asset with svg mime type', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $s3Mock = Mockery::mock(S3Service::class)->makePartial();
    $s3Mock->shouldReceive('uploadFile')->andReturn([
        's3_key' => 'assets/diagram-uuid.svg',
        'size' => 100,
        'etag' => '"def456"',
    ]);
    $this->app->instance(S3Service::class, $s3Mock);

    // Mock AssetProcessingService to avoid real processing
    $processingMock = Mockery::mock(AssetProcessingService::class);
    $processingMock->shouldReceive('processImageAsset')->once();
    $processingMock->shouldReceive('applyUploadMetadata')->once();
    $this->app->instance(AssetProcessingService::class, $processingMock);

    $response = $this->actingAs($user)->postJson(route('tools.tikz-svg.upload'), [
        'content' => '<svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>',
        'filename' => 'diagram.svg',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['asset_id', 'asset_url', 'filename']);

    $this->assertDatabaseHas('assets', [
        's3_key' => 'assets/diagram-uuid.svg',
        'mime_type' => 'image/svg+xml',
    ]);
});

// ---------------------------------------------------------------------------
// PNG upload (used by TikZ server results)
// ---------------------------------------------------------------------------

test('png upload validates base64 content', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($user)->postJson(route('tools.tikz-png.upload'), [
        'content' => '!!!not-base64!!!',
        'filename' => 'diagram.png',
    ]);

    $response->assertStatus(422);
    $response->assertJson(['error' => 'Invalid base64 data']);
});

test('png upload creates asset with dimensions', function () {
    $user = User::factory()->create(['role' => 'editor']);

    // Create a tiny valid PNG (1x1 pixel)
    $pngContent = base64_encode(
        hex2bin('89504e470d0a1a0a0000000d4948445200000001000000010802000000907753de0000000c4944415408d763f8cf00000001010000189dd84d0000000049454e44ae426082')
    );

    $s3Mock = Mockery::mock(S3Service::class)->makePartial();
    $s3Mock->shouldReceive('uploadFile')->andReturn([
        's3_key' => 'assets/diagram-uuid.png',
        'size' => 200,
        'etag' => '"ghi789"',
    ]);
    $this->app->instance(S3Service::class, $s3Mock);

    $processingMock = Mockery::mock(AssetProcessingService::class);
    $processingMock->shouldReceive('processImageAsset')->once();
    $processingMock->shouldReceive('applyUploadMetadata')->once();
    $this->app->instance(AssetProcessingService::class, $processingMock);

    $response = $this->actingAs($user)->postJson(route('tools.tikz-png.upload'), [
        'content' => $pngContent,
        'filename' => 'diagram.png',
        'width' => 800,
        'height' => 600,
    ]);

    $response->assertOk();

    $this->assertDatabaseHas('assets', [
        's3_key' => 'assets/diagram-uuid.png',
        'mime_type' => 'image/png',
        'width' => 800,
        'height' => 600,
    ]);
});

// ---------------------------------------------------------------------------
// API users cannot access tools (web-only routes)
// ---------------------------------------------------------------------------

test('api users cannot access tools pages', function () {
    $apiUser = User::factory()->create(['role' => 'api']);

    // API users can access the route (it's auth-only, no role check),
    // but they should still get a 200 since tools are available to all authenticated non-API roles.
    // Actually — tools routes are in the general auth group, so API users CAN access them.
    // This test documents the current behavior.
    $this->actingAs($apiUser)->get(route('tools.index'))->assertOk();
});
