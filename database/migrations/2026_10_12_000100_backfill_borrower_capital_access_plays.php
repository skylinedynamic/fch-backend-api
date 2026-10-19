<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill borrower_capital_access_plays (findings O41).
 *
 * The strategy list and overview GETs used to create a play row whenever a
 * borrower had applications on a strategy but no play row ("legacy data
 * sync"), so reading a page wrote to the database. The read path no longer
 * writes; this creates those rows once instead: running, or completed when
 * every step of the strategy is approved - the same rule the read path used.
 * Pairs that already have a row are left alone, so it can be re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pairs = DB::table('capital_access_applications as a')
            ->leftJoin('borrower_capital_access_plays as p', function ($join) {
                $join->on('p.borrower_id', '=', 'a.borrower_id')->on('p.capital_access_id', '=', 'a.capital_access_id');
            })
            ->whereNull('p.id')
            ->select('a.borrower_id', 'a.capital_access_id')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $totalSteps = DB::table('capital_access_sequences')->where('capital_access_id', $pair->capital_access_id)->count();
            $approvedSteps = DB::table('capital_access_applications')
                ->where('borrower_id', $pair->borrower_id)
                ->where('capital_access_id', $pair->capital_access_id)
                ->where('status', 'Approved')
                ->count();

            DB::table('borrower_capital_access_plays')->insert([
                'borrower_id' => $pair->borrower_id,
                'capital_access_id' => $pair->capital_access_id,
                'status' => ($totalSteps > 0 && $approvedSteps === $totalSteps) ? 'completed' : 'running',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the rows are what the read path would have written.
    }
};
