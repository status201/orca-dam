<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class GameScore extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'score'];

    /** @return BelongsTo<User, $this> */
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
            // `name` comes from the joined users table via selectRaw, not from game_scores, so it
            // is read with getAttribute() rather than as a property — declaring a @property for a
            // column this model only has inside this one query would misdescribe the model.
            ->map(fn ($row) => [
                'name' => $row->getAttribute('name'),
                'score' => (int) $row->score,
            ]);
    }
}
