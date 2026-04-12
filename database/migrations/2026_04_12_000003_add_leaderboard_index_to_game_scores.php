<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite index for leaderboard query: GROUP BY user_id, MAX(score).
     */
    public function up(): void
    {
        Schema::table('game_scores', function (Blueprint $table) {
            $table->index(['user_id', 'score'], 'game_scores_leaderboard_index');
            $table->dropIndex(['score']); // Single score index is redundant with composite
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_scores', function (Blueprint $table) {
            $table->dropIndex('game_scores_leaderboard_index');
            $table->index(['score']);
        });
    }
};
