<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Providers;

use App\Support\SchedulerAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Fiscal\Contracts\ZatcaOfflineTills;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Events\TerminalPaymentCaptured;
use Juwri\Modules\Pos\Console\Commands\HashStaffPins;
use Juwri\Modules\Pos\Console\Commands\InspectPrintTicket;
use Juwri\Modules\Pos\Console\Commands\PosOfflineCardsSync;
use Juwri\Modules\Pos\Console\Commands\PosOfflineZatcaReport;
use Juwri\Modules\Pos\Database\Seeders\PosV2DemoSeeder;
use Juwri\Modules\Pos\Listeners\SettleTerminalPayment;
use Juwri\Modules\Pos\Services\Offline\OfflineZatcaWatch;
use Juwri\Modules\Pos\Services\Printing\PaidOrderPrint;

/**
 * Pos module provider (P1-08e).
 */
class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Pos', new PosStoreData);
        ModuleSeeders::register('Pos', optional: [PosV2DemoSeeder::class]);
        ModuleRoutes::register('Pos', __DIR__.'/../Routes');
        // P6-94: fiscal:zatca-check and the owner ZATCA page read the offline tills through this contract.
        $this->app->bind(ZatcaOfflineTills::class, OfflineZatcaWatch::class);
    }

    public function boot(): void
    {
        // P5-26: a captured SoftPOS card payment settles its POS order once.
        Event::listen(TerminalPaymentCaptured::class, SettleTerminalPayment::class);
        // J25-7 part 4: the paid-order auto-print (Order is_paid hook) runs from the outbox, after commit.
        $this->callAfterResolving(Outbox::class, fn (Outbox $outbox) => PaidOrderPrint::register($outbox));

        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([InspectPrintTicket::class, HashStaffPins::class, PosOfflineCardsSync::class, PosOfflineZatcaReport::class]);
            // Offline cards: ask the provider every 5 minutes (a no-op while nothing is pending).
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('pos:offline-cards-sync')->everyFiveMinutes()->withoutOverlapping(8)->onFailure(fn () => SchedulerAlert::fail('pos:offline-cards-sync'));
                // OP-12: offline-signed till invoices (ZATCA wants simplified invoices within 24 h); a no-op while off.
                $schedule->command('pos:offline-zatca-report')->everyFiveMinutes()->withoutOverlapping(8)->onFailure(fn () => SchedulerAlert::fail('pos:offline-zatca-report'));
            });
        }
    }
}
