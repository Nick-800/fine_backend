<?php

declare(strict_types=1);

use App\Http\Controllers\Api\v1\AccountController;
use App\Http\Controllers\Api\v1\Admin\ProvisionUserController;
use App\Http\Controllers\Api\v1\AttendanceController;
use App\Http\Controllers\Api\v1\AuditLogController;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\BankHoldController;
use App\Http\Controllers\Api\v1\BomController;
use App\Http\Controllers\Api\v1\CashAccountController;
use App\Http\Controllers\Api\v1\ClientController;
use App\Http\Controllers\Api\v1\ConsumptionReportController;
use App\Http\Controllers\Api\v1\CreditApprovalController;
use App\Http\Controllers\Api\v1\CutterWorkOrderController;
use App\Http\Controllers\Api\v1\DashboardController;
use App\Http\Controllers\Api\v1\EmployeeController;
use App\Http\Controllers\Api\v1\EntityLookupController;
use App\Http\Controllers\Api\v1\ExternalEmployerController;
use App\Http\Controllers\Api\v1\FinancialReportController;
use App\Http\Controllers\Api\v1\FixedAssetController;
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
use App\Http\Controllers\Api\v1\LaborRoleRateController;
use App\Http\Controllers\Api\v1\LandedCostLineController;
use App\Http\Controllers\Api\v1\LeaveRequestController;
use App\Http\Controllers\Api\v1\OperatingUnitController;
use App\Http\Controllers\Api\v1\OverheadAllocationController;
use App\Http\Controllers\Api\v1\OverheadExpenseController;
use App\Http\Controllers\Api\v1\PayableSettlementController;
use App\Http\Controllers\Api\v1\PaymentRequestController;
use App\Http\Controllers\Api\v1\PayrollRunController;
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
            // Entity lookups (used by the domain "select existing entity" picker).
            Route::get('/entities', [EntityLookupController::class, 'index']);

            // Operating Units index (accessible to any authenticated user to view accessible units)
            Route::get('/operating-units', [OperatingUnitController::class, 'index'])->name('operating-units.index');

            // Admin: Owner and Global Admins only
            Route::middleware('require.role:owner')->group(function () {
                Route::apiResource('operating-units', OperatingUnitController::class)->except(['index']);
                Route::get('/unit-blueprints', [UnitBlueprintController::class, 'index']);
                Route::post('/unit-blueprints', [UnitBlueprintController::class, 'store']);
                Route::get('/roles', [RoleController::class, 'index']);
                Route::post('/roles', [RoleController::class, 'store']);
                Route::apiResource('users', UserController::class);
                Route::get('/users/{id}/roles', [UserController::class, 'roles']);
                Route::post('/users/{id}/roles/assign', [UserController::class, 'assignRole']);
                Route::delete('/users/{id}/roles/{roleId}', [UserController::class, 'removeRole']);
                Route::get('/audit-logs', [AuditLogController::class, 'index']);
                Route::get('/audit-logs/{tableName}/{recordId}', [AuditLogController::class, 'show']);
                Route::post('/admin/entities/{id}/provision-user', [ProvisionUserController::class]);
            });

            // Finance: Mutations (Strictly Accounting Manager & Owner)
            Route::middleware('require.role:owner,accounting-manager')->group(function () {
                Route::post('/overhead-expenses', [OverheadExpenseController::class, 'store']);
                Route::post('/overhead-expenses/{id}/allocate', [OverheadExpenseController::class, 'allocate']);
                Route::get('/overhead-allocation-rules', [OverheadExpenseController::class, 'rules']);
                Route::post('/overhead-allocation-rules', [OverheadExpenseController::class, 'storeRule']);
                Route::post('/fixed-assets', [FixedAssetController::class, 'store']);
                Route::post('/fixed-assets/{id}/depreciate', [FixedAssetController::class, 'depreciate']);
                Route::post('/fixed-assets/{id}/dispose', [FixedAssetController::class, 'dispose']);
                Route::post('/fixed-assets/{id}/transition', [FixedAssetController::class, 'transition']);
            });

            // Allocation & landed cost approvals are guarded at the domain level by AllocationPaymentService (asserts manager of unit)
            Route::post('/overhead-allocations/{id}/approve', [OverheadAllocationController::class, 'approve']);
            Route::post('/overhead-allocations/{id}/mark-paid', [OverheadAllocationController::class, 'markPaid']);
            Route::post('/import-orders/{id}/landed-cost-lines/{lineId}/approve', [LandedCostLineController::class, 'approve']);
            Route::post('/import-orders/{id}/landed-cost-lines/{lineId}/mark-paid', [LandedCostLineController::class, 'markPaid']);

            // Manual journal posting is guarded inside JournalEntryController (returns MANUAL_JOURNAL_FORBIDDEN)
            Route::post('/journal-entries', [JournalEntryController::class, 'store']);

            // Financial Ledger & Reports: Ledger queries, statements and reports (scoped by unit)
            Route::middleware('require.role:owner,accounting-manager,unit_manager,manager,inventory-manager,foam-manager,foam-operator,cutter-manager,cutter-operator,furniture-manager,assembler,store-manager,pos-cashier,procurement-manager,treasury-officer')->group(function () {
                Route::get('/accounts', [AccountController::class, 'index']);
                Route::get('/accounts/{id}/ledger', [AccountController::class, 'ledger']);
                Route::get('/journal-entries', [JournalEntryController::class, 'index']);
                Route::get('/journal-entries/for-document/{type}/{documentId}', [JournalEntryController::class, 'forDocument']);
                Route::get('/journal-entries/{id}', [JournalEntryController::class, 'show']);
                Route::get('/reports/trial-balance', [JournalEntryController::class, 'trialBalance']);
                Route::get('/reports/income-statement', [FinancialReportController::class, 'incomeStatement']);
                Route::get('/reports/balance-sheet', [FinancialReportController::class, 'balanceSheet']);
                Route::get('/reports/unit-profitability', [FinancialReportController::class, 'unitProfitability']);
                Route::get('/overhead-expenses', [OverheadExpenseController::class, 'index']);
                Route::get('/fixed-assets', [FixedAssetController::class, 'index']);
                Route::get('/fixed-assets/{id}', [FixedAssetController::class, 'show']);
                Route::get('/fixed-assets/{id}/depreciation-schedule', [FixedAssetController::class, 'depreciationSchedule']);
            });

            // Payable settlement is guarded inside PayableSettlementController (returns SETTLEMENT_FORBIDDEN)
            Route::post('/payable-settlements', [PayableSettlementController::class, 'store']);

            // Treasury & Payables
            Route::middleware('require.role:owner,treasury-officer,accounting-manager,procurement-manager,unit_manager,manager')->group(function () {
                Route::get('/payment-requests', [PaymentRequestController::class, 'all']);
                Route::get('/import-orders/{id}/payment-requests', [PaymentRequestController::class, 'index']);
                Route::post('/import-orders/{id}/payment-requests/{requestId}/process', [PaymentRequestController::class, 'process']);
                Route::post('/payment-requests/{id}/execute', [PaymentRequestController::class, 'execute']);
                Route::get('/bank-holds', [BankHoldController::class, 'index']);
                Route::get('/import-orders/{id}/bank-holds', [BankHoldController::class, 'forOrder']);
                Route::get('/fx-rates', [FxRateController::class, 'index']);
                Route::post('/fx-rates', [FxRateController::class, 'store']);
                Route::get('/payable-settlements', [PayableSettlementController::class, 'index']);
                Route::get('/payable-settlements/outstanding', [PayableSettlementController::class, 'outstanding']);
                Route::get('/cash-accounts', [CashAccountController::class, 'index']);
                Route::post('/cash-accounts', [CashAccountController::class, 'store']);
            });

            // Procurement
            Route::middleware('require.role:owner,procurement-manager,treasury-officer,accounting-manager,unit_manager,manager')->group(function () {
                Route::apiResource('suppliers', SupplierController::class);
                Route::apiResource('import-orders', ImportOrderController::class)->only(['index', 'store', 'show']);
                Route::post('/import-orders/{id}/transition', [ImportOrderController::class, 'transition']);
                Route::get('/import-orders/{id}/landed-cost-lines', [LandedCostLineController::class, 'index']);
                Route::post('/import-orders/{id}/landed-cost-lines', [LandedCostLineController::class, 'store']);
                Route::get('/import-orders/{id}/goods-receipts', [GoodsReceiptController::class, 'index']);
            });

            // HR & Payroll & Employees
            Route::middleware('require.role:owner,hr-manager,accounting-manager,unit_manager,manager,foam-manager,cutter-manager,furniture-manager,store-manager,procurement-manager')->group(function () {
                Route::get('/employees/{id}/attendance', [EmployeeController::class, 'attendance']);
                Route::get('/employees/{id}/labor-logs', [EmployeeController::class, 'laborLogs']);
                Route::get('/employees/{id}/payslips', [EmployeeController::class, 'payslips']);
                Route::apiResource('employees', EmployeeController::class);
                Route::get('/attendance', [AttendanceController::class, 'index']);
                Route::post('/attendance/bulk', [AttendanceController::class, 'bulk']);
                Route::put('/attendance/{id}', [AttendanceController::class, 'update']);
                Route::get('/labor-role-rates', [LaborRoleRateController::class, 'index']);
                Route::get('/labor-role-rates/current', [LaborRoleRateController::class, 'current']);
                Route::post('/labor-role-rates', [LaborRoleRateController::class, 'store']);
                Route::get('/payroll-runs', [PayrollRunController::class, 'index']);
                Route::post('/payroll-runs', [PayrollRunController::class, 'store']);
                Route::post('/payroll-runs/{id}/calculate', [PayrollRunController::class, 'calculate']);
                Route::post('/payroll-runs/{id}/submit', [PayrollRunController::class, 'submit']);
                Route::post('/payroll-runs/{id}/approve', [PayrollRunController::class, 'approve']);
                Route::post('/payroll-runs/{id}/mark-paid', [PayrollRunController::class, 'markPaid']);
                Route::post('/payroll-runs/{id}/post', [PayrollRunController::class, 'post']);
                Route::get('/payroll-runs/{id}/payslips', [PayrollRunController::class, 'payslips']);
                Route::get('/payslips/{id}', [PayrollRunController::class, 'showPayslip']);
                Route::put('/payslips/{id}/deductions', [PayrollRunController::class, 'setDeductions']);
                Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
                Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
                Route::put('/leave-requests/{id}/approve', [LeaveRequestController::class, 'approve']);
                Route::put('/leave-requests/{id}/reject', [LeaveRequestController::class, 'reject']);
                Route::apiResource('external-employers', ExternalEmployerController::class);
            });

            // Foam Manufacturing
            Route::middleware('require.role:owner,foam-manager,foam-operator,unit_manager,manager')->group(function () {
                Route::post('/production-batches/{id}/blocks', [ProductionBatchController::class, 'registerBlocks']);
                Route::post('/production-batches/{id}/transition', [ProductionBatchController::class, 'transition']);
                Route::get('/production-batches/{id}/consumption-report', [ConsumptionReportController::class, 'show']);
                Route::post('/production-batches/{id}/consumption-report', [ConsumptionReportController::class, 'store']);
                Route::apiResource('production-batches', ProductionBatchController::class);
            });

            // Cutter Manufacturing
            Route::middleware('require.role:owner,cutter-manager,cutter-operator,unit_manager,manager')->group(function () {
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
            });

            // Furniture Manufacturing
            Route::middleware('require.role:owner,furniture-manager,assembler,unit_manager,manager')->group(function () {
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
            });

            // Sales, POS & Credit
            Route::middleware('require.role:owner,store-manager,pos-cashier,unit_manager,manager')->group(function () {
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
                Route::get('/pos/daily-close', [PosController::class, 'showDailyClose']);
                Route::post('/pos/daily-close', [PosController::class, 'dailyClose']);
                Route::get('/pos/sales/{id}', [PosController::class, 'show']);
            });

            // Inventory, Stock Lots, Tanks & Work Orders
            Route::middleware('require.role:owner,foam-manager,foam-operator,cutter-manager,cutter-operator,furniture-manager,assembler,store-manager,pos-cashier,procurement-manager,treasury-officer,accounting-manager,unit_manager,manager,inventory-manager')->group(function () {
                Route::apiResource('item-categories', ItemCategoryController::class);
                Route::get('/attribute-definitions', [InventoryAttributeController::class, 'index']);
                Route::post('/attribute-definitions', [InventoryAttributeController::class, 'store']);
                Route::get('/item-categories/{categoryId}/attribute-definitions', [InventoryAttributeController::class, 'indexForCategory']);
                Route::post('/item-categories/{categoryId}/attribute-definitions', [InventoryAttributeController::class, 'storeForCategory']);
                Route::put('/attribute-definitions/{id}', [InventoryAttributeController::class, 'update']);
                Route::delete('/attribute-definitions/{id}', [InventoryAttributeController::class, 'destroy']);
                Route::apiResource('inventory-items', InventoryItemController::class);
                Route::get('/stock-lots/available-for-cutting', [StockLotController::class, 'availableForCutting']);
                Route::get('/stock-lots/available-foam-blocks', [StockLotController::class, 'availableFoamBlocks']);
                Route::post('/stock-lots/intake', [StockLotController::class, 'intake']);
                Route::post('/stock-lots/{id}/process-cut-remnant', [StockLotController::class, 'processCutRemnant']);
                Route::apiResource('stock-lots', StockLotController::class)->except(['destroy']);
                Route::get('/tank-stocks', [TankStockController::class, 'index']);
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
                Route::apiResource('work-orders', WorkOrderController::class);
                Route::post('/work-orders/{id}/complete', [WorkOrderController::class, 'complete']);
                Route::get('/inventory-movements', [InventoryMovementController::class, 'index']);
                Route::post('/inventory-movements', [InventoryMovementController::class, 'store']);
                Route::get('/inventory-movements/for-document/{type}/{documentId}', [InventoryMovementController::class, 'forDocument']);
                Route::get('/inventory-movements/{id}', [InventoryMovementController::class, 'show']);
                Route::get('/inventory/stock/{sku}', [InventoryMovementController::class, 'stock']);
                Route::get('/inventory/ledger/{warehouseId}', [InventoryMovementController::class, 'ledger']);
                Route::get('/internal-restock-requests', [InternalRestockController::class, 'index']);
                Route::post('/internal-restock-requests', [InternalRestockController::class, 'store']);
                Route::put('/internal-restock-requests/{id}/approve', [InternalRestockController::class, 'approve']);
                Route::put('/internal-restock-requests/{id}/reject', [InternalRestockController::class, 'reject']);
                Route::post('/internal-restock-requests/{id}/fulfill', [InternalRestockController::class, 'fulfill']);
            });

            // Clients
            Route::middleware('require.role:owner,store-manager,pos-cashier,procurement-manager,accounting-manager,unit_manager,manager')->group(function () {
                Route::apiResource('clients', ClientController::class);
            });

            // Owner Dashboard (guarded internally by ResolvesReportScope)
            Route::get('/dashboard/kpis', [DashboardController::class, 'kpis']);
            Route::get('/dashboard/unit-comparison', [DashboardController::class, 'unitComparison']);
            Route::get('/dashboard/inventory-rollup', [DashboardController::class, 'inventoryRollup']);
            Route::get('/dashboard/operational-pipeline', [DashboardController::class, 'operationalPipeline']);
            Route::get('/dashboard/pending-approvals', [DashboardController::class, 'pendingApprovals']);
            Route::get('/dashboard/my-allocation-approvals', [DashboardController::class, 'myAllocationApprovals']);
        });
    });
});
