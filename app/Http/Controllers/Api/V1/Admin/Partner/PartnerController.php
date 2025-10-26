<?php

namespace App\Http\Controllers\Api\V1\Admin\Partner;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePartnerRequest;
use App\Http\Requests\Admin\UpdatePartnerRequest;
use App\Http\Resources\Partner\PartnerResource;
use App\Services\Partner\PartnerService;

class PartnerController extends Controller
{
    protected PartnerService $partnerService;

    public function __construct(PartnerService $partnerService)
    {
        $this->partnerService = $partnerService;
    }

    /**
     * Store a newly created partner/company.
     */
    public function store(StorePartnerRequest $request)
    {
        $company = $this->partnerService->store($request->validated());

        return ApiResponse::success(
            new PartnerResource($company),
            'Partner created successfully',
            201
        );
    }

    /**
     * Update an existing partner/company.
     */
    public function update(UpdatePartnerRequest $request, int $id)
    {
        $company = $this->partnerService->findById($id);

        if (!$company) {
            return ApiResponse::notFound('Partner not found');
        }

        $updatedCompany = $this->partnerService->update($company, $request->validated());

        return ApiResponse::success(
            new PartnerResource($updatedCompany),
            'Partner updated successfully'
        );
    }
}