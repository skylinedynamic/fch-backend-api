<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner profile › Commissions: each Funding commission tier has its own
 * type - a percentage of the success fee (as before) or a flat amount per
 * deal. A tier holds the value of its type (`percentage` or `amount`), the
 * other null. Existing tiers are percentages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_commission_tiers', function (Blueprint $table) {
            // PartnerCommissionTier::TYPE_PERCENTAGE / TYPE_FLAT
            $table->string('commission_type', 20)->default('percentage')->after('max_count');
            $table->decimal('percentage', 5, 2)->nullable()->change();
            // The partner's flat commission ($) per deal in this tier.
            $table->decimal('amount', 10, 2)->nullable()->after('percentage');
        });
    }

    public function down(): void
    {
        Schema::table('partner_commission_tiers', function (Blueprint $table) {
            $table->dropColumn(['commission_type', 'amount']);
        });
    }
};
