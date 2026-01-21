<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->word(),
            'value' => fake()->word(),
            'type' => 'string',
            'group' => 'general',
            'description' => fake()->sentence(),
        ];
    }

    public function integer(): static
    {
        return $this->state(fn (array $attributes) => [
            'value' => (string) fake()->numberBetween(1, 100),
            'type' => 'integer',
        ]);
    }

    public function boolean(): static
    {
        return $this->state(fn (array $attributes) => [
            'value' => fake()->boolean() ? '1' : '0',
            'type' => 'boolean',
        ]);
    }
}
