<?php

use Illuminate\Support\Facades\Route;
use Dedoc\Scramble\Scramble;

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\SchedulerCheckController;
use App\Http\Controllers\Web\EmbedFormPageController;

Route::get("/", function () {
    abort(404);
});

// Public Embed Form — shareable URL or iframe embed
Route::get('/form/{key}', [EmbedFormPageController::class, 'show'])->name('embed-form.show');




Route::get("/login", [AuthController::class, "showLoginForm"])->name("login");
Route::post("/login", [AuthController::class, "login"]);
Route::post("/logout", [AuthController::class, "logout"])->name("logout");

Route::middleware("auth")->group(function () {
    Route::get("/dashboard", [DashboardController::class, "index"])->name("dashboard");
    Route::get("/dashboard/logs", [DashboardController::class, "logs"])->name("dashboard.logs");
    Route::get("/dashboard/scheduler", [DashboardController::class, "scheduler"])->name("dashboard.scheduler");
    Route::get("/dashboard/health", [DashboardController::class, "health"])->name("dashboard.health");
    Route::get("/dashboard/charts", [DashboardController::class, "charts"])->name("dashboard.charts");
    Route::get("/dashboard/backups", [DashboardController::class, "backups"])->name("dashboard.backups");
    Route::get("/dashboard/backups/download", [DashboardController::class, "downloadBackup"])->name("dashboard.backups.download");
    Route::post("/dashboard/backups/restore", [DashboardController::class, "restoreBackup"])->name("dashboard.backups.restore");
    Route::delete("/dashboard/backups/delete", [DashboardController::class, "deleteBackup"])->name("dashboard.backups.delete");
    Route::get("/dashboard/docs/admin", [DashboardController::class, "docsAdmin"])->name("dashboard.docs.admin");
    Route::get("/dashboard/docs/borrower", [DashboardController::class, "docsBorrower"])->name("dashboard.docs.borrower");
});

// Scheduler health check endpoint — call via external cron (e.g. AWS crontab)
Route::get("/scheduler/check", SchedulerCheckController::class);

// Route::get("/testing", function () {
//     return view("recaptcha");
// });

// Register borrower API documentation routes
Scramble::registerUiRoute("docs/borrower", api: "borrower");
Scramble::registerJsonSpecificationRoute("docs/borrower/api.json", api: "borrower");

// Register combined admin documentation (admin + partner) under one URL
Scramble::registerUiRoute("docs/admin", api: "admin");
Scramble::registerJsonSpecificationRoute("docs/admin/api.json", api: "admin");

