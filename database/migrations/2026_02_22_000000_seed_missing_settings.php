<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'timezone'],
            [
                'value' => 'UTC',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Application timezone',
            ]
        );

        Setting::firstOrCreate(
            ['key' => 'jwt_enabled_override'],
            [
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'general',
                'description' => 'Enable or disable JWT authentication override',
            ]
        );

        Setting::firstOrCreate(
            ['key' => 'api_meta_endpoint_enabled'],
            [
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'general',
                'description' => 'Enable or disable the public API meta endpoint',
            ]
        );
    }

    public function down(): void
    {
        Setting::whereIn('key', [
            'timezone',
            'jwt_enabled_override',
            'api_meta_endpoint_enabled',
        ])->delete();
    }
};
