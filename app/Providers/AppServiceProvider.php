<?php

namespace App\Providers;

use App\Auth\AuthConfig;
use App\Auth\AuthService;
use App\Auth\Identity;
use App\Database\Db;
use App\Reports\DeferredSalesReport;
use App\Reports\SalesReportStarter;
use Illuminate\Support\Facades\DB as Connections;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Db::class, fn () => new Db(fn () => Connections::connection('pgsql')->getPdo()));
        $this->app->singleton(AuthConfig::class, fn () => AuthConfig::fromEnv(config('onehealth.env')));
        $this->app->singleton(AuthService::class);
        $this->app->singleton(Identity::class);
        $this->app->bind(SalesReportStarter::class, DeferredSalesReport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
