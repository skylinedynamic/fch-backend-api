<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('capital_accesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sub_title')->nullable();
            $table->foreignId('partner_id')->nullable()->constrained('partners');
            $table->decimal('target_exposure_min', 15, 2)->nullable();
            $table->decimal('target_exposure_max', 15, 2)->nullable();
            $table->decimal('minimum_fico_requirement', 15, 2)->nullable();
            $table->decimal('maximum_utilization_threshold', 15, 2)->nullable();
            $table->decimal('difficulty_modifier', 15, 2)->nullable();
            $table->integer('est_timeline_min')->nullable();
            $table->integer('est_timeline_max')->nullable();
            $table->json('what_you_will_get')->nullable();
            $table->json('pre_flight_checklist')->nullable();
            $table->string('status')->default('Active'); // Active, Inactive
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('capital_accesses');
    }
};
