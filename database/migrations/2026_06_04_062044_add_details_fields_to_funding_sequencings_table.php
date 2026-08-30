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
        Schema::table('funding_sequencings', function (Blueprint $table) {
            $table->string('speed_to_funds')->nullable();
            $table->string('uses')->nullable();
            $table->json('missing_documents')->nullable();
            $table->json('readiness_factors')->nullable();
            $table->string('eligibility_status')->nullable();
            $table->string('eligibility_label')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funding_sequencings', function (Blueprint $table) {
            $table->dropColumn([
                'speed_to_funds',
                'uses',
                'missing_documents',
                'readiness_factors',
                'eligibility_status',
                'eligibility_label'
            ]);
        });
    }
};
