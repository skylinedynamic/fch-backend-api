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
        Schema::create('task_related_apis', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('provider')->nullable();
            $table->text('additional_info')->nullable();
            $table->unsignedBigInteger('tier_id')->nullable();
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
        Schema::dropIfExists('task_related_apis');
    }
};
