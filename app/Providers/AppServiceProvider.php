<?php

namespace App\Providers;

use App\Admin\AdminData;
use App\Admin\AdminUsers;
use App\Ai\Classifier;
use App\Ai\OpenAi;
use App\Ai\QaDiagnostics;
use App\Ai\Quota;
use App\Ai\Reviewer;
use App\Auth\AuthConfig;
use App\Auth\AuthService;
use App\Auth\Identity;
use App\Database\Db;
use App\Mail\TemplateEmails;
use App\Mail\TemplateMailer;
use App\Mail\Transports;
use App\Reports\AutomaticSalesReport;
use App\Reports\SalesReports;
use App\Reports\SalesReportStarter;
use App\Storage\FileStore;
use App\Storage\FileStores;
use App\Storage\SignedLinks;
use App\Submissions\MakeWebhook;
use App\Support\Audit;
use App\Support\CronJobs;
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
        $this->app->bind(SalesReportStarter::class, AutomaticSalesReport::class);

        $env = fn (string $name) => config("onehealth.env.{$name}");
        $this->app->singleton(FileStore::class, fn () => FileStores::fromEnv(config('onehealth.env')));
        $this->app->singleton(SignedLinks::class, fn () => new SignedLinks($env('AUTH_SECRET')));
        // The email transport is resolved on first send, so a missing mail setting only affects sending.
        $this->app->singleton(TemplateMailer::class, fn ($app) => new TemplateEmails(
            $app->make(Db::class), fn () => Transports::fromEnv($env, $app->environment('production')), $env,
        ));
        $this->app->singleton(SalesReports::class, fn ($app) => new SalesReports(
            $app->make(Db::class), $app->make(Audit::class), fn () => $app->make(FileStore::class), $app->make(TemplateMailer::class), $env,
        ));
        $this->app->singleton(CronJobs::class, fn ($app) => new CronJobs($app->make(Db::class), $env));
        $this->app->singleton(MakeWebhook::class, fn () => new MakeWebhook($env));
        $this->app->singleton(AdminUsers::class, fn ($app) => new AdminUsers(
            $app->make(Db::class), $app->make(AuthService::class), $app->make(Audit::class), $app->make(TemplateMailer::class), $env,
        ));
        $this->app->singleton(Quota::class, fn ($app) => new Quota($app->make(Db::class), $env));
        $this->app->singleton(OpenAi::class, fn ($app) => new OpenAi($app->make(Db::class), $env));
        $this->app->singleton(Classifier::class, fn () => new Classifier($env));
        $this->app->singleton(Reviewer::class, fn () => new Reviewer($env));
        $this->app->singleton(QaDiagnostics::class, fn () => new QaDiagnostics($env));
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
