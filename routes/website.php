<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Global\Website\Lead\LeadController;
use App\Http\Controllers\Api\V1\Global\Website\PreQualification\PreQualificationController;
use App\Http\Controllers\Api\V1\Global\Website\CMS\BlogController;
use App\Http\Controllers\Api\V1\Global\Website\CMS\SeoController;
use App\Http\Controllers\Api\V1\Global\Website\CMS\SiteSettingController;

Route::prefix('v1/website')->group(function () {
    Route::post('leads', [LeadController::class, 'store']);
    Route::post('pre-qualification', [PreQualificationController::class, 'store']);

    // Blog
    Route::get('blogs', [BlogController::class, 'index']);
    Route::get('blogs/categories', [BlogController::class, 'categories']);
    Route::get('blogs/tags', [BlogController::class, 'tags']);
    Route::get('blogs/latest', [BlogController::class, 'latest']);
    Route::get('blogs/{slug}', [BlogController::class, 'show']);

    // SEO
    Route::get('seo', [SeoController::class, 'show']);

    // Site Settings
    Route::get('settings', [SiteSettingController::class, 'index']);
    Route::get('ping', function() { return response()->json(['ping' => 'pong']); });
});
