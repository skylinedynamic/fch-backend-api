<?php

namespace App\Http\Controllers\Api\V1\Borrower\Funding;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Api\V1\Borrower\Funding\StoreFundingRequestRequest;
use App\Http\Requests\Api\V1\Borrower\Funding\FetchFundingRequestRequest;
use App\Services\Borrower\Funding\FundingRequestService;
use Exception;

class FundingRequestController extends Controller
{
    protected FundingRequestService $fundingRequestService;

    public function __construct(FundingRequestService $fundingRequestService)
    {
        $this->fundingRequestService = $fundingRequestService;
    }

    /**
     * Get a list of funding requests.
     *
     * @param FetchFundingRequestRequest $request
     * @return JsonResponse
     */
    public function index(FetchFundingRequestRequest $request): JsonResponse
    {
        try {
            return $this->fundingRequestService->index($request->validated());
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific funding request.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        try {
            return $this->fundingRequestService->show($id);
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
     * @param StoreFundingRequestRequest $request
     * @return JsonResponse
     */
    public function store(StoreFundingRequestRequest $request): JsonResponse
    {
        try {
            $data = $this->fundingRequestService->store($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'funding request submitted successfully.',
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
