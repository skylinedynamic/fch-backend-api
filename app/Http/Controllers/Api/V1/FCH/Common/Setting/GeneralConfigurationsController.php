<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\FCH\Common\Setting\GeneralConfigurationsService;
use App\Http\Requests\Api\V1\FCH\Common\Setting\UpdateGeneralConfigurationsRequest;
use App\Http\Resources\FCH\Common\Setting\GeneralConfigurationResource;

class GeneralConfigurationsController extends Controller
{
    public function __construct(
        protected GeneralConfigurationsService $service
    ) {}

    /**
     * Get general branding configurations.
     */
    public function show(): JsonResponse
    {
        try {
            $entityType = \App\Models\Partner\Partner::class;
            $entityId   = (int) request()->input('entity_id', auth()->user()->partner_id);

            $result = $this->service->show($entityType, $entityId);

            return ApiResponse::success($result, 'General information fetched successfully.');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Failed to fetch general information.');
        }
    }

    /**
     * Update general branding configurations.
     */
    public function update(UpdateGeneralConfigurationsRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $entityType = \App\Models\Partner\Partner::class;
            $entityId = $request->input('entity_id', auth()->user()->partner_id);

            $result = $this->service->update($data, $entityType, $entityId);

            return ApiResponse::success(
                new GeneralConfigurationResource($result),
                __('site_settings.updated_success')
            );
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.updated_failed'));
        }
    }
}
