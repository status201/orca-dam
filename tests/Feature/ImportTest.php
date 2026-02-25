<?php

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;

// --- Authorization ---

test('guests cannot access import page', function () {
    $this->get(route('import.index'))->assertRedirect(route('login'));
});

test('editors cannot access import page', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $this->actingAs($editor)->get(route('import.index'))->assertForbidden();
});

test('api users cannot access import page', function () {
    $api = User::factory()->create(['role' => 'api']);

    $this->actingAs($api)->get(route('import.index'))->assertForbidden();
});

test('admins can access import page', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get(route('import.index'))->assertOk();
});

test('guests cannot access preview endpoint', function () {
    $this->postJson(route('import.preview'))->assertUnauthorized();
});

test('editors cannot access preview endpoint', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $this->actingAs($editor)->postJson(route('import.preview'), [
        'csv_data' => "s3_key,alt_text\nassets/test.jpg,hello",
        'match_field' => 's3_key',
    ])->assertForbidden();
});

test('guests cannot access import endpoint', function () {
    $this->postJson(route('import.import'))->assertUnauthorized();
});

test('editors cannot access import endpoint', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $this->actingAs($editor)->postJson(route('import.import'), [
        'csv_data' => "s3_key,alt_text\nassets/test.jpg,hello",
        'match_field' => 's3_key',
    ])->assertForbidden();
});

// --- Validation ---

test('preview requires csv_data and match_field', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->postJson(route('import.preview'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['csv_data', 'match_field']);
});

test('preview rejects invalid match_field', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => "s3_key,alt_text\nassets/test.jpg,hello",
        'match_field' => 'invalid_field',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['match_field']);
});

test('preview returns error for csv with only header row', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => 's3_key,alt_text',
        'match_field' => 's3_key',
    ])->assertStatus(422)
        ->assertJsonPath('error', 'No valid CSV data found.');
});

test('preview returns error when match field column missing from csv', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => "alt_text,caption\nhello,world",
        'match_field' => 's3_key',
    ])->assertStatus(422)
        ->assertJsonFragment(['error' => 'The CSV must contain a "s3_key" column for matching.']);
});

// --- Preview by s3_key ---

test('preview matches assets by s3_key', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create([
        's3_key' => 'assets/photos/image1.jpg',
        'filename' => 'image1.jpg',
    ]);

    $csv = "s3_key,alt_text\nassets/photos/image1.jpg,New alt text";

    $response = $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()
        ->assertJsonPath('matched', 1)
        ->assertJsonPath('unmatched', 0)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('results.0.status', 'matched')
        ->assertJsonPath('results.0.asset.id', $asset->id);
});

// --- Preview by filename ---

test('preview matches assets by filename', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->create(['filename' => 'sunset.jpg']);

    $csv = "filename,alt_text\nsunset.jpg,A beautiful sunset";

    $response = $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => $csv,
        'match_field' => 'filename',
    ]);

    $response->assertOk()
        ->assertJsonPath('matched', 1)
        ->assertJsonPath('results.0.status', 'matched');
});

// --- Preview reports unmatched and skipped ---

test('preview reports unmatched rows', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $csv = "s3_key,alt_text\nassets/nonexistent.jpg,Some text";

    $response = $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()
        ->assertJsonPath('matched', 0)
        ->assertJsonPath('unmatched', 1)
        ->assertJsonPath('results.0.status', 'not_found')
        ->assertJsonPath('results.0.match_value', 'assets/nonexistent.jpg');
});

test('preview skips rows with empty match field', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $csv = "s3_key,alt_text\n,Some text";

    $response = $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()
        ->assertJsonPath('skipped', 1)
        ->assertJsonPath('matched', 0);
});

// --- Preview detects changes ---

test('preview detects field changes', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->create([
        's3_key' => 'assets/img.jpg',
        'alt_text' => 'Old alt',
        'caption' => 'Old caption',
    ]);

    $csv = "s3_key,alt_text,caption\nassets/img.jpg,New alt,New caption";

    $response = $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk();
    $changes = $response->json('results.0.changes');
    expect($changes)->toHaveKey('alt_text');
    expect($changes['alt_text']['from'])->toBe('Old alt');
    expect($changes['alt_text']['to'])->toBe('New alt');
    expect($changes['caption']['from'])->toBe('Old caption');
    expect($changes['caption']['to'])->toBe('New caption');
});

