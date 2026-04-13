<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\User\User;

class ChangeRequestService
{
    /**
     * Submit a new change request for a borrower field
     *
     * @param int $userId
     * @param array $data
     * @return BorrowerChangeRequest
     * @throws \Exception
     */
    public function submitRequest(int $userId, array $data)
    {
        $user = User::with("entity")->where("id", $userId)->first();
        $borrower = $user?->entity;

        if (!$borrower) {
            throw new \Exception(__("profile.borrower_not_found"));
        }

        return BorrowerChangeRequest::create([
            "partner_id" => $borrower->partner_id,
            "borrower_id" => $borrower->id,
            "field_name" => $data["field_name"],
            "old_value" => $data["old_value"],
            "new_value" => $data["new_value"],
            "status" => BorrowerChangeRequest::STATUS_PENDING,
            "note" => null,
            "updated_by" => null,
        ]);
    }
}

