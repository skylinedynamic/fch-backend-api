<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\StorePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerRequest;
use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Services\Partner\PartnerService;

class PartnerController extends Controller
{
    protected PartnerService $partnerService;

    public function __construct(PartnerService $partnerService)
    {
        $this->partnerService = $partnerService;
    }

    /**
     * Get partners.
     */
    public function index()
    {
        $partners = $this->partnerService->getAllPartners();

        return ApiResponse::success(
            PartnerResource::collection($partners),
            'Partners retrieved successfully'
        );
    }

    /**
     * Store Partner.
     */
    public function store(StorePartnerRequest $request)
    {
        $user = $this->partnerService->store($request->validated());

        return ApiResponse::success(
            new PartnerResource($user),
            'Partner created successfully',
            201
        );
    }

    /**
     * Update Update.
     */
    public function update(UpdatePartnerRequest $request, int $id)
    {
        $partner = $this->partnerService->findById($id);

        return ApiResponse::success(
            new PartnerResource($partner),
            'Partner updated successfully'
        );

        if (!$partner) {
            return ApiResponse::notFound('Partner not found');
        }

        $updatedPartner = $this->partnerService->update($partner, $request->validated());

        return ApiResponse::success(
            new PartnerResource($updatedPartner),
            'Partner updated successfully'
        );
    }
}