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
        Schema::create('license_validations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('license_key')->nullable()->index();
            $table->string('hardware_fingerprint')->nullable()->index();
            $table->enum('validation_type', ['online', 'offline', 'jwt', 'cache'])->default('online');
            $table->enum('result', ['success', 'failed'])->default('success')->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('validated_at')->index();

            $table->index(['validated_at', 'result']);
            $table->index(['license_key', 'validated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('license_validations');
    }
};
