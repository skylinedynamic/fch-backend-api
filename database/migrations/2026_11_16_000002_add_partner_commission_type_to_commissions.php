<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A funding application's commission keeps how the partner's share was set:
 * from a Percentage tier (or typed, null) or from a Flat Amount tier - with
 * that exact amount, so closing at the final funded amount pays it to the
 * cent instead of a rounded % of the success fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            // PartnerCommissionTier::TYPE_PERCENTAGE / TYPE_FLAT; null = typed by hand (a %).
            $table->string('partner_commission_type', 20)->nullable()->after('partner_commission');
            $table->decimal('partner_commission_flat_amount', 15, 2)->nullable()->after('partner_commission_type');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn(['partner_commission_type', 'partner_commission_flat_amount']);
        });
    }
};
