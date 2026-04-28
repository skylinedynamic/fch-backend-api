<?php

namespace App\Http\Controllers\Api\V1\Global\Website\CMS;

use App\Http\Controllers\Controller;
use App\Models\CMS\SiteSetting;
use App\Helpers\ApiResponse;

class SiteSettingController extends Controller
{
    public function index()
    {
        $setting = SiteSetting::first();
        return ApiResponse::success($setting, 'Site settings retrieved successfully');
    }
}
