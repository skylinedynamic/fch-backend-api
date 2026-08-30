<?php

use App\Models\PaymentMethod\PaymentProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('saved_payment_methods', function (Blueprint $table) {
            $table->id();

            // Polymorphic relation to Partner or Borrower
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');

            // Payment ID
            $table->string('provider_method_id'); // Stripe Payment Method ID (pm_xxxx)

            // Card details for display
            $table->string('provider')->default(PaymentProvider::STRIPE);
            $table->string('card_brand')->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->integer('exp_month')->nullable();
            $table->integer('exp_year')->nullable();
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            // Non-unique index for lookup speed (supporting 1-to-many relationship)
            $table->index(['entity_type', 'entity_id'], 'saved_pm_entity_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_payment_methods');
    }
};
