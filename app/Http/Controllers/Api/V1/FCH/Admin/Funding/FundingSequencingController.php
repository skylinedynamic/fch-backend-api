<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Funding;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\StoreFundingSequencingRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\UpdateFundingSequencingRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\UpdateFundingSequencingDisplayRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Funding\UpdateFundingSequencingVisibilityRequest;
use App\Http\Resources\FCH\Funding\FundingSequencingResource;
use App\Services\FCH\Admin\Funding\FundingSequencingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FundingSequencingController extends Controller
{
    protected $service;

    public function __construct(FundingSequencingService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->all();

        $filters = [
            'per_page' => isset($input['per_page']) ? $input['per_page'] : ($input['rows'] ?? null),
            'page' => isset($input['page']) ? $input['page'] : ($input['first'] ?? 1),
            'search' => isset($input['search']) ? trim((string) $input['search']) : null,
            'status' => $input['status'] ?? null,
            'funding_group_id' => $input['funding_group_id'] ?? null,
            'funding_type_id' => $input['funding_type_id'] ?? null,
            'sort_field' => $input['sort_field'] ?? null,
            'sort_order' => $input['sort_order'] ?? null,
            'filters' => $input['filters'] ?? [],
        ];

        $filters['per_page'] = ($filters['per_page'] === 'All' || $filters['per_page'] == -1) ? -1 : (int) ($filters['per_page'] ?? 10);

        if (isset($input['first']) && isset($input['rows'])) {
            $filters['page'] = (int) ($input['first'] / $input['rows']) + 1;
        } else {
            $filters['page'] = max(1, (int) $filters['page']);
        }

        $data = $this->service->list($filters);

        if ($filters['per_page'] === -1) {
            return ApiResponse::success(FundingSequencingResource::collection($data), __('funding_sequencing.list_fetched'));
        }

        $resource = FundingSequencingResource::collection($data);
        $paginated = $resource->response()->getData(true);
        return ApiResponse::success($paginated, __('funding_sequencing.list_fetched'));
    }
    
    public function grouped(): JsonResponse
    {
        $data = $this->service->getGroupedSequencings();
        return ApiResponse::success($data, __('funding_sequencing.grouped_list_fetched'));
    }

    public function store(StoreFundingSequencingRequest $request): JsonResponse
    {
        $sequencing = $this->service->create($request->validated());

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.created'),
            201
        );
    }

    public function show($id): JsonResponse
    {
        $sequencing = $this->service->show($id);

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.fetched')
        );
    }

    public function update(UpdateFundingSequencingRequest $request, $id): JsonResponse
    {
        $sequencing = $this->service->update($id, $request->validated());

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.updated')
        );
    }

    public function updateDisplay(UpdateFundingSequencingDisplayRequest $request, $id): JsonResponse
    {
        $sequencing = $this->service->update($id, $request->validated());

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.updated')
        );
    }

    public function updateVisibility(UpdateFundingSequencingVisibilityRequest $request, $id): JsonResponse
    {
        $sequencing = $this->service->update($id, $request->validated());

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.updated')
        );
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        $request->validate(['status' => 'required|string|in:Active,Inactive']);
        $sequencing = $this->service->updateStatus($id, $request->status);

        return ApiResponse::success(
            new FundingSequencingResource($sequencing),
            __('funding_sequencing.status_updated')
        );
    }

    public function destroy($id): JsonResponse
    {
        $this->service->delete($id);

        return ApiResponse::success(
            null,
            __('funding_sequencing.deleted')
        );
    }
}
