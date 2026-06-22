<?php

namespace App\Services\Borrower\Funding;

use App\Models\FundingReview\FundingReview;
use App\Http\Resources\Borrower\Funding\FundingReviewResource;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;
use Exception;

class FundingReviewService
{
    use PaginatesApi;

    /**
     * Store a new funding review.
     *
     * @param array $data
     * @return array
     */
    public function store(array $data)
    {
        $user = Auth::user();
        $borrower = $user->borrower;

        $fundingOption = \App\Models\Funding\FundingSequencing::find($data['funding_sequence_id']);
        if ($fundingOption) {
            $readinessService = app(\App\Services\Borrower\Funding\FundingReadinessService::class);
            $missingDocs = $readinessService->getMissingDocuments($fundingOption, $borrower);
            
            if (!empty($missingDocs)) {
                throw new Exception("Please upload all required documents before submitting.");
            }
        }

        $data['borrower_id'] = $borrower->id;
        $data['partner_id'] = $borrower->partner_id;
        $data['status'] = 'Pending';

        $fundingReview = FundingReview::updateOrCreate(
            [
                'borrower_id' => $borrower->id,
                'funding_sequence_id' => $data['funding_sequence_id']
            ],
            $data
        );

        return [
            'id' => $fundingReview->id,
            'status' => $fundingReview->status,
            'created_at' => $fundingReview->created_at->format('M d, Y h:i A')
        ];
    }

    /**
     * Get a list of funding reviews for the authenticated borrower.
     *
     * @param array $filters
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(array $filters)
    {
        $user = Auth::user();
        $borrower = $user->borrower;

        if (!$borrower) {
            throw new Exception("Borrower profile not found for this user.");
        }

        $query = FundingReview::query()
            ->select('funding_reviews.*')
            ->leftJoin('funding_sequencings', 'funding_reviews.funding_sequence_id', '=', 'funding_sequencings.id')
            ->with(['borrower', 'fundingSequence', 'reviewer'])
            ->where('funding_reviews.borrower_id', $borrower->id);

        if (!empty($filters['status'])) {
            $query->where('funding_reviews.status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('funding_reviews.funding_type', 'like', "%{$search}%")
                  ->orWhere('funding_reviews.use_of_funds', 'like', "%{$search}%")
                  ->orWhere('funding_reviews.requested_amount', 'like', "%{$search}%")
                  ->orWhere('funding_reviews.status', 'like', "%{$search}%")
                  ->orWhereRaw("DATE_FORMAT(funding_reviews.created_at, '%b %d, %Y') LIKE ?", ["%{$search}%"])
                  ->orWhereRaw("DATE_FORMAT(funding_reviews.created_at, '%M %d, %Y') LIKE ?", ["%{$search}%"])
                  ->orWhere('funding_sequencings.amount_range', 'like', "%{$search}%")
                  ->orWhere('funding_sequencings.name', 'like', "%{$search}%");
            });
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortField, ['id', 'created_at', 'requested_amount', 'status', 'funding_type', 'use_of_funds'])) {
            $query->orderBy("funding_reviews.{$sortField}", $sortOrder);
        } elseif ($sortField === 'amount_range') {
            $query->orderBy('funding_sequencings.amount_range', $sortOrder);
        } else {
            $query->orderBy('funding_reviews.id', 'desc');
        }

        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 10;
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        
        $paginator = $this->paginateBuilderWith($query, $perPage, $page);

        return $this->respondWithPaginationResource(
            $paginator,
            FundingReviewResource::class,
            'Funding reviews retrieved successfully.'
        );
    }

    /**
     * Get details of a specific funding review.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $user = Auth::user();
        $borrower = $user->borrower;

        if (!$borrower) {
            throw new Exception("Borrower profile not found for this user.");
        }

        $review = FundingReview::with(['borrower', 'fundingSequence', 'reviewer'])
            ->where('borrower_id', $borrower->id)
            ->where('id', $id)
            ->first();

        if (!$review) {
            throw new Exception("Funding review not found.");
        }

        return response()->json([
            'success' => true,
            'data' => new FundingReviewResource($review),
            'message' => 'Funding review retrieved successfully.'
        ]);
    }
}
