<?php

use App\Models\Commission\Commission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BUG-107: deleting a client used to leave its commissions behind, still
 * counted in the Commissions totals against a funding application that was
 * gone. Clients deleted from now on take those commissions with them
 * (Borrower::safeDeleteWithRelations); this clears the ones already left.
 *
 * Soft-deletes commissions not paid out whose funding application is deleted
 * or missing. Commissions already paid out stay, as the record of the payout.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('commissions')
            ->whereNull('deleted_at')
            ->where('payment_status', '!=', Commission::PAYMENT_STATUS_PAID)
            ->where(function ($q) {
                $q->whereNull('funding_application_id')
                    ->orWhereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('funding_applications')
                            ->whereColumn('funding_applications.id', 'commissions.funding_application_id')
                            ->whereNull('funding_applications.deleted_at');
                    });
            })
            ->update(['deleted_at' => now()]);
    }

    /** The rows cannot be told apart from ones deleted for other reasons afterwards, so nothing is restored. */
    public function down(): void
    {
    }
};
