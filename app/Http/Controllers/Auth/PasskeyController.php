<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

class PasskeyController extends Controller
{
    /**
     * Session key for the in-flight registration ceremony options.
     */
    private const SESSION_KEY = 'passkey.registration_options';

    public function __construct(
        protected PasskeyService $passkeyService
    ) {}

    /**
     * Return registration options for the authenticated user.
     */
    public function options(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->canEnablePasskeys()) {
            return response()->json([
                'message' => __('Your account type does not support passkeys.'),
            ], 403);
        }

        if ($this->passkeyService->hasReachedLimit($user)) {
            return response()->json([
                'message' => __('You have reached the maximum number of passkeys (:max).', [
                    'max' => PasskeyService::MAX_CREDENTIALS_PER_USER,
                ]),
            ], 422);
        }

        $options = $generate($user);
        $request->session()->put(self::SESSION_KEY, WebAuthn::toJson($options));

        return response()->json([
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    /**
     * Verify the attestation response and persist the new passkey.
     */
    public function store(Request $request, StorePasskey $store): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->canEnablePasskeys()) {
            return response()->json([
                'message' => __('Your account type does not support passkeys.'),
            ], 403);
        }

        // Concurrent registrations could squeak past the options gate; reject here too.
        if ($this->passkeyService->hasReachedLimit($user)) {
            return response()->json([
                'message' => __('You have reached the maximum number of passkeys (:max).', [
                    'max' => PasskeyService::MAX_CREDENTIALS_PER_USER,
                ]),
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ]);

        $name = trim((string) ($validated['name'] ?? ''));
        $name = $name === '' ? __('Passkey') : $name;

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
            throw ValidationException::withMessages([
                'credential' => __('Passkey registration session expired. Please try again.'),
            ]);
        }

        $options = WebAuthn::fromJson($serialized, PublicKeyCredentialCreationOptions::class);

        try {
            $store($user, $name, $credential, $options);
        } catch (InvalidPasskeyException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

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
            'name' => 'nullable|string|max:100',
        ]);

        $updated = $this->passkeyService->renameCredential(
            $request->user(),
            $credential,
            $request->input('name')
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
        $deleted = $this->passkeyService->deleteCredential($request->user(), $credential);

        if (! $deleted) {
            return back()->withErrors(['passkey' => __('Passkey not found.')]);
        }

        return back()->with('status', __('Passkey removed.'));
    }
}
