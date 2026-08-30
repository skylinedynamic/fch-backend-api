<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Commission;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Common\Commission\FetchCommissionRequest;
use App\Http\Resources\FCH\Admin\Commission\CommissionResource;
use App\Http\Resources\FCH\Common\Commission\CommissionDetailsResource;
use App\Services\FCH\Common\Commission\CommissionService;
use Throwable;

class CommissionController extends Controller
{
    protected CommissionService $commissionService;

    public function __construct(CommissionService $commissionService)
    {
        $this->commissionService = $commissionService;
    }

    /**
     * Get all commissions.
     */
    public function index(FetchCommissionRequest $request)
    {
        try {
            $input = $request->validated();
            
            $filters = [
                'per_page'      => $input['per_page'] ?? null,
                'page'          => $input['page'] ?? 1,
                'search'        => $input['search'] ?? null,
                'filters'       => $input['filters'] ?? [],
                'sort_field'    => $input['sort_field'] ?? null,
                'sort_order'    => $input['sort_order'] ?? null,
                'partner_id'    => $input['partner_id'] ?? null,
                'payment_status'=> $input['payment_status'] ?? null,
                'commission_status'=> $input['commission_status'] ?? null,
            ];

            return $this->commissionService->getCommissions($filters);
        } catch (Throwable $e) {
            return ApiResponse::serverError($e->getMessage(), 500);
        }
    }

    /**
     * Get commission details by ID.
     */
    public function show(int $id)
    {
        try {
            $commission = $this->commissionService->getCommissionById($id);

            return ApiResponse::success(
                new CommissionDetailsResource($commission),
                __('commission.details_retrieved')
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ApiResponse::notFound($e->getMessage());
        } catch (Throwable $e) {
            return ApiResponse::serverError($e->getMessage(), 500);
        }
    }
}
