<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only trail of user administration — see specs/features/user-audit-log.md.
 *
 * A `users` row records when it was created but nothing about an UPDATE that flips
 * `role`, so the most security-relevant mutation in the system left no trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_audit_logs', function (Blueprint $table) {
            $table->id();

            // Subject. nullOnDelete keeps the trail readable after the account is gone;
            // the label preserves who it was, since the FK cannot.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_label');

            // Actor. Null for console commands, seeders and queued jobs.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label');

            $table->string('event');
            $table->json('changes')->nullable();
            $table->string('ip')->nullable();
            $table->string('user_agent')->nullable();

            // created_at only — rows are immutable, so updated_at would be a lie.
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_audit_logs');
    }
};
