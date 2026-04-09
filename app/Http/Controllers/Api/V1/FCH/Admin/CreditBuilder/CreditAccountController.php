<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\CreditAccountRequest;
use App\Http\Resources\FCH\CreditBuilder\CreditAccountResource;
use App\Services\FCH\Admin\CreditBuilder\CreditAccountService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CreditAccountController extends Controller
{
    protected $service;

    public function __construct(CreditAccountService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $input = $request->all();

        $filters = [
            'per_page' => isset($input['per_page']) ? $input['per_page'] : ($input['rows'] ?? null),
            'page' => isset($input['page']) ? $input['page'] : ($input['first'] ?? 1),
            'search' => isset($input['search']) ? trim((string) $input['search']) : null,
            'filters' => $input['filters'] ?? null,
            'sort_field' => $input['sort_field'] ?? null,
            'sort_order' => $input['sort_order'] ?? null,
            'status' => $input['status'] ?? null,
            'issuer' => $input['issuer'] ?? null,
            'credit_category' => $input['credit_category'] ?? null,
            'difficulty_tier' => $input['difficulty_tier'] ?? null,
            'approval_level' => $input['approval_level'] ?? null,
        ];

        // Basic normalization
        $filters['per_page'] = ($filters['per_page'] === 'All' || $filters['per_page'] == -1) ? -1 : (int) ($filters['per_page'] ?? 10);
        
        // If 'first' was provided (offset), convert to page
        if (isset($input['first']) && isset($input['rows'])) {
            $filters['page'] = (int) ($input['first'] / $input['rows']) + 1;
        } else {
            $filters['page'] = max(1, (int) $filters['page']);
        }

        return $this->service->list($filters);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreditAccountRequest $request): JsonResponse
    {
        $account = $this->service->create($request->validated());
        
        return ApiResponse::success(
            new CreditAccountResource($account),
            __('credit_builder.credit_account_created'),
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show($id): JsonResponse
    {
        $account = $this->service->show($id);
        
        return ApiResponse::success(
            new CreditAccountResource($account),
            __('credit_builder.credit_account_fetched')
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CreditAccountRequest $request, $id): JsonResponse
    {
        $account = $this->service->update($id, $request->validated());
        
        return ApiResponse::success(
            new CreditAccountResource($account),
            __('credit_builder.credit_account_updated')
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id): JsonResponse
    {
        $this->service->delete($id);
        
        return ApiResponse::success(
            null,
            __('credit_builder.credit_account_deleted')
        );
    }
}
