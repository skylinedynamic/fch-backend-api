<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\Common\ScoreConfiguration;
use App\Http\Resources\Borrower\CreditBuilder\CapitalAccessStrategyResource;
use Illuminate\Http\JsonResponse;
use App\Helpers\ApiResponse;

class CapitalAccessStrategyService
{
    /**
     * Get all active capital access strategies.
     *
     * Optimized: BorrowerContext and ScoreConfigurationCache are built once,
     * all probability + odds calculations share them (zero redundant DB queries).
     */
    public function list(): JsonResponse
    {
        $strategies = CapitalAccess::with('sequences.creditAccount')->active()->get();
        $borrower   = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache] = $this->resolveRecommended($strategies, $borrower);

        $resourceCollection = CapitalAccessStrategyResource::collection($strategies);

        $resourceCollection->collection->each(function ($resource) use ($recommendedId, $ctx, $cache) {
            $resource->additional([
                'recommended_id' => $recommendedId,
                'borrower_ctx'   => $ctx,
                'score_cache'    => $cache,
            ]);
        });

        return ApiResponse::success(
            $resourceCollection,
            __('credit_builder.capital_access_list_fetched')
        );
    }

    /**
     * Get a specific capital access strategy.
     *
     * Optimized: the recommended strategy is found in the same loop that
     * computes the probability for the requested strategy — no duplicate pass.
     */
    public function show(int $id): JsonResponse
    {
        $strategy   = CapitalAccess::with('sequences.creditAccount')->active()->findOrFail($id);
        $strategies = CapitalAccess::active()->get();
        $borrower   = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache] = $this->resolveRecommended($strategies, $borrower, $strategy);

        $resource = new CapitalAccessStrategyResource($strategy);
        $resource->additional([
            'recommended_id' => $recommendedId,
            'borrower_ctx'   => $ctx,
            'score_cache'    => $cache,
        ]);

        return ApiResponse::success(
            $resource,
            __('credit_builder.capital_access_fetched')
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Build BorrowerContext + ScoreConfigurationCache once, then compute
     * approval probability for every strategy in a single pass to find
     * the recommended one.
     *
     * @param \Illuminate\Support\Collection $strategies  All active strategies
     * @param Borrower|null                  $borrower
     * @param CapitalAccess|null             $targetStrategy  When set, attach prob_data to it too
     * @return array  [$recommendedId, $ctx|null, $cache|null]
     */
    private function resolveRecommended(
        $strategies,
        ?Borrower $borrower,
        ?CapitalAccess $targetStrategy = null
    ): array {
        $recommendedId = null;
        $ctx           = null;
        $cache         = null;

        if ($borrower && $strategies->isNotEmpty()) {
            // Build context once — fires ReadinessScoreCalculationService exactly once
            $ctx   = BorrowerContext::from($borrower);
            $cache = app(ScoreConfigurationCache::class);

            // Pre-load tiers for probability (zero additional DB queries)
            $probTiers = $cache->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);
            $modifiers = $cache->getModifiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);

            $probabilityService = app(ApprovalProbabilityService::class);
            $bestProb = -1;

            foreach ($strategies as $strat) {
                $probData = $probabilityService->calculate($borrower, $strat, $ctx, $probTiers, $modifiers);
                $prob     = $probData['probability'];

                // Attach to the requested single strategy (show() use-case)
                if ($targetStrategy && $strat->id === $targetStrategy->id) {
                    $targetStrategy->approval_probability_data = $probData;
                }

                // Attach to every strategy in the collection (list() use-case)
                $strat->approval_probability_data = $probData;

                if ($prob > $bestProb) {
                    $bestProb      = $prob;
                    $recommendedId = $strat->id;
                }
            }

            // Re-sort strategies by probability descending (list() use-case)
            $strategies = $strategies->sortByDesc(fn($s) => $s->approval_probability_data['probability'] ?? 0)->values();
        }

        if (!$recommendedId && $strategies->isNotEmpty()) {
            $recommendedId = $strategies->first()->id;
        }

        return [$recommendedId, $ctx, $cache];
    }
}
