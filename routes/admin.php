<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\Partner\PartnerController;

/*
|--------------------------------------------------------------------------
| Admin API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register admin API routes for your application.
| These routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::prefix('v1/admin')->middleware(['auth:api', 'admin'])->group(function () {
    Route::post('partners', [PartnerController::class, 'store']);
    Route::put('partners/{id}', [PartnerController::class, 'update']);
});