test('preview does not flag unchanged fields', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->create([
        's3_key' => 'assets/img.jpg',
        'alt_text' => 'Same alt',
    ]);

    $csv = "s3_key,alt_text\nassets/img.jpg,Same alt";

    $response = $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk();
    $changes = $response->json('results.0.changes');
    expect($changes)->not->toHaveKey('alt_text');
});

// --- Preview validation errors ---

test('preview flags invalid license type', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->create(['s3_key' => 'assets/img.jpg']);

    $csv = "s3_key,license_type\nassets/img.jpg,invalid_license";

    $response = $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk();
    $errors = $response->json('results.0.errors');
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('invalid_license');
});

test('preview flags invalid date format', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->create(['s3_key' => 'assets/img.jpg']);

    $csv = "s3_key,license_expiry_date\nassets/img.jpg,31-12-2026";

    $response = $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk();
    $errors = $response->json('results.0.errors');
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('31-12-2026');
});

test('preview accepts valid license types', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->create(['s3_key' => 'assets/img.jpg']);

    $csv = "s3_key,license_type\nassets/img.jpg,cc_by";

    $response = $this->actingAs($admin)->postJson(route('import.preview'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk();
    $errors = $response->json('results.0.errors');
    expect($errors)->toBeEmpty();
});

// --- Import updates assets ---

test('import updates asset fields', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create([
        's3_key' => 'assets/img.jpg',
        'alt_text' => 'Old',
        'caption' => '',
    ]);

    $csv = "s3_key,alt_text,caption,copyright\nassets/img.jpg,New alt,New caption,John Doe";

    $response = $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('skipped', 0);

    $asset->refresh();
    expect($asset->alt_text)->toBe('New alt');
    expect($asset->caption)->toBe('New caption');
    expect($asset->copyright)->toBe('John Doe');
    expect($asset->last_modified_by)->toBe($admin->id);
});

test('import skips empty fields preserving existing values', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create([
        's3_key' => 'assets/img.jpg',
        'alt_text' => 'Keep this',
        'caption' => 'Keep this too',
    ]);

    $csv = "s3_key,alt_text,caption\nassets/img.jpg,,";

    $response = $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()->assertJsonPath('skipped', 1);

    $asset->refresh();
    expect($asset->alt_text)->toBe('Keep this');
    expect($asset->caption)->toBe('Keep this too');
});

test('import matches by filename', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create(['filename' => 'photo.jpg', 'alt_text' => '']);

    $csv = "filename,alt_text\nphoto.jpg,Updated via filename match";

    $response = $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 'filename',
    ]);

    $response->assertOk()->assertJsonPath('updated', 1);

    $asset->refresh();
    expect($asset->alt_text)->toBe('Updated via filename match');
});

// --- Import tags ---

test('import creates and attaches user tags lowercased', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create(['s3_key' => 'assets/img.jpg']);

    $csv = "s3_key,user_tags\nassets/img.jpg,\"Nature, Landscape, SUNSET\"";

    $response = $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()->assertJsonPath('updated', 1);

    $asset->refresh();
    $tagNames = $asset->tags->pluck('name')->sort()->values()->all();
    expect($tagNames)->toBe(['landscape', 'nature', 'sunset']);
    expect($asset->tags->first()->type)->toBe('user');
});

test('import does not remove existing tags', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create(['s3_key' => 'assets/img.jpg']);
    $existingTag = Tag::factory()->user()->create(['name' => 'existing']);
    $asset->tags()->attach($existingTag);

    $csv = "s3_key,user_tags\nassets/img.jpg,newtag";

    $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ])->assertOk();

    $asset->refresh();
    $tagNames = $asset->tags->pluck('name')->sort()->values()->all();
    expect($tagNames)->toBe(['existing', 'newtag']);
});

