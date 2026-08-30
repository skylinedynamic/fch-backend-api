<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {

        Schema::create('score_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('model_slug')->nullable();
            $table->string('factor_slug')->nullable();
            $table->string('label')->nullable();
            $table->decimal('min_value', 12, 2)->nullable();
            $table->decimal('max_value', 12, 2)->nullable();
            $table->decimal('numeric_value', 12, 2)->nullable();
            $table->json('conditions')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_configurations');
    }
};
