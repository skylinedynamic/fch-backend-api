<?php

namespace App\Http\Controllers\Api\V1\Borrower\Funding;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Api\V1\Borrower\Funding\StoreFundingReviewRequest;
use App\Http\Requests\Api\V1\Borrower\Funding\FetchFundingReviewRequest;
use App\Services\Borrower\Funding\FundingReviewService;
use Exception;

class FundingReviewController extends Controller
{
    protected FundingReviewService $fundingReviewService;

    public function __construct(FundingReviewService $fundingReviewService)
    {
        $this->fundingReviewService = $fundingReviewService;
    }

    /**
     * Get a list of funding reviews.
     *
     * @param FetchFundingReviewRequest $request
     * @return JsonResponse
     */
    public function index(FetchFundingReviewRequest $request): JsonResponse
    {
        try {
            return $this->fundingReviewService->index($request->validated());
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific funding review.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        try {
            return $this->fundingReviewService->show($id);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreFundingReviewRequest $request
     * @return JsonResponse
     */
    public function store(StoreFundingReviewRequest $request): JsonResponse
    {
        try {
            $data = $this->fundingReviewService->store($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Funding review submitted successfully.',
                'data' => $data
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
