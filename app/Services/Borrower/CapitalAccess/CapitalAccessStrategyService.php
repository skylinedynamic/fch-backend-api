<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\Common\ScoreConfiguration;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStrategyResource;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStrategySelectionResource;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStrategyOverviewResource;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStrategyChecklistResource;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStrategyRoadmapResource;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStepDetailResource;
use Illuminate\Http\JsonResponse;
use App\Helpers\ApiResponse;

class CapitalAccessStrategyService
{
    /**
     * Get a lightweight list of active strategies for selection.
     */
    public function listForSelection(): JsonResponse
    {
        $strategies = CapitalAccess::active()->get();
        $borrower   = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower);

        $resourceCollection = CapitalAccessStrategySelectionResource::collection($strategies);

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
     * Get all active capital access strategies.
     *
     * Optimized: BorrowerContext and ScoreConfigurationCache are built once,
     * all probability + odds calculations share them (zero redundant DB queries).
     */
    public function list(): JsonResponse
    {
        $strategies = CapitalAccess::with('sequences.creditAccount')->active()->get();
        $borrower   = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower);

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

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower, $strategy);

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

    /**
     * Get a lightweight overview of a specific capital access strategy.
     */
    public function showOverview(int $id): JsonResponse
    {
        $strategy   = CapitalAccess::active()->findOrFail($id);
        $strategies = CapitalAccess::active()->get();
        $borrower   = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower, $strategy);

        if ($borrower) {
            $probData = $strategy->approval_probability_data;
            if (!$probData) {
                $probData = app(ApprovalProbabilityService::class)
                    ->calculate($borrower, $strategy, $ctx);
            }

            if (isset($probData['status']) && $probData['status'] === false) {
                return ApiResponse::success([
                    'id'              => $strategy->id,
                    'name'            => $strategy->name,
                    'approval_status' => false,
                    'reason_code'     => $probData['reason_code'] ?? null,
                ], 'Capital Access Strategy is locked');
            }
        }

        $resource = new CapitalAccessStrategyOverviewResource($strategy);
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

    /**
     * Get a lightweight pre-flight checklist of a specific capital access strategy.
     */
    public function showChecklist(int $id): JsonResponse
    {
        $strategy   = CapitalAccess::active()->findOrFail($id);
        $strategies = CapitalAccess::active()->get();
        $borrower   = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower, $strategy);

        if ($borrower) {
            $probData = $strategy->approval_probability_data;
            if (!$probData) {
                $probData = app(ApprovalProbabilityService::class)
                    ->calculate($borrower, $strategy, $ctx);
            }

            if (isset($probData['status']) && $probData['status'] === false) {
                return ApiResponse::success([
                    'id'              => $strategy->id,
                    'name'            => $strategy->name,
                    'approval_status' => false,
                    'reason_code'     => $probData['reason_code'] ?? null,
                ], 'Capital Access Strategy is locked');
            }
        }

        if ($borrower) {
            $this->ensurePlayStatusRunning($borrower, $id);
        }

        $resource = new CapitalAccessStrategyChecklistResource($strategy);
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

    /**
     * Get the roadmap (sorted sequences + per-card probability) of a strategy.
     */
    public function showRoadmap(int $id): JsonResponse
    {
        $strategy   = CapitalAccess::with('sequences.creditAccount')->active()->findOrFail($id);
        $strategies = CapitalAccess::active()->get();
        $borrower   = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower, $strategy);

        // Lock gate — if the overall strategy is not eligible, return locked wrapper
        if ($borrower) {
            $probData = $strategy->approval_probability_data;
            if (!$probData) {
                $probData = app(ApprovalProbabilityService::class)
                    ->calculate($borrower, $strategy, $ctx);
            }

            if (isset($probData['status']) && $probData['status'] === false) {
                return ApiResponse::success([
                    'id'              => $strategy->id,
                    'name'            => $strategy->name,
                    'approval_status' => false,
                    'reason_code'     => $probData['reason_code'] ?? null,
                ], 'Capital Access Strategy is locked');
            }
        }

        if ($borrower) {
            $this->ensurePlayStatusRunning($borrower, $id);
        }

        $resource = new CapitalAccessStrategyRoadmapResource($strategy);
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
    // Step Detail
    // =========================================================================

    /**
     * Get detail for a single roadmap step (sequence) including per-card odds.
     */
    public function showStep(int $strategyId, int $sequenceId): JsonResponse
    {
        $strategy = CapitalAccess::active()->findOrFail($strategyId);
        $borrower = auth()->user()?->borrower;

        // Verify the sequence belongs to this strategy
        $seq = $strategy->sequences()->with('creditAccount', 'capitalAccess')->findOrFail($sequenceId);

        // Build context for odds calculation
        $ctx   = null;
        $cache = null;
        if ($borrower) {
            $ctx   = BorrowerContext::from($borrower);
            $cache = app(ScoreConfigurationCache::class);
        }

        if ($borrower) {
            $this->ensurePlayStatusRunning($borrower, $strategyId);
        }

        $resource = new CapitalAccessStepDetailResource($seq);
        $resource->additional([
            'borrower_ctx' => $ctx,
            'score_cache'  => $cache,
        ]);

        return ApiResponse::success(
            $resource,
            __('credit_builder.capital_access_fetched')
        );
    }

    /**
     * Submit application/log result for a single roadmap step (sequence).
     */
    public function submitStep(int $strategyId, int $sequenceId, array $data): JsonResponse
    {
        $strategy = CapitalAccess::active()->findOrFail($strategyId);
        $borrower = auth()->user()?->borrower;
        if (!$borrower) {
            return ApiResponse::error('Unauthorized', 401);
        }

        // Verify the sequence belongs to this strategy
        $seq = $strategy->sequences()->findOrFail($sequenceId);

        // Save application data
        $application = \App\Models\CreditBuilder\CapitalAccessApplication::updateOrCreate(
            [
                'borrower_id'                => $borrower->id,
                'capital_access_sequence_id' => $seq->id,
            ],
            [
                'capital_access_id' => $strategy->id,
                'credit_limit'      => $data['credit_limit'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'status'            => $data['status'] ?? \App\Models\CreditBuilder\CapitalAccessApplication::STATUS_PENDING,
            ]
        );

        // --- Maintain separate status in borrower_capital_access_plays ---
        $totalSteps = $strategy->sequences()->count();
        $approvedSteps = \App\Models\CreditBuilder\CapitalAccessApplication::where('borrower_id', $borrower->id)
            ->where('capital_access_id', $strategy->id)
            ->where('status', \App\Models\CreditBuilder\CapitalAccessApplication::STATUS_APPROVED)
            ->count();

        $status = ($totalSteps > 0 && $approvedSteps === $totalSteps)
            ? \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::STATUS_COMPLETED
            : \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::STATUS_RUNNING;

        \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::updateOrCreate(
            [
                'borrower_id'       => $borrower->id,
                'capital_access_id' => $strategy->id,
            ],
            [
                'status'            => $status,
            ]
        );

        return ApiResponse::success(
            $application,
            'Application submitted successfully'
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
     * @return array  [$recommendedId, $ctx|null, $cache|null, $strategies]
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
            $filteredStrategies = collect();

            foreach ($strategies as $strat) {
                $probData = $probabilityService->calculate($borrower, $strat, $ctx, $probTiers, $modifiers);

                // Only include unlocked strategies (status is true)
                if (isset($probData['status']) && $probData['status'] === true) {
                    $strat->approval_probability_data = $probData;

                    if ($targetStrategy && $strat->id === $targetStrategy->id) {
                        $targetStrategy->approval_probability_data = $probData;
                    }

                    // --- CHECK IF COMPLETED (Check separate status table with fallback sync) ---
                    $playStatus = \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                        ->where('capital_access_id', $strat->id)
                        ->first();

                    if ($playStatus) {
                        $isCompleted = ($playStatus->status === \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::STATUS_COMPLETED);
                    } else {
                        // Fallback: check dynamic counts (for legacy data sync)
                        $totalSteps = $strat->sequences()->count();
                        $approvedSteps = \App\Models\CreditBuilder\CapitalAccessApplication::where('borrower_id', $borrower->id)
                            ->where('capital_access_id', $strat->id)
                            ->where('status', \App\Models\CreditBuilder\CapitalAccessApplication::STATUS_APPROVED)
                            ->count();
                        
                        $isCompleted = ($totalSteps > 0 && $approvedSteps === $totalSteps);

                        // If they have worked on this strategy, create play status row
                        $hasStarted = \App\Models\CreditBuilder\CapitalAccessApplication::where('borrower_id', $borrower->id)
                            ->where('capital_access_id', $strat->id)
                            ->exists();

                        if ($hasStarted) {
                            \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::create([
                                'borrower_id'       => $borrower->id,
                                'capital_access_id' => $strat->id,
                                'status'            => $isCompleted ? \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::STATUS_COMPLETED : \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::STATUS_RUNNING,
                            ]);
                        }
                    }
                    $strat->is_completed = $isCompleted;

                    $filteredStrategies->push($strat);

                    // Only recommend if it is NOT completed
                    if (!$isCompleted) {
                        $prob = $probData['probability'];
                        if ($prob > $bestProb) {
                            $bestProb      = $prob;
                            $recommendedId = $strat->id;
                        }
                    }
                }
            }

            // Re-sort strategies by probability descending (list() use-case)
            $strategies = $filteredStrategies->sortByDesc(fn($s) => $s->approval_probability_data['probability'] ?? 0)->values();
        }

        // If no recommended ID is selected (e.g. all are completed), default to the first uncompleted strategy
        if (!$recommendedId && $strategies->isNotEmpty()) {
            $uncompleted = $strategies->first(fn($s) => !($s->is_completed ?? false));
            $recommendedId = $uncompleted ? $uncompleted->id : $strategies->first()->id;
        }

        return [$recommendedId, $ctx, $cache, $strategies];
    }

    /**
     * Ensure a capital access play status is registered as 'running' for the borrower.
     */
    private function ensurePlayStatusRunning(\App\Models\Borrower\Borrower $borrower, int $strategyId): void
    {
        \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::firstOrCreate(
            [
                'borrower_id'       => $borrower->id,
                'capital_access_id' => $strategyId,
            ],
            [
                'status'            => \App\Models\CreditBuilder\BorrowerCapitalAccessPlay::STATUS_RUNNING,
            ]
        );
    }
}

