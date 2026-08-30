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
        Schema::create('funding_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('partners');
            $table->foreignId('borrower_id')->constrained('borrowers');
            $table->foreignId('funding_sequence_id')->constrained('funding_sequencings')->onDelete('cascade');
            $table->string('funding_type')->nullable();
            $table->decimal('requested_amount', 15, 2)->nullable();
            $table->string('use_of_funds')->nullable();
            $table->json('documents_uploaded')->nullable();
            $table->string('estimated_speed')->nullable();
            $table->string('status')->default('Pending');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('funding_reviews');
    }
};
