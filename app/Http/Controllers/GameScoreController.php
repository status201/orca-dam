<?php

namespace App\Http\Controllers;

use App\Models\GameScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameScoreController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['leaderboard' => GameScore::leaderboard()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'score' => 'required|integer|min:1|max:999999',
        ]);

        GameScore::create([
            'user_id' => $request->user()->id,
            'score' => $validated['score'],
        ]);

        return response()->json(['leaderboard' => GameScore::leaderboard()]);
    }
}
