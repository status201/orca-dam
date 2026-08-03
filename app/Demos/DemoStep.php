<?php

namespace App\Demos;

/**
 * One step of a guided demo: an element to point at and something to say about it.
 *
 * A value object rather than an array so a mistyped key is a PHP error instead of a
 * silently missing popover — the engine skips a step it cannot resolve (by design,
 * see specs/features/guided-demos.md REQ-9), which makes typos otherwise invisible.
 *
 * `$target` is a data-testid *value*, never a CSS selector: the authoritative copy of
 * the string lives in the Blade view, and tests/Feature/GuidedDemoTest.php asserts the
 * correspondence.
 */
final readonly class DemoStep
{
    /**
     * @param  string|array<int, string>|null  $target  data-testid value(s). With a list, the
     *                                                  first *visible* candidate wins — that is how one step covers the
     *                                                  desktop/mobile duplicate pair and the grid's three view modes,
     *                                                  which differ by localStorage and so cannot be resolved here.
     *                                                  Null renders an unanchored card.
     * @param  string  $routeName  the route this step belongs to; a step on another route
     *                             renders as a hand-off card instead of a spotlight.
     * @param  array<string, mixed>  $routeParams
     * @param  string  $placement  top|bottom|left|right|center
     * @param  string|array<string, string>|null  $reveal  'scroll-top' | ['hover' => testid] | ['click' => testid]
     * @param  array<string, mixed>|null  $advanceOn  ['event' => 'input'|'click'|'change', 'on' => 'self',
     *                                                'minLength' => int] | ['appear' => testid]
     * @param  string  $fallback  'skip' (silently move on) | 'center' (deliver the copy unanchored)
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $routeName,
        public string|array|null $target = null,
        public array $routeParams = [],
        public string $placement = 'bottom',
        public string|array|null $reveal = null,
        public ?array $advanceOn = null,
        public string $fallback = 'skip',
    ) {}

    /**
     * The wire shape handed to the browser.
     *
     * route() is resolved here — at request time, inside a request — never at
     * definition time, so the demo survives route:cache and a subdirectory install.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'title' => $this->title,
            'body' => $this->body,
            'routeName' => $this->routeName,
            'url' => route($this->routeName, $this->routeParams),
            'placement' => $this->placement,
            'reveal' => $this->reveal,
            'advanceOn' => $this->advanceOn,
            'fallback' => $this->fallback,
        ];
    }

    /**
     * Every data-testid this step depends on, target and reveal alike.
     *
     * Used by the integrity test to prove each one is actually rendered somewhere.
     *
     * @return array<int, string>
     */
    public function testids(): array
    {
        $ids = is_array($this->target) ? $this->target : (array) $this->target;

        if (is_array($this->reveal)) {
            $ids = array_merge($ids, array_values($this->reveal));
        }

        if (isset($this->advanceOn['appear'])) {
            $ids[] = $this->advanceOn['appear'];
        }

        return array_values(array_filter($ids, static fn ($id) => is_string($id) && $id !== ''));
    }
}
