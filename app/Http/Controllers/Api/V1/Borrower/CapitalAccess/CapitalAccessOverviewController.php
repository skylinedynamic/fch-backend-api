<?php

namespace App\Http\Controllers\Api\V1\Borrower\CapitalAccess;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\CreditBuilder\CapitalAccessApplication;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use Illuminate\Http\JsonResponse;
use App\Models\CreditBuilder\CapitalAccess;
use App\Services\Borrower\CapitalAccess\BorrowerContext;
use App\Services\Borrower\CapitalAccess\ScoreConfigurationCache;
use App\Models\Common\ScoreConfiguration;
use App\Services\Borrower\CapitalAccess\ApprovalProbabilityService;

class CapitalAccessOverviewController extends Controller
{
    /**
     * Get the capital access overview data for the authenticated borrower.
     */
    public function index(): JsonResponse
    {
        $borrower = auth()->user()->borrower;
        if (!$borrower) {
            return ApiResponse::notFound(__('borrower.not_found'));
        }

        return $this->getOverviewResponse($borrower);
    }

    private function getOverviewResponse($borrower): JsonResponse
    {
        // Build BorrowerContext once
        $ctx = BorrowerContext::from($borrower);
        $result = $ctx->readinessData;

        $score = $result['total_score'] ?? 0;
        $status = $result['score_label'] ?? 'N/A';

        // Calculate dynamic estimated access limit
        $strategies = CapitalAccess::active()->get();
        $totalExposureMax = 0;
        $exposureBreakdown = [];

        if ($strategies->isNotEmpty()) {
            $cache = app(ScoreConfigurationCache::class);
            $probTiers = $cache->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);
            $modifiers = $cache->getModifiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);
            $probabilityService = app(ApprovalProbabilityService::class);

            $bestProb = -1;
            $recommendedStrat = null;

            // Pre-load completed play IDs — one query, skip them in chart & estimated access
            $completedPlayIds = BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                ->where('status', BorrowerCapitalAccessPlay::STATUS_COMPLETED)
                ->pluck('capital_access_id')
                ->all();

            foreach ($strategies as $strat) {
                // Skip completed plays — they should not count toward access totals or chart
                if (in_array($strat->id, $completedPlayIds)) {
                    continue;
                }

                $probData = $probabilityService->calculate($borrower, $strat, $ctx, $probTiers, $modifiers, true);
                if (isset($probData['status']) && $probData['status'] === true) {
                    $exposureMax = (float) ($strat->target_exposure_max ?? 0);
                    $totalExposureMax += $exposureMax;

                    // Only non-completed, eligible strategies populate the chart
                    $exposureBreakdown[] = [
                        'name' => $strat->name,
                        'max_exposure' => $exposureMax,
                    ];

                    // Track the highest-probability non-completed strategy as recommended
                    $prob = $probData['probability'] ?? 0;
                    if ($prob > $bestProb) {
                        $bestProb = $prob;
                        $recommendedStrat = $strat;
                    }
                }
            }
        }

        $runningPlay = BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
            ->where('status', BorrowerCapitalAccessPlay::STATUS_RUNNING)
            ->first();

        $currentPlay = null;
        if ($runningPlay) {
            $strat = CapitalAccess::find($runningPlay->capital_access_id);
            if ($strat) {
                $currentPlay = [
                    'id'   => $strat->id,
                    'name' => $strat->name,
                ];
            }
        }

        $reasonCode = null;
        $unlockAt = null;

        if ($currentPlay === null) {
            $activePlayIds = $strategies->pluck('id')->all();
            if (empty($activePlayIds)) {
                $reasonCode = 'NO_ACTIVE_PLAYS';
            } else {
                $completedAll = true;
                foreach ($activePlayIds as $id) {
                    if (!in_array($id, $completedPlayIds)) {
                        $completedAll = false;
                        break;
                    }
                }

                if ($completedAll) {
                    $reasonCode = 'ALL_PLAYS_COMPLETED';
                } else {
                    if ($recommendedStrat && $probabilityService) {
                        $probDataDelay = $probabilityService->calculate($borrower, $recommendedStrat, $ctx, $probTiers, $modifiers, false);
                        if (isset($probDataDelay['reason_code']) && $probDataDelay['reason_code'] === 'NEXT_PLAY_DELAY_ACTIVE') {
                            $reasonCode = 'NEXT_PLAY_DELAY_ACTIVE';
                            $unlockAt = $probDataDelay['unlock_at'] ?? null;
                        } else {
                            $reasonCode = 'NO_ACTIVE_PLAY_CHOSEN';
                        }
                    } else {
                        // All remaining active plays are locked/disqualified
                        foreach ($strategies as $strat) {
                            if (in_array($strat->id, $completedPlayIds)) {
                                continue;
                            }
                            if ($probabilityService) {
                                $probData = $probabilityService->calculate($borrower, $strat, $ctx, $probTiers, $modifiers, true);
                                if (isset($probData['reason_code'])) {
                                    $reasonCode = $probData['reason_code'];
                                    break;
                                }
                            }
                        }
                        if (!$reasonCode) {
                            $reasonCode = 'REQUIREMENTS_NOT_MET';
                        }
                    }
                }
            }
        }

        $estimatedAccess = currencyFormat($totalExposureMax);

        $data = [
            'readiness_score'        => $score,
            'readiness_status'       => $status,
            'readiness_status_color' => $result['score_label_color'] ?? null,
            'factors'                => $result['factors'] ?? [],
            'estimated_total_access' => $estimatedAccess,
            // As the dashboard's top cards show amounts: $920K, $4.53M.
            'estimated_total_access_compact' => formatCompactCurrency($totalExposureMax),
            'exposure_breakdown'     => $exposureBreakdown,
            'current_play'           => $currentPlay,
            'reason_code'            => $reasonCode,
            'unlock_at'              => $unlockAt,
        ];

        return ApiResponse::success(
            $data,
            __('credit_builder.capital_access_overview_fetched')
        );
    }
}
