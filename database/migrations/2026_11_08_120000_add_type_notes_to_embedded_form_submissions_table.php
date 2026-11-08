<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the applicant wrote when Industry Type or Entity Type is "Other" - as
 * the borrower profile keeps it (business_*_type_note).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embedded_form_submissions', function (Blueprint $table) {
            $table->string('industry_type_note')->nullable()->after('industry_type');
            $table->string('entity_type_note')->nullable()->after('entity_type');
        });
    }

    public function down(): void
    {
        Schema::table('embedded_form_submissions', function (Blueprint $table) {
            $table->dropColumn(['industry_type_note', 'entity_type_note']);
        });
    }
};
