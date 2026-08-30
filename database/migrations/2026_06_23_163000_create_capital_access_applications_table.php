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
        Schema::create('capital_access_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')
                ->constrained('borrowers')
                ->onDelete('cascade');
            $table->foreignId('capital_access_id')
                ->constrained('capital_accesses')
                ->onDelete('cascade');
            $table->foreignId('capital_access_sequence_id')
                ->constrained('capital_access_sequences')
                ->onDelete('cascade');
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('Submitted'); // e.g. Submitted, Approved, Pending, Declined
            $table->timestamps();

            $table->unique(['borrower_id', 'capital_access_sequence_id'], 'caa_borrower_seq_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capital_access_applications');
    }
};
