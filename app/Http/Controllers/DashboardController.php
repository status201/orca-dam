<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        // Consolidated asset stats: 1 query instead of 3 (total + my_assets + sum)
        $assetStats = DB::selectOne(
            'SELECT COUNT(*) as total, SUM(CASE WHEN user_id = ? THEN 1 ELSE 0 END) as mine, COALESCE(SUM(size), 0) as total_size FROM assets WHERE deleted_at IS NULL',
            [Auth::id()]
        );

        // Consolidated tag stats: 1 query instead of 3 (total + user + ai)
        $tagCounts = DB::selectOne(
            "SELECT COUNT(*) as total, SUM(CASE WHEN type = 'user' THEN 1 ELSE 0 END) as user_tags, SUM(CASE WHEN type = 'ai' THEN 1 ELSE 0 END) as ai_tags FROM tags"
        );

        $stats = [
            'total_assets' => (int) $assetStats->total,
            'total_tags' => (int) $tagCounts->total,
            'user_tags' => (int) $tagCounts->user_tags,
            'ai_tags' => (int) $tagCounts->ai_tags,
            'my_assets' => (int) $assetStats->mine,
            'total_users' => User::count(),
            'trashed_assets' => Asset::onlyTrashed()->count(),
        ];

        $stats['total_storage'] = $this->formatBytes((int) $assetStats->total_size);

        // Get user role
        $user = Auth::user();
        $isAdmin = $user->isAdmin();
        $showPasskeyPromo = $user->canEnablePasskeys() && ! $user->hasPasskeysEnabled();

        // Editor-only stats
        if (! $isAdmin) {
            $myTags = Tag::whereHas('assets', fn ($q) => $q->where('user_id', $user->id))->get();
            $stats['my_tags'] = $myTags->count();
            $stats['my_user_tags'] = $myTags->where('type', 'user')->count();
            $stats['my_ai_tags'] = $myTags->where('type', 'ai')->count();

            $stats['items_per_page'] = $user->getItemsPerPage();
            $stats['items_per_page_is_default'] = $user->getPreference('items_per_page') === null;
        }

        return view('dashboard', compact('stats', 'isAdmin', 'showPasskeyPromo'));
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
