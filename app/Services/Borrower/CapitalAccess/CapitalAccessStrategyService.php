<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\Borrower\Borrower;
use App\Models\CreditBuilder\CapitalAccess;
use App\Models\CreditBuilder\CapitalAccessApplication;
use App\Models\CreditBuilder\BorrowerCapitalAccessPlay;
use App\Models\Common\ScoreConfiguration;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStrategyResource;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStrategySelectionResource;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStrategyOverviewResource;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStrategyChecklistResource;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStrategyRoadmapResource;
use App\Http\Resources\Borrower\CapitalAccess\CapitalAccessStepDetailResource;
use App\Models\CreditBuilder\CapitalAccessSequence;
use Illuminate\Http\JsonResponse;
use App\Helpers\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use App\Http\Resources\Borrower\CapitalAccess\HasEvaluatedChecklist;

class CapitalAccessStrategyService
{
    use HasEvaluatedChecklist;

    /**
     * Get a lightweight list of active strategies for selection.
     */
    public function listForSelection(): JsonResponse
    {
        $strategies = CapitalAccess::active()->get();
        $borrower = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower);

        $resourceCollection = CapitalAccessStrategySelectionResource::collection($strategies);

        $resourceCollection->collection->each(function ($resource) use ($recommendedId, $ctx, $cache) {
            $resource->additional([
                'recommended_id' => $recommendedId,
                'borrower_ctx' => $ctx,
                'score_cache' => $cache,
            ]);
        });

