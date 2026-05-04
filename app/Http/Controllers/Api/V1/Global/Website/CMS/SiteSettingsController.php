<?php

namespace App\Http\Controllers\Api\V1\Global\Website\CMS;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Services\Global\Website\CMS\WebsiteSiteSettingsService;

class SiteSettingsController extends Controller
{
    protected WebsiteSiteSettingsService $siteSettingsService;

    public function __construct(WebsiteSiteSettingsService $siteSettingsService)
    {
        $this->siteSettingsService = $siteSettingsService;
    }

    public function index()
    {
        try {
            $setting = $this->siteSettingsService->getSettings();
            
            if (!$setting) {
                return ApiResponse::notFound(__('site_settings.not_found'));
            }

            return ApiResponse::success($setting, __('site_settings.retrieved_success'));
        } catch (\Exception $e) {
            return ApiResponse::serverError(__('site_settings.retrieved_failed'));
        }
    }
}
