<?php

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;
use App\Support\ColumnLimits;
use App\Support\ErrorId;
use App\Support\S3KeyHash;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The QueryException backstop, end to end through the real exception handler.
 *
 * Half of these drive *real* SQLite 23000 errors — `foreign_key_constraints` is on by default, so
 * UNIQUE, NOT NULL and FOREIGN KEY violations are genuine PDO errors, not hand-made ones. The
 * 22001 cases have to be synthesised (SQLite does not enforce varchar length and CI runs no
 * MariaDB), which is exactly why the real-driver cases matter: they prove the pipeline, not just
 * the classifier.
 *
 * @see specs/features/error-handling.md
 * @see specs/decisions/adr-016-database-errors-are-user-errors.md
 */

/** A route that throws a specific driver error, registered live for one test. */
function routeThrowing(string $state, int $code, string $message, string $sql = 'update `assets` set `copyright` = ? where `id` = ?'): string
{
    Route::middleware('web')->post('/__spec/db-error', function () use ($state, $code, $message, $sql) {
        $pdo = new PDOException("SQLSTATE[{$state}]: {$message}");
        $pdo->errorInfo = [$state, $code, $message];

        throw new QueryException('mysql', $sql, ['a-secret-value', 1], $pdo);
    });

    return '/__spec/db-error';
}

/** A route whose body really makes SQLite raise the given failure. */
function routeRunning(callable $body): string
{
    Route::middleware('web')->post('/__spec/db-real', $body);

    return '/__spec/db-real';
}

test('an over-length value rejected by the driver becomes a keyed 422', function () {
    $url = routeThrowing('22001', 1406, "Data too long for column 'copyright' at row 1");

    $response = $this->actingAs(User::factory()->create(['role' => 'editor']))->postJson($url);

    $response->assertStatus(422)->assertJsonValidationErrors('copyright');

    // 500 characters is the limit the message must quote, and the SQL must not appear at all.
    expect($response->json('message'))->toContain((string) ColumnLimits::for('assets', 'copyright'))
        ->and($response->getContent())->not->toContain('a-secret-value')
        ->and($response->getContent())->not->toContain('SQLSTATE')
        ->and($response->getContent())->not->toContain('update `assets`');
});

test('the same rejection on a form post redirects back with the field error and the input', function () {
    // The reported bug's actual journey: the classic edit form, not an XHR. The existing
    // @error('copyright') block in edit.blade.php renders this with no view change.
    $url = routeThrowing('22001', 1406, "Data too long for column 'copyright' at row 1");
    $asset = Asset::factory()->create();

    $response = $this->actingAs(User::factory()->create(['role' => 'editor']))
        ->from(route('assets.edit', $asset))
        ->post($url, ['copyright' => 'a very long copyright']);

    $response->assertRedirect(route('assets.edit', $asset))
        ->assertSessionHasErrors('copyright')
        ->assertSessionHasInput('copyright', 'a very long copyright');
});

test('a driver rejection is indistinguishable from the rule that should have caught it', function () {
    $user = User::factory()->create(['role' => 'editor']);
    $asset = Asset::factory()->create(['user_id' => $user->id]);

    // The real validation failure…
    $fromRule = $this->actingAs($user)->patchJson(route('assets.update', $asset), [
        'copyright' => str_repeat('a', ColumnLimits::for('assets', 'copyright') + 1),
    ]);

    // …and the backstop catching the same thing at the driver.
    $fromDriver = $this->actingAs($user)->postJson(
        routeThrowing('22001', 1406, "Data too long for column 'copyright' at row 1")
    );

    expect($fromDriver->status())->toBe($fromRule->status())
        ->and(array_keys($fromDriver->json()))->toBe(array_keys($fromRule->json()))
        ->and(array_keys($fromDriver->json('errors')))->toBe(array_keys($fromRule->json('errors')));
});

