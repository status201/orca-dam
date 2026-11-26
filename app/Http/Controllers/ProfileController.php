<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Asset;
use App\Models\Setting;
use App\Models\User;
use App\Services\FolderRegistry;
use App\Services\S3Service;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request, FolderRegistry $folderRegistry): View
    {
        $rootFolder = S3Service::getRootFolder();
        $folders = $folderRegistry->forPicker();

        // A home folder must sit within the global root; forPicker() already lists the root itself.
        if ($rootFolder !== '') {
            $folders = array_values(array_filter($folders, fn ($f) => $f === $rootFolder || str_starts_with($f, $rootFolder.'/')));
        }

        $globalItemsPerPage = (int) Setting::get('items_per_page', 24);

        $availableUiLanguages = config('orca.ui_locales');
        $globalLocale = Setting::get('locale', 'en');
        $globalLocaleLabel = $availableUiLanguages[$globalLocale] ?? 'English';

        return view('profile.edit', [
            'user' => $request->user(),
            'folders' => $folders,
            'rootFolder' => $rootFolder,
            'globalItemsPerPage' => $globalItemsPerPage,
            'availableUiLanguages' => $availableUiLanguages,
            'globalLocaleLabel' => $globalLocaleLabel,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // assets.user_id is RESTRICT, and only an admin delete can transfer assets — refuse
        // before logging out, so a refusal leaves the user signed in
        // (specs/features/user-management.md REQ-self-delete-refused-with-assets).
        if (Asset::withTrashed()->where('user_id', $user->id)->exists()) {
            return $this->refuseSelfDelete($user);
        }

        // Logout must come first: it saves the remember token, which would re-insert a deleted
        // row. An upload landing between the check and the delete trips the RESTRICT FK — sign
        // the user back in and refuse the same way.
        Auth::logout();

        try {
            $user->delete();
        } catch (QueryException $e) {
            Auth::login($user);

            if (Asset::withTrashed()->where('user_id', $user->id)->exists()) {
                return $this->refuseSelfDelete($user);
            }

            throw $e;
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function refuseSelfDelete(User $user): RedirectResponse
    {
        // An admin cannot delete themselves from the Users page either, so point at another admin.
        $message = $user->isAdmin()
            ? __('You still own assets. Another administrator can delete your account from the Users page and transfer them.')
            : __('You still own assets. Ask an administrator to delete your account and transfer them.');

        return back()->withErrors(['password' => $message], 'userDeletion');
    }

    /**
     * Update the user's preferences.
     */
    public function updatePreferences(Request $request): RedirectResponse|JsonResponse
    {
        $rootFolder = S3Service::getRootFolder();

        $validated = $request->validate([
            'home_folder' => ['nullable', 'string', 'max:255', function ($attr, $value, $fail) use ($rootFolder) {
                if ($value && $rootFolder !== '' && $value !== $rootFolder && ! str_starts_with($value, $rootFolder.'/')) {
                    $fail('Home folder must be within the configured root folder.');
                }
            }],
            'items_per_page' => 'nullable|integer|in:0,12,24,36,48,60,72,96',
            'dark_mode' => 'nullable|string|in:disabled,force_dark,force_light',
            'locale' => ['nullable', 'string', Rule::in(SetLocale::getSupportedLocales())],
        ]);

        $preferences = $request->user()->preferences ?? [];

        // Home folder: empty = use default
        if (! empty($validated['home_folder'])) {
            $preferences['home_folder'] = $validated['home_folder'];
        } else {
            unset($preferences['home_folder']);
        }

        // Items per page: 0 or empty = use default
        if (! empty($validated['items_per_page']) && (int) $validated['items_per_page'] > 0) {
            $preferences['items_per_page'] = (int) $validated['items_per_page'];
        } else {
            unset($preferences['items_per_page']);
        }

        // Dark mode: disabled or empty = use default (no class)
        if (! empty($validated['dark_mode']) && $validated['dark_mode'] !== 'disabled') {
            $preferences['dark_mode'] = $validated['dark_mode'];
        } else {
            unset($preferences['dark_mode']);
        }

        // Locale: empty = use global default
        if (! empty($validated['locale'])) {
            $preferences['locale'] = $validated['locale'];
        } else {
            unset($preferences['locale']);
        }

        $request->user()->update(['preferences' => $preferences ?: null]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('Preferences saved successfully'),
                'preferences' => $request->user()->fresh()->preferences,
            ]);
        }

        return Redirect::route('profile.edit')->with('status', 'preferences-updated');
    }
}
