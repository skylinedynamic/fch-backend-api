<?php

namespace App\Services\FCH\Common\ChangeRequest;

use App\Helpers\ApiResponse;
use App\Services\Profile\ChangeRequestNotifier;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerChangeRequest;
use App\Models\User\User;
use App\Services\Global\Account\EmailChangeService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class ChangeRequestService
{
    use \App\Traits\PaginatesApi;

    /**
     * List change requests with filters and pagination.
     */
    public function list(array $filters)
    {
        $sortField = $filters['sort_field'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        $query = BorrowerChangeRequest::with([
            'borrower' => function ($q) {
                $q->select('id', 'partner_id', 'business_name', 'first_name', 'last_name');
            },
            'updater' => function ($q) {
                $q->select('id', 'name');
            }
        ]);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['partner_id'])) {
            $query->where('partner_id', $filters['partner_id']);
        }

        if (!empty($filters['borrower_id'])) {
            $query->where('borrower_id', $filters['borrower_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('field_name', 'like', "%{$search}%")
                    ->orWhere('visible_field_name', 'like', "%{$search}%")
                    ->orWhere('db_field_name', 'like', "%{$search}%")
                    ->orWhere('old_value', 'like', "%{$search}%")
                    ->orWhere('new_value', 'like', "%{$search}%")
                    ->orWhereHas('borrower', function ($bq) use ($search) {
                        $bq->where('business_name', 'like', "%{$search}%")
                            ->orWhere('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        // Apply advanced filters and sorting if QueryFilter is suitable here
        // For now using manual sort as it was before but cleaner
        $sortOrder = strtolower((string) $sortOrder) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortField, ['id', 'field_name', 'old_value', 'new_value', 'status', 'created_at', 'note'])) {
            $query->orderBy($sortField, $sortOrder);
        } elseif ($sortField === 'borrower_business_name') {
            $query->orderBy(
                Borrower::select('business_name')->whereColumn('borrowers.id', 'borrower_change_requests.borrower_id')->limit(1),
                $sortOrder
            );
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Calculate statistics
        $statistics = [
            'total_requests' => (clone $query)->count(),
            'pending_requests' => (clone $query)->where('status', BorrowerChangeRequest::STATUS_PENDING)->count(),
            'approved_requests' => (clone $query)->where('status', BorrowerChangeRequest::STATUS_APPROVED)->count(),
            'rejected_requests' => (clone $query)->where('status', BorrowerChangeRequest::STATUS_REJECTED)->count(),
        ];

        $perPage = $filters['per_page'] ?? 15;
        $page = $filters['page'] ?? 1;

        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            \App\Http\Resources\FCH\Common\ChangeRequest\ChangeRequestResource::class,
            __('profile.change_request_list_success'),
            200,
            ['statistics' => $statistics]
        );
    }

    /**
     * Update status and optional note of a change request.
     */
    /**
     * An SSN or EIN change's two numbers in full, for the reviewer deciding it -
     * the queue lists them masked (BUG-014). Logged on the client, without them.
     */
    public function reveal(int $id): JsonResponse
    {
        $changeRequest = BorrowerChangeRequest::findOrFail($id);

        if (!$changeRequest->isMaskedField()) {
            return ApiResponse::error(__('profile.change_request_nothing_to_reveal'), 422);
        }

        $label = \App\Models\ActivityLog\ActivityLog::fieldLabel($changeRequest->field_name);

        app(\App\Services\FCH\Common\ActivityLog\ActivityLogService::class)->logSystemEvent(
            Borrower::class,
            $changeRequest->borrower_id,
            $changeRequest->partner_id,
            $changeRequest->borrower_id,
            "{$label} Viewed",
            "The full {$label} in a change request was viewed.",
        );

        return ApiResponse::success([
            'id' => $changeRequest->id,
            'old_value' => $changeRequest->old_value,
            'new_value' => $changeRequest->new_value,
        ], __('profile.change_request_list_success'));
    }

    public function updateStatus(int $id, array $data): JsonResponse
    {
        $changeRequest = BorrowerChangeRequest::findOrFail($id);

        if ($changeRequest->status !== BorrowerChangeRequest::STATUS_PENDING) {
            return ApiResponse::error(__('profile.change_request_already_processed'), 422);
        }

        // A client's sign-in email isn't written straight to the record: it goes
        // through the same confirm-from-the-new-address flow as every other
        // email change, which also keeps the login and the record in step.
        // Checked before the request is marked approved, so an address that's
        // already taken leaves it pending instead of "approved but not applied".
        $emailChange = app(EmailChangeService::class);
        $loginForEmailChange = $this->loginForEmailChange($changeRequest, $data['status']);

        if ($loginForEmailChange) {
            try {
                $emailChange->ensureAvailable((string) $changeRequest->new_value, $loginForEmailChange);
            } catch (ValidationException $e) {
                return ApiResponse::error($e->getMessage(), 422);
            }
        }

        $changeRequest->update([
            'status' => $data['status'],
            'note' => $data['note'] ?? $changeRequest->note,
            'updated_by' => auth()->id(),
        ]);

        // If approved, apply the change to the correct model/table
        if ($data['status'] === BorrowerChangeRequest::STATUS_APPROVED) {
            $borrower = $changeRequest->borrower;
            if ($borrower) {
                $entityType = $changeRequest->entity_type;
                $entityId   = $changeRequest->entity_id;

                if ($entityType && $entityId) {
                    // entity_type is a fully-qualified model class name
                    if ($loginForEmailChange) {
                        $emailChange->request($loginForEmailChange, (string) $changeRequest->new_value);
                    } elseif ($entityType === \App\Models\Borrower\Borrower::class) {
                        // Borrower's own table — update via the model directly
                        $borrower->{$changeRequest->db_field_name} = $changeRequest->new_value;
                        $borrower->save();
                    } elseif (class_exists($entityType)) {
                        // Child model (e.g. BorrowerBankAccount) — resolve table dynamically
                        $tableName = (new $entityType)->getTable();
                        \Illuminate\Support\Facades\DB::table($tableName)
                            ->where('id', $entityId)
                            ->where('borrower_id', $borrower->id)
                            ->update([
                                $changeRequest->db_field_name => $changeRequest->new_value,
                                'updated_at' => now(),
                            ]);
                    }
                }
            }
        }

        // Tell the borrower, from their OWN partner's mailbox. The notifier logs
        // and swallows its own failures.
        app(ChangeRequestNotifier::class)->decisionMade($changeRequest);

        return ApiResponse::success(
            new \App\Http\Resources\FCH\Common\ChangeRequest\ChangeRequestResource($changeRequest->fresh(['borrower', 'updater'])),
            __('profile.change_request_status_updated')
        );
    }

    /**
     * The login whose sign-in email an approved request would change: only an
     * approval of the client's own `email` field, and only when they have a
     * login — without one there's nothing to confirm and it applies directly.
     */
    private function loginForEmailChange(BorrowerChangeRequest $changeRequest, string $status): ?User
    {
        if ($status !== BorrowerChangeRequest::STATUS_APPROVED
            || $changeRequest->entity_type !== Borrower::class
            || $changeRequest->db_field_name !== 'email') {
            return null;
        }

        return $changeRequest->borrower?->user;
    }
}
