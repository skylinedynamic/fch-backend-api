<?php

namespace App\Services\Borrower\Funding;

use App\Http\Resources\Borrower\Funding\FundingReviewResource;
use App\Mail\FundingReview\UnderwritingSubmitted;
use App\Models\Funding\FundingSequencing;
use App\Models\FundingReview\FundingReview;
use App\Models\Partner\Partner;
use App\Services\Borrower\Funding\FundingReadinessService;
use App\Traits\PaginatesApi;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

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

        $fundingOption = FundingSequencing::find($data['funding_sequence_id']);
        $lenderIds = [];
        if ($fundingOption) {
            $readinessService = app(FundingReadinessService::class);
            $missingDocs = $readinessService->getMissingDocuments($fundingOption, $borrower);
            
            if (!empty($missingDocs)) {
                throw new Exception("Please upload all required documents before submitting.");
            }

            // Calculate matched lenders
            $lenders = $fundingOption->lenderVisibilities;
            foreach ($lenders as $lenderSeq) {
                $status = $lenderSeq->getEngineStatusForBorrower($borrower);
                if ($status !== FundingSequencing::ENGINE_STATUS_HIDDEN) {
                    $lenderIds[] = $lenderSeq->lender_id;
                }
            }
            if (empty($lenderIds)) {
                $lenderIds = $lenders->pluck('lender_id')->toArray();
            }
        }

        $data['borrower_id'] = $borrower->id;
        $data['partner_id'] = $borrower->partner_id;
        $data['status'] = FundingReview::STATUS_PENDING;
        $data['lender_ids'] = $data['lender_ids'] ?? $lenderIds;

        $fundingReview = FundingReview::updateOrCreate(
            [
                'borrower_id' => $borrower->id,
                'funding_sequence_id' => $data['funding_sequence_id']
            ],
            $data
        );

        if ($fundingReview->wasRecentlyCreated || $fundingReview->status === FundingReview::STATUS_PENDING) {
            $hostPartner = Partner::where('is_host', 1)->first();
            if ($hostPartner && $hostPartner->email) {
                Mail::to($hostPartner->email)
                    ->send(new UnderwritingSubmitted($fundingReview));
            }
        }

        $fundingReview->loadMissing(['borrower', 'fundingSequence', 'reviewer']);
        $request = request();
        return (new FundingReviewResource($fundingReview))->toArray($request);
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
