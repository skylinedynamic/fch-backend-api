<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\CreditBuilder\CapitalAccess\StoreCapitalAccessRequest;
use App\Http\Requests\Api\V1\FCH\Common\CreditBuilder\CapitalAccess\UpdateCapitalAccessRequest;
use App\Http\Resources\FCH\CreditBuilder\CapitalAccessResource;
use App\Http\Resources\FCH\CreditBuilder\CapitalAccessDetailResource;
use App\Services\FCH\Common\CreditBuilder\CapitalAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CapitalAccessController extends Controller
{
    protected CapitalAccessService $service;

    public function __construct(CapitalAccessService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a listing of capital accesses.
     */
    public function index(Request $request)
    {
        $input = $request->all();

        $filters = [
            'per_page'   => isset($input['per_page']) ? $input['per_page'] : ($input['rows'] ?? null),
            'page'       => isset($input['page'])     ? $input['page']     : ($input['first'] ?? 1),
            'search'     => isset($input['search'])   ? trim((string) $input['search']) : null,
            'filters'    => $input['filters']    ?? null,
            'sort_field' => $input['sort_field'] ?? null,
            'sort_order' => $input['sort_order'] ?? null,
            'status'     => $input['status']     ?? null,
        ];

        // Basic normalization
        $filters['per_page'] = ($filters['per_page'] === 'All' || $filters['per_page'] == -1)
            ? -1
            : (int) ($filters['per_page'] ?? 10);

        // Convert PrimeReact first/rows pagination to page/per_page
        if (isset($input['first']) && isset($input['rows'])) {
            $filters['page'] = (int) ($input['first'] / $input['rows']) + 1;
        } else {
            $filters['page'] = max(1, (int) $filters['page']);
        }

        return $this->service->list($filters);
    }

    /**
     * Store a newly created capital access.
     */
    public function store(StoreCapitalAccessRequest $request): JsonResponse
    {
        $capitalAccess = $this->service->create($request->validated());

        return ApiResponse::success(
            new CapitalAccessDetailResource($capitalAccess),
            __('credit_builder.capital_access_created'),
            201
        );
    }

    /**
     * Display the specified capital access.
     */
    public function show($id): JsonResponse
    {
        $capitalAccess = $this->service->show($id);

        return ApiResponse::success(
            new CapitalAccessDetailResource($capitalAccess),
            __('credit_builder.capital_access_fetched')
        );
    }

    /**
     * Update the specified capital access.
     */
    public function update(UpdateCapitalAccessRequest $request, $id): JsonResponse
    {
        $capitalAccess = $this->service->update($id, $request->validated());

        return ApiResponse::success(
            new CapitalAccessDetailResource($capitalAccess),
            __('credit_builder.capital_access_updated')
        );
    }

    /**
     * Update the status of the specified capital access.
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        $request->validate(['status' => 'required|string|in:Active,Inactive']);

        $capitalAccess = $this->service->updateStatus($id, $request->status);

        return ApiResponse::success(
            new CapitalAccessDetailResource($capitalAccess),
            __('credit_builder.capital_access_status_updated')
        );
    }

    /**
     * Remove the specified capital access.
     */
    public function destroy($id): JsonResponse
    {
        $this->service->delete($id);

        return ApiResponse::success(
            null,
            __('credit_builder.capital_access_deleted')
        );
    }
}
