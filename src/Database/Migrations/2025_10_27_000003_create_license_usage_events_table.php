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
        Schema::create('license_usage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('license_key')->nullable()->index();
            $table->string('event_type')->index(); // e.g., 'feature_access', 'api_request', 'page_view'
            $table->string('feature_key')->nullable()->index();
            $table->string('action')->nullable(); // e.g., 'create', 'read', 'update', 'delete'
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('user_id')->nullable()->index();
            $table->timestamp('created_at')->index();

            $table->index(['license_key', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index(['feature_key', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('license_usage_events');
    }
};
