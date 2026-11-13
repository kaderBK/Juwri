<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Providers;

use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Menu\Console\Commands\AiRunMenuActions;
use Juwri\Modules\Menu\Console\Commands\AuditMenuPlacement;
use Juwri\Modules\Menu\Console\Commands\BackfillMenuThumbs;
use Juwri\Modules\Menu\Console\Commands\MeasureModifierUndercharge;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Menu\Contracts\ItemPricing;
use Juwri\Modules\Menu\Contracts\PromotionRules;
use Juwri\Modules\Menu\Contracts\QuoteItems;
use Juwri\Modules\Menu\Contracts\Recommendations;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Menu\Contracts\StockAlertRequests;
use Juwri\Modules\Menu\Contracts\StorefrontCatalog;
use Juwri\Modules\Menu\Contracts\Wishlists;
use Juwri\Modules\Menu\Jobs\GenerateMenuItemImagesJob;
use Juwri\Modules\Menu\Jobs\NotifyFollowersJob;
use Juwri\Modules\Menu\Jobs\SendStockAlertsJob;
use Juwri\Modules\Menu\Jobs\TranslateMenuJob;
use Juwri\Modules\Menu\Mail\BackInStockMail;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Services\Availability\ScheduledItemPricing;
use Juwri\Modules\Menu\Services\Catalog\NoPromotionRules;
use Juwri\Modules\Menu\Services\Grocery\GroceryCatalogService;
use Juwri\Modules\Menu\Services\QuoteItemCache;
use Juwri\Modules\Menu\Services\Recommendations\RecommendationEngine;
use Juwri\Modules\Menu\Services\Retail\RetailCatalogService;
use Juwri\Modules\Menu\Services\Retail\StockAlerts;
use Juwri\Modules\Menu\Services\Retail\StockState;
use Juwri\Modules\Menu\Services\Retail\Wishlist;
use Juwri\Modules\Menu\Services\Storefront\StorefrontCatalogReader;

/**
 * Menu module provider (P1-08c). ResetSoldOut, ConvertToWebp and PurgeTrashedMenu stay registered by name in
 * app/Console/Kernel.
 */
class MenuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Menu', new MenuStoreData);
        $this->app->bind(\Juwri\Core\Contracts\Stores\StoreCatalogProbe::class, \Juwri\Modules\Menu\Services\StoreMenuProbe::class);
        $this->app->bind(Recommendations::class, RecommendationEngine::class);
        // Load-test follow-up: the items of one cart quote, loaded once (state is per request; see QuoteItemCache).
        $this->app->bind(QuoteItems::class, QuoteItemCache::class);
        // P1-23: items no promotion may touch (the Pharmacy module binds its per-market medicine rule).
        $this->app->singletonIf(PromotionRules::class, NoPromotionRules::class);
        // The scheduled (sale / happy-hour) price for modules that sell without the order API (goods cart + checkout).
        $this->app->bind(ItemPricing::class, ScheduledItemPricing::class);
        $this->app->bind(StorefrontCatalog::class, StorefrontCatalogReader::class); // P3-02
        // P3-08: the retail catalogue (variant matrix, stock states, swatches, size guides), wishlists, back-in-stock.
        $this->app->bind(RetailCatalog::class, RetailCatalogService::class);
        $this->app->bind(Wishlists::class, Wishlist::class);
        // P3-09: grocery — weighed items, pack unit prices, the store's substitution choice
        $this->app->bind(GroceryCatalog::class, GroceryCatalogService::class);
        $this->app->bind(StockAlertRequests::class, StockAlerts::class);
        ModuleRoutes::register('Menu', __DIR__.'/../Routes');
        TenantJobs::classify(tenant: [GenerateMenuItemImagesJob::class, NotifyFollowersJob::class, TranslateMenuJob::class, SendStockAlertsJob::class, BackInStockMail::class]);
    }

    public function boot(): void
    {
        // P3-08 / N5: an owner edit that brings an out-of-stock item or variant back sends its back-in-stock requests.
        Menu::updated(function (Menu $item): void {
            if (! $item->wasChanged(['stock_qty', 'is_sold_out', 'track_inventory'])) {
                return;
            }
            $was = (bool) $item->getOriginal('is_sold_out')
                || ((bool) $item->getOriginal('track_inventory') && $item->getOriginal('stock_qty') !== null && (int) $item->getOriginal('stock_qty') <= 0);
            if ($was && StockState::forItem($item)['state'] !== 'out') {
                StockAlerts::restocked((int) $item->id, null);
            }
        });
        MenuVariant::updated(function (MenuVariant $variant): void {
            if (! $variant->wasChanged(['stock_qty', 'active'])) {
                return;
            }
            $was = ! (bool) $variant->getOriginal('active')
                || ($variant->getOriginal('stock_qty') !== null && (int) $variant->getOriginal('stock_qty') <= 0);
            $item = Menu::query()->find($variant->menu_id);
            if ($was && $item !== null && StockState::forVariant($item, $variant)['state'] !== 'out') {
                StockAlerts::restocked((int) $item->id, (int) $variant->id);
            }
        });

        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([AiRunMenuActions::class, AuditMenuPlacement::class, BackfillMenuThumbs::class, MeasureModifierUndercharge::class]);
        }
    }
}
