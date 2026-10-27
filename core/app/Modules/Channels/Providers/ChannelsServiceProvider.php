<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Providers;

use App\Models\Order;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Channels\Console\Commands\ValidateAggregatorPayload;
use Juwri\Modules\Channels\Contracts\PartnerTransport;
use Juwri\Modules\Channels\Jobs\ProcessHungerStationOrderJob;
use Juwri\Modules\Channels\Jobs\PushPosOrderJob;
use Juwri\Modules\Channels\Models\PosBridgeConnection;
use Juwri\Modules\Channels\Services\Aggregators\AggregatorAvailabilityPusher;
use Juwri\Modules\Channels\Services\Aggregators\AggregatorStatusPusher;
use Juwri\Modules\Channels\Services\Aggregators\DeliverectProvider;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaAggregatorSync;
use Juwri\Modules\Channels\Services\Transport\DirectPartnerTransport;
use Juwri\Modules\Channels\Services\Transport\RelayPartnerTransport;

/**
 * Channels module provider (P1-08e).
 */
class ChannelsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PARITY-RUN T7: tell the aggregator that owns an order about its status changes.
        Hooks::on('order.status-changed.channels', 'aggregators', fn (Order $order) => AggregatorStatusPusher::onOrderStatusChanged($order));
        // D7/D8: "Ordered via <channel>" names of aggregator order sources.
        Hooks::on('order.channel-label', 'aggregators', fn (string $source) => DeliverectProvider::CHANNEL_NAMES[$source] ?? null);
        // P1-13 13c-R: how partner-credential connectors reach their partner (Community: direct; a Cloud relay binds its own).
        // Self-hosted: CHANNELS_PARTNER_TRANSPORT=juwri_relay sends them through the juwri Cloud relay (hosted.gate:relay).
        $this->app->singletonIf(PartnerTransport::class, fn ($app) => config('hosted_services.partner_transport') === 'juwri_relay'
            ? $app->make(RelayPartnerTransport::class) : new DirectPartnerTransport);
        $this->app->bindIf(\Juwri\Modules\Channels\Contracts\PartnerClients::class, \Juwri\Modules\Channels\Services\Transport\PlatformPartnerClients::class);
        StoreDataRegistry::register('Channels', new ChannelsStoreData);
        ModuleRoutes::register('Channels', __DIR__.'/../Routes');
        TenantJobs::classify(platform: [ProcessHungerStationOrderJob::class], tenant: [PushPosOrderJob::class]); // P1-13: PushPosOrderJob was in Core's list
        // P1-13: lower layers reach Channels through hook slots (Menu's 86 toggle, Ordering's paid-order outbox).
        // An item's 86 toggle reaches the marketplaces: India first, then the rest (each best-effort, see Hooks::run).
        Hooks::on('menu.item-availability-changed', 'india', fn (int $storeId, int $menuId, bool $available) => IndiaAggregatorSync::onItemAvailabilityChanged($storeId, $menuId, $available));
        Hooks::on('menu.item-availability-changed', 'aggregators', fn (int $storeId, int $menuId, bool $available) => AggregatorAvailabilityPusher::onItemAvailabilityChanged($storeId, $menuId, $available));
        // A paid order is pushed into each active connected POS (outbox handler; failures reach the outbox as before).
        Hooks::on('order.paid.pos-push', 'posbridge', function (Order $order): void {
            foreach (PosBridgeConnection::where('store_id', $order->store_id)->where('status', 'active')->get() as $posConn) {
                PushPosOrderJob::dispatch($order->id, $posConn->provider);
            }
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([ValidateAggregatorPayload::class]); // whatsapp:cart-recovery lives in Marketing (P1-13 13c)
        }
    }
}
