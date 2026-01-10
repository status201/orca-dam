<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('upload_id')->unique(); // S3 multipart upload ID
            $table->string('session_token')->unique(); // Client-side identifier
            $table->string('filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->string('s3_key');
            $table->unsignedInteger('chunk_size')->default(10485760); // 10MB in bytes
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('uploaded_chunks')->default(0);
            $table->json('part_etags')->nullable(); // [{PartNumber: 1, ETag: "..."}]
            $table->enum('status', ['pending', 'uploading', 'completed', 'failed', 'aborted'])->default('pending');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('last_activity_at'); // For cleanup jobs
            $table->index('session_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
