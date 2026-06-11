<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_models', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('output_min', 5, 2)->default(0);
            $table->decimal('output_max', 5, 2)->default(100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('score_factors', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('unit', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('score_model_factor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('score_model_id')->constrained('score_models')->onDelete('cascade');
            $table->foreignId('score_factor_id')->constrained('score_factors')->onDelete('cascade');
            $table->decimal('weight', 5, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['score_model_id', 'score_factor_id']);
        });

        Schema::create('score_config_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('partner_id')->nullable()->index();
            $table->foreignId('score_model_id')->nullable()->constrained('score_models')->onDelete('cascade');
            $table->string('category', 30);
            $table->string('factor_slug', 50)->nullable();
            $table->string('label', 100)->nullable();
            $table->decimal('min_value', 15, 2)->nullable();
            $table->decimal('max_value', 15, 2)->nullable();
            $table->decimal('numeric_value', 5, 2)->nullable();
            $table->json('conditions')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'score_model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_config_items');
        Schema::dropIfExists('score_model_factor');
        Schema::dropIfExists('score_factors');
        Schema::dropIfExists('score_models');
    }
};