test('import reuses existing tags by name', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create(['s3_key' => 'assets/img.jpg']);
    Tag::factory()->user()->create(['name' => 'reuseme']);

    $csv = "s3_key,user_tags\nassets/img.jpg,reuseme";

    $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ])->assertOk();

    expect(Tag::where('name', 'reuseme')->count())->toBe(1);
    expect($asset->fresh()->tags->pluck('name')->all())->toBe(['reuseme']);
});

// --- Import validation ---

test('import skips rows with invalid license type', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->create(['s3_key' => 'assets/img.jpg']);

    $csv = "s3_key,license_type\nassets/img.jpg,bogus";

    $response = $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('skipped', 1);
    expect($response->json('errors'))->toHaveCount(1);
});

test('import skips rows with invalid date format', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->create(['s3_key' => 'assets/img.jpg']);

    $csv = "s3_key,license_expiry_date\nassets/img.jpg,not-a-date";

    $response = $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('skipped', 1);
    expect($response->json('errors'))->toHaveCount(1);
});

test('import updates license type with valid value', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create(['s3_key' => 'assets/img.jpg', 'license_type' => null]);

    $csv = "s3_key,license_type,license_expiry_date\nassets/img.jpg,cc_by_sa,2027-06-15";

    $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ])->assertOk()->assertJsonPath('updated', 1);

    $asset->refresh();
    expect($asset->license_type)->toBe('cc_by_sa');
    expect($asset->license_expiry_date->format('Y-m-d'))->toBe('2027-06-15');
});

// --- Import skips unmatched ---

test('import skips unmatched rows', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $csv = "s3_key,alt_text\nassets/nonexistent.jpg,text";

    $response = $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('skipped', 1);
});

// --- Multiple rows ---

test('import handles multiple rows with mixed results', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Asset::factory()->create(['s3_key' => 'assets/a.jpg', 'alt_text' => '']);
    Asset::factory()->create(['s3_key' => 'assets/b.jpg', 'alt_text' => '']);

    $csv = "s3_key,alt_text,license_type\nassets/a.jpg,Updated A,cc_by\nassets/b.jpg,Updated B,invalid\nassets/missing.jpg,Nope,";

    $response = $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()
        ->assertJsonPath('updated', 1)
        ->assertJsonPath('skipped', 2);
    expect($response->json('errors'))->toHaveCount(1);

    expect(Asset::where('s3_key', 'assets/a.jpg')->first()->alt_text)->toBe('Updated A');
    expect(Asset::where('s3_key', 'assets/b.jpg')->first()->alt_text)->toBe('');
});

// --- Import requires validation ---

test('import requires csv_data and match_field', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->postJson(route('import.import'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['csv_data', 'match_field']);
});

// Reference tag import tests

test('import creates reference tags from csv', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create(['s3_key' => 'assets/img.jpg']);

    $csv = "s3_key,reference_tags\nassets/img.jpg,\"2F.4.6.2, REF-001\"";

    $response = $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ]);

    $response->assertOk()->assertJsonPath('updated', 1);

    $asset->refresh();
    $refTags = $asset->tags->where('type', 'reference');
    expect($refTags)->toHaveCount(2);
    expect($refTags->pluck('name')->sort()->values()->all())->toBe(['2f.4.6.2', 'ref-001']);
});

test('import preserves existing reference tags', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $asset = Asset::factory()->create(['s3_key' => 'assets/img.jpg']);
    $existingRefTag = Tag::factory()->reference()->create(['name' => 'existing-ref']);
    $asset->tags()->attach($existingRefTag);

    $csv = "s3_key,reference_tags\nassets/img.jpg,new-ref";

    $this->actingAs($admin)->postJson(route('import.import'), [
        'csv_data' => $csv,
        'match_field' => 's3_key',
    ])->assertOk();

    $asset->refresh();
    $refTags = $asset->tags->where('type', 'reference');
    expect($refTags)->toHaveCount(2);
    expect($refTags->pluck('name')->sort()->values()->all())->toBe(['existing-ref', 'new-ref']);
});
