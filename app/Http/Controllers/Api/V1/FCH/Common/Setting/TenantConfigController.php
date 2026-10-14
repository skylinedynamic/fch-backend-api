<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\FCH\Partner\TenantConfigResource;
use App\Http\Requests\Api\V1\FCH\Admin\Partner\UpdateTenantConfigRequest;
use App\Services\FCH\Common\Setting\TenantConfigService;
use Illuminate\Support\Facades\Auth;

class TenantConfigController extends Controller
{
    protected TenantConfigService $tenantConfigService;

    public function __construct(TenantConfigService $tenantConfigService)
    {
        $this->tenantConfigService = $tenantConfigService;
    }

    /**
     * Get the tenant config for the currently authenticated partner.
     */
    public function show()
    {
        $user = Auth::user();
        // The user's partner - not entity_id, which for a staff user is their
        // employee id and names some other partner.
        $partnerId = $user?->partner_id;

        if (!$partnerId) {
            return ApiResponse::notFound(__('partner.error.not_found'));
        }

        $partner = $this->tenantConfigService->getTenantConfig((int) $partnerId);

        if (!$partner) {
            return ApiResponse::notFound(__('partner.error.not_found'));
        }

        return ApiResponse::success(
            (new TenantConfigResource($partner))->resolve(),
            __('partner.success.tenant_config_retrieved')
        );
    }

    /**
     * Update the tenant config for the currently authenticated partner.
     */
    public function update(UpdateTenantConfigRequest $request)
    {
        $user = Auth::user();
        // See show(): the user's partner, never entity_id.
        $partnerId = $user?->partner_id;

        if (!$partnerId) {
            return ApiResponse::notFound(__('partner.error.not_found'));
        }

        try {
            $updated = $this->tenantConfigService->updateTenantConfig(
                (int) $partnerId,
                $request->validated()
            );

            return ApiResponse::success(
                (new TenantConfigResource($updated))->resolve(),
                __('partner.success.tenant_config_updated')
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }
}
