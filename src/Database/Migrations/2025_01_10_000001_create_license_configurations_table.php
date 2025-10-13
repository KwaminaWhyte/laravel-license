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
        Schema::create('license_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // 'license_key', 'server_url', 'product_id', etc.
            $table->text('value')->nullable(); // Encrypted value
            $table->string('type')->default('string'); // string, boolean, integer
            $table->boolean('is_encrypted')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('license_configurations');
    }
};
