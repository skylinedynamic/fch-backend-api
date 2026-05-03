<?php

namespace App\Http\Controllers\Api\V1\FCH\Admin\CMS;

use App\Http\Controllers\Controller;
use App\Models\CMS\SiteSetting;
use App\Helpers\ApiResponse;
use App\Http\Requests\Api\V1\FCH\Admin\CMS\SiteSettingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Http\Resources\FCH\CMS\SiteSettingResource;
use App\Services\FCH\Admin\CMS\SiteSettingService;

class SiteSettingController extends Controller
{
    protected SiteSettingService $siteSettingService;

    public function __construct(SiteSettingService $siteSettingService)
    {
        $this->siteSettingService = $siteSettingService;
    }

    public function index()
    {
        try {
            $setting = $this->siteSettingService->getSettings(auth()->id());
            return ApiResponse::success(new SiteSettingResource($setting), __('site_settings.retrieved_success'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.retrieved_failed'));
        }
    }

    public function update(SiteSettingRequest $request)
    {
        try {
            $data = $request->validated();

            // Pass files if present
            if ($request->hasFile('header_logo')) {
                $data['header_logo'] = $request->file('header_logo');
            }
            if ($request->hasFile('footer_logo')) {
                $data['footer_logo'] = $request->file('footer_logo');
            }
            if ($request->hasFile('partner_logo')) {
                $data['partner_logo'] = $request->file('partner_logo');
            }
            if ($request->hasFile('favicon')) {
                $data['favicon'] = $request->file('favicon');
            }

            $setting = $this->siteSettingService->updateSettings(auth()->id(), $data);

            return ApiResponse::success(new SiteSettingResource($setting), __('site_settings.updated_success'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.updated_failed'));
        }
    }
}

