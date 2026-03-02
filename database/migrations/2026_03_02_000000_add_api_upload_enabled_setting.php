<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'api_upload_enabled'],
            [
                'value' => '1',
                'type' => 'boolean',
                'group' => 'api',
                'description' => 'Enable or disable API upload endpoints (direct and chunked)',
            ]
        );
    }

    public function down(): void
    {
        Setting::where('key', 'api_upload_enabled')->delete();
    }
};
