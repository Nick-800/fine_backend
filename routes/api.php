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
use App\Http\Controllers\Api\v1\InventoryAttributeController;
use App\Http\Controllers\Api\v1\InventoryItemController;
use App\Http\Controllers\Api\v1\InventoryMovementController;
use App\Http\Controllers\Api\v1\InventoryValuationController;
use App\Http\Controllers\Api\v1\ItemCategoryController;
use App\Http\Controllers\Api\v1\LandedCostLineController;
use App\Http\Controllers\Api\v1\OperatingUnitController;
use App\Http\Controllers\Api\v1\PaymentRequestController;
use App\Http\Controllers\Api\v1\ProductionBatchController;
use App\Http\Controllers\Api\v1\RoleController;
use App\Http\Controllers\Api\v1\StockAdjustmentRequestController;
use App\Http\Controllers\Api\v1\StockLotController;
use App\Http\Controllers\Api\v1\SupplierController;
use App\Http\Controllers\Api\v1\TankStockController;
use App\Http\Controllers\Api\v1\UnitBlueprintController;
use App\Http\Controllers\Api\v1\UserController;
use App\Http\Controllers\Api\v1\WarehouseController;
use App\Http\Controllers\Api\v1\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public guest routes
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Routes requiring authentication
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

            // Production & Work Orders
            Route::apiResource('work-orders', WorkOrderController::class);
            Route::post('/work-orders/{id}/complete', [WorkOrderController::class, 'complete']);
            Route::get('/inventory-movements', [InventoryMovementController::class, 'index']);
            Route::get('/inventory/stock/{sku}', [InventoryMovementController::class, 'stock']);

            // Clients CRUD
            Route::post('/clients/{id}/split-entity', [ClientController::class, 'splitEntity']);
            Route::post('/clients/{id}/relink-entity', [ClientController::class, 'relinkEntity']);
            Route::apiResource('clients', ClientController::class);

            // Entities CRUD
            Route::post('/entities/{id}/provision-user', [EntityController::class, 'provisionUser']);
            Route::apiResource('entities', EntityController::class);

            // Employees CRUD
            Route::post('/employees/{id}/split-entity', [EmployeeController::class, 'splitEntity']);
            Route::post('/employees/{id}/relink-entity', [EmployeeController::class, 'relinkEntity']);
            Route::apiResource('employees', EmployeeController::class);

            // External Employers CRUD
            Route::post('/external-employers/{id}/split-entity', [ExternalEmployerController::class, 'splitEntity']);
            Route::post('/external-employers/{id}/relink-entity', [ExternalEmployerController::class, 'relinkEntity']);
            Route::apiResource('external-employers', ExternalEmployerController::class);

            // Phase 02: Procurement System
            Route::apiResource('suppliers', SupplierController::class);
            Route::apiResource('import-orders', ImportOrderController::class);
            Route::post('/import-orders/{id}/status', [ImportOrderController::class, 'updateStatus']);
            Route::get('/import-orders/{id}/payment-requests', [PaymentRequestController::class, 'index']);
            Route::post('/import-orders/{id}/payment-requests', [PaymentRequestController::class, 'store']);
            Route::post('/import-orders/{id}/payment-requests/{requestId}/process', [PaymentRequestController::class, 'process']);
            Route::get('/import-orders/{id}/bank-holds', [BankHoldController::class, 'index']);
            Route::post('/import-orders/{id}/bank-holds', [BankHoldController::class, 'store']);
            Route::post('/import-orders/{id}/bank-holds/{holdId}/release', [BankHoldController::class, 'release']);
            Route::get('/import-orders/{id}/landed-cost-lines', [LandedCostLineController::class, 'index']);
            Route::post('/import-orders/{id}/landed-cost-lines', [LandedCostLineController::class, 'store']);
            Route::post('/import-orders/{id}/landed-cost-lines/{lineId}/confirm', [LandedCostLineController::class, 'confirm']);
            Route::get('/import-orders/{id}/goods-receipts', [GoodsReceiptController::class, 'index']);
            Route::get('/fx-rates', [FxRateController::class, 'index']);
            Route::post('/fx-rates', [FxRateController::class, 'store']);
            Route::get('/cash-accounts', [CashAccountController::class, 'index']);
            Route::post('/cash-accounts', [CashAccountController::class, 'store']);

            // Phase 03: Inventory Management System & Dynamic Attributes
            Route::apiResource('item-categories', ItemCategoryController::class);
            Route::get('/attribute-definitions', [InventoryAttributeController::class, 'index']);
            Route::post('/attribute-definitions', [InventoryAttributeController::class, 'store']);
            Route::get('/item-categories/{categoryId}/attribute-definitions', [InventoryAttributeController::class, 'indexForCategory']);
            Route::post('/item-categories/{categoryId}/attribute-definitions', [InventoryAttributeController::class, 'storeForCategory']);
            Route::put('/attribute-definitions/{id}', [InventoryAttributeController::class, 'update']);
            Route::delete('/attribute-definitions/{id}', [InventoryAttributeController::class, 'destroy']);

            Route::apiResource('inventory-items', InventoryItemController::class);
            Route::get('/stock-lots/available-for-cutting', [StockLotController::class, 'availableForCutting']);
            Route::post('/stock-lots/{id}/process-cut-remnant', [StockLotController::class, 'processCutRemnant']);
            Route::apiResource('stock-lots', StockLotController::class);
            Route::get('/tank-stocks', [TankStockController::class, 'index']);
            Route::post('/tank-stocks/refill', [TankStockController::class, 'refill']);
            Route::get('/stock-adjustment-requests', [StockAdjustmentRequestController::class, 'index']);
            Route::post('/stock-adjustment-requests', [StockAdjustmentRequestController::class, 'store']);
            Route::post('/stock-adjustment-requests/{id}/approve', [StockAdjustmentRequestController::class, 'approve']);
            Route::post('/stock-adjustment-requests/{id}/reject', [StockAdjustmentRequestController::class, 'reject']);
            Route::get('/inventory/valuation', [InventoryValuationController::class, 'index']);
            Route::get('/inventory/valuation/rollup', [InventoryValuationController::class, 'rollup']);

            Route::get('/warehouses', [WarehouseController::class, 'index']);

            // Phase 04: Foam Manufacturing — Production Batches & Block Identity
            Route::post('/production-batches/{id}/blocks', [ProductionBatchController::class, 'registerBlocks']);
            Route::apiResource('production-batches', ProductionBatchController::class);
        });
    });
});
