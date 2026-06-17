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
        $strategies = CapitalAccess::with('creditAccounts')->where('status', CapitalAccess::STATUS_ACTIVE)->get();

        $recommended = $strategies->first();
        $recommendedId = $recommended ? $recommended->id : null;

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
            ->where('status', CapitalAccess::STATUS_ACTIVE)
            ->findOrFail($id);

        // Fetch all active strategies to find the recommended one
        $strategies = CapitalAccess::where('status', CapitalAccess::STATUS_ACTIVE)->get();
        $recommended = $strategies->first();
        $recommendedId = $recommended ? $recommended->id : null;

        $resource = new CapitalAccessStrategyResource($strategy);
        $resource->additional(['recommended_id' => $recommendedId]);

        return ApiResponse::success(
            $resource,
            __('credit_builder.capital_access_fetched')
        );
    }
}
