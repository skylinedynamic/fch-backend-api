<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Corrects the recorded provenance of pre_qualification_requests.personal_credit_score.
 *
 * That column only ever holds the band the borrower selects in the wizard
 * ("Good (670-739)"). It is nulled out of every prefill and is never populated
 * from a bureau pull, so its source is borrower-reported, always.
 *
 * Existing rows say "Verified Credit Profile" because the label was derived from
 * whether the borrower happened to have a credit-profile fico_score — a
 * different number than the one stored in the column it describes. The Internal
 * AUE ranks verified data above borrower-reported data, so those rows promote a
 * self-selected band to verified evidence.
 *
 * The fix in PreQualificationService::creditScoreSourceFor() only corrects rows
 * written from here on; this corrects the ones already stored.
 *
 * Production notes:
 *  - Data-only. No schema change, no lock on the table structure, no downtime.
 *  - Chunked by primary key so a large table is updated in bounded batches
 *    rather than one long transaction.
 *  - Idempotent. Re-running matches nothing and changes nothing.
 *  - Row counts are written to the log so the change is auditable after the fact.
 */
return new class extends Migration
{
    /**
     * Every value the old derivation could write. Both described the same
     * borrower-selected band, so both are normalised to one vocabulary rather
     * than leaving the column with three spellings of the same fact.
     */
    private const LEGACY_VALUES = [
        'Verified Credit Profile',
        'Self-Reported',
    ];

    private const CORRECTED_VALUE = 'Borrower-Reported';

    private const CHUNK = 500;

    public function up(): void
    {
        $total = 0;

        foreach (self::LEGACY_VALUES as $legacy) {
            $updated = 0;

            do {
                $ids = DB::table('pre_qualification_requests')
                    ->where('credit_score_source', $legacy)
                    ->orderBy('id')
                    ->limit(self::CHUNK)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    break;
                }

                $updated += DB::table('pre_qualification_requests')
                    ->whereIn('id', $ids)
                    ->update(['credit_score_source' => self::CORRECTED_VALUE]);
            } while ($ids->count() === self::CHUNK);

            if ($updated > 0) {
                Log::info('Corrected credit_score_source on pre-qualification requests', [
                    'from' => $legacy,
                    'to' => self::CORRECTED_VALUE,
                    'rows' => $updated,
                ]);
            }

            $total += $updated;
        }

        Log::info('credit_score_source correction complete', ['rows' => $total]);
    }

    /**
     * Not reversed. Restoring "Verified Credit Profile" would put the incorrect
     * provenance back on underwriting records, and the original value carried no
     * information that is lost by correcting it — it was derived from the
     * borrower's credit profile, not entered by anyone, and the derivation still
     * exists in git history.
     */
    public function down(): void
    {
        //
    }
};
