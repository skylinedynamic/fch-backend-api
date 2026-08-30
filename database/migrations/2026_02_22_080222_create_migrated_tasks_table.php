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
        Schema::create('migrated_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrower_id')->nullable();
            $table->foreignId('partner_id')->nullable();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('vendor_id');
            $table->unsignedBigInteger('tier_from');
            $table->unsignedBigInteger('tier_to');
            $table->string('status')->nullable();
            $table->foreignId('migrated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('migrated_tasks');
    }
};
