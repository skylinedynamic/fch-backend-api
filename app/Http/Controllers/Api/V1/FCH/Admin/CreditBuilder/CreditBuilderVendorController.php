<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CreditBuilder;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\CreditBuilder\Vendors\CreditBuilderVendorRequest;
use App\Http\Resources\FCH\CreditBuilder\CreditBuilderVendorResource;
use App\Services\FCH\Admin\CreditBuilder\CreditBuilderVendorService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CreditBuilderVendorController extends Controller
{
    protected $service;

    public function __construct(CreditBuilderVendorService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);
        $vendors = $this->service->getAll($limit);
        $data = CreditBuilderVendorResource::collection($vendors)->response()->getData(true);

        return ApiResponse::successWithPagination(
            $data['data'],
            [
                'links' => $data['links'],
                'meta' => $data['meta']
            ],
            __('credit_builder.vendor_list_fetched')
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreditBuilderVendorRequest $request): JsonResponse
    {
        $vendor = $this->service->create($request->validated());
        
        return ApiResponse::success(
            new CreditBuilderVendorResource($vendor),
            __('credit_builder.vendor_created'),
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show($id): JsonResponse
    {
        $vendor = $this->service->show($id);
        
        return ApiResponse::success(
            new CreditBuilderVendorResource($vendor),
            __('credit_builder.vendor_fetched')
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CreditBuilderVendorRequest $request, $id): JsonResponse
    {
        $vendor = $this->service->update($id, $request->validated());
        
        return ApiResponse::success(
            new CreditBuilderVendorResource($vendor),
            __('credit_builder.vendor_updated')
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
            __('credit_builder.vendor_deleted')
        );
    }
}
