<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        $filename = fake()->word() . '.' . fake()->randomElement(['jpg', 'png', 'pdf', 'doc']);
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
        ];
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return [
            's3_key' => 'assets/' . Str::uuid() . '.' . $extension,
            'filename' => $filename,
            'mime_type' => $mimeTypes[$extension] ?? 'application/octet-stream',
            'size' => fake()->numberBetween(1000, 10000000),
            'etag' => Str::random(32),
            'width' => str_starts_with($mimeTypes[$extension] ?? '', 'image/') ? fake()->numberBetween(100, 4000) : null,
            'height' => str_starts_with($mimeTypes[$extension] ?? '', 'image/') ? fake()->numberBetween(100, 4000) : null,
            'thumbnail_s3_key' => str_starts_with($mimeTypes[$extension] ?? '', 'image/') ? 'thumbnails/' . Str::uuid() . '_thumb.jpg' : null,
            'alt_text' => fake()->optional()->sentence(),
            'caption' => fake()->optional()->paragraph(),
            'license_type' => fake()->optional()->randomElement(['public_domain', 'cc_by', 'cc_by_sa', 'all_rights_reserved']),
            'license_expiry_date' => fake()->optional()->dateTimeBetween('now', '+2 years'),
            'copyright' => fake()->optional()->company(),
            'copyright_source' => fake()->optional()->url(),
            'user_id' => User::factory(),
        ];
    }

    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'filename' => fake()->word() . '.jpg',
            'mime_type' => 'image/jpeg',
            's3_key' => 'assets/' . Str::uuid() . '.jpg',
            'width' => fake()->numberBetween(100, 4000),
            'height' => fake()->numberBetween(100, 4000),
            'thumbnail_s3_key' => 'thumbnails/' . Str::uuid() . '_thumb.jpg',
        ]);
    }

    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'filename' => fake()->word() . '.pdf',
            'mime_type' => 'application/pdf',
            's3_key' => 'assets/' . Str::uuid() . '.pdf',
            'width' => null,
            'height' => null,
            'thumbnail_s3_key' => null,
        ]);
    }

    public function withLicense(string $type, ?\DateTime $expiryDate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'license_type' => $type,
            'license_expiry_date' => $expiryDate,
        ]);
    }

    public function withCopyright(string $copyright, ?string $source = null): static
    {
        return $this->state(fn (array $attributes) => [
            'copyright' => $copyright,
            'copyright_source' => $source,
        ]);
    }
}
