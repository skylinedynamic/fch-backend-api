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

        $fieldName = $data["field_name"];
        $mapping = \App\Services\FCH\Common\ChangeRequest\BorrowerMappingService::getFieldMapping($fieldName);

        if (!$mapping) {
            throw new \Exception("Field mapping not found for: {$fieldName}");
        }

        $dbColumn = $mapping['db_column'];
        $fieldType = $mapping['type'];
        $visibleName = $mapping['visible_column'];
        $oldValue = $borrower->{$dbColumn};

        return BorrowerChangeRequest::create([
            "partner_id" => $borrower->partner_id,
            "borrower_id" => $borrower->id,
            "field_name" => $fieldName,
            "field_type" => $fieldType,
            "visible_field_name" => $visibleName,
            "db_field_name" => $dbColumn,
            "old_value" => $oldValue,
            "new_value" => $data["new_value"],
            "status" => BorrowerChangeRequest::STATUS_PENDING,
            "note" => null,
            "updated_by" => null,
        ]);
    }
}

