<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class JwtSecretController extends Controller
{
    /**
     * List all users with JWT secrets.
     */
    public function index(): JsonResponse
    {
        $users = User::whereNotNull('jwt_secret')
            ->get(['id', 'name', 'email', 'role', 'jwt_secret_generated_at'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'generated_at' => $user->jwt_secret_generated_at?->format('Y-m-d H:i'),
            ]);

        // Get all users for the dropdown
        $allUsers = User::select(['id', 'name', 'email', 'role'])->get();

        $jwtSettingEnabled = Setting::get('jwt_enabled_override', true);
        $metaEndpointEnabled = Setting::get('api_meta_endpoint_enabled', true);

        return response()->json([
            'users_with_secrets' => $users,
            'all_users' => $allUsers,
            'jwt_enabled' => config('jwt.enabled', false),
            'jwt_setting_enabled' => $jwtSettingEnabled,
        ]);
    }

    /**
     * Generate a new JWT secret for a user.
     */
    public function generate(Request $request, User $user): JsonResponse
    {
        $isRegenerate = $user->hasJwtSecret();

        // Generate a 64-character random secret (256-bit)
        $secret = Str::random(64);

        $user->update([
            'jwt_secret' => $secret,
            'jwt_secret_generated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $isRegenerate
                ? 'JWT secret regenerated successfully'
                : 'JWT secret generated successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            // Only return the secret once - it won't be shown again
            'secret' => $secret,
            'generated_at' => $user->jwt_secret_generated_at->format('Y-m-d H:i'),
        ]);
    }

    /**
     * Revoke (delete) a user's JWT secret.
     */
    public function revoke(User $user): JsonResponse
    {
        if (! $user->hasJwtSecret()) {
            return response()->json([
                'success' => false,
                'message' => 'User does not have a JWT secret',
            ], 404);
        }

        $user->update([
            'jwt_secret' => null,
            'jwt_secret_generated_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'JWT secret revoked successfully',
        ]);
    }
}
