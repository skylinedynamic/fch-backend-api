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
        Schema::create('funding_sequencings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funding_group_id')->nullable()->constrained('funding_groups')->nullOnDelete();
            $table->foreignId('funding_type_id')->nullable()->constrained('funding_types')->nullOnDelete();
            $table->string('name');
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->string('amount_range')->nullable();
            $table->string('terms')->nullable();
            $table->string('icon')->nullable(); 
            $table->string('icon_color')->nullable();
            $table->string('status')->default('Active');
            $table->integer('sort_order')->default(0);
            $table->string('action_url')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('funding_sequencings');
    }
};
