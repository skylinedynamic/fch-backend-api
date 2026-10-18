<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\CreditProfileRequest;
use App\Models\Borrower\Borrower;
use App\Models\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class CreditProfileRequestService
{
    /**
     * Store a new credit profile request and mark the borrower's credit profile as updated.
     *
     * @param User $user
     * @param array $data
     * @return CreditProfileRequest
     * @throws Exception
     */
    public function store(User $user, array $data): CreditProfileRequest
    {
        Log::info("Starting CreditProfileRequest creation/update for Borrower ID: {$user->entity_id}");
        
        try {
            DB::beginTransaction();

            $borrowerId = $user->entity_id;

            // One request per borrower (borrower_id is unique): submitting again
            // updates it. withTrashed() so a soft-deleted request is brought back
            // rather than a second row added beside it; updateOrCreate() falls
            // back to the existing row if a simultaneous submission inserted first.
            $creditProfileRequest = CreditProfileRequest::withTrashed()->updateOrCreate(
                ['borrower_id' => $borrowerId],
                $data + ['deleted_at' => null]
            );

            // A resubmission is the latest request even when nothing in it
            // changed, so the Request Credit Profile list (newest first) shows it
            // at the top.
            if (!$creditProfileRequest->wasRecentlyCreated) {
                $creditProfileRequest->touch();
            }

            // Set the borrower's credit_profile_updated to true
            $borrower = Borrower::find($borrowerId);
            if ($borrower) {
                $borrower->credit_profile_updated = true;
                $borrower->save();
            }

            // Set is_refresh = true in the user table for this borrower
            $user->update(['is_refresh' => true]);

            DB::commit();

            Log::info("Successfully stored/updated CreditProfileRequest for Borrower ID: {$user->entity_id} (Request ID: {$creditProfileRequest->id})");

            return $creditProfileRequest;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to store credit profile request for Borrower ID: {$user->entity_id}. Error: " . $e->getMessage());
            throw new Exception('Failed to store credit profile request: ' . $e->getMessage());
        }
    }

    /**
     * The borrower's "Request update": their credit profile request, at the top
     * of the Request Credit Profile list. The one on file is brought forward
     * (restored if deleted) with the details they gave on the form; a borrower
     * who never submitted the form (the host saved their report directly) gets
     * one built from their profile, empty where the profile holds nothing.
     */
    public function requestUpdate(Borrower $borrower): CreditProfileRequest
    {
        $existing = CreditProfileRequest::withTrashed()->where('borrower_id', $borrower->id)->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }
            $existing->touch();

            return $existing;
        }

        // updateOrCreate: a simultaneous request may have inserted first
        // (borrower_id is unique).
        return CreditProfileRequest::withTrashed()->updateOrCreate(
            ['borrower_id' => $borrower->id],
            [
                'first_name' => $borrower->first_name,
                'middle_name' => $borrower->middle_name,
                'last_name' => $borrower->last_name,
                'address' => $borrower->home_address,
                'city' => $borrower->city,
                'state' => $borrower->state,
                'zip' => $borrower->postal_code,
                // Only a full SSN can be pulled with; the last four alone is not one.
                'ssn' => $borrower->hasFullSsn() ? $borrower->ssn : null,
                'date_of_birth' => $borrower->date_of_birth ? toDateOnly($borrower->date_of_birth) : null,
                'phone' => $borrower->phone_number,
                'email' => $borrower->email,
                'deleted_at' => null,
            ]
        );
    }

    /**
     * Get the latest submitted credit profile request.
     *
     * @param int $borrowerId
     * @return CreditProfileRequest|null
     */
    public function getLatestRequest(int $borrowerId): ?CreditProfileRequest
    {
        return CreditProfileRequest::where('borrower_id', $borrowerId)
            ->latest()
            ->first();
    }
}
