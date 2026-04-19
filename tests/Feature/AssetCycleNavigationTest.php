<?php

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

/**
 * Build N image assets named in alphabetical order so we can sort by name
 * deterministically (a.jpg, b.jpg, …). Returns the assets in that order.
 */
function makeOrderedAssets(int $count): Collection
{
    $assets = collect();
    foreach (range(0, $count - 1) as $i) {
        $letter = chr(ord('a') + $i);
        $assets->push(Asset::factory()->image()->create([
            'filename' => $letter.'.jpg',
            's3_key' => "assets/{$letter}.jpg",
        ]));
    }

    return Asset::whereIn('id', $assets->pluck('id'))->orderBy('filename')->get();
}

test('show page without context query params has no cycle nav', function () {
    $user = User::factory()->create();
    $assets = makeOrderedAssets(3);

    $response = $this->actingAs($user)->get(route('assets.show', $assets[1]));

    $response->assertStatus(200);
    $response->assertDontSee('data-asset-cycle-nav', false);
    $response->assertDontSee('data-cycle="prev"', false);
    $response->assertDontSee('data-cycle="next"', false);
});

test('show page with context renders cycle nav with correct position', function () {
    $user = User::factory()->create();
    $assets = makeOrderedAssets(5);

    $response = $this->actingAs($user)->get(
        route('assets.show', $assets[2]).'?sort=name_asc'
    );

    $response->assertStatus(200);
    $response->assertSee('data-asset-cycle-nav', false);
    $response->assertSee('data-cycle="prev"', false);
    $response->assertSee('data-cycle="next"', false);
    // Position counter ("3" and "5" appear in the rendered widget)
    $response->assertSeeInOrder(['3', 'of', '5'], false);
});

test('first asset in result set has no prev anchor', function () {
    $user = User::factory()->create();
    $assets = makeOrderedAssets(4);

    $response = $this->actingAs($user)->get(
        route('assets.show', $assets[0]).'?sort=name_asc'
    );

    $response->assertStatus(200);
    $response->assertSee('data-asset-cycle-nav', false);
    $response->assertDontSee('data-cycle="prev"', false);
    $response->assertSee('data-cycle="next"', false);
});

test('last asset in result set has no next anchor', function () {
    $user = User::factory()->create();
    $assets = makeOrderedAssets(4);

    $response = $this->actingAs($user)->get(
        route('assets.show', $assets[3]).'?sort=name_asc'
    );

    $response->assertStatus(200);
    $response->assertSee('data-cycle="prev"', false);
    $response->assertDontSee('data-cycle="next"', false);
});

test('next URL bumps page when neighbour is on a new page', function () {
    $user = User::factory()->create();
    // 24 assets, per_page=12, viewing asset at index 11 (last on page 1).
    // Filenames a..x ensure deterministic ordering by name.
    $assets = makeOrderedAssets(24);
    $response = $this->actingAs($user)->get(
        route('assets.show', $assets[11]).'?sort=name_asc&per_page=12&page=1'
    );

    $response->assertStatus(200);
    $html = $response->getContent();

    // Next neighbour is asset[12], its page = floor(12/12)+1 = 2
    expect($html)->toMatch('#/assets/'.$assets[12]->id.'\?[^"]*page=2#');
    // Prev neighbour is asset[10], its page = floor(10/12)+1 = 1
    expect($html)->toMatch('#/assets/'.$assets[10]->id.'\?[^"]*page=1#');
});

test('tag filter narrows the cycle set', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->create(['name' => 'cats']);

    // 5 assets total, only 3 tagged with "cats"
    $tagged = collect();
    foreach (['a', 'b', 'c'] as $letter) {
        $asset = Asset::factory()->image()->create([
            'filename' => $letter.'.jpg',
            's3_key' => "assets/{$letter}.jpg",
        ]);
        $asset->tags()->attach($tag->id);
        $tagged->push($asset);
    }
    foreach (['x', 'y'] as $letter) {
        Asset::factory()->image()->create([
            'filename' => $letter.'.jpg',
            's3_key' => "assets/{$letter}.jpg",
        ]);
    }

    // Visit middle tagged asset with tag filter and sort
    $response = $this->actingAs($user)->get(
        route('assets.show', $tagged[1]).'?tags='.$tag->id.'&sort=name_asc'
    );

    $response->assertStatus(200);
    $html = $response->getContent();

    // "2 of 3" — only the 3 tagged assets are in the cycle
    expect($html)->toContain('data-asset-cycle-nav');
    $response->assertSeeInOrder(['2', 'of', '3'], false);
    // Summary should mention the tag name
    expect($html)->toContain('cats');
});

