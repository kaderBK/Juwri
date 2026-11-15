<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Providers;

use App\Jobs\RunThemeLighthouseGate;
use App\Support\SchedulerAlert;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Contracts\Seo\StoreJsonLd;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Core\Support\TrustedHostSources;
use Juwri\Modules\Bookings\Contracts\BookingPageRenderer;
use Juwri\Modules\Laundry\Contracts\LaundryPageRenderer;
use Juwri\Modules\Storefront\Console\Commands\DemoSeed;
use Juwri\Modules\Storefront\Console\Commands\FolioVariants;
use Juwri\Modules\Storefront\Console\Commands\GoogleOrderingFeeds;
use Juwri\Modules\Storefront\Console\Commands\RecheckDomains;
use Juwri\Modules\Storefront\Console\Commands\ThemeParitySeed;
use Juwri\Modules\Storefront\Console\Commands\ThemePresetThumbs;
use Juwri\Modules\Storefront\Database\Seeders\BladeShowcaseSeeder;
use Juwri\Modules\Storefront\Database\Seeders\BookDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\DemoAccountsSeeder;
use Juwri\Modules\Storefront\Database\Seeders\DemoActivitySeeder;
use Juwri\Modules\Storefront\Database\Seeders\DemoCatalogSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2eAdminSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2eDarkFeatureEnableSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2eDispatchDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2eKdsStationDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2eLaneAFixturesSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2eLaneAJourneysSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2eLaneAStoreSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2ePlatformSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2ePlatformV2Seeder;
use Juwri\Modules\Storefront\Database\Seeders\E2ePosModifierDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\E2eStubGatewaySeeder;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\LiquidShowcaseSeeder;
use Juwri\Modules\Storefront\Database\Seeders\MarketplaceThemeSeeder;
use Juwri\Modules\Storefront\Database\Seeders\StorefrontShowcaseSeeder;
use Juwri\Modules\Storefront\Database\Seeders\StudioStarterThemesSeeder;
use Juwri\Modules\Storefront\Database\Seeders\ThemePackDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Database\Seeders\ThemeStoreCatalogSeeder;
use Juwri\Modules\Storefront\Database\Seeders\VerticalShowcaseSeeder;
use Juwri\Modules\Storefront\Listeners\MergeGuestWishlist;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\Seo\StoreJsonLdBuilder;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Storefront\Services\Storefront\BookStorefront;
use Juwri\Modules\Storefront\Services\Storefront\KitStorefront;
use Juwri\Modules\Storefront\Services\Storefront\LaundryStorefront;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoCredentials;

/**
 * Storefront module provider (P1-20): one store-address resolver per request / job / test application.
 */
class StorefrontServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Storefront', new StorefrontStoreData);
        // P1-12: the store JSON-LD for other modules (Marketplace store page) without a Storefront import.
        $this->app->bind(StoreJsonLd::class, StoreJsonLdBuilder::class);
        ModuleSeeders::register('Storefront', demo: [ThemePackDemoSeeder::class, KitDemoSeeder::class], optional: [DemoAccountsSeeder::class, DemoActivitySeeder::class, DemoCatalogSeeder::class, BladeShowcaseSeeder::class, BookDemoSeeder::class, E2ePlatformSeeder::class,
            // P1-13 13e: the E2E fixture seeders (Community features) that were registered by Platform.
            E2eAdminSeeder::class, E2eDarkFeatureEnableSeeder::class, E2eDispatchDemoSeeder::class, E2eKdsStationDemoSeeder::class, E2eLaneAFixturesSeeder::class, E2ePlatformV2Seeder::class, E2ePosModifierDemoSeeder::class, E2eStubGatewaySeeder::class, E2eLaneAJourneysSeeder::class, E2eLaneAStoreSeeder::class,
            MarketplaceThemeSeeder::class, StorefrontShowcaseSeeder::class, StudioStarterThemesSeeder::class, ThemeShowcaseSeeder::class, ThemeStoreCatalogSeeder::class, LiquidShowcaseSeeder::class,
            // P1-13 13c-R: the vertical showcase (imports Hotel models; was Platform's, then ee Platform's in 13e).
            VerticalShowcaseSeeder::class]);
        ModuleRoutes::register('Storefront', __DIR__.'/../Routes');
        // P3-11: the `book` theme draws the booking flow's pages
        $this->app->bind(BookingPageRenderer::class, BookStorefront::class);
        $this->app->bind(LaundryPageRenderer::class, LaundryStorefront::class);
        $this->app->scoped(StoreAddressResolver::class);
        // J62-1: the owner demo's logins — one instance per run, so every seeder asks for the same secret.
        $this->app->singleton(DemoCredentials::class);
        // P1-08f: platform theme studio job (kept in App\ until its callers move).
        TenantJobs::classify(platform: [RunThemeLighthouseGate::class]);
    }

    public function boot(): void
    {
        // P3-06: the kiosk start screen of a store on a kit theme (the Pos kiosk route asks this slot; empty = the legacy screen)
        Hooks::on('kiosk.start-view', 'storefront', fn (Store $store, Request $request) => app(KitStorefront::class)->kioskStart($store, $request));
        if ($this->app->runningInConsole()) {
            $this->commands([FolioVariants::class, ThemePresetThumbs::class, GoogleOrderingFeeds::class, RecheckDomains::class, DemoSeed::class, ThemeParitySeed::class]);
            // P6-241: lapsed custom domains are released after hosts.release_after_days of failing DNS.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('storefront:domains-recheck')->dailyAt('04:20')->withoutOverlapping(60)
                    ->onFailure(fn () => SchedulerAlert::fail('storefront:domains-recheck'));
            });
        }

        // P3-08: a guest wishlist joins the customer's list at sign-in.
        Event::listen(Login::class, [MergeGuestWishlist::class, 'onLogin']);

        // P1-08a: verified store domains are trusted hosts for Core's external-redirect loop guard.
        TrustedHostSources::add('store_domains', static fn (): iterable => Schema::hasTable('store_domains')
            ? StoreDomain::query()->withoutGlobalScopes()->verified()->pluck('host')
            : []);
    }
}
