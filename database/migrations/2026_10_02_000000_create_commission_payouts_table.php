<?php

use App\Models\Payment\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payout made from a commission — to the partner whose client it is, or, for
 * a client of the host partner, to the user assigned to that client. Records
 * who was paid (as they were when paid) and links the invoice and payment made
 * for it, which hold the amount and the payment method. One per commission.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_id')->unique()->constrained('commissions')->cascadeOnDelete();

            // Who was paid: a Partner or a User.
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();

            $table->string('payout_type');

            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->string('currency')->default(Payment::CURRENCY_USD)->nullable();
            $table->string('currency_symbol')->default('$')->nullable();
            $table->decimal('conversion_rate', 10, 2)->default(1.00)->nullable();

            $table->timestamp('paid_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_payouts');
    }
};
