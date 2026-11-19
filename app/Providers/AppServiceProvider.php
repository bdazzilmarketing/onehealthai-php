<?php

namespace App\Providers;

use App\Admin\AdminData;
use App\Admin\AdminUsers;
use App\Auth\AuthConfig;
use App\Auth\AuthService;
use App\Auth\Identity;
use App\Database\Db;
use App\Mail\TemplateMailer;
use App\Mail\UnavailableMailer;
use App\Reports\DeferredSalesReport;
use App\Reports\SalesReportStarter;
use App\Storage\FileStore;
use App\Storage\FileStores;
use App\Storage\SignedLinks;
use App\Submissions\MakeWebhook;
use App\Support\Audit;
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
        $this->app->bind(TemplateMailer::class, UnavailableMailer::class);

        $env = fn (string $name) => config("onehealth.env.{$name}");
        $this->app->singleton(FileStore::class, fn () => FileStores::fromEnv(config('onehealth.env')));
        $this->app->singleton(SignedLinks::class, fn () => new SignedLinks($env('AUTH_SECRET')));
        $this->app->singleton(MakeWebhook::class, fn () => new MakeWebhook($env));
        $this->app->singleton(AdminUsers::class, fn ($app) => new AdminUsers(
            $app->make(Db::class), $app->make(AuthService::class), $app->make(Audit::class), $app->make(TemplateMailer::class), $env,
        ));
        // The file store is resolved on first use, so a missing storage setting only affects uploads.
        $this->app->singleton(AdminData::class, fn ($app) => new AdminData($app->make(Audit::class), fn () => $app->make(FileStore::class), $env));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
