<?php

namespace App\Services\FCH\Common\FundingReview;

use App\Models\FundingReview\FundingReview;
use App\Models\User\User;
use App\Helpers\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Traits\PaginatesApi;

class FundingReviewService
{
    use PaginatesApi;

    /**
     * Get Funding Review List
     */
    public function getFundingReviewList(array $filters)
    {
        $authUser = Auth::user();

        $query = FundingReview::with(['borrower', 'fundingSequence'])
            ->when($authUser->user_type == User::PARTNER, function ($q) use ($authUser) {
                $q->where('partner_id', $authUser->partner_id);
            });

        // Apply filters
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedSortFields = ['id', 'created_at', 'funding_type', 'use_of_funds', 'requested_amount', 'status', 'funding_sequence_id'];
        
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('id', 'desc'); // Fallback
        }

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        
        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPagination($paginator, __('funding_review.funding_reviews_retrieved'));
    }

    /**
     * Get Funding Review By ID
     */
    public function getFundingReviewById($id)
    {
        $authUser = Auth::user();
        
        $review = FundingReview::with(['borrower.borrowerDocuments.documentType', 'fundingSequence'])
            ->when($authUser->user_type == User::PARTNER, function($query) use($authUser){
                $query->where('partner_id', $authUser->partner_id);
            })
            ->where('id', $id)
            ->first();

        if (!$review) {
            return ApiResponse::error(__('funding_review.funding_review_not_found'));
        }

        return ApiResponse::success($review);
    }

    /**
     * Update Funding Review Status
     */
    public function fundingReviewStatusUpdate(array $data)
    {
        $authUser = Auth::user();

        $review = FundingReview::where('id', $data['id'])
            ->when($authUser->user_type == User::PARTNER, function ($q) use ($authUser) {
                $q->where('partner_id', $authUser->partner_id);
            })->first();

        if (!$review) {
            return ApiResponse::error(__('funding_review.funding_review_not_found'));
        }

        $review->status = $data['status'];
        
        if ($data['status'] === FundingReview::STATUS_APPROVED) {
            $review->approved_at = Carbon::now();
            $review->approved_by = $authUser->id;
            $review->reject_reason = null; // Maybe we could use reject_reason as a general note or add a separate note column? The prompt just said "reason note accurately". Let's assume reject_reason is used for reject notes. Wait, I should probably save approval note as well if it exists? The model only has reject_reason. Let's just save reject_reason even if it's approved? No, let's keep it null for approval.
        } elseif ($data['status'] === FundingReview::STATUS_REJECTED) {
            $review->reject_reason = $data['reject_reason'] ?? null;
            $review->approved_at = Carbon::now();
            $review->approved_by = $authUser->id;
        }

        $review->save();

        return ApiResponse::success($review, __('funding_review.status_updated_successfully'));
    }
}
