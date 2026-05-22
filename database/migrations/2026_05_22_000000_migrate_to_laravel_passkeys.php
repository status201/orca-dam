<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Passkeys\Passkeys;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('webauthn_credentials');

        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Passkeys::userModel(), 'user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            // longText, not json — App\Models\Passkey casts this to encrypted:json,
            // and the encrypted payload is an opaque string rather than valid JSON.
            $table->longText('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');

        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->string('id', 510)->primary();
            $table->morphs('authenticatable');
            $table->string('user_id');
            $table->string('alias')->nullable();
            $table->unsignedInteger('counter')->default(0);
            $table->string('rp_id');
            $table->string('origin');
            $table->json('transports')->nullable();
            $table->string('aaguid', 36);
            $table->text('public_key');
            $table->string('attestation_format')->default('none');
            $table->json('certificates')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }
};
