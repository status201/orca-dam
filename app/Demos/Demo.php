<?php

namespace App\Demos;

use App\Models\User;

/**
 * One interactive onboarding walkthrough.
 *
 * A demo is declared here, in PHP, rather than in JS or config so that its copy
 * goes through __() (and therefore through lang/nl.json and
 * TranslationIntegrityTest), its links through route(), and its availability
 * through the authenticated user. See specs/decisions/adr-015-guided-demos-server-declared.md.
 *
 * Implementations must be registered explicitly in the DemoRegistry singleton —
 * see AppServiceProvider::register() and specs/recipes/add-a-guided-demo.md.
 */
interface Demo
{
    /**
     * Stable kebab-case identifier. This is the `?demo=` value in a URL and the
     * key completion is recorded under in users.preferences.
     */
    public function id(): string;

    /** Human-readable name, translated. */
    public function title(): string;

    /** One line describing what the demo covers, translated. */
    public function description(): string;

    /**
     * Whether this user may play the demo at all.
     *
     * A false answer hides the demo, refuses to boot it when named in a URL, and
     * refuses to record it as complete. Mirror the ability that gates the pages
     * the demo visits rather than re-deriving a role list.
     */
    public function isAvailableTo(User $user): bool;

    /**
     * The ordered steps, already filtered for this user.
     *
     * @return array<int, DemoStep>
     */
    public function steps(User $user): array;

    /**
     * The id of the demo offered when this one finishes, or null.
     *
     * May name a demo the user cannot play — DemoRegistry::next() gates the offer,
     * so a demo never repeats a role check its successor already owns.
     */
    public function nextDemoId(): ?string;
}
