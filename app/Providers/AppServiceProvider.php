<?php

namespace App\Providers;

use App\Database\Db;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
