<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Providers;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Modules\Messaging\Console\Commands\GenerateVapidKeys;
use Juwri\Modules\Messaging\Console\Commands\PurgeWhatsAppData;
use Juwri\Modules\Messaging\Console\Commands\WhatsAppAuditNumbers;
use Juwri\Modules\Messaging\Console\Commands\WhatsAppMigrateLegacy;
use Juwri\Modules\Messaging\Contracts\MessageChargeGate;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Contracts\MobilePush;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Contracts\StaffPush;
use Juwri\Modules\Messaging\Contracts\WhatsApp;
use Juwri\Modules\Messaging\Jobs\ProcessWhatsAppWebhookJob;
use Juwri\Modules\Messaging\Jobs\SendMobilePushJob;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Juwri\Modules\Messaging\Jobs\SendSmsJob;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Juwri\Modules\Messaging\Listeners\RecordEmailCost;
use Juwri\Modules\Messaging\Listeners\SanitizeOutgoingHeaders;
use Juwri\Modules\Messaging\Listeners\SkipSuppressedRecipients;
use Juwri\Modules\Messaging\Services\NullMessageChargeGate;
use Juwri\Modules\Messaging\Services\PublicApi;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Juwri\Modules\Messaging\Services\SmsService;
use Juwri\Modules\Messaging\Services\StaffPush\StaffPushService;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppService;
use Juwri\Core\Support\ModuleRoutes;

/**
 * Messaging module provider (P1-08b).
 *
 * J27 follow-up: the message cost ledger — email lines from MessageSent; the default (bill-nothing) WhatsApp charge
 * gate, which Billing rebinds (bindIf here, so the order of providers does not matter).
 */
class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Messaging', new MessagingStoreData);
        ModuleRoutes::register('Messaging', __DIR__.'/../Routes');
        $this->app->bindIf(MessageChargeGate::class, NullMessageChargeGate::class);

        // P1-08 L1 public surface: what higher modules may use (contracts resolve to the existing classes).
        $this->app->bind(Sms::class, SmsService::class);
        $this->app->bind(\Juwri\Modules\Messaging\Contracts\MessageCosts::class, \Juwri\Modules\Messaging\Services\MessageCostLedger::class);
        $this->app->bind(\Juwri\Modules\Messaging\Contracts\PlatformKeySends::class, \Juwri\Modules\Messaging\Services\Hosted\PlatformKeySender::class);
        $this->app->bind(SmsOtp::class, SmsOtpService::class);
        $this->app->bind(StaffPush::class, StaffPushService::class);
        $this->app->bind(WhatsApp::class, WhatsAppService::class);
        $this->app->singleton(PublicApi::class);
        $this->app->alias(PublicApi::class, MessageQueue::class);
        $this->app->alias(PublicApi::class, MobilePush::class);

        TenantJobs::classify(
            platform: [
                ProcessWhatsAppWebhookJob::class, // inbound webhook: finds the store from the payload
            ],
            tenant: [
                SendMobilePushJob::class,
                SendPushNotificationJob::class,
                SendSmsJob::class,
                SendWhatsAppJob::class,
            ],
        );
    }

    public function boot(): void
    {
        Event::listen(MessageSending::class, SanitizeOutgoingHeaders::class);
        Event::listen(MessageSending::class, SkipSuppressedRecipients::class); // P6-249
        Event::listen(MessageSent::class, RecordEmailCost::class);

        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([GenerateVapidKeys::class, PurgeWhatsAppData::class, WhatsAppAuditNumbers::class, WhatsAppMigrateLegacy::class]);
        }
    }
}
