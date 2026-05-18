<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\Setting;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Services\FCH\Common\Setting\AppConfigurationService;
use App\Http\Resources\FCH\Common\AppConfigurationResource;
use App\Http\Requests\Api\V1\FCH\Common\Setting\GetAppConfigurationRequest;
use App\Http\Requests\Api\V1\FCH\Common\Setting\StoreAppConfigurationRequest;

class AppConfigurationController extends Controller
{
    protected AppConfigurationService $configService;

    public function __construct(AppConfigurationService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * Get app configuration(s) for the current authenticated user's scope.
     * Validated via GetAppConfigurationRequest.
     */
    public function index(GetAppConfigurationRequest $request)
    {
        try {
            $user = auth()->user();
            $partnerId = $user->partner_id;

            $module = $request->input('module');
            $key = $request->input('key');

            if ($key) {
                if (is_array($key)) {
                    $configs = $this->configService->getMultiple($key, $module, $partnerId);
                    return ApiResponse::success(new AppConfigurationResource($configs));
                }

                $value = $this->configService->get($key, null, $module, $partnerId);
                return ApiResponse::success([
                    'key' => $key,
                    'value' => $value
                ]);
            }

            $configs = $this->configService->getByModule($module, $partnerId);
            return ApiResponse::success(new AppConfigurationResource($configs));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.retrieved_failed'));
        }
    }

    /**
     * Save app configuration(s) for the current authenticated user's scope.
     * Validated via StoreAppConfigurationRequest.
     */
    public function store(StoreAppConfigurationRequest $request)
    {
        try {
            $user = auth()->user();
            $partnerId = $user->partner_id;
            $module = $request->input('module');

            if ($request->has('key')) {
                $key = $request->input('key');
                $value = $request->input('value');

                $this->configService->set($key, $value, $module, $partnerId);
            } else {
                $settings = $request->input('settings') ?? $request->except(['module', 'partner_id']);
                
                foreach ((array) $settings as $key => $value) {
                    $this->configService->set($key, $value, $module, $partnerId);
                }
            }

            return ApiResponse::success(null, __('app_config.saved_successfully'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.updated_failed'));
        }
    }
}
