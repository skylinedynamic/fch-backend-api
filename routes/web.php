<?php

use Illuminate\Support\Facades\Route;
use Dedoc\Scramble\Scramble;

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\PrometheusController;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/logs', [DashboardController::class, 'logs'])->name('dashboard.logs');
    Route::get('/dashboard/health', [DashboardController::class, 'health'])->name('dashboard.health');
    Route::get('/dashboard/charts', [DashboardController::class, 'charts'])->name('dashboard.charts');
    Route::get('/dashboard/fleet', [DashboardController::class, 'fleet'])->name('dashboard.fleet');
    Route::get('/dashboard/docs/admin', [DashboardController::class, 'docsAdmin'])->name('dashboard.docs.admin');
    Route::get('/dashboard/docs/borrower', [DashboardController::class, 'docsBorrower'])->name('dashboard.docs.borrower');
});

// Prometheus exporter endpoint
Route::get('/prometheus', PrometheusController::class);

Route::get('/testing', function () {
    return view('recaptcha');
});

// Register borrower API documentation routes
Scramble::registerUiRoute('docs/borrower', api: 'borrower');
Scramble::registerJsonSpecificationRoute('docs/borrower/api.json', api: 'borrower');

// Register combined admin documentation (admin + partner) under one URL
Scramble::registerUiRoute('docs/admin', api: 'admin');
Scramble::registerJsonSpecificationRoute('docs/admin/api.json', api: 'admin');
