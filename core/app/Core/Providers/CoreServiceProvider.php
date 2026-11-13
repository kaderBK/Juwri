<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Providers;

use App\Jobs\DeleteUsersJob;
use Cviebrock\EloquentSluggable\SluggableObserver;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Console\Commands\AcceptPaymentPageBaselineCommand;
use Juwri\Core\Console\Commands\BreachNoticeCommand;
use Juwri\Core\Console\Commands\CheckPaymentPagesCommand;
use Juwri\Core\Console\Commands\CleanupStoreBackups;
use Juwri\Core\Console\Commands\ConnectJuwriServicesCommand;
use Juwri\Core\Console\Commands\GenerateUserFullExportPackage;
use Juwri\Core\Console\Commands\GenerateUserHungerinoExportPackage;
use Juwri\Core\Console\Commands\GenerateUserTargetExportPackage;
use Juwri\Core\Console\Commands\JuwriServicesUpdateCommand;
use Juwri\Core\Console\Commands\LangScan;
use Juwri\Core\Console\Commands\LangStatus;
use Juwri\Core\Console\Commands\LegalCheck;
use Juwri\Core\Console\Commands\NotifyTermsChangeCommand;
use Juwri\Core\Console\Commands\PaymentScriptsCommand;
use Juwri\Core\Console\Commands\PlatformDbBackup;
use Juwri\Core\Console\Commands\PlatformEnvHealth;
use Juwri\Core\Console\Commands\PlatformMediaBackup;
use Juwri\Core\Console\Commands\PruneAbuseEvents;
use Juwri\Core\Console\Commands\PruneDatabaseStoresCommand;
use Juwri\Core\Console\Commands\PruneInactiveMarketingContactsCommand;
use Juwri\Core\Console\Commands\PruneOwnerScreenViewsCommand;
use Juwri\Core\Console\Commands\PruneSecurityLogsCommand;
use Juwri\Core\Console\Commands\PurgeDeletedAccountsCommand;
use Juwri\Core\Console\Commands\RefreshExchangeRates;
use Juwri\Core\Console\Commands\RetryAccountStaffCloseCommand;
use Juwri\Core\Console\Commands\RunScheduledBackups;
use Juwri\Core\Console\Commands\StoreDataDeleteCommand;
use Juwri\Core\Console\Commands\StoreDataExportCommand;
use Juwri\Core\Console\Commands\StoreDataImportCommand;
use Juwri\Core\Console\Commands\StoreRestoreBundleCommand;
use Juwri\Core\Console\Commands\SupplyChainAudit;
use Juwri\Core\Console\CoreSchedule;
use Juwri\Core\Console\TenancyRlsCommand;
use Juwri\Core\Console\TenancyTablesCommand;
use Juwri\Core\Contracts\Tenancy\TenantConnectionResolver;
use Juwri\Core\Database\SchemaMacros;
use Juwri\Core\Database\Seeders\CuisineSeeder;
use Juwri\Core\Editions\CommunityDefaults;
use Juwri\Core\Editions\EditionLoader;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Features\PlatformFeatures;
use Juwri\Core\Http\Middleware\PassThrough;
use Juwri\Core\Jobs\BuildAccountExportJob;
use Juwri\Core\Jobs\GenerateStoreBackupJob;
use Juwri\Core\Mail\BackupReadyMail;
use Juwri\Core\Mail\SupportMessage;
use Juwri\Core\Pci\PaymentPageMonitor;
use Juwri\Core\Policies\TenantPolicy;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Services\Tenancy\CoreStoreData;
use Juwri\Core\Services\Tenancy\GlobalSlugService;
use Juwri\Core\Services\Tenancy\SharedDatabaseConnectionResolver;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantConnections;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Services\Tenancy\TenantDatabaseSession;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\LegacyClassAliases;
use Juwri\Core\Support\ModuleFactories;
use Juwri\Core\Support\ModuleMap;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Core\Support\ModuleSeeders;

