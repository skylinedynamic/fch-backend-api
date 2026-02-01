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
        Schema::create('subscription_phases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained('subscriptions')->onDelete('cascade');
            $table->enum('phase', ['enrollment', 'platform_fee'])->default('enrollment');

            $table->integer('installments_paid')->default(0);

            $table->timestamp('enrollment_completed_at')->nullable();

            $table->date('next_installment_date')->nullable();
            $table->date('next_platform_fee_date')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_phases');
    }
};
