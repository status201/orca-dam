<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::create([
            'key' => 's3_root_folder',
            'value' => '',
            'type' => 'string',
            'group' => 'aws',
            'description' => 'S3 prefix for new uploads. Leave empty for bucket root.',
        ]);
    }

    public function down(): void
    {
        Setting::where('key', 's3_root_folder')->delete();
    }
};
