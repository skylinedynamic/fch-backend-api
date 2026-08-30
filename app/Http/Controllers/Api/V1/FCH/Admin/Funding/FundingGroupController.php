<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Funding;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\StoreFundingGroupRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\UpdateFundingGroupRequest;
use App\Models\Funding\FundingGroup;
use Illuminate\Http\JsonResponse;

class FundingGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = FundingGroup::orderBy('sort_order')->orderBy('id','desc')->get(['id', 'name', 'status']);
        return ApiResponse::success($groups, __('funding_sequencing.group_created'));
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

    public function destroy($id): JsonResponse
    {
        $group = FundingGroup::findOrFail($id);
        $group->delete();

        return ApiResponse::success(null, __('funding_sequencing.group_deleted'));
    }
}
