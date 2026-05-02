<?php

use App\Models\Setting;
use App\Services\AssetSearchParser;

test('parses bare terms as regular', function () {
    $parsed = AssetSearchParser::parseSearchTerms('beach sunset');

    expect($parsed['regular'])->toEqual(['beach', 'sunset']);
    expect($parsed['required'])->toBeEmpty();
    expect($parsed['excluded'])->toBeEmpty();
});

test('parses + prefix as required', function () {
    $parsed = AssetSearchParser::parseSearchTerms('+beach +summer');

    expect($parsed['required'])->toEqual(['beach', 'summer']);
    expect($parsed['regular'])->toBeEmpty();
    expect($parsed['excluded'])->toBeEmpty();
});

test('parses - prefix as excluded', function () {
    $parsed = AssetSearchParser::parseSearchTerms('beach -sunset');

    expect($parsed['regular'])->toEqual(['beach']);
    expect($parsed['excluded'])->toEqual(['sunset']);
    expect($parsed['required'])->toBeEmpty();
});

test('parses mixed +, -, and bare terms', function () {
    $parsed = AssetSearchParser::parseSearchTerms('summer +beach -rain wave');

    expect($parsed['regular'])->toEqual(['summer', 'wave']);
    expect($parsed['required'])->toEqual(['beach']);
    expect($parsed['excluded'])->toEqual(['rain']);
});

test('quoted phrase with no prefix is treated as required', function () {
    $parsed = AssetSearchParser::parseSearchTerms('"blue sky"');

    expect($parsed['required'])->toEqual(['blue sky']);
    expect($parsed['regular'])->toBeEmpty();
    expect($parsed['excluded'])->toBeEmpty();
});

test('quoted phrase with - prefix is excluded', function () {
    $parsed = AssetSearchParser::parseSearchTerms('beach -"old version"');

    expect($parsed['regular'])->toEqual(['beach']);
    expect($parsed['excluded'])->toEqual(['old version']);
    expect($parsed['required'])->toBeEmpty();
});

test('quoted phrase with + prefix is required', function () {
    $parsed = AssetSearchParser::parseSearchTerms('summer +"blue sky"');

    expect($parsed['regular'])->toEqual(['summer']);
    expect($parsed['required'])->toEqual(['blue sky']);
});

test('phrases are extracted before bare token splitting', function () {
    $parsed = AssetSearchParser::parseSearchTerms('+"blue sky" red green -"old red"');

    expect($parsed['required'])->toEqual(['blue sky']);
    expect($parsed['excluded'])->toEqual(['old red']);
    expect($parsed['regular'])->toEqual(['red', 'green']);
});

test('bare + and - tokens (no following word) are ignored', function () {
    $parsed = AssetSearchParser::parseSearchTerms('beach + - sunset');

    expect($parsed['regular'])->toEqual(['beach', 'sunset']);
    expect($parsed['required'])->toBeEmpty();
    expect($parsed['excluded'])->toBeEmpty();
});

test('empty quotes do not match the phrase regex and are kept as a bare token', function () {
    // The phrase regex requires at least one inner character, so "" passes
    // through the phrase pass and is then split as a bare token.
    $parsed = AssetSearchParser::parseSearchTerms('beach "" sunset');

    expect($parsed['regular'])->toEqual(['beach', '""', 'sunset']);
    expect($parsed['required'])->toBeEmpty();
});

test('strips configured s3 bucket url prefix', function () {
    config(['filesystems.disks.s3.url' => 'https://my-bucket.s3.amazonaws.com']);

    $normalized = AssetSearchParser::normalizeSearchTerm(
        'https://my-bucket.s3.amazonaws.com/assets/photos/x.jpg'
    );

    expect($normalized)->toBe('assets/photos/x.jpg');
});

test('strips configured custom domain prefix', function () {
    config(['filesystems.disks.s3.url' => 'https://other.s3.amazonaws.com']);
    Setting::set('custom_domain', 'https://cdn.example.com');

    $normalized = AssetSearchParser::normalizeSearchTerm(
        'https://cdn.example.com/assets/photos/x.jpg'
    );

    expect($normalized)->toBe('assets/photos/x.jpg');
});

test('returns input unchanged when no url prefix matches', function () {
    config(['filesystems.disks.s3.url' => 'https://other.s3.amazonaws.com']);

    $normalized = AssetSearchParser::normalizeSearchTerm('plain search term');

    expect($normalized)->toBe('plain search term');
});
