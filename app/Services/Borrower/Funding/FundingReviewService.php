<?php

namespace App\Services\Borrower\Funding;

use App\Models\Borrower\FundingReview;
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

        $data['borrower_id'] = $borrower->id;
        $data['partner_id'] = $borrower->partner_id;
        $data['status'] = 'Pending';

        $fundingReview = FundingReview::create($data);

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

        $query = FundingReview::query()->with(['borrower', 'fundingSequence'])
            ->where('borrower_id', $borrower->id);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('funding_type', 'like', "%{$search}%")
                  ->orWhere('use_of_funds', 'like', "%{$search}%")
                  ->orWhere('requested_amount', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%")
                  ->orWhereRaw("DATE_FORMAT(created_at, '%b %d, %Y') LIKE ?", ["%{$search}%"])
                  ->orWhereRaw("DATE_FORMAT(created_at, '%M %d, %Y') LIKE ?", ["%{$search}%"]);
            });
        }

        $sortField = $filters['sort_field'] ?? 'id';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortField, ['id', 'created_at', 'requested_amount', 'status'])) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('id', 'desc');
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

        $review = FundingReview::with(['borrower', 'fundingSequence'])
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
