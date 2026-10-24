<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The embedded form's business EIN, the currency of the requested amount, and
 * the funding types an applicant would also consider besides the preferred one.
 *
 * The EIN is kept as its nine digits, as the borrower profile keeps it. The
 * additional funding types go on the funding application too, so a conversion
 * carries them across; currency and currency_symbol already exist there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embedded_form_submissions', function (Blueprint $table) {
            $table->string('business_ein', 20)->nullable()->after('entity_type');
            $table->string('currency', 3)->nullable()->after('requested_amount');
            $table->json('additional_funding_types')->nullable()->after('preferred_funding_type');
        });

        Schema::table('funding_applications', function (Blueprint $table) {
            $table->json('additional_funding_types')->nullable()->after('preferred_funding_type');
        });
    }

    public function down(): void
    {
        Schema::table('embedded_form_submissions', function (Blueprint $table) {
            $table->dropColumn(['business_ein', 'currency', 'additional_funding_types']);
        });

        Schema::table('funding_applications', function (Blueprint $table) {
            $table->dropColumn('additional_funding_types');
        });
    }
};
