<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A borrower has one credit profile request; submitting again updates it.
 *
 * The service already looked the row up by borrower before inserting, but
 * nothing in the table enforced it, so two submissions arriving together (a
 * double click, a retry) could both find nothing and both insert; and a
 * soft-deleted row was invisible to the lookup, so the next submission added a
 * second one beside it.
 *
 * Existing duplicates are merged into the borrower's latest row first - a live
 * row over a deleted one, then the most recently updated - with the FCRA
 * consents that pointed at the others repointed to it, so no authorisation
 * loses the request it belongs to. Then borrower_id becomes unique.
 */
return new class extends Migration
{
    private const INDEX = 'credit_profile_requests_borrower_id_unique';

    public function up(): void
    {
        $duplicated = DB::table('credit_profile_requests')
            ->select('borrower_id')
            ->groupBy('borrower_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('borrower_id');

        foreach ($duplicated as $borrowerId) {
            $rows = DB::table('credit_profile_requests')
                ->where('borrower_id', $borrowerId)
                ->orderByRaw('deleted_at IS NULL DESC')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->pluck('id');

            $keep = $rows->first();
            $merged = $rows->slice(1)->values();

            if (Schema::hasTable('credit_authorization_consents')) {
                DB::table('credit_authorization_consents')
                    ->whereIn('credit_profile_request_id', $merged)
                    ->update(['credit_profile_request_id' => $keep]);
            }

            DB::table('credit_profile_requests')->whereIn('id', $merged)->delete();
        }

        Schema::table('credit_profile_requests', function (Blueprint $table) {
            $table->unique('borrower_id', self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('credit_profile_requests', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }
};