/**
 * juwri Core module provider (P1-03): schema convention macros, the tenant context (P1-04) and the per-module
 * migration folders (app/Core/Database/Migrations + app/Modules/<Module>/Database/Migrations,
 * listed in the module map, {@see ModuleMap}, with the edition packages' module folders). Framework tables stay in database/migrations.
 */
class CoreServiceProvider extends ServiceProvider
{
    /** Artisan commands that run the seeders (without the edition store/branch limits). */
    public const SEEDING_COMMANDS = ['db:seed', 'migrate:fresh', 'migrate:refresh', 'migrate'];

    public function register(): void
    {
        StoreDataRegistry::register('Core', new CoreStoreData);
        SchemaMacros::register();
        // P1-08: models moved out of App\Models keep their old class name as the morph type,
        // so polymorphic *_type columns keep their stored values.
        Relation::morphMap(LegacyClassAliases::morphMap());
        // P1-11: factories live with their module (Database/Factories); see ModuleFactories.
        Factory::guessFactoryNamesUsing(static function (string $model): string {
            $old = LegacyClassAliases::oldNameOf($model) ?? $model;

            return ModuleFactories::forModel($model) ?? ModuleFactories::forModel($old) ?? Factory::$namespace.class_basename($old).'Factory';
        });
        // P1-04 slice 5: one tenant context per request / job / test application.
        $this->app->scoped(TenantContext::class);
        // P1-13: instance-level backup jobs/mails (were classified by the Platform module).
        TenantJobs::classify(platform: [SupportMessage::class, DeleteUsersJob::class], tenant: [GenerateStoreBackupJob::class, BackupReadyMail::class, BuildAccountExportJob::class]);
        // P1-04 slice 7 (J29-3): one shared database by default; an edition or Cloud rebinds for dedicated (silo) databases.
        $this->app->singletonIf(TenantConnectionResolver::class, SharedDatabaseConnectionResolver::class);
        // P1-13: Community defaults of the edition extension points (ee module providers bind their own).
        CommunityDefaults::register($this->app);
        // BL-043: the Sluggable observer checks existing slugs across every account (tenant scope + RLS lifted).
        $this->app->singleton(SluggableObserver::class, fn ($app) => new SluggableObserver(new GlobalSlugService, $app['events']));
        // P1-13 13e: instance-ops schedule entries (restore drill, supply-chain audit, abuse scan; were Platform's).
        CoreSchedule::register();
        // P1-13 13d follow-up: the cuisine taxonomy is Community reference data (store settings, storefront JSON-LD).
        ModuleSeeders::register('Core', default: [CuisineSeeder::class]);
        // P1-13: Core's own route slots (instance ops moved out of Platform).
        ModuleRoutes::register('Core', __DIR__.'/../Routes');
        // P1-13 13b/13i: installed edition packages (found through Composer; each checks its own licence).
        EditionLoader::register($this->app);
    }

