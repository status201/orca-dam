<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'tikz_color_package_name'],
            [
                'value' => 'studyflow-colors',
                'type' => 'string',
                'group' => 'general',
                'description' => 'LaTeX package name (without .sty) for TikZ color definitions',
            ]
        );

        Setting::firstOrCreate(
            ['key' => 'tikz_color_package'],
            [
                'value' => '',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Full .sty file content for the TikZ color package',
            ]
        );
    }

    public function down(): void
    {
        Setting::where('key', 'tikz_color_package_name')->delete();
        Setting::where('key', 'tikz_color_package')->delete();
    }
};
