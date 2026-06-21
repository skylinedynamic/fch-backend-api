<?php

namespace App\Services\Borrower\CapitalAccess;

use App\Models\CreditBuilder\CapitalAccess;
use App\Http\Resources\Borrower\CreditBuilder\CapitalAccessStrategyResource;
use Illuminate\Http\JsonResponse;
use App\Helpers\ApiResponse;

class CapitalAccessStrategyService
{
    /**
     * Get all active capital access strategies.
     */
    public function list(): JsonResponse
    {
        $strategies = CapitalAccess::with('creditAccounts')->active()->get();
        $borrower = auth()->user()?->borrower;
        $recommendedId = null;

        if ($borrower && $strategies->isNotEmpty()) {
            $probabilityService = app(ApprovalProbabilityService::class);
            $bestPlay = null;
            $bestProb = -1;

            foreach ($strategies as $strategy) {
                $probData = $probabilityService->calculate($borrower, $strategy);
                $prob = $probData['probability'];
                if ($prob > $bestProb) {
                    $bestProb = $prob;
                    $bestPlay = $strategy;
                }
            }
            if ($bestPlay) {
                $recommendedId = $bestPlay->id;
            }
        }

        if (!$recommendedId && $strategies->isNotEmpty()) {
            $recommendedId = $strategies->first()->id;
        }

        $resourceCollection = CapitalAccessStrategyResource::collection($strategies);

        // Inject the recommended_id additional data to each resource
        $resourceCollection->collection->each(function ($resource) use ($recommendedId) {
            $resource->additional(['recommended_id' => $recommendedId]);
        });

        return ApiResponse::success(
            $resourceCollection,
            __('credit_builder.capital_access_list_fetched')
        );
    }

    /**
     * Get a specific capital access strategy.
     */
    public function show(int $id): JsonResponse
    {
        $strategy = CapitalAccess::with('creditAccounts')
            ->active()
            ->findOrFail($id);

        // Fetch all active strategies to find the recommended one dynamically
        $strategies = CapitalAccess::active()->get();
        $borrower = auth()->user()?->borrower;
        $recommendedId = null;

        if ($borrower && $strategies->isNotEmpty()) {
            $probabilityService = app(ApprovalProbabilityService::class);
            $bestPlay = null;
            $bestProb = -1;

            foreach ($strategies as $strat) {
                $probData = $probabilityService->calculate($borrower, $strat);
                $prob = $probData['probability'];
                if ($prob > $bestProb) {
                    $bestProb = $prob;
                    $bestPlay = $strat;
                }
            }
            if ($bestPlay) {
                $recommendedId = $bestPlay->id;
            }
        }

        if (!$recommendedId && $strategies->isNotEmpty()) {
            $recommendedId = $strategies->first()->id;
        }

        $resource = new CapitalAccessStrategyResource($strategy);
        $resource->additional(['recommended_id' => $recommendedId]);

        return ApiResponse::success(
            $resource,
            __('credit_builder.capital_access_fetched')
        );
    }
}
