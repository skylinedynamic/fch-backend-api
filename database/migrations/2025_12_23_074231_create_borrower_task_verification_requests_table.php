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
        Schema::create('borrower_task_verification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->foreignId('tier_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tier_tasks')->nullOnDelete();
            $table->string('status')->nullable()->index();
            $table->unsignedBigInteger('migrated_task_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_task_verification_requests');
    }
};
