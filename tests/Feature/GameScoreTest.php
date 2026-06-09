<?php

use App\Models\GameScore;
use App\Models\User;

// ---------------------------------------------------------------------------
// Authentication — the game routes live in the auth group
// ---------------------------------------------------------------------------

test('guests cannot view the leaderboard', function () {
    $this->get(route('game.scores'))->assertRedirect(route('login'));
});

test('guests cannot submit a score', function () {
    $this->post(route('game.scores.store'), ['score' => 100])->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Fetching the leaderboard
// ---------------------------------------------------------------------------

test('authenticated users can fetch the leaderboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route('game.scores'))
        ->assertOk()
        ->assertJsonStructure(['leaderboard']);
});

// ---------------------------------------------------------------------------
// Submitting a score
// ---------------------------------------------------------------------------

test('submitting a score stores it and returns the leaderboard', function () {
    $user = User::factory()->create(['name' => 'Ada']);

    $response = $this->actingAs($user)->postJson(route('game.scores.store'), ['score' => 1234]);

    $response->assertOk();
    $this->assertDatabaseHas('game_scores', ['user_id' => $user->id, 'score' => 1234]);

    $leaderboard = $response->json('leaderboard');
    expect($leaderboard[0]['name'])->toBe('Ada');
    expect($leaderboard[0]['score'])->toBe(1234);
});

test('score submission rejects out-of-range or non-integer values', function (mixed $score) {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('game.scores.store'), ['score' => $score])
        ->assertJsonValidationErrors('score');

    expect(GameScore::count())->toBe(0);
})->with([
    'zero' => 0,
    'too large' => 1_000_000,
    'negative' => -5,
    'non-integer' => 'abc',
    'missing' => null,
]);

// ---------------------------------------------------------------------------
// Leaderboard ranking
// ---------------------------------------------------------------------------

test('leaderboard shows each users best score, ordered desc, capped at five', function () {
    $ada = User::factory()->create(['name' => 'Ada']);
    GameScore::factory()->create(['user_id' => $ada->id, 'score' => 10]);
    GameScore::factory()->create(['user_id' => $ada->id, 'score' => 90]); // best for Ada
    GameScore::factory()->create(['user_id' => $ada->id, 'score' => 50]);

    // Five more single-score players to exercise ordering + the cap.
    foreach ([20, 30, 40, 60, 80] as $i => $score) {
        $player = User::factory()->create(['name' => "Player{$i}"]);
        GameScore::factory()->create(['user_id' => $player->id, 'score' => $score]);
    }

    $viewer = User::factory()->create();
    $leaderboard = $this->actingAs($viewer)->getJson(route('game.scores'))->json('leaderboard');

    // Best-per-user, sorted desc, top 5: 90 (Ada), 80, 60, 40, 30 — the 20 falls off.
    expect($leaderboard)->toHaveCount(5);
    expect(array_column($leaderboard, 'score'))->toBe([90, 80, 60, 40, 30]);
    expect($leaderboard[0]['name'])->toBe('Ada');
});
