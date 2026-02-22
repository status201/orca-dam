<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'custom_domain'],
            [
                'value' => '',
                'type' => 'string',
                'group' => 'aws',
                'description' => 'Custom domain for asset URLs (e.g., https://cdn.example.com)',
            ]
        );
    }

    public function down(): void
    {
        Setting::where('key', 'custom_domain')->delete();
    }
};
