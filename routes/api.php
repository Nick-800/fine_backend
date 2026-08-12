<?php

declare(strict_types=1);

use App\Http\Controllers\Api\v1\AuditLogController;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\BankHoldController;
use App\Http\Controllers\Api\v1\BomController;
use App\Http\Controllers\Api\v1\CashAccountController;
use App\Http\Controllers\Api\v1\ClientController;
use App\Http\Controllers\Api\v1\ConsumptionReportController;
use App\Http\Controllers\Api\v1\CreditApprovalController;
use App\Http\Controllers\Api\v1\CutterWorkOrderController;
use App\Http\Controllers\Api\v1\EmployeeController;
use App\Http\Controllers\Api\v1\EntityController;
use App\Http\Controllers\Api\v1\ExternalEmployerController;
use App\Http\Controllers\Api\v1\FinancialReportController;
use App\Http\Controllers\Api\v1\FxRateController;
use App\Http\Controllers\Api\v1\GoodsReceiptController;
use App\Http\Controllers\Api\v1\ImportOrderController;
use App\Http\Controllers\Api\v1\InternalRestockController;
use App\Http\Controllers\Api\v1\InventoryAttributeController;
use App\Http\Controllers\Api\v1\InventoryItemController;
use App\Http\Controllers\Api\v1\InventoryMovementController;
use App\Http\Controllers\Api\v1\InventoryValuationController;
use App\Http\Controllers\Api\v1\ItemCategoryController;
use App\Http\Controllers\Api\v1\JournalEntryController;
use App\Http\Controllers\Api\v1\LandedCostLineController;
use App\Http\Controllers\Api\v1\OperatingUnitController;
use App\Http\Controllers\Api\v1\PaymentRequestController;
use App\Http\Controllers\Api\v1\PosController;
use App\Http\Controllers\Api\v1\ProductController;
use App\Http\Controllers\Api\v1\ProductionBatchController;
use App\Http\Controllers\Api\v1\ProductionOrderController;
use App\Http\Controllers\Api\v1\RoleController;
use App\Http\Controllers\Api\v1\SalesOrderController;
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
            // Stock movements are the immutable inventory audit trail (INV-06):
            // readable and appendable, never edited or deleted.
            Route::get('/inventory-movements', [InventoryMovementController::class, 'index']);
            Route::post('/inventory-movements', [InventoryMovementController::class, 'store']);
            Route::get('/inventory-movements/for-document/{type}/{documentId}', [InventoryMovementController::class, 'forDocument']);
            Route::get('/inventory-movements/{id}', [InventoryMovementController::class, 'show']);
            Route::get('/inventory/stock/{sku}', [InventoryMovementController::class, 'stock']);
            Route::get('/inventory/ledger/{warehouseId}', [InventoryMovementController::class, 'ledger']);

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
            Route::post('/import-orders/{id}/transition', [ImportOrderController::class, 'transition']);
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
            // Balanced refill sourced from a real lot; /refill remains as the
            // unsourced adjustment path for opening balances and corrections.
            Route::post('/tank-stocks/refill-from-lot', [TankStockController::class, 'refillFromLot']);
            Route::post('/tank-stocks/refill', [TankStockController::class, 'refill']);
            Route::get('/tank-stocks/{id}', [TankStockController::class, 'show']);
            Route::get('/stock-adjustment-requests', [StockAdjustmentRequestController::class, 'index']);
            Route::post('/stock-adjustment-requests', [StockAdjustmentRequestController::class, 'store']);
            Route::post('/stock-adjustment-requests/{id}/approve', [StockAdjustmentRequestController::class, 'approve']);
            Route::post('/stock-adjustment-requests/{id}/reject', [StockAdjustmentRequestController::class, 'reject']);
            Route::get('/inventory/valuation', [InventoryValuationController::class, 'index']);
            Route::get('/inventory/valuation/rollup', [InventoryValuationController::class, 'rollup']);

            Route::apiResource('warehouses', WarehouseController::class);

            // Phase 05: Cutter Manufacturing
            Route::get('/cutter-work-orders', [CutterWorkOrderController::class, 'index']);
            Route::post('/cutter-work-orders', [CutterWorkOrderController::class, 'store']);
            Route::get('/cutter-work-orders/{id}', [CutterWorkOrderController::class, 'show']);
            Route::post('/cutter-work-orders/{id}/transition', [CutterWorkOrderController::class, 'transition']);
            Route::post('/cutter-work-orders/{id}/lines', [CutterWorkOrderController::class, 'storeLine']);
            Route::post('/cutter-work-orders/{id}/weigh-in', [CutterWorkOrderController::class, 'recordWeighIn']);
            Route::get('/cutter-work-orders/{id}/byproduct-yields', [CutterWorkOrderController::class, 'byproductYields']);
            Route::put('/cutter-work-order-lines/{lineId}/assign-template', [CutterWorkOrderController::class, 'assignTemplate']);
            Route::get('/cutter-work-order-lines/{lineId}/available-blocks', [CutterWorkOrderController::class, 'availableBlocks']);
            Route::post('/cutter-work-order-lines/{lineId}/select-block', [CutterWorkOrderController::class, 'selectBlock']);

            // Phase 06: Furniture Manufacturing
            Route::apiResource('products', ProductController::class);
            Route::get('/products/{id}/boms', [ProductController::class, 'boms']);
            Route::post('/boms', [BomController::class, 'store']);
            Route::get('/boms/{id}', [BomController::class, 'show']);
            Route::post('/boms/{id}/activate', [BomController::class, 'activate']);
            Route::post('/boms/{id}/clone', [BomController::class, 'clone']);
            Route::get('/boms/{id}/price-preview', [BomController::class, 'pricePreview']);
            Route::post('/boms/{id}/component-lines', [BomController::class, 'storeComponentLine']);
            Route::delete('/boms/{id}/component-lines/{lineId}', [BomController::class, 'destroyComponentLine']);
            Route::post('/boms/{id}/labor-requirements', [BomController::class, 'storeLaborRequirement']);
            Route::delete('/boms/{id}/labor-requirements/{reqId}', [BomController::class, 'destroyLaborRequirement']);
            Route::get('/production-orders', [ProductionOrderController::class, 'index']);
            Route::post('/production-orders', [ProductionOrderController::class, 'store']);
            Route::get('/production-orders/{id}', [ProductionOrderController::class, 'show']);
            Route::post('/production-orders/{id}/transition', [ProductionOrderController::class, 'transition']);
            Route::get('/production-orders/{id}/labor-logs', [ProductionOrderController::class, 'laborLogs']);
            Route::post('/production-orders/{id}/labor-logs', [ProductionOrderController::class, 'storeLaborLog']);

            // Phase 07: Sales, POS & Credit. Actions decide outcomes — there is
            // no free-target transition, so the credit gate cannot be bypassed.
            Route::get('/sales-orders', [SalesOrderController::class, 'index']);
            Route::post('/sales-orders', [SalesOrderController::class, 'store']);
            Route::get('/sales-orders/{id}', [SalesOrderController::class, 'show']);
            Route::post('/sales-orders/{id}/submit', [SalesOrderController::class, 'submit']);
            Route::post('/sales-orders/{id}/fulfill', [SalesOrderController::class, 'fulfill']);
            Route::post('/sales-orders/{id}/record-payment', [SalesOrderController::class, 'recordPayment']);
            Route::post('/sales-orders/{id}/complete', [SalesOrderController::class, 'complete']);
            Route::get('/sales-orders/{id}/invoice', [SalesOrderController::class, 'invoice']);
            Route::get('/credit-approval-requests', [CreditApprovalController::class, 'index']);
            Route::put('/credit-approval-requests/{id}/approve', [CreditApprovalController::class, 'approve']);
            Route::put('/credit-approval-requests/{id}/reject', [CreditApprovalController::class, 'reject']);
            Route::post('/pos/sales', [PosController::class, 'checkout']);
            Route::get('/pos/daily-report', [PosController::class, 'dailyReport']);
            Route::get('/pos/sales/{id}', [PosController::class, 'show']);
            Route::get('/internal-restock-requests', [InternalRestockController::class, 'index']);
            Route::post('/internal-restock-requests', [InternalRestockController::class, 'store']);
            Route::put('/internal-restock-requests/{id}/approve', [InternalRestockController::class, 'approve']);
            Route::put('/internal-restock-requests/{id}/reject', [InternalRestockController::class, 'reject']);
            Route::post('/internal-restock-requests/{id}/fulfill', [InternalRestockController::class, 'fulfill']);

            // Phase 08 (slice): double-entry ledger. Entries are posted by the
            // modules that cause them, never created directly here.
            Route::get('/journal-entries', [JournalEntryController::class, 'index']);
            Route::post('/journal-entries', [JournalEntryController::class, 'store']);
            Route::get('/journal-entries/for-document/{type}/{documentId}', [JournalEntryController::class, 'forDocument']);
            Route::get('/journal-entries/{id}', [JournalEntryController::class, 'show']);
            Route::get('/reports/trial-balance', [JournalEntryController::class, 'trialBalance']);
            Route::get('/reports/income-statement', [FinancialReportController::class, 'incomeStatement']);
            Route::get('/reports/balance-sheet', [FinancialReportController::class, 'balanceSheet']);
            Route::get('/reports/unit-profitability', [FinancialReportController::class, 'unitProfitability']);

            // Phase 04: Foam Manufacturing — Production Batches & Block Identity
            Route::post('/production-batches/{id}/blocks', [ProductionBatchController::class, 'registerBlocks']);
            Route::post('/production-batches/{id}/transition', [ProductionBatchController::class, 'transition']);
            Route::get('/production-batches/{id}/consumption-report', [ConsumptionReportController::class, 'show']);
            Route::post('/production-batches/{id}/consumption-report', [ConsumptionReportController::class, 'store']);
            Route::apiResource('production-batches', ProductionBatchController::class);
        });
    });
});
