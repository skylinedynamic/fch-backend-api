<?php

use App\Models\CreditBuilder\BorrowerTierTask;
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
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('title');
            $table->text('subtitle')->nullable();
            $table->longText('description')->nullable();
            $table->string('status')->nullable();
            $table->integer('order')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('tier_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tier_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->string('title');
            $table->text('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->integer('order')->nullable();
            $table->string('url')->nullable();
            $table->string('button_title')->nullable();
            $table->string('status')->nullable();
            $table->integer('estimated_completion_days')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('borrower_tier_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tier_id')->nullable()->constrained('tiers')->nullOnDelete();
            $table->foreignId('tier_task_id')->nullable()->constrained('tier_tasks')->nullOnDelete();
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->nullOnDelete();
            $table->string('status')->default(BorrowerTierTask::PENDING);
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrower_tier_tasks');
        Schema::dropIfExists('tier_tasks');
        Schema::dropIfExists('tiers');
    }
};
