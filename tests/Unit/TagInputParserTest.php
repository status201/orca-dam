<?php

use App\Models\Tag;
use App\Support\TagInputParser;

test('parse splits a comma-separated string into trimmed names', function () {
    expect(TagInputParser::parse('cat, dog, animal'))->toBe(['cat', 'dog', 'animal']);
});

test('parse splits comma-joined array elements', function () {
    expect(TagInputParser::parse(['cat,dog', 'animal']))->toBe(['cat', 'dog', 'animal']);
});

test('parse lowercases names', function () {
    expect(TagInputParser::parse('Cat, DOG'))->toBe(['cat', 'dog']);
});

test('parse drops empty segments', function () {
    expect(TagInputParser::parse('a,,b,'))->toBe(['a', 'b']);
});

test('parse trims surrounding whitespace', function () {
    expect(TagInputParser::parse('  a  ,  b  '))->toBe(['a', 'b']);
});

test('parse de-duplicates while preserving first-seen order', function () {
    expect(TagInputParser::parse('red, blue, red'))->toBe(['red', 'blue']);
});

test('parse de-duplicates case-insensitively', function () {
    expect(TagInputParser::parse(['Red', 'red']))->toBe(['red']);
});

test('parse drops names longer than the max length', function () {
    $long = str_repeat('a', 101);

    expect(TagInputParser::parse("ok, {$long}, fine"))->toBe(['ok', 'fine']);
});

test('parse honours a custom max length', function () {
    expect(TagInputParser::parse('abc, abcd', 3))->toBe(['abc']);
});

test('parse defaults to the Tag max name length', function () {
    $atLimit = str_repeat('a', Tag::MAX_NAME_LENGTH);
    $overLimit = str_repeat('b', Tag::MAX_NAME_LENGTH + 1);

    expect(TagInputParser::parse("{$atLimit}, {$overLimit}"))->toBe([$atLimit]);
});

test('parse returns an empty array for null or empty input', function () {
    expect(TagInputParser::parse(null))->toBe([]);
    expect(TagInputParser::parse(''))->toBe([]);
    expect(TagInputParser::parse([]))->toBe([]);
    expect(TagInputParser::parse('   '))->toBe([]);
});
