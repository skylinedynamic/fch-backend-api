<?php

namespace Database\Seeders;

use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\User\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Removes the borrower-portal E2E account's pending change requests, before
 * and after the change-request E2E (fch-borrower-react:
 * e2e/sidebar/profile/business-details-change-request-submit.spec.ts).
 *
 * The spec cancels its own request from the page; this is the safety net for a
 * run that fails before it does, since a request left pending would block the
 * next run (R2 BUG-039: a field waiting for review takes no second request).
 * Only Pending rows are removed, for good - reviewed history is kept.
 * Local/testing only.
 *
 *   php artisan db:seed --class=E2EClearBorrowerChangeRequestsSeeder
 */
class E2EClearBorrowerChangeRequestsSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EClearBorrowerChangeRequestsSeeder only runs in local/testing.');
        }

        $email = env('E2E_BORROWER_EMAIL', 'craig-borrower@fourcornerholdings.com');
        $borrowerId = User::where('email', $email)->firstOrFail()->entity_id;

        // Force deleted, as the borrower's own Cancel does - including any an
        // earlier version of this seeder only soft deleted.
        $removed = BorrowerChangeRequest::withTrashed()
            ->where('borrower_id', $borrowerId)
            ->where('status', BorrowerChangeRequest::STATUS_PENDING)
            ->forceDelete();

        $this->command?->info("Removed {$removed} pending change request(s) for {$email}");
    }
}
