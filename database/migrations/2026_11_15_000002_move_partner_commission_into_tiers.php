<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A partner's single commission (%) becomes one tier for every deal (0 and
 * up). partners.commission is left in place but no longer read; dropping it
 * is a follow-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $partners = DB::table('partners')->whereNotNull('commission')->get(['id', 'commission']);

            foreach ($partners as $partner) {
                if (DB::table('partner_commission_tiers')->where('partner_id', $partner->id)->exists()) {
                    continue;
                }

                DB::table('partner_commission_tiers')->insert([
                    'partner_id' => $partner->id,
                    'min_count' => 0,
                    'max_count' => null,
                    'percentage' => $partner->commission,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('partner_commission_tiers')->delete();
    }
};
