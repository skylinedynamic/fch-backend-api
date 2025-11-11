<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\PartnerController;
use App\Http\Controllers\Api\V1\FCH\Admin\Partner\DemoRequestController;
// ✅ Import Role & Permission Controllers


/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
| All admin API endpoints are loaded here. 
| Protected by Passport (auth:api) and Admin middleware.
| We also include set.current.business for business-wise data scoping.
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->middleware(['auth:api', 'admin'])
    ->group(function () {

        // 🧭 Partner routes
        Route::get('partners', [PartnerController::class, 'index']);
        Route::post('partners', [PartnerController::class, 'store']);
        Route::put('partners/{id}', [PartnerController::class, 'update']);

        // 📅 Demo requests
        Route::get('demo-requests', [DemoRequestController::class, 'index']);
        Route::post('demo-requests/store', [DemoRequestController::class, 'store']);
        // Route::post('demo-requests/update', [DemoRequestController::class, 'update']);
    });
