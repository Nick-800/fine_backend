<?php

namespace App\Providers;

use App\Models\InventoryItem;
use App\Models\ItemCategory;
use App\Models\OperatingUnit;
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
    }
}
