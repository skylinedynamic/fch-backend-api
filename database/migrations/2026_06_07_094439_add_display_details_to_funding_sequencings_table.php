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
            $table->string('speed_to_funds')->nullable()->after('terms');
            $table->json('uses')->nullable()->after('speed_to_funds');
            $table->json('qualify_reasons')->nullable()->after('uses');
            $table->json('readiness_factors')->nullable()->after('qualify_reasons');
            $table->json('missing_documents')->nullable()->after('readiness_factors');
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
                'qualify_reasons',
                'readiness_factors',
                'missing_documents'
            ]);
        });
    }
};
