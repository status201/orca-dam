<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Setting;
use App\Services\S3Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $rootFolder = S3Service::getRootFolder();
        $folders = Setting::get('s3_folders', $rootFolder !== '' ? [$rootFolder] : []);

        // Filter to folders within global root
        if ($rootFolder !== '') {
            $folders = array_values(array_filter($folders, fn ($f) => $f === $rootFolder || str_starts_with($f, $rootFolder.'/')
            ));
        }

        // Ensure root folder is in the list
        if (! empty($rootFolder) && ! in_array($rootFolder, $folders)) {
            array_unshift($folders, $rootFolder);
        }

        $globalItemsPerPage = (int) Setting::get('items_per_page', 24);

        return view('profile.edit', [
            'user' => $request->user(),
            'folders' => $folders,
            'rootFolder' => $rootFolder,
            'globalItemsPerPage' => $globalItemsPerPage,
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

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Update the user's preferences.
     */
    public function updatePreferences(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
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

        $request->user()->update(['preferences' => $preferences ?: null]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Preferences saved successfully',
                'preferences' => $request->user()->fresh()->preferences,
            ]);
        }

        return Redirect::route('profile.edit')->with('status', 'preferences-updated');
    }
}
