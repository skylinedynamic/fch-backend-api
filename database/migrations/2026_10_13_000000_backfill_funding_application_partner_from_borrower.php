<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill funding_applications.partner_id from the borrower (findings O17).
 *
 * Partner-side saves used to stamp the application with the caller's partner,
 * so an application the host's team prepared for a partner's borrower was
 * moved into the host's book. An application belongs to its borrower's
 * partner; this puts back every row that drifted. Rows that already match are
 * untouched, so it can be re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('funding_applications')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('borrowers')
                    ->whereColumn('borrowers.id', 'funding_applications.borrower_id')
                    ->whereNotNull('borrowers.partner_id')
                    ->where(function ($differs) {
                        $differs->whereNull('funding_applications.partner_id')
                            ->orWhereColumn('borrowers.partner_id', '!=', 'funding_applications.partner_id');
                    });
            })
            ->update([
                'partner_id' => DB::raw('(SELECT borrowers.partner_id FROM borrowers WHERE borrowers.id = funding_applications.borrower_id)'),
            ]);
    }

    public function down(): void
    {
        // Nothing to undo: the old values were the wrong partner.
    }
};
