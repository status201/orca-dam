<!--
  Recipe: add a new guided demo (an interactive onboarding walkthrough).
-->

# Recipe — Add a guided demo

```yaml
id: add-a-guided-demo
status: implemented
version: 1
owner: core
related:
  - architecture
  - ../features/guided-demos
  - ../features/localization
  - ../decisions/adr-015-guided-demos-server-declared
source:
  - app/Demos/
  - app/Providers/AppServiceProvider.php
  - lang/nl.json
```

A repeatable **playbook**, not a feature. The demo engine is generic: it knows only
`data-testid` values and DOM events, so a new walkthrough is *data*, never engine code
(see [ADR-015](../decisions/adr-015-guided-demos-server-declared.md)). The concrete worked
instance is `App\Demos\WelcomeDemo`, specified by
[`guided-demos.md`](../features/guided-demos.md).

## Background / Why

Because a demo is one class behind the `App\Demos\Demo` interface, adding one cannot break an
existing one: the registry gates availability, so a demo may name a successor it is not allowed
to play, and a role that may not play a demo never sees it offered. That is what makes
"admin-only demos" a new file rather than a set of conditionals threaded through the demos that
already exist.

The steps below are almost all *reference* work — you are pointing at testids and route names
that already exist. The only judgement call is the storyboard.

## Steps

### 1. Storyboard it against real testids — before writing any PHP

List the elements you want to point at, in order, and find the `data-testid` each one already
carries. `grep -rn 'data-testid' resources/views/` is the whole tool. Two rules decide whether a
step is viable:

- **Every role the demo is available to must be able to see the target.** A step anchored to a
  `@can`-gated control in a demo available to editors is a step that silently skips. Check the
  role matrix in [`authorization-policies.md`](../features/authorization-policies.md).
- **If the element has no testid, add one** (`resources/views/**` is exempt from the SDD gate, so
  this is cheap). Watch for a Blade component that does not render `{{ $attributes }}` — a
  `data-testid` passed to one of those is silently dropped rather than erroring.

Group the storyboard by page. A page boundary costs one navigation, so a demo that alternates
between two pages step-by-step is a design mistake, not a configuration.

### 2. Define it — `app/Demos/<Name>Demo.php`

```php
namespace App\Demos;

use App\Models\User;

final class TrashDemo implements Demo
{
    public function id(): string { return 'trash'; }

    public function title(): string { return __('Trash and restore'); }

    public function description(): string { return __('How deleting works, and how to undo it.'); }

    // Mirror the policy that gates the pages this demo visits — never a bare `true`
    // for a demo that points at gated controls.
    public function isAvailableTo(User $user): bool
    {
        return $user->can('restore', \App\Models\Asset::class);
    }

    public function nextDemoId(): ?string { return null; }

    public function steps(User $user): array
    {
        return [
            new DemoStep(
                target: 'nav-assets-trash',
                title: __('Nothing is deleted straight away'),
                body: __('Removing an asset moves it here first, so a mistake costs a click to undo.'),
                routeName: 'dashboard',
                reveal: ['hover' => 'nav-assets'],
            ),
            new DemoStep(
                target: 'trash-restore-selected',
                title: __('Put it back'),
                body: __('Select what you want and restore it — the file itself never left storage.'),
                routeName: 'assets.trash',
                placement: 'bottom',
                advanceOn: ['event' => 'click', 'on' => 'self'],
                fallback: 'center',
            ),
        ];
    }
}
```

Only `target`, `title`, `body` and `routeName` are usually needed; the rest carry sensible
defaults. Reach for the optional fields when:

- **`target` as a list** — the first *visible* candidate wins. Use it for the desktop/mobile
  duplicate pair (`['grid-search', 'grid-search-mobile']`) and for elements whose variant depends
  on client state, like the asset grid's three view modes
  (`['asset-card', 'asset-masonry-card', 'asset-row']`).
- **`reveal`** — `'scroll-top'`, `['hover' => testid]` for a hover submenu, `['click' => testid]`
  for a collapsed panel.
