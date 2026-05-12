<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\Configuration;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Services\FCH\Admin\Configuration\AppConfigurationService;
use App\Http\Resources\FCH\Common\AppConfigurationResource;
use Illuminate\Http\Request;

class AppConfigurationController extends Controller
{
    protected AppConfigurationService $configService;

    public function __construct(AppConfigurationService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * Get all app configurations for the current authenticated user's scope.
     */
    public function index()
    {
        try {
            $user = auth()->user();
            $configs = $this->configService->getAllConfigurations($user->entity_type, $user->partner_id);

            return ApiResponse::success(new AppConfigurationResource($configs));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.retrieved_failed'));
        }
    }

    /**
     * Show one or more specific config keys
     */
    public function show(Request $request)
    {
        try {
            $keys = $request->keys ?? [];
            if (!is_array($keys)) {
                $keys = [$keys];
            }

            $user = auth()->user();
            $configs = $this->configService->getConfigurations($keys, $user->entity_type, $user->partner_id);

            return ApiResponse::success(new AppConfigurationResource($configs));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.retrieved_failed'));
        }
    }

    /**
     * Save multiple configurations at once.
     */
    public function store(Request $request)
    {
        try {
            $settings = $request->settings ?? $request->all();
            $user = auth()->user();

            $this->configService->saveConfigurations((array) $settings, $user->entity_type, $user->partner_id);

            return ApiResponse::success(null, __('app_config.saved_successfully'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.updated_failed'));
        }
    }
}
