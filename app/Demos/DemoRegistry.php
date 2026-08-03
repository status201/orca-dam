<?php

namespace App\Demos;

use App\Models\User;

/**
 * The set of guided demos the app knows about.
 *
 * Constructed with an explicit list in AppServiceProvider::register() rather than
 * discovered by glob, so the order demos are offered in is deterministic — the same
 * choice resources/js/app.js makes for Alpine modules.
 *
 * See specs/features/guided-demos.md.
 */
class DemoRegistry
{
    /**
     * @param  array<int, Demo>  $demos
     */
    public function __construct(private array $demos = []) {}

    /**
     * A demo by id, ignoring availability. Use get() for anything user-facing.
     */
    public function find(string $id): ?Demo
    {
        foreach ($this->demos as $demo) {
            if ($demo->id() === $id) {
                return $demo;
            }
        }

        return null;
    }

    /**
     * A demo by id, gated for this user. Null when unknown *or* not available —
     * callers that need to tell the two apart use find() as well.
     */
    public function get(string $id, User $user): ?Demo
    {
        $demo = $this->find($id);

        return $demo && $demo->isAvailableTo($user) ? $demo : null;
    }

    /**
     * Every demo this user may play, in registration order.
     *
     * @return array<int, Demo>
     */
    public function all(User $user): array
    {
        return array_values(array_filter(
            $this->demos,
            static fn (Demo $demo) => $demo->isAvailableTo($user),
        ));
    }

    /**
     * The successor offered when $demo finishes, gated for this user.
     *
     * A demo may name a successor unconditionally; the gate lives here, so an editor
     * finishing a demo whose successor is admin-only simply sees no offer.
     */
    public function next(Demo $demo, User $user): ?Demo
    {
        $id = $demo->nextDemoId();

        return $id === null ? null : $this->get($id, $user);
    }

    /**
     * The payload the browser needs, or null when no demo is armed.
     *
     * Returning null is the common case — the overlay renders nothing at all on a page
     * with no `?demo=` parameter (REQ-12), so this must stay cheap.
     *
     * @return array<string, mixed>|null
     */
    public function payload(?string $id, ?User $user, int $step = 0, ?string $currentRoute = null): ?array
    {
        if ($id === null || $id === '' || $user === null) {
            return null;
        }

        $demo = $this->get($id, $user);

        if ($demo === null) {
            return null;
        }

        $steps = $demo->steps($user);

        if ($steps === []) {
            return null;
        }

        $next = $this->next($demo, $user);

        return [
            'id' => $demo->id(),
            'title' => $demo->title(),
            // Clamped rather than rejected: a hand-edited or stale link should open the
            // demo somewhere sensible instead of failing.
            'step' => max(0, min($step, count($steps) - 1)),
            'currentRoute' => $currentRoute,
            'completeUrl' => route('demos.complete', $demo->id()),
            'next' => $next === null ? null : ['id' => $next->id(), 'title' => $next->title()],
            'ui' => self::chrome(),
            'steps' => array_map(static fn (DemoStep $s) => $s->toArray(), $steps),
        ];
    }

    /**
     * The overlay's own copy — shared by every demo, so a new demo adds no chrome strings.
     *
     * @return array<string, string>
     */
    public static function chrome(): array
    {
        return [
            'next' => __('Next'),
            'back' => __('Back'),
            'skip' => __('Skip demo'),
            'done' => __('Done'),
            'stepOf' => __('Step :current of :total'),
            'goToPage' => __('Continue on the next page'),
            'notNow' => __('Not now'),
            'startNext' => __('Start :title'),
            'finished' => __('That is the tour — you know where everything lives now.'),
            'tryIt' => __('Try it to continue, or use Next to skip ahead.'),
        ];
    }
}
