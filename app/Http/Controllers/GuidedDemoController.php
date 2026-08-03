<?php

namespace App\Http\Controllers;

use App\Demos\DemoRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The only server surface guided demos need.
 *
 * Playing a demo needs no endpoint at all: the definition rides the page render and
 * the position rides the URL (see specs/features/guided-demos.md REQ-6). Only the
 * terminal state — finished, or dismissed part-way — is worth keeping.
 *
 * Deliberately not folded into ProfileController::updatePreferences(), which treats an
 * absent field as "cleared" and unsets it. That is safe only because the profile form
 * always submits all four of its fields; a request carrying just a demo id would wipe
 * the user's home folder, results-per-page, dark mode and locale.
 */
class GuidedDemoController extends Controller
{
    public function complete(Request $request, DemoRegistry $registry, string $demo): JsonResponse
    {
        $validated = $request->validate([
            'dismissed' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        // 404 for an id nobody registered, 403 for one this user may not play — so a
        // demo cannot be marked complete by someone it was never offered to.
        if ($registry->find($demo) === null) {
            throw new NotFoundHttpException;
        }

        $resolved = $registry->get($demo, $user);

        if ($resolved === null) {
            throw new AccessDeniedHttpException;
        }

        // setPreference() is a shallow top-level write and cannot set a nested path, so
        // rebuild the whole map and write it once. Reads stay dotted, because
        // getPreference() uses data_get().
        $completed = $user->getPreference('guided_demos', []);
        $completed = is_array($completed) ? $completed : [];

        $completed[$resolved->id()] = [
            'completed_at' => now()->toIso8601String(),
            'dismissed' => (bool) ($validated['dismissed'] ?? false),
        ];

        $user->setPreference('guided_demos', $completed);

        return response()->json([
            'message' => __('Demo progress saved.'),
            'completed' => array_keys($completed),
        ]);
    }
}
