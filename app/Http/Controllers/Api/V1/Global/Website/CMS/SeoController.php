<?php

namespace App\Http\Controllers\Api\V1\Global\Website\CMS;

use App\Http\Controllers\Controller;
use App\Services\Global\Website\CMS\WebsiteSeoService;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class SeoController extends Controller
{
    protected $seoService;

    public function __construct(WebsiteSeoService $seoService)
    {
        $this->seoService = $seoService;
    }

    public function show(Request $request)
    {
        $setting = $this->seoService->getByPath($request->path);
        
        if (!$setting) {
            return ApiResponse::success($this->seoService->getDefaultSeo(), __('seo.default'));
        }

        return ApiResponse::success($setting, __('seo.fetched'));
    }
}
