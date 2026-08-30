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
        Schema::create('app_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->nullable(); // Optional: used for multi-tenancy or specific contexts
            $table->unsignedBigInteger('entity_id')->nullable(); 
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
            
            $table->index(['entity_type', 'entity_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_configurations');
    }
};
