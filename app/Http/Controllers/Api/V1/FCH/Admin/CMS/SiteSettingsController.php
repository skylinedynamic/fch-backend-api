<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CMS;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\UpdateSiteSettingsRequest;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\GetSettingRequest;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\SaveSettingRequest;
use Illuminate\Validation\ValidationException;
use App\Services\FCH\Admin\CMS\SiteSettingsService;

class SiteSettingsController extends Controller
{
    protected SiteSettingsService $siteSettingsService;

    public function __construct(SiteSettingsService $siteSettingsService)
    {
        $this->siteSettingsService = $siteSettingsService;
    }

    public function index()
    {
        try {
            $partnerId = auth()->user()->partner_id;
            $setting = $this->siteSettingsService->getSettings($partnerId);
            return ApiResponse::success($setting, __('site_settings.retrieved_success'));
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return ApiResponse::serverError(__('site_settings.retrieved_failed'));
        }
    }

    public function update(UpdateSiteSettingsRequest $request)
    {
        try {
            $partnerId = auth()->user()->partner_id;
            $setting = $this->siteSettingsService->updateSettings($partnerId, $request->validated());

            return ApiResponse::success($setting, __('site_settings.updated_success'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.updated_failed'));
        }
    }

}
