<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Partner\Partner;
use Illuminate\Http\JsonResponse;
use App\Services\FCH\Common\Setting\GeneralConfigurationsService;
use App\Http\Requests\Api\V1\FCH\Common\Setting\UpdateGeneralConfigurationsRequest;
use App\Http\Resources\FCH\Common\Setting\BrandingConfigurationResource;

class BrandingConfigurationController extends Controller
{
    public function __construct(
        protected GeneralConfigurationsService $service
    ) {
    }

    /**
     * Get general branding configurations.
     */
    public function show(): JsonResponse
    {
        try {
            $entityType = Partner::class;
            $entityId = auth()->user()->partner_id;

            $result = $this->service->show($entityType, $entityId, false);

            return ApiResponse::success(
                new BrandingConfigurationResource($result),
                __('site_settings.retrieved_success')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.retrieved_failed'));
        }
    }

    /**
     * Update general branding configurations.
     */
    public function update(UpdateGeneralConfigurationsRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $entityType = Partner::class;
            $entityId = auth()->user()->partner_id;

            $result = $this->service->update($data, $entityType, $entityId);

            return ApiResponse::success(
                new BrandingConfigurationResource($result),
                __('site_settings.updated_success')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.updated_failed'));
        }
    }
}
