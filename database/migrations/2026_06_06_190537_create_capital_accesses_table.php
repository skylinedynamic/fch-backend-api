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
        Schema::create('capital_accesses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sub_title');
            $table->foreignId('partner_id')->nullable()->constrained('partners');
            $table->decimal('target_exposure_min', 15, 2);
            $table->decimal('target_exposure_max', 15, 2);
            $table->integer('est_timeline_min');
            $table->integer('est_timeline_max');
            $table->json('what_you_will_get');
            $table->json('pre_flight_checklist');
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
