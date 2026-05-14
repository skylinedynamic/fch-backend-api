<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\FCH\Common\Setting\EmailConfigurationService;
use App\Http\Resources\FCH\Common\Setting\EmailConfigurationResource;
use App\Http\Requests\Api\V1\FCH\Common\Setting\StoreEmailConfigurationRequest;

class EmailConfigurationController extends Controller
{
    public function __construct(
        protected EmailConfigurationService $service
    ) {}

    /**
     * List all configurations for a partner.
     * First call → DB → cache saved.
     * Subsequent calls → cache hit → no DB.
     */
    public function index(Request $request): JsonResponse
    {
        $partnerId = $request->get('partner_id');

        $configs = $this->service->getConfigurations($partnerId);

        return ApiResponse::success(
            EmailConfigurationResource::collection($configs),
            __('email_config.retrieved')
        );
    }

    /**
     * Store or update → cache flushed inside service.
     */
    public function store(StoreEmailConfigurationRequest $request): JsonResponse
    {
        $config = $this->service->storeOrUpdate($request->validated());

        return ApiResponse::success(
            new EmailConfigurationResource($config),
            __('email_config.saved')
        );
    }

    /**
     * Toggle active status → cache flushed inside service.
     */
    public function toggleStatus(int $id): JsonResponse
    {
        $config = $this->service->toggleStatus($id);

        return ApiResponse::success(
            new EmailConfigurationResource($config),
            __('email_config.status_updated')
        );
    }
}