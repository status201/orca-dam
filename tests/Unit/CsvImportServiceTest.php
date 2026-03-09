<?php

use App\Models\Asset;
use App\Services\CsvImportService;

// ─── parseCsv() ──────────────────────────────────────────────────────────────

test('parseCsv returns correct associative row arrays for valid multi-row csv', function () {
    $service = new CsvImportService;
    $csv = "s3_key,filename,alt_text\nassets/a.jpg,a.jpg,Photo A\nassets/b.jpg,b.jpg,Photo B";

    $rows = $service->parseCsv($csv);

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toBe(['s3_key' => 'assets/a.jpg', 'filename' => 'a.jpg', 'alt_text' => 'Photo A']);
    expect($rows[1])->toBe(['s3_key' => 'assets/b.jpg', 'filename' => 'b.jpg', 'alt_text' => 'Photo B']);
});

test('parseCsv returns empty array for header-only csv', function () {
    $service = new CsvImportService;
    $csv = 's3_key,filename,alt_text';

    expect($service->parseCsv($csv))->toBe([]);
});

test('parseCsv returns empty array for empty string', function () {
    $service = new CsvImportService;

    expect($service->parseCsv(''))->toBe([]);
});

test('parseCsv skips blank lines', function () {
    $service = new CsvImportService;
    $csv = "s3_key,filename\nassets/a.jpg,a.jpg\n\nassets/b.jpg,b.jpg\n";

    $rows = $service->parseCsv($csv);

    expect($rows)->toHaveCount(2);
});

test('parseCsv handles CRLF line endings', function () {
    $service = new CsvImportService;
    $csv = "s3_key,filename\r\nassets/a.jpg,a.jpg\r\nassets/b.jpg,b.jpg";

    $rows = $service->parseCsv($csv);

    expect($rows)->toHaveCount(2);
    expect($rows[0]['s3_key'])->toBe('assets/a.jpg');
});

test('parseCsv trims leading and trailing whitespace from headers', function () {
    $service = new CsvImportService;
    $csv = " s3_key , filename \nassets/a.jpg,a.jpg";

    $rows = $service->parseCsv($csv);

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toHaveKey('s3_key');
    expect($rows[0])->toHaveKey('filename');
});

test('parseCsv fills missing columns with empty string', function () {
    $service = new CsvImportService;
    $csv = "s3_key,filename,alt_text\nassets/a.jpg,a.jpg";

    $rows = $service->parseCsv($csv);

    expect($rows[0]['alt_text'])->toBe('');
});

// ─── calculateChanges() ──────────────────────────────────────────────────────

test('calculateChanges returns empty array when no fields changed', function () {
    $service = new CsvImportService;
    $asset = Asset::factory()->create(['alt_text' => 'Same text']);
    $row = ['alt_text' => 'Same text'];

    expect($service->calculateChanges($asset, $row))->toBe([]);
});

test('calculateChanges detects changed alt_text', function () {
    $service = new CsvImportService;
    $asset = Asset::factory()->create(['alt_text' => 'Old text']);
    $row = ['alt_text' => 'New text'];

    $changes = $service->calculateChanges($asset, $row);

    expect($changes)->toHaveKey('alt_text');
    expect($changes['alt_text']['from'])->toBe('Old text');
    expect($changes['alt_text']['to'])->toBe('New text');
});

test('calculateChanges skips empty or whitespace-only csv values', function () {
    $service = new CsvImportService;
    $asset = Asset::factory()->create(['alt_text' => 'Existing']);
    $row = ['alt_text' => '  '];

    expect($service->calculateChanges($asset, $row))->toBe([]);
});

test('calculateChanges compares license_expiry_date when null on asset', function () {
    $service = new CsvImportService;
    $asset = Asset::factory()->create(['license_expiry_date' => null]);
    $row = ['license_expiry_date' => '2027-01-01'];

    $changes = $service->calculateChanges($asset, $row);

    expect($changes)->toHaveKey('license_expiry_date');
    expect($changes['license_expiry_date']['from'])->toBe('');
    expect($changes['license_expiry_date']['to'])->toBe('2027-01-01');
});

test('calculateChanges formats Carbon license_expiry_date before comparison', function () {
    $service = new CsvImportService;
    $asset = Asset::factory()->create(['license_expiry_date' => '2025-06-15']);
    $row = ['license_expiry_date' => '2025-06-15'];

    // Same date — should not appear in changes
    expect($service->calculateChanges($asset, $row))->not->toHaveKey('license_expiry_date');
});

test('calculateChanges detects user_tags', function () {
    $service = new CsvImportService;
    $asset = Asset::factory()->create();
    $row = ['user_tags' => 'tag1, tag2'];

    $changes = $service->calculateChanges($asset, $row);

    expect($changes)->toHaveKey('user_tags');
    expect($changes['user_tags']['add'])->toBe('tag1, tag2');
});

test('calculateChanges detects reference_tags', function () {
    $service = new CsvImportService;
    $asset = Asset::factory()->create();
    $row = ['reference_tags' => 'ref1'];

    $changes = $service->calculateChanges($asset, $row);

    expect($changes)->toHaveKey('reference_tags');
    expect($changes['reference_tags']['add'])->toBe('ref1');
});

test('calculateChanges ignores empty user_tags field', function () {
    $service = new CsvImportService;
    $asset = Asset::factory()->create();
    $row = ['user_tags' => ''];

    expect($service->calculateChanges($asset, $row))->not->toHaveKey('user_tags');
});

// ─── validateRow() ───────────────────────────────────────────────────────────

test('validateRow returns no errors for valid license type cc_by', function () {
    $service = new CsvImportService;

    expect($service->validateRow(['license_type' => 'cc_by']))->toBe([]);
});

test('validateRow returns error for invalid license type', function () {
    $service = new CsvImportService;

    $errors = $service->validateRow(['license_type' => 'invalid_type']);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('invalid_type');
});

test('validateRow returns no errors for valid date format', function () {
    $service = new CsvImportService;

    expect($service->validateRow(['license_expiry_date' => '2025-12-31']))->toBe([]);
});

test('validateRow returns error for invalid date format dd-mm-yyyy', function () {
    $service = new CsvImportService;

    $errors = $service->validateRow(['license_expiry_date' => '31-12-2025']);

    expect($errors)->toHaveCount(1);
});

test('validateRow returns no errors for empty license_type', function () {
    $service = new CsvImportService;

    expect($service->validateRow(['license_type' => '']))->toBe([]);
});

test('validateRow returns two errors for both invalid license_type and invalid date', function () {
    $service = new CsvImportService;

    $errors = $service->validateRow([
        'license_type' => 'bad_type',
        'license_expiry_date' => 'not-a-date',
    ]);

    expect($errors)->toHaveCount(2);
});
