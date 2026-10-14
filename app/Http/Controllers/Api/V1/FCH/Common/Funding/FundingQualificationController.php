<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Funding;

use App\Http\Controllers\Controller;
use App\Models\Borrower\Borrower;
use App\Services\FCH\Common\Funding\FundingQualificationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

            $funding = \App\Models\Funding\FundingSequencing::find($fundingId);
            if ($funding) {
                $engineStatus = $funding->engine_status ?? \App\Models\Funding\FundingSequencing::ENGINE_STATUS_ACTIVE;
                if ($engineStatus === \App\Models\Funding\FundingSequencing::ENGINE_STATUS_HIDDEN) {
                    $hardStopReasons = $funding->getEngineStatusReasonsForBorrower($borrower);
                    foreach ($hardStopReasons as $reason) {
                        $exists = false;
                        foreach ($factors as $factor) {
                            if ($factor['label'] === $reason['label']) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $factors[] = $reason;
                        }
                    }
                }
            }

            return response()->json([
                'status' => true,
                'data' => $factors
            ]);
        } catch (ModelNotFoundException $e) {
            // An unknown funding id is a missing resource, not a server fault.
            return response()->json(['status' => false, 'message' => 'Funding not found.'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to evaluate readiness factors: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getClientReadinessFactors(Request $request, $borrowerId, $fundingId): JsonResponse
    {
        try {
            // Own partner's clients only (any for the host's team).
            $borrower = Borrower::visibleTo($request->user())->find($borrowerId);

            if (!$borrower) {
                return response()->json(['status' => false, 'message' => 'Borrower not found.'], 404);
            }

            $factors = $this->service->evaluateReadinessFactors($borrower, $fundingId);

            $funding = \App\Models\Funding\FundingSequencing::find($fundingId);
            if ($funding) {
                $engineStatus = $funding->engine_status ?? \App\Models\Funding\FundingSequencing::ENGINE_STATUS_ACTIVE;
                if ($engineStatus === \App\Models\Funding\FundingSequencing::ENGINE_STATUS_HIDDEN) {
                    $hardStopReasons = $funding->getEngineStatusReasonsForBorrower($borrower);
                    foreach ($hardStopReasons as $reason) {
                        $exists = false;
                        foreach ($factors as $factor) {
                            if ($factor['label'] === $reason['label']) {
                                $exists = true;
                                break;
                            }
                        }
                        if (!$exists) {
                            $factors[] = $reason;
                        }
                    }
                }
            }

            return response()->json([
                'status' => true,
                'data' => $factors
            ]);
        } catch (ModelNotFoundException $e) {
            // An unknown funding id is a missing resource, not a server fault.
            return response()->json(['status' => false, 'message' => 'Funding not found.'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to evaluate readiness factors: ' . $e->getMessage()
            ], 500);
        }
    }
}
