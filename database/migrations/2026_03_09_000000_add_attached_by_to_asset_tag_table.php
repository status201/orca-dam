<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_tag', function (Blueprint $table) {
            $table->string('attached_by', 20)->default('user')->after('tag_id');
        });

        // Backfill: set attached_by to match the tag's type
        DB::statement('UPDATE asset_tag SET attached_by = (SELECT type FROM tags WHERE tags.id = asset_tag.tag_id)');
    }

    public function down(): void
    {
        Schema::table('asset_tag', function (Blueprint $table) {
            $table->dropColumn('attached_by');
        });
    }
};
