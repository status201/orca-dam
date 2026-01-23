<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, integer, boolean, json
            $table->string('group')->default('general'); // For grouping settings
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Insert default settings
        DB::table('settings')->insert([
            [
                'key' => 'items_per_page',
                'value' => '24',
                'type' => 'integer',
                'group' => 'display',
                'description' => 'Number of items to display per page',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'rekognition_max_labels',
                'value' => '3',
                'type' => 'integer',
                'group' => 'aws',
                'description' => 'Maximum number of AI tags per asset',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'rekognition_language',
                'value' => 'nl',
                'type' => 'string',
                'group' => 'aws',
                'description' => 'Language for AI-generated tags',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
