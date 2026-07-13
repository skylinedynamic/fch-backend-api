<?php

namespace App\Http\Controllers\Api\V1\Borrower\Funding;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Borrower\Funding\FundingService;
use App\Http\Resources\Borrower\Funding\FundingGroupResource;
use App\Http\Resources\Borrower\Funding\FundingTypeResource;
use App\Http\Resources\Borrower\Funding\FundingSequencingResource;
use Illuminate\Http\JsonResponse;

class FundingController extends Controller
{
    protected $service;

    public function __construct(FundingService $service)
    {
        $this->service = $service;
    }

    /**
     * Get grouped funding data for the borrower portal.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $groupedSequencings = $this->service->getGroupedSequencings();
            $types = $this->service->getActiveTypes();
            $stats = $this->service->getDashboardStats($groupedSequencings);

            return ApiResponse::success(
                [
                    'groups' => FundingGroupResource::collection($groupedSequencings),
                    'types' => FundingTypeResource::collection($types),
                    'stats' => $stats
                ],
                __('borrower.funding_data_retrieved')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(
                __('borrower.funding_data_failed') . ': ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get a single funding option detail by ID.
     *
     * @param int|string $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        try {
            $sequencing = $this->service->getSequencingById($id);

            return ApiResponse::success(
                new FundingSequencingResource($sequencing),
                __('borrower.funding_option_retrieved')
            );
        } catch (\Exception $e) {
            return ApiResponse::error(
                __('borrower.funding_option_failed') . ': ' . $e->getMessage(),
                404
            );
        }
    }
}
