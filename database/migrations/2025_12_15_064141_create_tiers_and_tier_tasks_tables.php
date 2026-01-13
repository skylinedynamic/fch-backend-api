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
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('title');
            $table->string('code')->nullable();
            $table->text('subtitle')->nullable();
            $table->longText('description')->nullable();
            $table->string('status')->nullable();
            $table->integer('order')->nullable();
            $table->boolean('has_vendor')->default(false);
            $table->integer('task_to_complete')->nullable();
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
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('manual_verification')->default(false);
            $table->unsignedBigInteger('related_api_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tier_tasks');
        Schema::dropIfExists('tiers');
    }
};
