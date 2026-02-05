<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class TwoFactorAuthController extends Controller
{
    public function __construct(
        protected TwoFactorService $twoFactorService
    ) {}

    /**
     * Show the 2FA challenge form during login
     */
    public function showChallenge(Request $request): View|RedirectResponse
    {
        // Check if we have a pending 2FA login
        if (! $request->session()->has('two_factor_user_id')) {
            return redirect()->route('login');
        }

        // Check if session has expired
        $timestamp = $request->session()->get('two_factor_timestamp');
        $ttl = config('two-factor.challenge_ttl', 300);

        if (! $timestamp || (time() - $timestamp) > $ttl) {
            $request->session()->forget(['two_factor_user_id', 'two_factor_timestamp']);

            return redirect()->route('login')
                ->withErrors(['email' => 'Your two-factor authentication session has expired. Please login again.']);
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * Verify the 2FA code during login
     */
    public function verifyChallenge(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        // Check if we have a pending 2FA login
        if (! $request->session()->has('two_factor_user_id')) {
            return redirect()->route('login');
        }

        $userId = $request->session()->get('two_factor_user_id');

        // Rate limiting
        $key = 'two-factor-challenge:'.$userId;
        $maxAttempts = config('two-factor.challenge_rate_limit', 5);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            $request->session()->forget(['two_factor_user_id', 'two_factor_timestamp']);

            return redirect()->route('login')
                ->withErrors(['email' => "Too many attempts. Please try again in {$seconds} seconds."]);
        }

        RateLimiter::hit($key, 60);

        $user = User::find($userId);

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget(['two_factor_user_id', 'two_factor_timestamp']);

            return redirect()->route('login');
        }

        $code = $request->input('code');

        // Check if it's a TOTP code (6 digits)
        if (preg_match('/^\d{6}$/', $code)) {
            if ($this->twoFactorService->verifyCode($user->two_factor_secret, $code)) {
                return $this->completeLogin($request, $user);
            }
        }

        // Check if it's a recovery code
        $hashedCodes = $user->two_factor_recovery_codes ?? [];
        $codeIndex = $this->twoFactorService->verifyRecoveryCode($code, $hashedCodes);

        if ($codeIndex !== false) {
            // Use the recovery code (remove it from the list)
            $this->twoFactorService->useRecoveryCode($user, $codeIndex);

            return $this->completeLogin($request, $user)
                ->with('status', 'You used a recovery code. You have '.$this->twoFactorService->getRemainingRecoveryCodesCount($user).' recovery codes remaining.');
        }

        return back()->withErrors(['code' => 'The provided two-factor authentication code is invalid.']);
    }

    /**
     * Complete the login after 2FA verification
     */
    protected function completeLogin(Request $request, User $user): RedirectResponse
    {
        // Clear rate limit on successful login
        RateLimiter::clear('two-factor-challenge:'.$user->id);

        // Clear 2FA session data
        $request->session()->forget(['two_factor_user_id', 'two_factor_timestamp']);

        // Log the user in
        Auth::login($user, $request->session()->get('two_factor_remember', false));
        $request->session()->forget('two_factor_remember');

        // Regenerate session
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Show the 2FA setup form
     */
    public function showSetup(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->canEnableTwoFactor()) {
            return redirect()->route('profile.edit')
                ->withErrors(['two_factor' => 'Your account type does not support two-factor authentication.']);
        }

        // If already enabled, redirect to profile
        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit');
        }

        // Generate a new secret if not in session or generate fresh
        $secret = $request->session()->get('two_factor_setup_secret');

        if (! $secret) {
            $secret = $this->twoFactorService->generateSecret();
            $request->session()->put('two_factor_setup_secret', $secret);
        }

        $qrCodeSvg = $this->twoFactorService->getQrCodeSvg($user, $secret);

        return view('auth.two-factor-setup', [
            'secret' => $secret,
            'qrCodeSvg' => $qrCodeSvg,
        ]);
    }

    /**
     * Confirm and enable 2FA
     */
    public function confirmSetup(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string|digits:6',
        ]);

        $user = $request->user();

        if (! $user->canEnableTwoFactor()) {
            return redirect()->route('profile.edit')
                ->withErrors(['two_factor' => 'Your account type does not support two-factor authentication.']);
        }

        $secret = $request->session()->get('two_factor_setup_secret');

        if (! $secret) {
            return redirect()->route('two-factor.setup')
                ->withErrors(['code' => 'Setup session expired. Please try again.']);
        }

        if (! $this->twoFactorService->verifyCode($secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'The provided code is invalid. Please try again.']);
        }

        // Enable 2FA and get recovery codes
        $recoveryCodes = $this->twoFactorService->enableTwoFactor($user, $secret);

        // Clear setup session
        $request->session()->forget('two_factor_setup_secret');

        // Store recovery codes in session to show on dedicated page
        $request->session()->put('two_factor_recovery_codes', $recoveryCodes);

        return redirect()->route('two-factor.recovery-codes.show')
            ->with('status', 'Two-factor authentication has been enabled. Please save your recovery codes.');
    }

    /**
     * Disable 2FA
     */
    public function disable(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit');
        }

        $this->twoFactorService->disableTwoFactor($user);

        return redirect()->route('profile.edit')
            ->with('status', 'Two-factor authentication has been disabled.');
    }

    /**
     * Regenerate recovery codes
     */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit')
                ->withErrors(['two_factor' => 'Two-factor authentication is not enabled.']);
        }

        $recoveryCodes = $this->twoFactorService->regenerateRecoveryCodes($user);

        // Store recovery codes in session to show on dedicated page
        $request->session()->put('two_factor_recovery_codes', $recoveryCodes);

        return redirect()->route('two-factor.recovery-codes.show')
            ->with('status', 'Recovery codes have been regenerated. Please save your new codes.');
    }

    /**
     * Show recovery codes
     */
    public function showRecoveryCodes(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit');
        }

        // Only show codes from session (after setup or regeneration)
        $recoveryCodes = $request->session()->get('two_factor_recovery_codes');

        if (! $recoveryCodes) {
            return redirect()->route('profile.edit')
                ->withErrors(['two_factor' => 'Recovery codes are only shown once after generation. Please regenerate if you need new codes.']);
        }

        // Clear the codes from session after retrieving (they should only be shown once)
        $request->session()->forget('two_factor_recovery_codes');

        return view('auth.two-factor-recovery-codes', [
            'recoveryCodes' => $recoveryCodes,
        ]);
    }
}
