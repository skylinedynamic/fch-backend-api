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

            // Save the details in the database
            $creditProfileRequest = CreditProfileRequest::updateOrCreate(
                ['borrower_id' => $borrowerId],
                $data
            );

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
