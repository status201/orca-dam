<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'maintenance_mode'],
            [
                'value' => '0',
                'type' => 'boolean',
                'group' => 'general',
                'description' => 'Enable maintenance mode to allow bulk file move operations',
            ]
        );
    }

    public function down(): void
    {
        Setting::where('key', 'maintenance_mode')->delete();
    }
};
