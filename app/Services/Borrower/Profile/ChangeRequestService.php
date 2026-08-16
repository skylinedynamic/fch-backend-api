<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\User\User;
use App\Models\Partner\Partner;
use App\Mail\Partner\NewChangeRequestSubmittedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

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
            throw new \Exception(__("borrower.borrower_not_found"));
        }

        $fieldName = $data["field_name"];
        $mapping = \App\Services\FCH\Common\ChangeRequest\BorrowerMappingService::getFieldMapping($fieldName);

        if (!$mapping) {
            throw new \Exception(__('borrower.field_mapping_not_found', ['field' => $fieldName]));
        }

        $dbColumn    = $mapping['db_column'];
        $fieldType   = $mapping['type'];
        $visibleName = $mapping['visible_column'];
        // FQCN stored in entity_type — resolved from the mapping
        $modelClass  = $mapping['model'];

        $oldValue = null;
        $entityId = $data['entity_id'] ?? null;

        // Handle child table fields (e.g. BorrowerBankAccount)
        if (isset($mapping['table']) && $entityId) {
            $tableName = $mapping['table'];
            $childRecord = \Illuminate\Support\Facades\DB::table($tableName)
                ->where('id', $entityId)
                ->where('borrower_id', $borrower->id)
                ->first();

            if ($childRecord) {
                $oldValue = $childRecord->{$dbColumn};
            }
        } else {
            // Standard borrower table field — entity_id is the borrower's own id
            $entityId = $borrower->id;
            $oldValue = $borrower->{$dbColumn};
        }

        $changeRequest = BorrowerChangeRequest::create([
            "partner_id"         => $borrower->partner_id,
            "borrower_id"        => $borrower->id,
            "field_name"         => $fieldName,
            "field_type"         => $fieldType,
            "visible_field_name" => $visibleName,
            "db_field_name"      => $dbColumn,
            "entity_type"        => $modelClass,
            "entity_id"          => $entityId,
            "old_value"          => $oldValue,
            "new_value"          => $data["new_value"],
            "status"             => BorrowerChangeRequest::STATUS_PENDING,
            "note"               => null,
            "updated_by"         => null,
        ]);

        // Send email to Host Partner with host partner email config
        try {
            $hostPartner = Partner::host();
            $hostEmail = $hostPartner?->email ?: $hostPartner?->contact_person_email;

            if ($hostEmail) {
                Mail::to($hostEmail)->send(new NewChangeRequestSubmittedMail($hostPartner, $changeRequest));
            } else {
                Log::warning('NewChangeRequestSubmitted email not sent: Host partner email is empty.');
            }
        } catch (\Exception $e) {
            Log::error('Failed to send Change Request email to host partner: ' . $e->getMessage());
        }

        return $changeRequest;
    }
}

