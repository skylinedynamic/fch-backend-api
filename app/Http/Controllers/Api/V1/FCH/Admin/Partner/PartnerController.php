<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Partner;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FCH\Partner\PartnerService;
use App\Http\Resources\FCH\Partner\PartnerResource;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\StorePartnerRequest;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdatePartnerRequest;

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
    public function index(Request $request)
    {
        $partnersResponse = $this->partnerService->getAllPartners($request);

        return ApiResponse::success([
            'partners' => PartnerResource::collection(collect($partnersResponse['data'])),
            'meta' => $partnersResponse['meta'],
        ], __('partner.success.retrieved'));
    }

    /**
     * Store Partner.
     */
    public function store(StorePartnerRequest $request)
    {
        $user = $this->partnerService->store($request->validated());


        return ApiResponse::success(
            new PartnerResource($user),
            __('partner.success.created'),
            201
        );
    }

    /**
     * Update Update.
     */
    public function update(UpdatePartnerRequest $request, int $id)
    {
        $partner = $this->partnerService->findById($id);

        if (!$partner) {
            return ApiResponse::notFound('Partner not found');
        }

        $updatedPartner = $this->partnerService->update($partner, $request->validated());

        return ApiResponse::success(
            new PartnerResource($updatedPartner),
            __('partner.success.updated'),
        );
    }
}