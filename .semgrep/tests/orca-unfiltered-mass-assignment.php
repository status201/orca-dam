<?php

/**
 * Fixture for the orca-unfiltered-mass-assignment rule — run by `semgrep --test`.
 *
 * Not autoloaded, never executed.
 */

use Illuminate\Http\Request;

function orcaFixtureMassAssignment(Request $request, object $user): void
{
    // ruleid: orca-unfiltered-mass-assignment
    $user->fill($request->all());

    // ruleid: orca-unfiltered-mass-assignment
    $user->update($request->all());

    // ruleid: orca-unfiltered-mass-assignment
    $user->forceFill($request->all());

    // ruleid: orca-unfiltered-mass-assignment
    $user->update(request()->all());

    // ruleid: orca-unfiltered-mass-assignment
    $user->update($request->except(['password']));

    // The five combinations the hand-written pattern list missed. `$WRITE` is a metavariable now,
    // so every (method × payload) pair is covered — these assert that.
    // ruleid: orca-unfiltered-mass-assignment
    $user->create($request->all());

    // ruleid: orca-unfiltered-mass-assignment
    $user->create(request()->all());

    // ruleid: orca-unfiltered-mass-assignment
    $user->create($request->except(['password']));

    // ruleid: orca-unfiltered-mass-assignment
    $user->forceFill(request()->all());

    // ruleid: orca-unfiltered-mass-assignment
    $user->forceFill($request->except(['password']));

    // A write method outside the four is not this rule's business.
    // ok: orca-unfiltered-mass-assignment
    $user->setRawAttributes($request->all());

    // The shape ProfileController actually uses.
    // ok: orca-unfiltered-mass-assignment
    $user->fill($request->validated());

    // ok: orca-unfiltered-mass-assignment
    $user->update(['name' => $request->input('name')]);

    // ok: orca-unfiltered-mass-assignment
    $user->update($request->only(['name', 'email']));

    // $request->all() is fine when it is not being assigned to a model.
    // ok: orca-unfiltered-mass-assignment
    logger()->debug('payload', $request->all());
}
