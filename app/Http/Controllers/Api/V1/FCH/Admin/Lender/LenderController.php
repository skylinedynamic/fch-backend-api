<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Lender;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\FetchLenderRequest;
use App\Models\Lender\Lender;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\StoreLenderRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Lender\UpdateLenderRequest;
use App\Http\Resources\FCH\Lender\LenderResource;
use App\Services\FCH\Admin\Lender\LenderService;
use App\Helpers\ApiResponse;

class LenderController extends Controller
{
    protected $lenderService;

    public function __construct(LenderService $lenderService)
    {
        $this->lenderService = $lenderService;
    }
    public function index(FetchLenderRequest $request)
    {
        $perPage = $request->input('per_page', 10);
        $sortField = $request->input('sort_field', 'id');
        $sortOrder = $request->input('sort_order', 'desc');
        $search = $request->input('search');

        $lenders = $this->lenderService->getLenders($perPage, $sortField, $sortOrder, $search);

        if ($perPage == -1 || $perPage == 'All') {
            return ApiResponse::success(LenderResource::collection($lenders), __('lender.fetched_successfully'));
        }

        return ApiResponse::successWithPagination(
            LenderResource::collection($lenders->items()),
            [
                'current_page' => $lenders->currentPage(),
                'last_page' => $lenders->lastPage(),
                'per_page' => $lenders->perPage(),
                'total' => $lenders->total(),
            ],
            __('lender.fetched_successfully')
        );
    }

    public function getAll()
    {
        $lenders = $this->lenderService->getAllActiveLenders();
        return ApiResponse::success($lenders, __('lender.fetched_successfully'));
    }

    public function store(StoreLenderRequest $request)
    {
        $validated = $request->validated();
        $lender = $this->lenderService->createLender($validated);

        return ApiResponse::success(new LenderResource($lender), __('lender.created_successfully'), 201);
    }

    public function show($id)
    {
        $lender = Lender::findOrFail($id);
        return ApiResponse::success(new LenderResource($lender), __('lender.fetched_successfully'));
    }

    public function update(UpdateLenderRequest $request, $id)
    {
        $validated = $request->validated();
        $lender = Lender::findOrFail($id);
        
        $lender = $this->lenderService->updateLender($lender, $validated);

        return ApiResponse::success(new LenderResource($lender), __('lender.updated_successfully'));
    }

    public function destroy($id)
    {
        $lender = Lender::findOrFail($id);
        $this->lenderService->deleteLender($lender);

        return ApiResponse::success(null, __('lender.deleted_successfully'));
    }
}
