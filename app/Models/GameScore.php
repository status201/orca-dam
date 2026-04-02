<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class GameScore extends Model
{
    protected $fillable = ['user_id', 'score'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the top scores (best per user).
     */
    public static function leaderboard(int $limit = 5): Collection
    {
        return static::query()
            ->select('game_scores.user_id')
            ->selectRaw('MAX(game_scores.score) as score')
            ->join('users', 'users.id', '=', 'game_scores.user_id')
            ->selectRaw('users.name')
            ->groupBy('game_scores.user_id', 'users.name')
            ->orderByDesc('score')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'score' => (int) $row->score,
            ]);
    }
}
