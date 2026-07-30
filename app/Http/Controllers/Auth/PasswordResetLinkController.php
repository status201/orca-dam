<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * Answers uniformly whatever the outcome — see specs/features/authentication.md
     * REQ-9. Breeze reported the broker status verbatim, so `passwords.user` ("We can't
     * find a user with that email address") turned this endpoint into a login-name
     * oracle: an unauthenticated caller could confirm whether any address held an ORCA
     * account. `passwords.throttled` leaked the same fact more quietly. Operators keep
     * the detail in the log; the requester gets one message for every case.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status !== Password::RESET_LINK_SENT) {
            Log::info('Password reset link not sent', [
                'status' => $status,
                'email' => $request->input('email'),
                'ip' => $request->ip(),
            ]);
        }

        // Deliberately not $status — a uniform answer is the whole point.
        return back()->with('status', __('If that email address matches an account, a password reset link has been sent.'));
    }
}
