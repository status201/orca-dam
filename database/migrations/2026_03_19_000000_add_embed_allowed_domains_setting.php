<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'embed_allowed_domains'],
            [
                'value' => '[]',
                'type' => 'json',
                'group' => 'general',
                'description' => 'Domains allowed to embed this application in an iframe (JSON array)',
            ]
        );
    }

    public function down(): void
    {
        Setting::where('key', 'embed_allowed_domains')->delete();
    }
};
