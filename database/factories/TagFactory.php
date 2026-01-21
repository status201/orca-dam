<?php

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;

class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'type' => 'user',
        ];
    }

    public function ai(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'ai',
        ]);
    }

    public function user(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'user',
        ]);
    }
}
