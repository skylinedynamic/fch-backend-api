<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\FCH\Common\User\UserController;
use App\Http\Controllers\Api\V1\FCH\Common\Employee\EmployeeController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\RoleController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\UserRoleController;
use App\Http\Controllers\Api\V1\FCH\Common\RolePermission\PermissionController;
use App\Http\Controllers\Api\V1\FCH\Common\Auth\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\Global\TwoFaAuth\TwoFaController as V1TwoFaController;
use App\Http\Controllers\Api\V1\Global\Notification\NotificationController as V1NotificationController;

Route::prefix('v1')->group(function () {
    // V1 auth routes
    Route::prefix('auth')->group(function () {
        Route::post('/login', [V1AuthController::class, 'login']);
        Route::post('/login/2fa/confirm', [V1TwoFaController::class, 'login_confirm']);
        Route::post('/forgot', [V1AuthController::class, 'forgot']);
        Route::post('/reset', [V1AuthController::class, 'reset']);

        Route::middleware('auth:api')->group(function () {
            // V1 auth routes
            Route::post('/logout', [V1AuthController::class, 'logout']);
        });
    });
    

    Route::middleware('auth:api')->group(function () {

        // V1 2FA routes
        Route::prefix('2fa')->group(function () {
            Route::post('/setup', [V1TwoFaController::class, 'setup']);
            Route::post('/verify', [V1TwoFaController::class, 'verify']);
            Route::post('/disable', [V1TwoFaController::class, 'disable']);
            Route::post('/resend', [V1TwoFaController::class, 'resend']);
            Route::get('/status', [V1TwoFaController::class, 'status']);
            Route::post('/recovery-codes/regenerate', [V1TwoFaController::class, 'regenerateRecoveryCodes']);
        });
        Route::prefix('common')->group(function () {
            
            Route::post('roles/fetch-roles',[RoleController::class,'fetchRoles']);
            Route::apiResource('roles', RoleController::class);

            Route::post('users/{user}/roles', [UserRoleController::class, 'assignRoles']);
            Route::post('users/{user}/permissions', [UserRoleController::class, 'assignPermissions']);

            Route::post('employees/fetch-employees',[EmployeeController::class,'fetchEmployees']);
            Route::post('employees/fetch-department-status',[EmployeeController::class,'fetchDepartmentStatus']);
            Route::post('employees/multiple-delete', [EmployeeController::class, 'multipleDelete']);
            Route::apiResource('employees', EmployeeController::class);

            Route::post('users/fetch-users', [UserController::class, 'fetchUsers']);
            Route::post('users/multiple-delete', [UserController::class, 'multipleDelete']);
            Route::apiResource('users', UserController::class);
            
            Route::post('permissions', [PermissionController::class, 'index']);
        });

        // V1 Notification routes
        Route::prefix('notifications')->group(function () {
            Route::post('/test-email', [V1NotificationController::class, 'sendTestEmail']);
        });
       
    });

});