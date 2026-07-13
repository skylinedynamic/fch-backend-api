<?php

namespace App\Services\FCH\Common\FundingRequest;

use App\Helpers\ApiResponse;
use App\Mail\FundingRequest\UnderwritingStatusUpdated;
use App\Models\FundingRequest\FundingRequest;
use App\Models\User\User;
use App\Traits\PaginatesApi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Models\ActivityLog\ActivityLog;
use App\Services\FCH\Common\ActivityLog\ActivityLogService;

class FundingRequestService
{
    use PaginatesApi;

    /**
     * Get Funding Request List
     */
    public function getFundingRequestList(array $filters)
    {
        $authUser = Auth::user();

        $isHost = $authUser->user_type === User::ADMIN || $authUser->user_type === User::EMPLOYEE || (isset($authUser->partner) && (int) ($authUser->partner->is_host ?? 0) === 1);

        $query = FundingRequest::with(['borrower', 'fundingSequence', 'partner'])
            ->when(!$isHost && isset($authUser->partner), function ($q) use ($authUser) {
                $q->where('partner_id', $authUser->partner_id);
            });

        // Apply filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['funding_sequence_id'])) {
            $query->where('funding_sequence_id', $filters['funding_sequence_id']);
        }

        if (!empty($filters['borrower_id'])) {
            $query->where('borrower_id', $filters['borrower_id']);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedSortFields = ['id', 'created_at', 'funding_type', 'use_of_funds', 'requested_amount', 'status', 'funding_sequence_id', 'borrower_name', 'partner_name'];
        
        if (in_array($sortField, $allowedSortFields)) {
            if ($sortField === 'borrower_name') {
                $query->orderBy(
                    \App\Models\Borrower\Borrower::select('first_name')
                        ->whereColumn('borrowers.id', 'funding_requests.borrower_id')
                        ->limit(1),
                    $sortOrder
                );
            } elseif ($sortField === 'partner_name') {
                $query->orderBy(
                    \App\Models\Partner\Partner::select('full_name')
                        ->whereColumn('partners.id', 'funding_requests.partner_id')
                        ->limit(1),
                    $sortOrder
                );
            } else {
                $query->orderBy($sortField, $sortOrder);
            }
        } else {
            $query->orderBy('id', 'desc'); // Fallback
        }

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        
        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        // Fetch unique funding sequences used within the current user's scope to populate the dropdown accurately
        $fundingTypes = \App\Models\Funding\FundingSequencing::whereIn('id', function($q) use ($authUser, $isHost) {
            $q->select('funding_sequence_id')
              ->from('funding_requests')
              ->when(!$isHost && isset($authUser->partner), function ($query) use ($authUser) {
                  $query->where('partner_id', $authUser->partner_id);
              });
        })->get(['id', 'name']);

        return $this->respondWithPagination(
            $paginator, 
            __('funding_request.funding_requests_retrieved'),
            200,
            null,
            ['funding_types' => $fundingTypes]
        );
    }

    /**
     * Get Funding Request By ID
     */
    public function getFundingRequestById($id)
    {
        $authUser = Auth::user();
        $isHost = $authUser->user_type === User::ADMIN || $authUser->user_type === User::EMPLOYEE || (isset($authUser->partner) && (int) ($authUser->partner->is_host ?? 0) === 1);
        
        $review = FundingRequest::with(['borrower.borrowerDocuments.documentType', 'fundingSequence', 'partner'])
            ->when(!$isHost && isset($authUser->partner), function($query) use($authUser){
                $query->where('partner_id', $authUser->partner_id);
            })
            ->where('id', $id)
            ->first();

        if (!$review) {
            return ApiResponse::error(__('funding_request.funding_request_not_found'));
        }

        return ApiResponse::success($review);
    }

    /**
     * Update Funding Request Status
     */
    public function fundingRequestStatusUpdate(array $data)
    {
        $authUser = Auth::user();

        $review = FundingRequest::where('id', $data['id'])
            ->when(isset($authUser->partner) && $authUser->partner->is_host == 0, function ($q) use ($authUser) {
                $q->where('partner_id', $authUser->partner_id);
            })->first();

        if (!$review) {
            return ApiResponse::error(__('funding_request.funding_request_not_found'));
        }

        $oldStatus = $review->status;
        $review->status = $data['status'];
        
        if ($data['status'] === FundingRequest::STATUS_APPROVED) {
            $review->approved_at = Carbon::now();
            $review->approved_by = $authUser->id;
            $review->reject_reason = null; 
        } elseif ($data['status'] === FundingRequest::STATUS_REJECTED) {
            $review->reject_reason = $data['reject_reason'] ?? null;
            $review->approved_at = Carbon::now();
            $review->approved_by = $authUser->id;
        }

        $review->save();

        if ($oldStatus !== $data['status']) {
            $borrowerEmail = $review->borrower->user->email ?? $review->borrower->business_email;
            if ($borrowerEmail) {
                Mail::to($borrowerEmail)
                    ->send(new UnderwritingStatusUpdated($review));
            }
        }

        $oldStatusFormatted = ucwords(strtolower($oldStatus));
        $newStatusFormatted = ucwords(strtolower($data['status']));

        if ($oldStatus === $data['status']) {
            $title = "Funding Request Review Note Updated";
            $description = "Review note / rejection reason was updated.";
        } else {
            $title = "Funding Request Status Changed: {$oldStatusFormatted} → {$newStatusFormatted}";
            $description = "Status updated to: " . ucfirst(strtolower($data['status']));
        }

        app(ActivityLogService::class)->logSystemEvent(
            FundingRequest::class,
            $review->id,
            $review->partner_id,
            $review->borrower_id,
            $title,
            $description,
            ActivityLog::LOG_TYPE_FUNDING_REQUEST_STATUS_UPDATE,
            ['status' => $data['status'], 'reject_reason' => $data['reject_reason'] ?? null]
        );

        return ApiResponse::success($review, __('funding_request.status_updated_successfully'));
    }

    /**
     * Assign Lender to Funding Request
     */
    public function assignLender(array $data)
    {
        $authUser = Auth::user();

        $review = FundingRequest::where('id', $data['id'])
            ->when(isset($authUser->partner) && $authUser->partner->is_host == 0, function ($q) use ($authUser) {
                $q->where('partner_id', $authUser->partner_id);
            })->first();

        if (!$review) {
            return ApiResponse::error(__('funding_request.funding_request_not_found'));
        }

        $review->lender_ids = $data['lender_ids'] ?? [];
        $review->assign_lender = !empty($data['lender_ids']) ? $data['lender_ids'][0] : null;
        $review->save();

        $lenderNames = \App\Models\Lender\Lender::whereIn('id', $review->lender_ids)->pluck('name')->toArray();
        $lendersStr = empty($lenderNames) ? 'None' : implode(', ', $lenderNames);

        app(ActivityLogService::class)->logSystemEvent(
            FundingRequest::class,
            $review->id,
            $review->partner_id,
            $review->borrower_id,
            "Lenders Assigned to Request",
            "Assigned Lender(s): " . $lendersStr,
            'update',
            [
                'lender_ids' => $review->lender_ids,
                'is_internal' => true
            ]
        );

        return ApiResponse::success($review, "Lenders assigned successfully");
    }
}
