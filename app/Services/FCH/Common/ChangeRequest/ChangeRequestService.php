<?php

namespace App\Services\FCH\Common\ChangeRequest;

use App\Helpers\ApiResponse;
use App\Models\Borrower\BorrowerChangeRequest;
use App\Mail\Borrower\ChangeRequestStatusMail;
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
        if (in_array($sortField, ['id', 'field_name', 'old_value', 'new_value', 'status', 'created_at', 'note'])) {
            $query->orderBy($sortField, $sortOrder);
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
    public function updateStatus(int $id, array $data): JsonResponse
    {
        $changeRequest = BorrowerChangeRequest::findOrFail($id);

        if ($changeRequest->status !== BorrowerChangeRequest::STATUS_PENDING) {
            return ApiResponse::error(__('profile.change_request_already_processed'), 422);
        }

        $changeRequest->update([
            'status' => $data['status'],
            'note' => $data['note'] ?? $changeRequest->note,
            'updated_by' => auth()->id(),
        ]);

        // If approved, apply the change to the borrower model
        if ($data['status'] === BorrowerChangeRequest::STATUS_APPROVED) {
            $borrower = $changeRequest->borrower;
            if ($borrower) {
                $borrower->{$changeRequest->db_field_name} = $changeRequest->new_value;
                $borrower->save();
            }
        }

        // Send email notification to borrower
        try {
            $borrower = $changeRequest->borrower;
            $email = $borrower->email ?? $borrower->business_email;

            if ($email) {
                Mail::to($email)->send(new ChangeRequestStatusMail([
                    'borrower_name' => $borrower->first_name . ' ' . $borrower->last_name,
                    'field_name' => $changeRequest->field_name,
                    'status' => $changeRequest->status,
                    'new_value' => $changeRequest->new_value,
                    'note' => $changeRequest->note,
                ]));
            }
        } catch (\Exception $e) {
            Log::error("Failed to send change request email: " . $e->getMessage());
        }

        return ApiResponse::success(
            new \App\Http\Resources\FCH\Common\ChangeRequest\ChangeRequestResource($changeRequest->fresh(['borrower', 'updater'])),
            __('profile.change_request_status_updated')
        );
    }
}
