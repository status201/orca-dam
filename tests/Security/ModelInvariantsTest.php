<?php

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Tests\Security\Support\SourceScanner;

/**
 * REQ-6 of specs/features/security-invariants.md — the model layer cannot be talked into
 * granting something.
 *
 * `role` is mass-assignable on User, and has to be: the admin user form assigns it. That makes
 * every write to a User one careless argument away from privilege escalation — `fill($request
 * ->all())` in any self-service action would do it, silently, with no route or policy change to
 * review. The same shape applies to the encrypted columns: a secret that is in `casts()` but
 * missing from `$hidden` leaks the moment the model is serialised into a JSON response.
 *
 * These are cheap to assert and impossible to notice by reading a diff.
 */

/** Ways a request's whole payload can reach a model write. */
function unfilteredAssignmentPatterns(): array
{
    return [
        '$request->all()',
        '$request->except(',
        'request()->all()',
    ];
}

/** Model-write methods that would apply such a payload. */
function modelWriteMethods(): array
{
    return ['fill(', 'update(', 'create(', 'forceFill('];
}

// ─── REQ-6: mass assignment ───────────────────────────────────────────────────

/**
 * Not "does $request->all() appear" — it appears legitimately, for logging and for building
 * queries. The finding is $request->all() being handed *directly* to a model write, because that
 * is the form that assigns whatever the caller sent, including fields the form never showed them.
 */
test('no model write is handed an unfiltered request payload', function () {
    $offenders = [];

    foreach (SourceScanner::phpFilesUnder(app_path()) as $file) {
        $source = SourceScanner::sourceOf($file);

        foreach (modelWriteMethods() as $method) {
            foreach (SourceScanner::callArgumentsFor($source, $method) as $call) {
                foreach (unfilteredAssignmentPatterns() as $pattern) {
                    if (str_contains($call, $pattern)) {
                        $offenders[] = SourceScanner::relative($file).': '
                            .trim(preg_replace('/\s+/', ' ', $call));
                    }
                }
            }
        }
    }

    expect($offenders)->toBe([],
        "These model writes assign an unfiltered request payload:\n  ".implode("\n  ", $offenders)
        ."\n\nUser::\$fillable includes 'role', so any of these on a User is a privilege-escalation "
        .'path. Validate first and assign $request->validated(), or list the fields explicitly.'
    );
});

/** The detector must be able to fire. */
test('the unfiltered-assignment detector actually catches one', function () {
    $bad = '$user->update($request->all());';
    $good = '$user->update($request->validated());';

    $hits = fn (string $source) => SourceScanner::callArgumentsFor($source, 'update(');

    expect($hits($bad)[0])->toContain('$request->all()');
    expect($hits($good)[0])->not->toContain('$request->all()');
});

/**
 * The single most important allowlist in the app: the fields a user may change about themselves.
 * ProfileController::update assigns `$request->validated()`, so this rule set *is* the boundary —
 * add 'role' here and any editor can make themselves an admin. PrivilegeEscalationTest drives the
 * same guarantee over HTTP; this states it as a contract so the failure names the cause.
 */
test('the profile form cannot assign anything beyond name and email', function () {
    // rules() reads $this->user()->id for the unique-email exclusion, so the request needs a
    // resolver — instantiating it bare throws before the rules can be inspected.
    $user = User::factory()->editor()->create();

    $request = ProfileUpdateRequest::create(route('profile.update'), 'PATCH');
    $request->setUserResolver(fn () => $user);

    $rules = array_keys($request->rules());

    sort($rules);

    expect($rules)->toBe(['email', 'name'],
        'ProfileUpdateRequest now accepts ['.implode(', ', $rules).']. Self-service profile '
        .'updates must never reach a privilege field; see specs/features/security-invariants.md REQ-6.'
    );
});

test('no model opts out of mass-assignment protection', function () {
    $unguarded = [];

    foreach (SourceScanner::phpFilesUnder(app_path('Models')) as $file) {
        $source = SourceScanner::sourceOf($file);

        if (preg_match('/\$guarded\s*=\s*\[\s*\]/', $source) === 1) {
            $unguarded[] = SourceScanner::relative($file);
        }
    }

    expect($unguarded)->toBe([],
        'These models set $guarded = [], which makes every column mass-assignable including any '
        .'added later: '.implode(', ', $unguarded)
    );
});

// ─── REQ-6: secrets stay out of serialised output ─────────────────────────────

/**
 * `jwt_secret`, `two_factor_secret` and `two_factor_recovery_codes` are encrypted at rest, which
 * protects the database — not the API response. `$hidden` is what keeps them out of a serialised
 * User, and the two lists are maintained by hand in different parts of the class. This ties them
 * together so a new encrypted column cannot be added to one and forgotten in the other.
 */
test('every encrypted user attribute is hidden from serialisation', function () {
    $user = new User;

    $casts = $user->getCasts();
    $hidden = $user->getHidden();

    $encrypted = array_keys(array_filter(
        $casts,
        fn ($cast) => is_string($cast) && str_starts_with($cast, 'encrypted')
    ));

    expect($encrypted)->not->toBeEmpty('Expected User to encrypt at least jwt_secret and the 2FA columns.');

    $exposed = array_values(array_diff($encrypted, $hidden));

    expect($exposed)->toBe([],
        'These User attributes are encrypted at rest but not in $hidden, so they are serialised '
        .'into API responses: '.implode(', ', $exposed)
    );
});

test('the credential columns are hidden from serialisation', function () {
    $required = ['password', 'remember_token', 'jwt_secret', 'two_factor_secret', 'two_factor_recovery_codes'];

    $missing = array_values(array_diff($required, (new User)->getHidden()));

    expect($missing)->toBe([],
        'These credential attributes are absent from User::$hidden, so they are serialised into '
        .'API responses: '.implode(', ', $missing)
    );
});

/** The property that actually matters, asserted on a real serialisation rather than on config. */
test('a serialised user carries no credential material', function () {
    $user = User::factory()->admin()->create([
        'jwt_secret' => str_repeat('s', 64),
        'two_factor_secret' => 'TOTPSECRET',
    ]);

    $json = $user->fresh()->toJson();

    foreach (['password', 'remember_token', 'jwt_secret', 'two_factor_secret'] as $attribute) {
        expect($json)->not->toContain($attribute);
    }

    expect($json)->not->toContain('TOTPSECRET');
    expect($json)->not->toContain(str_repeat('s', 64));
});
