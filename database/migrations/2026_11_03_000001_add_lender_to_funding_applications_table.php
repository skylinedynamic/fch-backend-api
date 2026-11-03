<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lender assigned to a funding application that has no funding request
 * (converted from a loan application). One with a request keeps using the
 * request's lender_ids / assign_lender, shared with the funding request page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->json('lender_ids')->nullable()->after('funding_sequence_id');
            $table->unsignedBigInteger('assign_lender')->nullable()->after('lender_ids');
        });
    }

    public function down(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->dropColumn(['lender_ids', 'assign_lender']);
        });
    }
};
