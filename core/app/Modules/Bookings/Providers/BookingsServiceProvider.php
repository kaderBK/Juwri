<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Bookings\Console\Commands\SendBookingReminders;
use Juwri\Modules\Bookings\Console\Commands\SweepBookings;
use Juwri\Modules\Bookings\Contracts\BookingApi;
use Juwri\Modules\Bookings\Contracts\StorefrontBookings;
use Juwri\Modules\Bookings\Mail\BookingReminderMail;
use Juwri\Modules\Bookings\Services\Api\BookingApiService;
use Juwri\Modules\Bookings\Services\DepositService;
use Juwri\Modules\Bookings\Services\StorefrontBookingsService;
use Juwri\Modules\Payments\Contracts\StoreCredits;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * P3-10: the Bookings module (L3, Community) — staff × service × duration appointments with 10-minute holds,
 * deposits, reminders, .ics and signed manage links. Routes: owner screens (slot Bookings/web-1, dashboard group)
 * and the plain storefront flow (slot Bookings/public-1, before the {slug} catch-all).
 */
class BookingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Bookings', new BookingsStoreData);
        ModuleRoutes::register('Bookings', __DIR__.'/../Routes');
        // P3-11: the booking engine as the storefront view model reads it
        $this->app->bind(StorefrontBookings::class, StorefrontBookingsService::class);
        // The reminder mail is queued from the console sweep (no tenant): it carries only display strings.
        TenantJobs::classify(platform: [BookingReminderMail::class]);
        // P3-06b phase D (lane C): the engine as the headless storefront API uses it.
        $this->app->bind(BookingApi::class, BookingApiService::class);
    }

    public function boot(): void
    {
        // A confirmed deposit payment comes back through the store-payment choke point (hosted payment page), which
        // settles statically: register the handler at boot, not when StoreCredits happens to be resolved.
        $this->app->make(StoreCredits::class)
            ->settleWith(DepositService::METHOD, fn (Transaction $t) => $this->app->make(DepositService::class)->markPaid($t));

        if ($this->app->runningInConsole()) {
            $this->commands([SweepBookings::class, SendBookingReminders::class]);
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('bookings:sweep')->everyMinute()->withoutOverlapping(5)->onFailure(fn () => \App\Support\SchedulerAlert::fail('bookings:sweep'));
                $schedule->command('bookings:remind')->everyTenMinutes()->withoutOverlapping(15)->onFailure(fn () => \App\Support\SchedulerAlert::fail('bookings:remind'));
            });
        }
    }
}
