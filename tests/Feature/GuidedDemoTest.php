<?php

use App\Demos\Demo;
use App\Demos\DemoRegistry;
use App\Demos\DemoStep;
use App\Demos\WelcomeDemo;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Pins specs/features/guided-demos.md.
 *
 * The load-bearing tests here are the first two: a step's `target` is a *reference* to a
 * data-testid whose authoritative copy lives in a Blade file, and nothing in the type
 * system connects them. Because a step with an unresolvable target is skipped silently by
 * design (REQ-9), a typo would otherwise ship as a demo that is merely shorter than it was
 * written to be. These iterate every registered demo, so a demo added later is covered
 * without adding a test.
 */

/** Every data-testid value rendered by any Blade view, gathered once. */
function renderedTestids(): array
{
    static $ids = null;

    if ($ids !== null) {
        return $ids;
    }

    $ids = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        preg_match_all('/data-testid="([^"]+)"/', $file->getContents(), $matches);
        foreach ($matches[1] as $id) {
            $ids[$id] = true;
        }
    }

    return $ids;
}

/** @return array<int, array{Demo, int, DemoStep}> */
function everyRegisteredStep(User $user): array
{
    $registry = app(DemoRegistry::class);
    $rows = [];

    foreach ($registry->all($user) as $demo) {
        foreach ($demo->steps($user) as $index => $step) {
            $rows[] = [$demo, $index, $step];
        }
    }

    return $rows;
}

test('every demo step targets a data-testid that some Blade view renders', function () {
    $rendered = renderedTestids();
    $user = User::factory()->create(['role' => 'admin']);

    $missing = [];

    foreach (everyRegisteredStep($user) as [$demo, $index, $step]) {
        foreach ($step->testids() as $id) {
            if (! isset($rendered[$id])) {
                $missing[] = "{$demo->id()} step {$index}: {$id}";
            }
        }
    }

    expect($missing)->toBe([]);
});

test('every demo step names a route that exists', function () {
    $user = User::factory()->create(['role' => 'admin']);

    foreach (everyRegisteredStep($user) as [$demo, $index, $step]) {
        expect(Route::has($step->routeName))
            ->toBeTrue("{$demo->id()} step {$index} names unknown route {$step->routeName}");

        expect($step->toArray()['url'])->toBeString()->not->toBe('');
    }
});

test('every demo step declares a placement and fallback the engine understands', function () {
    $user = User::factory()->create(['role' => 'admin']);

    foreach (everyRegisteredStep($user) as [$demo, $index, $step]) {
        expect($step->placement)->toBeIn(['top', 'bottom', 'left', 'right', 'center']);
        expect($step->fallback)->toBeIn(['skip', 'center']);
    }
});

test('a named successor resolves to a registered demo or is deliberately absent', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $registry = app(DemoRegistry::class);

    foreach ($registry->all($admin) as $demo) {
        $id = $demo->nextDemoId();

        if ($id === null) {
            continue;
        }

        // 'admin-basics' is named by WelcomeDemo but not written yet — recorded under
        // "Open questions" in the spec. The registry handles it by offering nothing,
        // which is exactly what this asserts.
        expect($registry->next($demo, $admin))->toBeNull();
    }
})->skip(fn () => app(DemoRegistry::class)->find('admin-basics') !== null,
    'admin-basics now exists — assert it resolves instead');

test('the registry ships no payload when no demo is named', function () {
    $user = User::factory()->create(['role' => 'editor']);
    $registry = app(DemoRegistry::class);

    expect($registry->payload(null, $user))->toBeNull();
    expect($registry->payload('', $user))->toBeNull();
    expect($registry->payload('welcome', null))->toBeNull();
    expect($registry->payload('no-such-demo', $user))->toBeNull();
});

test('an out of range step is clamped rather than rejected', function () {
    $user = User::factory()->create(['role' => 'editor']);
    $registry = app(DemoRegistry::class);

    $last = count((new WelcomeDemo)->steps($user)) - 1;

    expect($registry->payload('welcome', $user, 999)['step'])->toBe($last);
    expect($registry->payload('welcome', $user, -5)['step'])->toBe(0);
});

test('the payload carries translated chrome and a resolved url per step', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $payload = app(DemoRegistry::class)->payload('welcome', $user, 0, 'dashboard');

    expect($payload['id'])->toBe('welcome');
    expect($payload['currentRoute'])->toBe('dashboard');
    expect($payload['ui'])->toHaveKeys(['next', 'back', 'skip', 'done']);
    expect($payload['steps'][0]['url'])->toBe(route('dashboard'));
    // The successor is not registered, so nothing is offered (see above).
    expect($payload['next'])->toBeNull();
});

