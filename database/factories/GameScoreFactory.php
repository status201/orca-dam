<?php

namespace Database\Factories;

use App\Models\GameScore;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameScore>
 */
class GameScoreFactory extends Factory
{
    protected $model = GameScore::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'score' => fake()->numberBetween(1, 999999),
        ];
    }
}