test('a real unique-constraint violation becomes a keyed 422', function () {
    Tag::factory()->create(['name' => 'landscape', 'type' => 'user']);

    $url = routeRunning(fn () => DB::table('tags')->insert([
        'name' => 'landscape', 'type' => 'user', 'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->actingAs(User::factory()->create(['role' => 'editor']))
        ->postJson($url)
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

test('a real not-null violation names the missing field', function () {
    // s3_key_hash is supplied even though s3_key is not, deliberately: it is also NOT NULL, and
    // leaving both out would let the driver report whichever column it reaches first. Filling it
    // makes s3_key the only missing one, so this asserts what it claims to.
    $url = routeRunning(fn () => DB::table('assets')->insert([
        'filename' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size' => 1,
        's3_key_hash' => S3KeyHash::of('assets/never-inserted.jpg'),
        'created_at' => now(), 'updated_at' => now(),
    ]));

    $this->actingAs(User::factory()->create(['role' => 'editor']))
        ->postJson($url)
        ->assertStatus(422)
        ->assertJsonValidationErrors('s3_key');
});

test('a real foreign-key violation is a 409 with an actionable message', function () {
    // A raw insert bypasses Asset's saving hook, so s3_key_hash has to be written by hand or the
    // NOT NULL on the surrogate fires before the foreign key this test is about.
    $url = routeRunning(fn () => DB::table('assets')->insert([
        's3_key' => 'assets/orphan.jpg', 's3_key_hash' => S3KeyHash::of('assets/orphan.jpg'),
        'filename' => 'orphan.jpg', 'mime_type' => 'image/jpeg',
        'size' => 1, 'user_id' => 999999, 'created_at' => now(), 'updated_at' => now(),
    ]));

    $response = $this->actingAs(User::factory()->create(['role' => 'editor']))->postJson($url);

    $response->assertStatus(409);
    expect($response->json('message'))->toBe(__('This change conflicts with a linked record. Reload the page and try again.'));
});

test('an unclassified database error keeps the debug output in development', function () {
    // The renderer returns null when it cannot classify the error and app.debug is on, so the
    // framework's own debug handling still runs and the developer keeps the exception and the
    // stack trace. Without that guard, every unrecognised DB error in dev would become an opaque
    // friendly message — which is why the null return must not be "simplified" away.
    config(['app.debug' => true]);

    $url = routeThrowing('HY000', 1030, 'Got error 28 from storage engine');

    $response = $this->actingAs(User::factory()->create(['role' => 'editor']))->postJson($url);

    $response->assertStatus(500);

    expect($response->json('message'))->toContain('Got error 28 from storage engine')
        ->and($response->json())->toHaveKey('exception');
});

test('an api-role caller sees no internals in an unclassified failure', function () {
    // APP_DEBUG is true by default in this repo, and with it on Laravel ships file, line and the
    // full stack trace in a JSON 5xx. Production is the case under test.
    config(['app.debug' => false]);

    $url = routeThrowing('HY000', 1030, 'Got error 28 from storage engine');

    $response = $this->actingAs(User::factory()->create(['role' => 'api']))->postJson($url);

    $response->assertStatus(500);

    expect($response->getContent())
        ->not->toContain('SQLSTATE')
        ->not->toContain('storage engine')
        ->not->toContain('a-secret-value')
        ->not->toContain('update `assets`')
        ->and($response->json('message'))->not->toBeEmpty()
        // Only the generic message survives for an api caller — no file, no line, no trace.
        ->and(array_keys($response->json()))->toBe(['message']);
});

test('every error response carries a reference the user can quote', function () {
    config(['app.debug' => false]);

    $url = routeThrowing('HY000', 1030, 'Got error 28 from storage engine');

    $response = $this->actingAs(User::factory()->create(['role' => 'editor']))->postJson($url);

    $reference = $response->headers->get('X-Orca-Error-Id');

    expect($reference)->not->toBeNull()
        ->and($reference)->toMatch('/^[0-9A-F]{6}$/')
        // The same value the log context carried, so an operator can find this exact request.
        ->and($reference)->toBe(ErrorId::current())
        ->and($response->json('message'))->toContain($reference);
});

test('the error page shows the same reference', function () {
    config(['app.debug' => false]);

    Route::middleware('web')->get('/__spec/boom', fn () => throw new RuntimeException('kaboom'));

    $response = $this->actingAs(User::factory()->create(['role' => 'editor']))->get('/__spec/boom');

    $response->assertStatus(500)->assertSee(ErrorId::current());
});

test('a normal validation failure is untouched by the backstop', function () {
    // The regression guard: the handler now has a render callback in front of it, and a
    // ValidationException must still take its own path.
    $user = User::factory()->create(['role' => 'editor']);
    $asset = Asset::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patchJson(route('assets.update', $asset), ['license_type' => str_repeat('a', 300)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('license_type');
});
