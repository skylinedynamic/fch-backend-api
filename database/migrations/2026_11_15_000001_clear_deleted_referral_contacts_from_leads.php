<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R3-BUG-017: a deleted contact (soft-deleted, so the foreign key's
 * "set null" never ran) stayed a lead's Referral Contact. Leads left
 * pointing at one are cleared; such a lead asks for a new Referral Contact
 * when it is next saved with Lead Source "Referral Partner".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('leads')
            ->whereIn('referral_partner_id', DB::table('contacts')->whereNotNull('deleted_at')->select('id'))
            ->update(['referral_partner_id' => null]);
    }

    public function down(): void
    {
        // The cleared ids pointed at deleted contacts; nothing to restore.
    }
};
