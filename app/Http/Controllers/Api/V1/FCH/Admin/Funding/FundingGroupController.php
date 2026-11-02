<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Funding;

use App\Exceptions\FundingGroupInUseException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\StoreFundingGroupRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\UpdateFundingGroupRequest;
use App\Models\Funding\FundingGroup;
use App\Services\FCH\Admin\Funding\FundingSequencingService;
use Illuminate\Http\JsonResponse;

class FundingGroupController extends Controller
{
    public function index(FundingSequencingService $service): JsonResponse
    {
        // Each with funding_sequences_count, so a group in use is refused at once.
        return ApiResponse::success($service->groupsWithUsage(), __('funding_sequencing.group_created'));
    }

    public function store(StoreFundingGroupRequest $request): JsonResponse
    {
        $group = FundingGroup::create([
            'name'   => $request->name,
            'status' => 'Active',
        ]);

        return ApiResponse::success($group, __('funding_sequencing.group_created'), 201);
    }

    public function update(UpdateFundingGroupRequest $request, $id): JsonResponse
    {
        $group = FundingGroup::findOrFail($id);
        $group->update(['name' => $request->name]);

        return ApiResponse::success($group, __('funding_sequencing.group_updated'));
    }

    public function destroy($id, FundingSequencingService $service): JsonResponse
    {
        $group = FundingGroup::findOrFail($id);

        try {
            $service->deleteGroup($group);
        } catch (FundingGroupInUseException $e) {
            // Funding sequences are filed under it - say which, and change nothing.
            return ApiResponse::error($e->getMessage(), 422, ['blocked' => $e->blocked]);
        }

        return ApiResponse::success(null, __('funding_sequencing.group_deleted'));
    }
}
