<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

class ApiDocsController extends Controller
{
    /**
     * Display the API documentation page with Swagger UI and token management.
     */
    public function index(): View
    {
        return view('api.index', [
            'jwtEnvEnabled' => config('jwt.enabled', false),
        ]);
    }

    /**
     * Get dashboard statistics for the API docs page.
     */
    public function dashboard(): JsonResponse
    {
        $tokenCount = PersonalAccessToken::count();
        $jwtSecretCount = User::whereNotNull('jwt_secret')->count();
        $apiUserCount = User::where('role', 'api')->count();

        $jwtEnvEnabled = config('jwt.enabled', false);
        $jwtSettingEnabled = Setting::get('jwt_enabled_override', true);
        $metaEndpointEnabled = Setting::get('api_meta_endpoint_enabled', true);
        $uploadEndpointEnabled = Setting::get('api_upload_enabled', true);

        return response()->json([
            'tokenCount' => $tokenCount,
            'jwtSecretCount' => $jwtSecretCount,
            'apiUserCount' => $apiUserCount,
            'jwtEnvEnabled' => $jwtEnvEnabled,
            'jwtSettingEnabled' => $jwtSettingEnabled,
            'uploadEndpointEnabled' => $uploadEndpointEnabled,
            'metaEndpointEnabled' => $metaEndpointEnabled,
        ]);
    }

    /**
     * Update API settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $request->validate([
            'key' => 'required|string|in:jwt_enabled_override,api_upload_enabled,api_meta_endpoint_enabled',
            'value' => 'required|boolean',
        ]);

        $key = $request->input('key');
        $value = $request->input('value');

        Setting::set($key, $value ? '1' : '0', 'boolean', 'api');

        return response()->json([
            'success' => true,
            'message' => 'Setting updated successfully',
        ]);
    }
}
