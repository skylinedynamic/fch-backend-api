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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('partners');
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('transaction_id')->nullable()->index();
            $table->decimal('amount', 23, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('transaction_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
