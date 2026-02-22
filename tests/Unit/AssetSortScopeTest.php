<?php

use App\Models\Asset;

test('applySort defaults to date_desc ordering by updated_at descending', function () {
    $old = Asset::factory()->create(['updated_at' => now()->subDays(2)]);
    $mid = Asset::factory()->create(['updated_at' => now()->subDay()]);
    $new = Asset::factory()->create(['updated_at' => now()]);

    $results = Asset::applySort('date_desc')->pluck('id')->all();

    expect($results)->toBe([$new->id, $mid->id, $old->id]);
});

test('applySort date_asc orders by updated_at ascending', function () {
    $old = Asset::factory()->create(['updated_at' => now()->subDays(2)]);
    $mid = Asset::factory()->create(['updated_at' => now()->subDay()]);
    $new = Asset::factory()->create(['updated_at' => now()]);

    $results = Asset::applySort('date_asc')->pluck('id')->all();

    expect($results)->toBe([$old->id, $mid->id, $new->id]);
});

test('applySort upload_desc and upload_asc order by created_at', function () {
    $first = Asset::factory()->create(['created_at' => now()->subDays(2)]);
    $second = Asset::factory()->create(['created_at' => now()->subDay()]);
    $third = Asset::factory()->create(['created_at' => now()]);

    $desc = Asset::applySort('upload_desc')->pluck('id')->all();
    expect($desc)->toBe([$third->id, $second->id, $first->id]);

    $asc = Asset::applySort('upload_asc')->pluck('id')->all();
    expect($asc)->toBe([$first->id, $second->id, $third->id]);
});

test('applySort size_asc and size_desc order by file size', function () {
    $small = Asset::factory()->create(['size' => 100]);
    $medium = Asset::factory()->create(['size' => 500]);
    $large = Asset::factory()->create(['size' => 1000]);

    $asc = Asset::applySort('size_asc')->pluck('id')->all();
    expect($asc)->toBe([$small->id, $medium->id, $large->id]);

    $desc = Asset::applySort('size_desc')->pluck('id')->all();
    expect($desc)->toBe([$large->id, $medium->id, $small->id]);
});

test('applySort name_asc and name_desc order by filename', function () {
    $a = Asset::factory()->create(['filename' => 'alpha.jpg']);
    $b = Asset::factory()->create(['filename' => 'beta.jpg']);
    $c = Asset::factory()->create(['filename' => 'gamma.jpg']);

    $asc = Asset::applySort('name_asc')->pluck('id')->all();
    expect($asc)->toBe([$a->id, $b->id, $c->id]);

    $desc = Asset::applySort('name_desc')->pluck('id')->all();
    expect($desc)->toBe([$c->id, $b->id, $a->id]);
});

test('applySort with unknown value falls back to date_desc', function () {
    $old = Asset::factory()->create(['updated_at' => now()->subDay()]);
    $new = Asset::factory()->create(['updated_at' => now()]);

    $results = Asset::applySort('invalid_sort')->pluck('id')->all();

    expect($results)->toBe([$new->id, $old->id]);
});
