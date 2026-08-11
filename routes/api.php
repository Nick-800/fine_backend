<?php

declare(strict_types=1);

use App\Http\Controllers\Api\v1\AuditLogController;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\BankHoldController;
use App\Http\Controllers\Api\v1\CashAccountController;
use App\Http\Controllers\Api\v1\ClientController;
use App\Http\Controllers\Api\v1\EmployeeController;
use App\Http\Controllers\Api\v1\EntityController;
use App\Http\Controllers\Api\v1\ExternalEmployerController;
use App\Http\Controllers\Api\v1\FxRateController;
use App\Http\Controllers\Api\v1\GoodsReceiptController;
use App\Http\Controllers\Api\v1\ImportOrderController;
use App\Http\Controllers\Api\v1\InventoryMovementController;
use App\Http\Controllers\Api\v1\LandedCostLineController;
use App\Http\Controllers\Api\v1\OperatingUnitController;
use App\Http\Controllers\Api\v1\PaymentRequestController;
use App\Http\Controllers\Api\v1\RoleController;
use App\Http\Controllers\Api\v1\SupplierController;
use App\Http\Controllers\Api\v1\UnitBlueprintController;
use App\Http\Controllers\Api\v1\UserController;
use App\Http\Controllers\Api\v1\WorkOrderController;
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

            // Unified Entity System
            Route::apiResource('entities', EntityController::class);
            Route::post('/entities/{id}/provision-user', [EntityController::class, 'provisionUser']);

            // Domain Extension Modules
            Route::post('/employees/{id}/split-entity', [EmployeeController::class, 'splitEntity']);
            Route::post('/employees/{id}/relink-entity', [EmployeeController::class, 'relinkEntity']);
            Route::apiResource('employees', EmployeeController::class);

            Route::post('/clients/{id}/split-entity', [ClientController::class, 'splitEntity']);
            Route::post('/clients/{id}/relink-entity', [ClientController::class, 'relinkEntity']);
            Route::apiResource('clients', ClientController::class);

            Route::post('/external-employers/{id}/split-entity', [ExternalEmployerController::class, 'splitEntity']);
            Route::post('/external-employers/{id}/relink-entity', [ExternalEmployerController::class, 'relinkEntity']);
            Route::apiResource('external-employers', ExternalEmployerController::class);

            // Work Orders & Inventory Movements
            Route::post('/work-orders/{id}/complete', [WorkOrderController::class, 'complete']);
            Route::apiResource('work-orders', WorkOrderController::class);
            Route::get('/inventory/stock/{sku}', [InventoryMovementController::class, 'stock']);
            Route::apiResource('inventory-movements', InventoryMovementController::class)->except(['update']);

            // Phase 02: Foreign Procurement, Import Pipeline & Treasury
            Route::apiResource('suppliers', SupplierController::class);
            Route::post('/import-orders/{id}/transition', [ImportOrderController::class, 'transition']);
            Route::apiResource('import-orders', ImportOrderController::class);
            Route::post('/payment-requests/{id}/execute', [PaymentRequestController::class, 'execute']);
            Route::get('/payment-requests', [PaymentRequestController::class, 'index']);
            Route::get('/bank-holds', [BankHoldController::class, 'index']);
            Route::get('/import-orders/{id}/landed-cost-lines', [LandedCostLineController::class, 'index']);
            Route::post('/import-orders/{id}/landed-cost-lines', [LandedCostLineController::class, 'store']);
            Route::post('/import-orders/{id}/landed-cost-lines/{lineId}/confirm', [LandedCostLineController::class, 'confirm']);
            Route::get('/import-orders/{id}/goods-receipts', [GoodsReceiptController::class, 'index']);
            Route::get('/fx-rates', [FxRateController::class, 'index']);
            Route::post('/fx-rates', [FxRateController::class, 'store']);
            Route::get('/cash-accounts', [CashAccountController::class, 'index']);
            Route::post('/cash-accounts', [CashAccountController::class, 'store']);
        });
    });
});
