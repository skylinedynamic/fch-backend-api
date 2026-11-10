<?php

namespace App\Services\Borrower\Profile;

use App\Models\Borrower\BorrowerChangeRequest;
use App\Services\Profile\ChangeRequestNotifier;
use App\Models\User\User;
use App\Models\Partner\Partner;
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

        // The value on file. A row field (e.g. a bank account) must name one of
        // this borrower's own rows, or approving it would change nothing - or
        // the wrong row; a profile column belongs to the borrower itself.
        $current = app(ChangeRequestGuard::class)->current($borrower, $fieldName, $data['entity_id'] ?? null);

        if (!$current) {
            throw new \Exception(__('borrower.change_request_record_not_found'));
        }

        $entityId = $current['entity_id'];
        $oldValue = $current['value'];

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

        // Announce it to the host, from the host's own mailbox. The notifier
        // logs and swallows its own failures - the request is already saved, so
        // a mail problem must not surface to the borrower.
        app(ChangeRequestNotifier::class)->requestSubmitted($changeRequest);

        return $changeRequest;
    }

    /**
     * The borrower withdraws a request still waiting for review. It is removed
     * for good (force delete), not kept as trashed: nothing was decided, so
     * there is no history to keep. Null when it is not this borrower's.
     *
     * @return bool|null true when removed, false when it was already reviewed
     */
    public function cancel(int $userId, int $id): ?bool
    {
        $borrowerId = User::where('id', $userId)->value('entity_id');
        $request = $borrowerId
            ? BorrowerChangeRequest::where('borrower_id', $borrowerId)->find($id)
            : null;

        if (!$request) {
            return null;
        }

        if ($request->status !== BorrowerChangeRequest::STATUS_PENDING) {
            return false;
        }

        $request->forceDelete();

        return true;
    }
}
