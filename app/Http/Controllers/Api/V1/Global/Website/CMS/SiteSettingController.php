<?php

namespace App\Http\Controllers\Api\V1\Global\Website\CMS;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Services\Global\Website\CMS\SiteSettingService;

class SiteSettingController extends Controller
{
    protected $siteSettingService;

    public function __construct(SiteSettingService $siteSettingService)
    {
        $this->siteSettingService = $siteSettingService;
    }

    public function index()
    {
        $setting = $this->siteSettingService->getSiteSettings();
        
        if (!$setting) {
            return ApiResponse::success(null, __('site_settings.not_found'));
        }
        
        return ApiResponse::success($setting, __('site_settings.retrieved_success'));
    }
}
