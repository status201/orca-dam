<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    /**
     * List all API tokens with user info.
     */
    public function index(): JsonResponse
    {
        $tokens = PersonalAccessToken::with('tokenable')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($token) {
                $user = $token->tokenable;

                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'user_name' => $user ? $user->name : 'N/A',
                    'user_email' => $user ? $user->email : 'N/A',
                    'user_role' => $user ? $user->role : 'N/A',
                    'user_id' => $user ? $user->id : null,
                    'created_at' => $token->created_at->format('Y-m-d H:i'),
                    'last_used_at' => $token->last_used_at ? $token->last_used_at->format('Y-m-d H:i') : null,
                ];
            });

        // Get list of users for the create form
        // Map to plain arrays to bypass $hidden attribute filtering
        $users = User::orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]);

        return response()->json([
            'tokens' => $tokens,
            'users' => $users,
        ]);
    }

    /**
     * Create a new API token.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $createNew = filter_var($request->input('create_new'), FILTER_VALIDATE_BOOLEAN);

            $rules = [
                'token_name' => 'required|string|max:255',
            ];

            if ($createNew) {
                $rules['new_user_name'] = 'required|string|max:255';
                $rules['new_user_email'] = 'required|email|unique:users,email';
            } else {
                $rules['user_id'] = 'required|exists:users,id';
            }

            $validated = $request->validate($rules);

            // Create new API user if requested
            if ($createNew) {
                $user = User::create([
                    'name' => $validated['new_user_name'],
                    'email' => $validated['new_user_email'],
                    'password' => Hash::make(Str::random(32)),
                    'role' => 'api',
                ]);
            } else {
                $user = User::findOrFail($validated['user_id']);
            }

            // Create the token
            $token = $user->createToken($validated['token_name']);

            return response()->json([
                'success' => true,
                'message' => 'Token created successfully',
                'token' => [
                    'id' => $token->accessToken->id,
                    'name' => $validated['token_name'],
                    'plain_text' => $token->plainTextToken,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'user_role' => $user->role,
                    'created_new_user' => $createNew,
                ],
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Authorization failed: '.$e->getMessage(),
                'debug' => [
                    'user' => auth()->user()?->email,
                    'role' => auth()->user()?->role,
                ],
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: '.$e->getMessage(),
                'type' => get_class($e),
            ], 500);
        }
    }

    /**
     * Revoke (delete) an API token.
     */
    public function destroy(int $id): JsonResponse
    {
        $token = PersonalAccessToken::find($id);

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Token not found',
            ], 404);
        }

        $token->delete();

        return response()->json([
            'success' => true,
            'message' => 'Token revoked successfully',
        ]);
    }

    /**
     * Revoke all tokens for a user.
     */
    public function destroyUserTokens(int $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        }

        $count = $user->tokens()->count();
        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => "Revoked {$count} token(s) for {$user->email}",
            'count' => $count,
        ]);
    }
}