test('a demo the viewer may not play does not boot', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $registry = new DemoRegistry([new class implements Demo
    {
        public function id(): string
        {
            return 'admins-only';
        }

        public function title(): string
        {
            return 'Admins only';
        }

        public function description(): string
        {
            return '';
        }

        public function isAvailableTo(User $user): bool
        {
            return $user->isAdmin();
        }

        public function nextDemoId(): ?string
        {
            return null;
        }

        public function steps(User $user): array
        {
            return [new DemoStep(title: 't', body: 'b', routeName: 'dashboard')];
        }
    }]);

    expect($registry->find('admins-only'))->not->toBeNull();
    expect($registry->get('admins-only', $user))->toBeNull();
    expect($registry->all($user))->toBe([]);
    expect($registry->payload('admins-only', $user))->toBeNull();
});

test('the welcome demo is available to every role', function () {
    foreach (['admin', 'editor', 'api'] as $role) {
        $user = User::factory()->create(['role' => $role]);

        expect((new WelcomeDemo)->isAvailableTo($user))->toBeTrue();
        expect(app(DemoRegistry::class)->payload('welcome', $user))->not->toBeNull();
    }
});

test('completing a demo records it against the user', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $response = $this->actingAs($user)->postJson(route('demos.complete', 'welcome'));

    $response->assertOk();
    $response->assertJsonStructure(['message', 'completed']);

    expect($user->fresh()->getPreference('guided_demos.welcome.completed_at'))->toBeString();
    expect($user->fresh()->getPreference('guided_demos.welcome.dismissed'))->toBeFalse();
});

test('dismissing a demo is recorded distinctly from finishing it', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $this->actingAs($user)
        ->postJson(route('demos.complete', 'welcome'), ['dismissed' => true])
        ->assertOk();

    expect($user->fresh()->getPreference('guided_demos.welcome.dismissed'))->toBeTrue();
});

test('completing a demo leaves the user other preferences alone', function () {
    // The regression this exists for: folding completion into
    // ProfileController::updatePreferences() would unset every field the request omits.
    $user = User::factory()->create([
        'role' => 'editor',
        'preferences' => [
            'home_folder' => 'assets',
            'items_per_page' => 48,
            'dark_mode' => 'force_dark',
            'locale' => 'nl',
        ],
    ]);

    $this->actingAs($user)->postJson(route('demos.complete', 'welcome'))->assertOk();

    $fresh = $user->fresh();
    expect($fresh->getPreference('home_folder'))->toBe('assets');
    expect($fresh->getPreference('items_per_page'))->toBe(48);
    expect($fresh->getPreference('dark_mode'))->toBe('force_dark');
    expect($fresh->getPreference('locale'))->toBe('nl');
    expect($fresh->getPreference('guided_demos.welcome.completed_at'))->toBeString();
});

test('an unknown demo cannot be marked complete', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $this->actingAs($user)
        ->postJson(route('demos.complete', 'no-such-demo'))
        ->assertNotFound();
});

test('a guest cannot mark a demo complete', function () {
    $this->postJson(route('demos.complete', 'welcome'))->assertUnauthorized();
});

test('a demo the caller may not play cannot be marked complete', function () {
    $editor = User::factory()->create(['role' => 'editor']);

    $this->app->instance(DemoRegistry::class, new DemoRegistry([new class implements Demo
    {
        public function id(): string
        {
            return 'admins-only';
        }

        public function title(): string
        {
            return 'Admins only';
        }

        public function description(): string
        {
            return '';
        }

        public function isAvailableTo(User $user): bool
        {
            return $user->isAdmin();
        }

        public function nextDemoId(): ?string
        {
            return null;
        }

        public function steps(User $user): array
        {
            return [new DemoStep(title: 't', body: 'b', routeName: 'dashboard')];
        }
    }]));

    $this->actingAs($editor)
        ->postJson(route('demos.complete', 'admins-only'))
        ->assertForbidden();
});

test('the demo overlay renders once on an authenticated page and never for a guest', function () {
    $user = User::factory()->create(['role' => 'editor']);

    $armed = $this->actingAs($user)->get(route('dashboard', ['demo' => 'welcome']));
    $armed->assertOk();
    expect(substr_count($armed->getContent(), 'data-testid="demo-overlay"'))->toBe(1);

    $idle = $this->actingAs($user)->get(route('dashboard'));
    $idle->assertOk();
    // REQ-12: nothing at all ships when no demo is armed. (The dashboard still mentions
    // "guidedDemo" — the carousel's launcher slide keys — so assert on the payload
    // assignment itself, not the word.)
    $idle->assertDontSee('demo-overlay', false);
    $idle->assertDontSee('__pageData.guidedDemo', false);

    auth()->logout();
    $this->get(route('login'))->assertDontSee('demo-overlay', false);
});

test('the demo overlay renders its chrome in Dutch', function () {
    $user = User::factory()->create(['role' => 'editor', 'preferences' => ['locale' => 'nl']]);

    $response = $this->actingAs($user)->get(route('dashboard', ['demo' => 'welcome']));

    $response->assertOk();
    $response->assertSee(__('Skip demo', [], 'nl'), false);
    expect(__('Skip demo', [], 'nl'))->not->toBe('Skip demo');
});