test('search context only includes matching assets in cycle', function () {
    $user = User::factory()->create();
    Asset::factory()->image()->create(['filename' => 'matchA.jpg', 's3_key' => 'assets/matchA.jpg']);
    $middle = Asset::factory()->image()->create(['filename' => 'matchB.jpg', 's3_key' => 'assets/matchB.jpg']);
    Asset::factory()->image()->create(['filename' => 'matchC.jpg', 's3_key' => 'assets/matchC.jpg']);
    Asset::factory()->image()->create(['filename' => 'other.jpg', 's3_key' => 'assets/other.jpg']);

    $response = $this->actingAs($user)->get(
        route('assets.show', $middle).'?search=match&sort=name_asc'
    );

    $response->assertStatus(200);
    $response->assertSeeInOrder(['2', 'of', '3'], false);
});

test('show page returns no cycle nav when asset is not in result set', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->create(['name' => 'cats']);

    $tagged = Asset::factory()->image()->create([
        'filename' => 'a.jpg',
        's3_key' => 'assets/a.jpg',
    ]);
    $tagged->tags()->attach($tag->id);

    // An asset NOT tagged with "cats"
    $untagged = Asset::factory()->image()->create([
        'filename' => 'z.jpg',
        's3_key' => 'assets/z.jpg',
    ]);

    // Visit untagged asset with the cats filter — stale link
    $response = $this->actingAs($user)->get(
        route('assets.show', $untagged).'?tags='.$tag->id
    );

    $response->assertStatus(200);
    $response->assertDontSee('data-asset-cycle-nav', false);
});

test('cycle nav is hidden for single-asset result sets', function () {
    $user = User::factory()->create();
    $only = Asset::factory()->image()->create([
        'filename' => 'only.jpg',
        's3_key' => 'assets/only.jpg',
    ]);

    $response = $this->actingAs($user)->get(
        route('assets.show', $only).'?sort=name_asc'
    );

    $response->assertStatus(200);
    $response->assertDontSee('data-asset-cycle-nav', false);
});

test('back URL includes context params when present', function () {
    $user = User::factory()->create();
    $assets = makeOrderedAssets(3);

    $response = $this->actingAs($user)->get(
        route('assets.show', $assets[1]).'?sort=name_asc&search=foo'
    );

    $response->assertStatus(200);
    $html = $response->getContent();

    // Back link should point to assets.index with the same params
    expect($html)->toMatch('#href="[^"]*/assets\?[^"]*sort=name_asc#');
    expect($html)->toContain('search=foo');
});

test('back URL falls back to assets index when no context present', function () {
    $user = User::factory()->create();
    $asset = Asset::factory()->image()->create([
        's3_key' => 'assets/x.jpg',
        'filename' => 'x.jpg',
    ]);

    // No prior session, no context params
    $response = $this->actingAs($user)->get(route('assets.show', $asset));

    $response->assertStatus(200);
    $html = $response->getContent();
    // Back link points to plain assets index (no query string)
    expect($html)->toMatch('#href="[^"]*/assets"#');
});

test('grid card links include current query string', function () {
    $user = User::factory()->create();
    Asset::factory()->image()->create(['filename' => 'a.jpg', 's3_key' => 'assets/a.jpg']);

    $response = $this->actingAs($user)->get(route('assets.index', [
        'sort' => 'name_asc',
        'search' => 'a',
    ]));

    $response->assertStatus(200);
    // The grid card click handler should embed the show URL with the current query string
    $html = $response->getContent();
    expect($html)->toContain('sort=name_asc');
});
