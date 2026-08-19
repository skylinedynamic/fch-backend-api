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
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->boolean('is_business_info_saved')->default(false)->after('funding_request_id');
            $table->boolean('is_ownership_info_saved')->default(false)->after('is_business_info_saved');
            $table->boolean('is_financial_info_saved')->default(false)->after('is_ownership_info_saved');
            $table->boolean('is_funding_request_saved')->default(false)->after('is_financial_info_saved');
            $table->boolean('is_disclosures_saved')->default(false)->after('is_funding_request_saved');
            $table->boolean('is_federal_compliance_saved')->default(false)->after('is_disclosures_saved');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('funding_applications', function (Blueprint $table) {
            $table->dropColumn([
                'is_business_info_saved',
                'is_ownership_info_saved',
                'is_financial_info_saved',
                'is_funding_request_saved',
                'is_disclosures_saved',
                'is_federal_compliance_saved'
            ]);
        });
    }
};
