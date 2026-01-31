<?php

use App\Models\Subscription\Subscription;
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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            // Partner relation
            // Partner & Borrower relation
            $table->foreignId('partner_id')->nullable()->constrained('partners')->onDelete('cascade');

            $table->string('borrower_id')->nullable();
            $table->string('partner_plan_id')->nullable();

            // Partner & enrollment info
            $table->string('partner_type')->nullable();
            $table->string('enrollment_type')->nullable();

            // Platform & subscription amounts
            $table->decimal('monthly_platform_fee', 10, 2)->nullable();
            $table->decimal('total_subscription_amount', 10, 2)->nullable();
            $table->decimal('payable_amount', 10, 2)->nullable();

            // Payment & fee timeline
            $table->date('first_payment_date')->nullable();
            $table->date('platform_fee_start_date')->nullable();
            $table->date('next_installment_date')->nullable();

            // Installment config
            $table->tinyInteger('installment_type')
                ->default(0)
                ->comment('0 = No installment, 1 = Installment');

            // Subscription lifecycle
            $table->date('subscription_start_date')->nullable();
            $table->date('subscription_end_date')->nullable();

            // Status
            $table->string('status')->default(Subscription::STATUS_PENDING)
                ->comment('Active, Inactive, Suspended, Cancelled');


            $table->string('currency')->default('USD')->nullable();
            $table->string('currency_symbol')->default('$')->nullable();
            $table->decimal('conversion_rate', 10, 2)->default(1.00)->nullable();


            // System columns
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
