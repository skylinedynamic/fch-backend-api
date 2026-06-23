<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Funding;

use App\Http\Controllers\Controller;
use App\Models\Borrower\Borrower;
use App\Services\FCH\Common\Funding\FundingQualificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FundingQualificationController extends Controller
{
    protected $service;

    public function __construct(FundingQualificationService $service)
    {
        $this->service = $service;
    }

    public function getReadinessFactors(Request $request, $fundingId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            // Try to get borrower from the authenticated user
            $borrower = $user && $user->entity_type === Borrower::class ? $user->entity : null;

            if (!$borrower) {
                return response()->json(['status' => false, 'message' => 'Borrower not found.'], 404);
            }

            $factors = $this->service->evaluateReadinessFactors($borrower, $fundingId);

            return response()->json([
                'status' => true,
                'data' => $factors
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to evaluate readiness factors: ' . $e->getMessage()
            ], 500);
        }
    }


}