- **`advanceOn`** — `['event' => 'input', 'on' => 'self', 'minLength' => 3]`,
  `['event' => 'click'|'change', 'on' => 'self']`, or `['appear' => testid]`. Use it for the one
  or two steps where doing the thing is the point; `Next` always stays available, so a step that
  merely *could* be interactive should not be.
- **`fallback: 'center'`** — when the target legitimately may not exist (an empty grid) but the
  explanation is still worth delivering. The default, `'skip'`, is right for a control that is
  simply absent for this user.
- **`placement`** — `'center'` for an unanchored card (an intro or an outro).

### 3. Register it — `app/Providers/AppServiceProvider.php`

```php
$this->app->singleton(DemoRegistry::class, fn () => new DemoRegistry([
    new Demos\WelcomeDemo(),
    new Demos\TrashDemo(),   // ← add here
]));
```

The list is explicit on purpose — the order is the order demos are offered in. If a demo hands
off to yours, set the predecessor's `nextDemoId()` to your `id()`; the registry gates the offer,
so you do not repeat the role check.

### 4. Translate it — `lang/nl.json`

Every `__()` string above needs a Dutch entry, inserted at its alphabetical position
(`app/Demos/` is scanned by `TranslationIntegrityTest`, so a missing one fails the Pest suite —
not the browser suite, where you would find it much later). See
[`add-a-translated-string.md`](add-a-translated-string.md); never run raw `lang:update`.

### 5. Verify

```bash
./vendor/bin/pint
php artisan config:clear && php artisan test --filter=GuidedDemo   # integrity: targets + routes
npm run test:e2e -- tests/e2e/guided-demo.spec.js
```

`tests/Feature/GuidedDemoTest.php` iterates **every** registered demo, so a typo'd testid or a
bad route name fails there without you writing a new test. Add a browser scenario of your own
only for behaviour the engine does not already cover — a novel `reveal` or `advanceOn`
combination — and pin it in the feature spec that owns the demo's subject matter, not in
`guided-demos.md`, which owns the engine.

Finally, walk it: `/dashboard?demo=<id>`. Nothing substitutes for seeing a spotlight land in the
wrong place.

## Gotchas

- **A skipped step looks like a working demo.** `fallback: 'skip'` is silent by design (REQ-9),
  so a mistyped testid produces a demo that is simply shorter than you wrote. Run the `--filter=GuidedDemo`
  Pest case before believing the browser.
- **A `data-testid` on a Blade component may go nowhere.** Components that do not render
  `{{ $attributes }}` drop it without erroring. Confirm the attribute reaches the DOM before
  writing the step.
- **`reveal: ['click' => …]` on a toggle can close what you just opened.** The engine checks the
  revealed panel's visibility first, so do not chain two steps that both click the same toggle.
- **A step whose control navigates on its own** (the grid's filter selects set
  `window.location.href`) must stay on the same route, or the demo resumes on a page its next step
  does not belong to and shows the hand-off card instead.
- **Don't hardcode a step count anywhere** — not in copy ("step 3 of 8"), not in a test. Steps are
  data and the engine reports the total.
- **`isAvailableTo` is not a policy.** It is deliberately outside `app/Policies/`
  ([ADR-015](../decisions/adr-015-guided-demos-server-declared.md)), so mirror the relevant
  ability rather than re-deriving a role list.

## Scenarios (BDD)

```gherkin
Scenario: A demo added by following this recipe is offered and playable
  Given a new Demo class registered in the DemoRegistry
  When a user it is available to opens a page naming it
  Then the payload is rendered and every step's target and route resolve
# pinned by: tests/Feature/GuidedDemoTest.php

Scenario: A demo added with a mistyped target fails the suite rather than shipping short
  Given a step naming a data-testid no Blade view renders
  When the Pest suite runs
  Then the registry integrity test fails and names the step
# pinned by: tests/Feature/GuidedDemoTest.php
```

## Tests & verification

- `tests/Feature/GuidedDemoTest.php` — iterates every registered demo, so it covers a new one
  the moment it is added to the registry: targets exist in the views, routes resolve, successors
  are registered.
- `tests/e2e/guided-demo.spec.js` — the engine's browser behaviour, which a new demo inherits.
- `./vendor/bin/pint --test` / `php artisan config:clear && php artisan test`.
