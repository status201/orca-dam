<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;

class PasskeyLoginController extends Controller
{
    /**
     * Maximum failed assertion attempts per IP per minute.
     */
    protected const RATE_LIMIT = 10;

    /**
     * Return assertion (login) options. Optionally scoped to an email if provided
     * (so the authenticator can offer matching credentials), but works without
     * one to support discoverable credentials / conditional UI.
     */
    public function options(AssertionRequest $request): Responsable
    {
        return $request->toVerify($request->validate([
            'email' => 'sometimes|nullable|email',
        ]));
    }

    /**
     * Verify an assertion and log the user in. Marks the session so the existing
     * 2FA gate in AuthenticatedSessionController is bypassed — the passkey already
     * provides phishing-resistant possession + verification.
     */
    public function login(AssertedRequest $request): JsonResponse
    {
        $key = 'passkey-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => __('Too many attempts. Please try again in :seconds seconds.', [
                    'seconds' => $seconds,
                ]),
            ], 429);
        }

        $user = $request->login();

        if (! $user instanceof User) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'message' => __('Passkey authentication failed.'),
            ], 422);
        }

        RateLimiter::clear($key);

        $user->last_login_at = now();
        $user->save();

        return response()->json([
            'redirect' => route('dashboard', absolute: false),
        ]);
    }
}
