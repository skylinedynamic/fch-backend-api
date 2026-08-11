<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create master payment_providers table
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // 'Stripe', 'Ecrypt', 'Square', 'Authorize.Net'
            $table->string('display_name')->nullable();
            $table->string('logo')->nullable(); // Logo path
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        // 2. Create partner_payment_providers pivot table
        Schema::create('partner_payment_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->onDelete('cascade');
            $table->foreignId('payment_provider_id')->constrained('payment_providers')->onDelete('cascade');
            $table->boolean('status')->default(true); // Enabled/Disabled for this partner
            $table->boolean('is_default')->default(false); // Default provider for this partner
            $table->text('configuration')->nullable(); // Stores encrypted credentials (e.g. sandbox/production details)
            $table->timestamps();

            // Prevent duplicate provider linkages for the same partner
            $table->unique(['partner_id', 'payment_provider_id'], 'partner_provider_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_payment_providers');
        Schema::dropIfExists('payment_providers');
    }
};
