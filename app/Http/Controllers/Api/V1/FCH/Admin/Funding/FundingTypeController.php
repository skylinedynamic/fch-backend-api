<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Funding;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\StoreFundingTypeRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\UpdateFundingTypeRequest;
use App\Models\Funding\FundingType;
use Illuminate\Http\JsonResponse;

class FundingTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = FundingType::orderBy('sort_order')->orderBy('id','desc')->get(['id', 'name', 'status']);
        return ApiResponse::success($types, __('funding_sequencing.type_created'));
    }

    public function store(StoreFundingTypeRequest $request): JsonResponse
    {
        $type = FundingType::create([
            'name'   => $request->name,
            'status' => 'Active',
        ]);

        return ApiResponse::success($type, __('funding_sequencing.type_created'), 201);
    }

    public function update(UpdateFundingTypeRequest $request, $id): JsonResponse
    {
        $type = FundingType::findOrFail($id);
        $type->update(['name' => $request->name]);

        return ApiResponse::success($type, __('funding_sequencing.type_updated'));
    }

    public function destroy($id): JsonResponse
    {
        $type = FundingType::findOrFail($id);
        $type->delete();

        return ApiResponse::success(null, __('funding_sequencing.type_deleted'));
    }
}
