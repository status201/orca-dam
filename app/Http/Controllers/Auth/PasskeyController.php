<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\WebAuthnService;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

class PasskeyController extends Controller
{
    public function __construct(
        protected WebAuthnService $webAuthnService
    ) {}

    /**
     * Return attestation (registration) options for the authenticated user.
     */
    public function options(AttestationRequest $request): Responsable|JsonResponse
    {
        $user = $request->user();

        if (! $user->canEnablePasskeys()) {
            return response()->json([
                'message' => __('Your account type does not support passkeys.'),
            ], 403);
        }

        if ($this->webAuthnService->hasReachedLimit($user)) {
            return response()->json([
                'message' => __('You have reached the maximum number of passkeys (:max).', [
                    'max' => WebAuthnService::MAX_CREDENTIALS_PER_USER,
                ]),
            ], 422);
        }

        return $request->toCreate();
    }

    /**
     * Verify the attestation response and persist the new credential.
     */
    public function store(AttestedRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canEnablePasskeys()) {
            return response()->json([
                'message' => __('Your account type does not support passkeys.'),
            ], 403);
        }

        $alias = $request->input('alias');
        $alias = is_string($alias) ? trim($alias) : null;
        $alias = $alias === '' ? null : $alias;

        if ($alias !== null && mb_strlen($alias) > 100) {
            throw ValidationException::withMessages([
                'alias' => __('The passkey name may not be longer than :max characters.', ['max' => 100]),
            ]);
        }

        $request->save(['alias' => $alias]);

        return response()->json([
            'message' => __('Passkey added successfully.'),
        ], 201);
    }

    /**
     * Rename a passkey owned by the authenticated user.
     */
    public function update(Request $request, string $credential): RedirectResponse
    {
        $request->validate([
            'alias' => 'nullable|string|max:100',
        ]);

        $updated = $this->webAuthnService->renameCredential(
            $request->user(),
            $credential,
            $request->input('alias')
        );

        if (! $updated) {
            return back()->withErrors(['passkey' => __('Passkey not found.')]);
        }

        return back()->with('status', __('Passkey renamed.'));
    }

    /**
     * Remove a passkey owned by the authenticated user.
     */
    public function destroy(Request $request, string $credential): RedirectResponse
    {
        $deleted = $this->webAuthnService->deleteCredential($request->user(), $credential);

        if (! $deleted) {
            return back()->withErrors(['passkey' => __('Passkey not found.')]);
        }

        return back()->with('status', __('Passkey removed.'));
    }
}
