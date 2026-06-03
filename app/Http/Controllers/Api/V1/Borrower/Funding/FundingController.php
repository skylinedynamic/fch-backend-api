<?php

namespace App\Http\Controllers\Api\V1\Borrower\Funding;

use App\Http\Controllers\Controller;
use App\Services\Borrower\Funding\FundingService;
use App\Http\Resources\Borrower\Funding\FundingGroupResource;
use App\Http\Resources\Borrower\Funding\FundingTypeResource;
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
            $stats = $this->service->getDashboardStats();

            return response()->json([
                'status' => true,
                'data' => [
                    'groups' => FundingGroupResource::collection($groupedSequencings),
                    'types' => FundingTypeResource::collection($types),
                    'stats' => $stats
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to load funding data: ' . $e->getMessage()
            ], 500);
        }
    }
}
