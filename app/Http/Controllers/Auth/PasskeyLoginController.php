<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

class PasskeyLoginController extends Controller
{
    /**
     * Maximum failed assertion attempts per IP per minute.
     */
    protected const RATE_LIMIT = 10;

    /**
     * Session key for the in-flight assertion ceremony options.
     */
    private const SESSION_KEY = 'passkey.verification_options';

    /**
     * Return assertion (login) options. Always discoverable — the authenticator
     * surfaces matching credentials via conditional UI without us scoping by email.
     */
    public function options(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate();
        $request->session()->put(self::SESSION_KEY, WebAuthn::toJson($options));

        return response()->json([
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    /**
     * Verify an assertion and log the user in. The user lands at /dashboard,
     * bypassing the AuthenticatedSessionController 2FA gate by routing — the
     * passkey already provides phishing-resistant possession + verification.
     */
    public function login(Request $request, VerifyPasskey $verify): JsonResponse
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

        $validated = $request->validate([
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
            'remember' => ['boolean'],
        ]);

        try {
            $credential = WebAuthn::fromJson(
                json_encode($validated['credential']) ?: '{}',
                PublicKeyCredential::class
            );
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'credential' => __('Invalid credential format.'),
            ]);
        }

        $serialized = $request->session()->pull(self::SESSION_KEY);

        if (! is_string($serialized) || $serialized === '') {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'credential' => __('Passkey verification session expired. Please try again.'),
            ]);
        }

        $options = WebAuthn::fromJson($serialized, PublicKeyCredentialRequestOptions::class);

        try {
            $passkey = $verify($credential, $options);
        } catch (InvalidPasskeyException) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'message' => __('Passkey authentication failed.'),
            ], 422);
        }

        $user = $passkey->user;

        if (! $user instanceof User) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'message' => __('Passkey authentication failed.'),
            ], 422);
        }

        RateLimiter::clear($key);

        Auth::guard('web')->login($user, (bool) ($validated['remember'] ?? false));
        $request->session()->regenerate();

        $user->last_login_at = now();
        $user->save();

        return response()->json([
            'redirect' => route('dashboard', absolute: false),
        ]);
    }
}