        return ApiResponse::success(
            $resourceCollection,
            __('credit_builder.capital_access_list_fetched')
        );
    }

    /**
     * Get all active capital access strategies for a specific borrower.
     */
    public function listForBorrower(Borrower $borrower): JsonResponse
    {
        $strategies = CapitalAccess::with('sequences.creditAccount')->active()->get();

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower);

        $resourceCollection = CapitalAccessStrategyResource::collection($strategies);

        $resourceCollection->collection->each(function ($resource) use ($recommendedId, $ctx, $cache) {
            $resource->additional([
                'recommended_id' => $recommendedId,
                'borrower_ctx' => $ctx,
                'score_cache' => $cache,
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
        $borrower = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower);

        $resourceCollection = CapitalAccessStrategyResource::collection($strategies);

        $resourceCollection->collection->each(function ($resource) use ($recommendedId, $ctx, $cache) {
            $resource->additional([
                'recommended_id' => $recommendedId,
                'borrower_ctx' => $ctx,
                'score_cache' => $cache,
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
        $strategy = CapitalAccess::with('sequences.creditAccount')->active()->findOrFail($id);
        $strategies = CapitalAccess::active()->get();
        $borrower = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower, $strategy);

        $resource = new CapitalAccessStrategyResource($strategy);
        $resource->additional([
            'recommended_id' => $recommendedId,
            'borrower_ctx' => $ctx,
            'score_cache' => $cache,
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
        $strategy = CapitalAccess::active()->findOrFail($id);
        $strategies = CapitalAccess::active()->get();
        $borrower = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower, $strategy);

        if ($borrower) {
            $probData = $strategy->approval_probability_data;
            if (!$probData) {
                $probData = app(ApprovalProbabilityService::class)
                    ->calculate($borrower, $strategy, $ctx);
            }

            if (isset($probData['status']) && $probData['status'] === false) {
                return ApiResponse::success([
                    'id' => $strategy->id,
                    'name' => $strategy->name,
                    'approval_status' => false,
                    'isAccessible' => false,
                    'reason_code' => $probData['reason_code'] ?? null,
                    'unlock_at' => $probData['unlock_at'] ?? null,
                ], 'Capital Access Strategy is locked');
            }
        }

        $resource = new CapitalAccessStrategyOverviewResource($strategy);
        $resource->additional([
            'recommended_id' => $recommendedId,
            'borrower_ctx' => $ctx,
            'score_cache' => $cache,
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
        $strategy = CapitalAccess::active()->findOrFail($id);
        $strategies = CapitalAccess::active()->get();
        $borrower = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower, $strategy);

        if ($borrower) {
            $probData = $strategy->approval_probability_data;
            if (!$probData) {
                $probData = app(ApprovalProbabilityService::class)
                    ->calculate($borrower, $strategy, $ctx);
            }

            if (isset($probData['status']) && $probData['status'] === false) {
                return ApiResponse::success([
                    'id' => $strategy->id,
                    'name' => $strategy->name,
                    'approval_status' => false,
                    'isAccessible' => false,
                    'reason_code' => $probData['reason_code'] ?? null,
                    'unlock_at' => $probData['unlock_at'] ?? null,
                ], 'Capital Access Strategy is locked');
            }
        }

        $resource = new CapitalAccessStrategyChecklistResource($strategy);
        $resource->additional([
            'recommended_id' => $recommendedId,
            'borrower_ctx' => $ctx,
            'score_cache' => $cache,
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
        $strategy = CapitalAccess::with('sequences.creditAccount')->active()->findOrFail($id);
        $strategies = CapitalAccess::active()->get();
        $borrower = auth()->user()?->borrower;

        [$recommendedId, $ctx, $cache, $strategies] = $this->resolveRecommended($strategies, $borrower, $strategy);

        // Lock gate — if the overall strategy is not eligible, return locked wrapper
        $isDelayActive = false;
        if ($borrower) {
            // Check pre-flight checklist completion
            $evaluatedChecklist = $this->getEvaluatedChecklist($borrower, $strategy->pre_flight_checklist);
            $allComplete = true;
            foreach ($evaluatedChecklist as $item) {
                if ($item['complete'] === false) {
                    $allComplete = false;
                    break;
                }
            }
            if (!$allComplete) {
                return ApiResponse::error('Pre-flight checklist is not completed.', 403);
            }

            $probData = $strategy->approval_probability_data;
            if (!$probData) {
                $probData = app(ApprovalProbabilityService::class)
                    ->calculate($borrower, $strategy, $ctx);
            }

            if (isset($probData['reason_code']) && $probData['reason_code'] === 'NEXT_PLAY_DELAY_ACTIVE') {
                $isDelayActive = true;
            }

            if (isset($probData['status']) && $probData['status'] === false) {
                return ApiResponse::success([
                    'id' => $strategy->id,
                    'name' => $strategy->name,
                    'approval_status' => false,
                    'isAccessible' => false,
                    'reason_code' => $probData['reason_code'] ?? null,
                ], 'Capital Access Strategy is locked');
            }
        }

        $resource = new CapitalAccessStrategyRoadmapResource($strategy);
        $resource->additional([
            'recommended_id' => $recommendedId,
            'borrower_ctx' => $ctx,
            'score_cache' => $cache,
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

        $probData = null;
        $recommendedId = null;
        $ctx = null;
        $cache = null;

        if ($borrower) {
            // Resolve recommended ID once to know if delay applies to this strategy
            $allStrategies = CapitalAccess::active()->get();
            [$recommendedId, $ctx, $cache] = $this->resolveRecommended($allStrategies, $borrower, $strategy);
            $isRecommended = ($strategyId === $recommendedId);

            // Pass pre-loaded cache data to avoid redundant DB queries
            $delayDays = $isRecommended ? ($cache ? $cache->getNextPlayDelayDays() : -1) : 0;
            $probData = app(ApprovalProbabilityService::class)->calculate(
                $borrower,
                $strategy,
                $ctx,
                null,
                null,
                !$isRecommended,
                $delayDays
            );
            if (isset($probData['status']) && $probData['status'] === false) {
                return ApiResponse::error('This strategy is currently locked.', 403);
            }
        }

        // Verify the sequence belongs to this strategy
        try {
            $seq = $strategy->sequences()->with('creditAccount', 'capitalAccess')->findOrFail($sequenceId);
        } catch (ModelNotFoundException) {
            return ApiResponse::error('This step does not exist for the selected strategy.', 403);
        }

        if ($borrower) {
            $accessError = $this->validateStepAccess($strategy, $seq, $borrower, $ctx, $cache);
            if ($accessError) {
                return $accessError;
            }

            $playStatus = BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                ->where('capital_access_id', $strategyId)
                ->first();
            if ($playStatus && $playStatus->status === BorrowerCapitalAccessPlay::STATUS_COMPLETED) {
                $hasApplication = CapitalAccessApplication::where('borrower_id', $borrower->id)
                    ->where('capital_access_sequence_id', $sequenceId)
                    ->exists();
                if (!$hasApplication) {
                    return ApiResponse::error('This step is not available for this completed play.', 403);
                }
            }
        }

        $resource = new CapitalAccessStepDetailResource($seq);
        $resource->additional([
            'recommended_id' => $recommendedId ?? null,
            'is_completed' => $strategy->is_completed ?? false,
            'reason_code' => $probData['reason_code'] ?? null,
            'unlock_at' => $probData['unlock_at'] ?? null,
            'borrower_ctx' => $ctx,
            'score_cache' => $cache,
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

        if ($borrower) {
            $allStrategies = CapitalAccess::active()->get();
            [$recommendedId, $ctx, $cache] = $this->resolveRecommended($allStrategies, $borrower, $strategy);
            $isRecommended = ($strategyId === $recommendedId);

            $delayDays = $isRecommended ? ($cache ? $cache->getNextPlayDelayDays() : -1) : 0;
            $probData = app(ApprovalProbabilityService::class)->calculate(
                $borrower,
                $strategy,
                $ctx,
                null,
                null,
                !$isRecommended,
                $delayDays
            );
            if (isset($probData['status']) && $probData['status'] === false) {
                return ApiResponse::error('This strategy is currently locked.', 403);
            }
        }

        // Verify the sequence belongs to this strategy
        try {
            $seq = $strategy->sequences()->findOrFail($sequenceId);
        } catch (ModelNotFoundException) {
            return ApiResponse::error('This step does not exist for the selected strategy.', 403);
        }

        if ($borrower) {
            $playStatus = BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                ->where('capital_access_id', $strategyId)
                ->first();

            $accessError = $this->validateStepAccess($strategy, $seq, $borrower, $ctx, $cache);
            if ($accessError) {
                return $accessError;
            }

            if ($playStatus && $playStatus->status === BorrowerCapitalAccessPlay::STATUS_COMPLETED) {
                $hasApplication = CapitalAccessApplication::where('borrower_id', $borrower->id)
                    ->where('capital_access_sequence_id', $sequenceId)
                    ->exists();
                if (!$hasApplication) {
                    return ApiResponse::error('Cannot submit step for a completed play.', 403);
                }
            }
        }

        // Save application data
        $application = CapitalAccessApplication::updateOrCreate(
            [
                'borrower_id' => $borrower->id,
                'capital_access_sequence_id' => $seq->id,
            ],
            [
                'capital_access_id' => $strategy->id,
                'credit_limit' => $data['credit_limit'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'] ?? CapitalAccessApplication::STATUS_PENDING,
            ]
        );

        // --- Maintain separate status in borrower_capital_access_plays ---
        $totalSteps = $strategy->sequences()->count();
        $approvedSteps = CapitalAccessApplication::where('borrower_id', $borrower->id)
            ->where('capital_access_id', $strategy->id)
            ->where('status', CapitalAccessApplication::STATUS_APPROVED)
            ->count();

        $status = ($totalSteps > 0 && $approvedSteps === $totalSteps)
            ? BorrowerCapitalAccessPlay::STATUS_COMPLETED
            : BorrowerCapitalAccessPlay::STATUS_RUNNING;

        BorrowerCapitalAccessPlay::updateOrCreate(
            [
                'borrower_id' => $borrower->id,
                'capital_access_id' => $strategy->id,
            ],
            [
                'status' => $status,
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
        $ctx = null;
        $cache = null;

        if ($borrower && $strategies->isNotEmpty()) {
            // Build context once — fires ReadinessScoreCalculationService exactly once
            $ctx = BorrowerContext::from($borrower);
            $cache = app(ScoreConfigurationCache::class);

            // Pre-load tiers for probability (zero additional DB queries)
            $probTiers = $cache->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);
            $modifiers = $cache->getModifiersFor(ScoreConfiguration::MODEL_APPROVAL_PROBABILITY);

            $probabilityService = app(ApprovalProbabilityService::class);
            $bestProb = -1;
            $candidateRecommendedId = null;
            $completionMap = [];

            // Pre-load delay context once — shared across all calculate() calls
            $delayDays = $cache->getNextPlayDelayDays();
            $latestCompletedPlay = $delayDays > 0
                ? BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                    ->where('status', BorrowerCapitalAccessPlay::STATUS_COMPLETED)
                    ->orderBy('updated_at', 'desc')
                    ->first()
                : null;

            // 1. First pass: Find recommended play ID without delay check
            foreach ($strategies as $strat) {
                $probData = $probabilityService->calculate($borrower, $strat, $ctx, $probTiers, $modifiers, true, $delayDays, $latestCompletedPlay);
                $isCompleted = false;

                if (isset($probData['status']) && $probData['status'] === true) {
                    $playStatus = BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
                        ->where('capital_access_id', $strat->id)
                        ->first();

                    if ($playStatus) {
                        $isCompleted = ($playStatus->status === BorrowerCapitalAccessPlay::STATUS_COMPLETED);
                    } else {
                        // No play row: worked out from the step counts, without
                        // writing one. This is a read - rows missing from before
                        // plays were tracked are backfilled by the migration
                        // 2026_10_12_000100_backfill_borrower_capital_access_plays,
                        // and new ones are written when a step is submitted.
                        $totalSteps = $strat->sequences()->count();
                        $approvedSteps = CapitalAccessApplication::where('borrower_id', $borrower->id)
                            ->where('capital_access_id', $strat->id)
                            ->where('status', CapitalAccessApplication::STATUS_APPROVED)
                            ->count();

                        $isCompleted = ($totalSteps > 0 && $approvedSteps === $totalSteps);
                    }

                    $completionMap[$strat->id] = $isCompleted;

                    // Only recommend if it is NOT completed
                    if (!$isCompleted) {
                        $prob = $probData['probability'];
                        if ($prob > $bestProb) {
                            $bestProb = $prob;
                            $candidateRecommendedId = $strat->id;
                        }
                    }
                }
            }

            if (!$candidateRecommendedId && $strategies->isNotEmpty()) {
                $uncompleted = $strategies->first(fn($s) => !($completionMap[$s->id] ?? false));
                $candidateRecommendedId = $uncompleted ? $uncompleted->id : $strategies->first()->id;
            }

            $recommendedId = $candidateRecommendedId;
            $filteredStrategies = collect();

            // 2. Second pass: Calculate final probability and lock status (apply delay check only to recommendation)
            foreach ($strategies as $strat) {
                $isRecommended = ($strat->id === $recommendedId);
                $skipDelay = !$isRecommended;

                // Pass pre-loaded delay data — zero extra DB queries per strategy
                $probData = $probabilityService->calculate($borrower, $strat, $ctx, $probTiers, $modifiers, $skipDelay, $delayDays, $latestCompletedPlay);

                $isAllowed = (isset($probData['status']) && $probData['status'] === true) || ($probData['reason_code'] ?? null) === 'NEXT_PLAY_DELAY_ACTIVE';

                if ($isAllowed) {
                    $strat->approval_probability_data = $probData;

                    if ($targetStrategy && $strat->id === $targetStrategy->id) {
                        $targetStrategy->approval_probability_data = $probData;
                    }

                    $isCompleted = $completionMap[$strat->id] ?? false;
                    $strat->is_completed = $isCompleted;

                    if ($targetStrategy && $strat->id === $targetStrategy->id) {
                        $targetStrategy->is_completed = $isCompleted;
                    }

                    $filteredStrategies->push($strat);
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
     * Start/Initialize a capital access play status as running.
     */
    public function startPlay(int $id): JsonResponse
    {
        $borrower = auth()->user()?->borrower;
        if (!$borrower) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $strategy = CapitalAccess::active()->findOrFail($id);

        $this->ensurePlayStatusRunning($borrower, $id);

        return ApiResponse::success(null, 'Capital Access Play started successfully');
    }

    /**
     * Ensure a capital access play status is registered as 'running' for the borrower.
     */
    private function ensurePlayStatusRunning(Borrower $borrower, int $strategyId): void
    {
        BorrowerCapitalAccessPlay::firstOrCreate(
            [
                'borrower_id' => $borrower->id,
                'capital_access_id' => $strategyId,
            ],
            [
                'status' => BorrowerCapitalAccessPlay::STATUS_RUNNING,
            ]
        );
    }

    /**
     * Validate if the given sequence/step is unlocked and accessible for the borrower.
     */
    protected function validateStepAccess(CapitalAccess $strategy, CapitalAccessSequence $seq, Borrower $borrower, $ctx, $cache): ?JsonResponse
    {
        // 1. Check pre-flight checklist completion
        $evaluatedChecklist = $this->getEvaluatedChecklist($borrower, $strategy->pre_flight_checklist);
        $allComplete = true;
        foreach ($evaluatedChecklist as $item) {
            if ($item['complete'] === false) {
                $allComplete = false;
                break;
            }
        }
        if (!$allComplete) {
            return ApiResponse::error('Pre-flight checklist is not completed.', 403);
        }

        // 2. Check preceding steps completion (all previous steps must be approved)
        $sequences = $strategy->sequences()->get();
        $timeOrder = [
            CapitalAccess::ACCESS_TIME_MORNING => 0,
            CapitalAccess::ACCESS_TIME_EVENING => 1,
            CapitalAccess::ACCESS_TIME_ANYTIME => 2,
            CapitalAccess::ACCESS_TIME_DAY     => 3,
        ];
        $dayTimeOrder = [
            CapitalAccess::ACCESS_TIME_MORNING => 0,
            CapitalAccess::ACCESS_TIME_EVENING => 1,
            CapitalAccess::ACCESS_TIME_ANYTIME => 2,
        ];

        $sequences = $sequences->sortBy(function ($s) use ($timeOrder, $dayTimeOrder) {
            $group   = $timeOrder[$s->access_time] ?? 99;
            $day     = $s->access_day ?? 0;
            $subTime = $dayTimeOrder[$s->access_day_time] ?? 99;
            return [$group, $day, $subTime];
        })->values();

        $applications = CapitalAccessApplication::where('borrower_id', $borrower->id)
            ->where('capital_access_id', $strategy->id)
            ->get()
            ->keyBy('capital_access_sequence_id');

        foreach ($sequences as $s) {
            if ($s->id === $seq->id) {
                break;
            }
            $app = $applications->get($s->id);
            if (!$app || $app->status !== CapitalAccessApplication::STATUS_APPROVED) {
                return ApiResponse::error('This step is locked because a previous step is not completed.', 403);
            }
        }

        // 3. Check sequence qualifications (e.g. FICO / utilization requirements)
        $existingApp = CapitalAccessApplication::where('borrower_id', $borrower->id)
            ->where('capital_access_sequence_id', $seq->id)
            ->first();

        // 4. The roadmap's schedule (Day N delay, time-of-day window). The portal
        // shows these as locked; without this a direct POST got through (E2E-016).
        if (!$existingApp) {
            $timeLock = $this->stepTimeLockReason($strategy, $seq, $borrower, $sequences);
            if ($timeLock) {
                return ApiResponse::error($timeLock, 403);
            }
        }

        if (!$existingApp) {
            $oddsTiers = $cache?->getTiersFor(ScoreConfiguration::MODEL_APPROVAL_ODDS);
            $oddsData  = app(\App\Services\Borrower\CapitalAccess\ApprovalOddsService::class)
                ->calculate($borrower, $seq, $strategy, $ctx, $oddsTiers);
            $oddsStatus = ($oddsData['status'] ?? true) !== false;
            if (!$oddsStatus) {
                return ApiResponse::error('This step is locked because you do not meet the qualifications.', 403);
            }
        }

        return null;
    }

    /**
     * Why a step is locked by the clock, using the same inputs the roadmap
     * resource gives the portal: play start (play row, else earliest
     * application), the time-setting windows, and the borrower's timezone.
     */
    protected function stepTimeLockReason(CapitalAccess $strategy, CapitalAccessSequence $seq, Borrower $borrower, $sortedSequences): ?string
    {
        $timezone = request()->header('x-timezone') ?: config('app.timezone', 'UTC');
        try {
            $now = \Carbon\CarbonImmutable::now($timezone);
        } catch (\Throwable) {
            $now = \Carbon\CarbonImmutable::now(config('app.timezone', 'UTC'));
        }

        $startedAt = BorrowerCapitalAccessPlay::where('borrower_id', $borrower->id)
            ->where('capital_access_id', $strategy->id)
            ->value('created_at')
            ?? CapitalAccessApplication::where('borrower_id', $borrower->id)
                ->where('capital_access_id', $strategy->id)
                ->min('created_at');

        $windows = ScoreConfiguration::where('category', ScoreConfiguration::CATEGORY_TIME)
            ->whereIn('model_slug', [ScoreConfiguration::MODEL_APPROVAL_ODDS, ScoreConfiguration::MODEL_GENERAL_SETTING])
            ->where('factor_slug', ScoreConfiguration::FACTOR_TIME_SETTING)
            ->get()
            ->mapWithKeys(function ($config) {
                $conditions = $config->conditions;
                $start = $conditions['start'] ?? $config->min_value ?? 0;
                $end = $conditions['end'] ?? $config->max_value ?? 24;
                return [$config->label => [(float) $start, (float) $end]];
            })
            ->all();

        return StepTimeLock::reason(
            $seq->access_time,
            $seq->access_day !== null ? (int) $seq->access_day : null,
            $seq->access_day_time,
            optional($sortedSequences->first())->id === $seq->id,
            $startedAt ? \Carbon\CarbonImmutable::parse($startedAt) : null,
            $now,
            $windows
        );
    }
}

