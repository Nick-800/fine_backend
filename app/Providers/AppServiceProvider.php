<?php

namespace App\Providers;

use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
use App\Models\OverheadExpense;
use App\Models\PayrollRun;
use App\Models\ProductionBatch;
use App\Models\Role;
use App\Models\StockAdjustmentRequest;
use App\Models\StockLot;
use App\Models\TankStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Observers\AuditObserver;
use App\Support\CurrentUnitContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentUnitContext::class, function () {
            return new CurrentUnitContext;
        });

        // Fallback autoloader for Database\Seeders and Database\Factories in case
        // an environment is running with an authoritative Composer classmap without
        // having re-dumped the autoloader after a git pull.
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'Database\\Seeders\\')) {
                $relative = substr($class, strlen('Database\\Seeders\\'));
                $file = database_path('seeders/'.str_replace('\\', '/', $relative).'.php');
                if (file_exists($file)) {
                    require_once $file;
                }
            } elseif (str_starts_with($class, 'Database\\Factories\\')) {
                $relative = substr($class, strlen('Database\\Factories\\'));
                $file = database_path('factories/'.str_replace('\\', '/', $relative).'.php');
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(AuditObserver::class);
        OperatingUnit::observe(AuditObserver::class);
        Warehouse::observe(AuditObserver::class);
        Role::observe(AuditObserver::class);

        // Inventory and production. Phase 03 requires the audit log to capture every
        // inventory adjustment, and these carry the material and money movements.
        InventoryItem::observe(AuditObserver::class);
        ItemCategory::observe(AuditObserver::class);
        StockLot::observe(AuditObserver::class);
        TankStock::observe(AuditObserver::class);
        StockAdjustmentRequest::observe(AuditObserver::class);
        ProductionBatch::observe(AuditObserver::class);

        // Phase 08: overhead and fixed assets carry money — every change is
        // audit-logged like the inventory value carriers above.
        OverheadExpense::observe(AuditObserver::class);
        FixedAsset::observe(AuditObserver::class);

        // Phase 09: payroll moves wages — same treatment.
        PayrollRun::observe(AuditObserver::class);
    }
}
