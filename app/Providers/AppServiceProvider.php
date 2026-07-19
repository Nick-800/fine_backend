<?php

namespace App\Providers;

use App\Models\OperatingUnit;
use App\Models\Role;
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
    }
}
