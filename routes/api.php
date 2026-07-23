<?php

declare(strict_types=1);

use App\Http\Controllers\Api\v1\AuditLogController;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\OperatingUnitController;
use App\Http\Controllers\Api\v1\RoleController;
use App\Http\Controllers\Api\v1\UnitBlueprintController;
use App\Http\Controllers\Api\v1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public guest routes
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Protected routes requiring authentication
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

        // Routes requiring an updated password and operating unit scope
        Route::middleware(['ensure.password.updated', 'scope.unit'])->group(function () {
            // Operating Units
            Route::apiResource('operating-units', OperatingUnitController::class);

            // Unit Blueprints
            Route::get('/unit-blueprints', [UnitBlueprintController::class, 'index']);
            Route::post('/unit-blueprints', [UnitBlueprintController::class, 'store']);

            // Roles CRUD
            Route::get('/roles', [RoleController::class, 'index']);
            Route::post('/roles', [RoleController::class, 'store']);

            // Users CRUD
            Route::apiResource('users', UserController::class);

            // User Roles mapping
            Route::get('/users/{id}/roles', [UserController::class, 'roles']);
            Route::post('/users/{id}/roles/assign', [UserController::class, 'assignRole']);
            Route::delete('/users/{id}/roles/{roleId}', [UserController::class, 'removeRole']);

            // Audit Logs
            Route::get('/audit-logs', [AuditLogController::class, 'index']);
            Route::get('/audit-logs/{tableName}/{recordId}', [AuditLogController::class, 'show']);
        });
    });
});
