<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Global\Website\Lead\LeadController;
use App\Http\Controllers\Api\V1\Global\Website\PreQualification\PreQualificationController;

Route::prefix('v1/website')->group(function () {
    Route::post('leads', [LeadController::class, 'store']);
    Route::post('pre-qualification', [PreQualificationController::class, 'store']);
});
