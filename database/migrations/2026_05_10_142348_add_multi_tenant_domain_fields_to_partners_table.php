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
        Schema::table('partners', function (Blueprint $table) {
            // Add columns
            $table->boolean('active_custom_url')->default(false)->after('website');
            $table->string('partner_domain')->nullable()->unique()->after('active_custom_url');
            $table->string('borrower_domain')->nullable()->unique()->after('partner_domain');

            // Add unique constraint to company_name
            $table->unique('company_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropUnique(['partner_domain']);
            $table->dropUnique(['borrower_domain']);
            $table->dropUnique(['company_name']);
            $table->dropColumn(['active_custom_url', 'partner_domain', 'borrower_domain']);
        });
    }
};
