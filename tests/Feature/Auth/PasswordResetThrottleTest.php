<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification as ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Hardening on the password-reset pair — see specs/features/authentication.md REQ-9.
 *
 * Kept apart from PasswordResetTest.php (scaffold-shaped, happy path) because the 6/min
 * route limit is a shared budget: mixing throttle cases into that file would couple those
 * four tests to each other's request counts. CACHE_STORE=array (phpunit.xml) gives each
 * test its own limiter, so no case here has to reset it — they just stay under 6 requests,
 * except the one that is proving the ceiling.
 */
class PasswordResetThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_address_gets_the_same_answer_as_a_known_one(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();

        $this->assertSame(
            $known->getSession()->get('status'),
            $unknown->getSession()->get('status'),
            'A known and an unknown address must be indistinguishable, or the endpoint is '
            .'a login-name oracle. See specs/features/authentication.md REQ-9.'
        );

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_a_broker_throttled_repeat_is_indistinguishable_from_the_first_request(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $first = $this->post('/forgot-password', ['email' => $user->email]);
        $second = $this->post('/forgot-password', ['email' => $user->email]);

        $second->assertSessionHasNoErrors();
        $this->assertSame(
            $first->getSession()->get('status'),
            $second->getSession()->get('status'),
            'passwords.throttled leaks that the address exists just as surely as passwords.user.'
        );

        // The broker's own 60s throttle still holds — the uniform answer is a presentation
        // change, not a licence to send more mail.
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_the_reset_routes_are_rate_limited_per_ip(): void
    {
        Notification::fake();

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->post('/forgot-password', ['email' => "probe{$attempt}@example.com"])
                ->assertStatus(302);
        }

        $this->post('/forgot-password', ['email' => 'probe7@example.com'])
            ->assertStatus(429);
    }

    public function test_every_password_reset_route_carries_the_throttle(): void
    {
        $expected = ['password.request', 'password.email', 'password.reset', 'password.store'];

        foreach ($expected as $name) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "Route {$name} is missing.");
            $this->assertContains('throttle:6,1', $route->gatherMiddleware(),
                "Route {$name} lost its throttle. See specs/features/authentication.md REQ-9."
            );
        }
    }
}
