<?php

namespace App\Services\Global\Website\CMS;

use App\Models\CMS\SeoSetting;
use Illuminate\Http\Request;

class WebsiteSeoService
{
    public function getByPath($path)
    {
        if (!$path) $path = '/';
        
        return SeoSetting::where('path', $path)->first();
    }

    public function getDefaultSeo()
    {
        return [
            'meta_title' => 'Four Corner Funding',
            'meta_description' => 'Fast, easy business funding solutions.',
            'keywords' => 'business funding, loans, credit builder',
        ];
    }
}