    public function boot(): void
    {
        // P1-13: Community default of an ee route alias used on Community routes (Reseller re-points it).
        if (! array_key_exists('reseller.suspension', $this->app['router']->getMiddleware())) {
            $this->app['router']->aliasMiddleware('reseller.suspension', PassThrough::class);
        }
        $this->loadMigrationsFrom(self::migrationPaths());
        PlatformFeatures::register(); // P1-07: the 86 admin_allow_* switches as Pennant features
        // P1-04 slice 5: account-level authorization for any tenant model (`@can('tenant.access', $model)`).
        Gate::define('tenant.access', [TenantPolicy::class, 'access']);
        // P1-04 slice 6: PostgreSQL RLS session settings follow the tenant context before every query.
        TenantDatabaseSession::register($this->app['db'], $this->app['events']);
        // P1-04 slice 7: queued jobs carry and restore the tenant; tenant models ask the connection resolver.
        TenantJobs::register($this->app['events']);
        TenantConnections::use($this->app->make(TenantConnectionResolver::class));
        if ($this->app->runningInConsole()) {
            // Edition limits: the product's demo/fixture seeders (db:seed, migrate --seed) are install-time data, not
            // owner growth — they run without the store/branch limits (StoreLimitGuard::seeding).
            $this->app['events']->listen(CommandStarting::class, function (CommandStarting $e): void {
                if (in_array($e->command, self::SEEDING_COMMANDS, true)) {
                    StoreLimitGuard::seeding(true);
                }
            });
            $this->app['events']->listen(CommandFinished::class, function (CommandFinished $e): void {
                if (in_array($e->command, self::SEEDING_COMMANDS, true)) {
                    StoreLimitGuard::seeding(false);
                }
            });
            // P1-08a: Core commands (app/Console/Kernel only auto-loads app/Console/Commands).
            $this->commands([TenancyRlsCommand::class, TenancyTablesCommand::class, LangScan::class, LangStatus::class, PurgeDeletedAccountsCommand::class, RetryAccountStaffCloseCommand::class, PruneSecurityLogsCommand::class, PruneInactiveMarketingContactsCommand::class, RefreshExchangeRates::class,
                BreachNoticeCommand::class, // P1-23
                StoreDataExportCommand::class, StoreDataDeleteCommand::class, StoreDataImportCommand::class, StoreRestoreBundleCommand::class,
                // P1-13: instance ops + backups of the one account (were registered by the Platform module).
                CleanupStoreBackups::class, PlatformDbBackup::class, PlatformEnvHealth::class, PlatformMediaBackup::class, RunScheduledBackups::class,
                PruneAbuseEvents::class, PruneOwnerScreenViewsCommand::class, PruneDatabaseStoresCommand::class, // P6-156
                // P1-13 13e: instance ops that were registered by the Platform module.
                GenerateUserFullExportPackage::class, GenerateUserHungerinoExportPackage::class, GenerateUserTargetExportPackage::class,
                SupplyChainAudit::class,
                // P1-23: release gate for the website legal pages.
                LegalCheck::class,
                // P1-23: send hook for the Terms-changed notice.
                NotifyTermsChangeCommand::class,
                // PCI DSS 4.0 6.4.3 / 11.6.1: payment-page script inventory + change detection.
                PaymentScriptsCommand::class, CheckPaymentPagesCommand::class,
                AcceptPaymentPageBaselineCommand::class,
                // Owner 2026-10-05: connect to the juwri-hosted services (the only registration path; no phone-home).
                ConnectJuwriServicesCommand::class, JuwriServicesUpdateCommand::class]);
            // PCI DSS 4.0 11.6.1: weekly by default (payment_scripts.monitor.cron); a change or an error fails the run → alert.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                if (config('payment_scripts.monitor.enabled', true)) {
                    $schedule->command('pci:check-payment-pages')->cron((string) config('payment_scripts.monitor.cron', '30 4 * * 1'))
                        ->withoutOverlapping(60)->onFailure(fn () => AlertDispatcher::send(PaymentPageMonitor::ALERT_KIND, 'pci:check-payment-pages reported changes or errors — see payment_page_checks'));
                }
            });
        }
    }

    /** @return list<string> */
    public static function migrationPaths(): array
    {
        $paths = [];
        $parts = ModuleMap::parts();
        foreach (ModuleMap::all() as $name => $module) {
            $dir = ModuleMap::dir($module['path']).'/Database/Migrations';
            if (is_dir($dir)) {
                $paths[] = $dir;
            }
            // P1-13 13h: a split module's edition half keeps its own tables in the edition package (like the package's
            // own modules above: migrated whenever the package is installed, absent without it).
            foreach ($module['edition'] === 'split' ? $parts[$name] ?? [] : [] as $part) {
                $partDir = ModuleMap::dir($part['path']).'/Database/Migrations';
                if (is_dir($partDir)) {
                    $paths[] = $partDir;
                }
            }
        }

        return $paths;
    }
}
