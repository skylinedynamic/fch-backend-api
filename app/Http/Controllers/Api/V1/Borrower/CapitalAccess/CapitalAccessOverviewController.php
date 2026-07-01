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

                $probData = $probabilityService->calculate($borrower, $strat, $ctx, $probTiers, $modifiers);
                if (isset($probData['status']) && $probData['status'] === true) {
                    // Only non-completed, eligible strategies populate the chart
                    $exposureBreakdown[] = [
                        'name' => $strat->name,
                        'max_exposure' => (float) ($strat->target_exposure_max ?? 0),
                    ];

                    // Track the highest-probability non-completed strategy as recommended
                    $prob = $probData['probability'] ?? 0;
                    if ($prob > $bestProb) {
                        $bestProb = $prob;
                        $recommendedStrat = $strat;
                    }
                }
            }

            // Estimated Total Access = recommended non-completed strategy only
            if ($recommendedStrat) {
                $totalExposureMax = (float) ($recommendedStrat->target_exposure_max ?? 0);
            }
        }

        $latestApp = CapitalAccessApplication::where('borrower_id', $borrower->id)
            ->orderBy('updated_at', 'desc')
            ->first();

        $currentPlay = null;
        if ($latestApp) {
            $strat = CapitalAccess::find($latestApp->capital_access_id);
            if ($strat) {
                $playStatus = BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                    ->where('capital_access_id', $strat->id)
                    ->first();

                if ($playStatus) {
                    $isCompleted = ($playStatus->status === BorrowerCapitalAccessPlay::STATUS_COMPLETED);
                } else {
                    $totalSteps = $strat->sequences()->count();
                    $approvedSteps = CapitalAccessApplication::where('borrower_id', $borrower->id)
                        ->where('capital_access_id', $strat->id)
                        ->where('status', CapitalAccessApplication::STATUS_APPROVED)
                        ->count();

                    $isCompleted = ($totalSteps > 0 && $approvedSteps === $totalSteps);
                }

                if (!$isCompleted) {
                    $currentPlay = [
                        'id' => $strat->id,
                        'name' => $strat->name,
                    ];
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
            'exposure_breakdown'     => $exposureBreakdown,
            'current_play'           => $currentPlay,
        ];

        return ApiResponse::success(
            $data,
            __('credit_builder.capital_access_overview_fetched')
        );
    }
}
