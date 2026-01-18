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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('partner_id')->constrained('partners')->onDelete('cascade');
            $table->unsignedBigInteger('partner_id')->index();

            // Card Sandbox
            $table->string('card_name_sandbox')->nullable();
            $table->string('card_no_sandbox')->nullable();
            $table->string('card_expiry_sandbox')->nullable();
            $table->string('card_cvv_sandbox')->nullable();

            // Card Production
            $table->string('card_name_production')->nullable();
            $table->string('card_no_production')->nullable();
            $table->string('card_expiry_production')->nullable();
            $table->string('card_cvv_production')->nullable();

            $table->string('currency')->nullable();
            $table->string('status')->default('active');

            // New fields directly in create
            $table->string('payment_mode')->default('sandbox'); // sandbox | production
            $table->string('stripe_key_sandbox')->nullable();
            $table->string('stripe_secret_sandbox')->nullable();
            $table->string('stripe_key_production')->nullable();
            $table->string('stripe_secret_production')->nullable();

            $table->string('payment_method')->nullable();
            $table->json('configuration')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
