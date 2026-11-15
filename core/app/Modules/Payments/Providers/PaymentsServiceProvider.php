<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Providers;

use App\Support\SchedulerAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Contracts\Billing\OutstandingPlatformCharges;
use Juwri\Core\Contracts\Webhooks\WebhookDeduplication;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Payments\Console\Commands\LedgerReconcile;
use Juwri\Modules\Payments\Console\Commands\PaymentsWatchCommand;
use Juwri\Modules\Payments\Console\Commands\PruneWebhooks;
use Juwri\Modules\Payments\Console\Commands\RegisterDisputeWebhooks;
use Juwri\Modules\Payments\Console\Commands\RelayOutbox;
use Juwri\Modules\Payments\Console\Commands\SendMarkers;
use Juwri\Modules\Payments\Console\Commands\SettleHeldRenewal;
use Juwri\Modules\Payments\Contracts\AbandonedCheckouts;
use Juwri\Modules\Payments\Contracts\CustomerGateways;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Contracts\GooglePlayBilling;
use Juwri\Modules\Payments\Contracts\Ledger;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;
use Juwri\Modules\Payments\Contracts\PurchaseReceipts;
use Juwri\Modules\Payments\Contracts\StoreCredits;
use Juwri\Modules\Payments\Contracts\StoreGatewayKeys;
use Juwri\Modules\Payments\Contracts\StoreTransfers;
use Juwri\Modules\Payments\Contracts\TerminalPayments;
use Juwri\Modules\Payments\Contracts\TransactionFulfiller;
use Juwri\Modules\Payments\Contracts\Wallet;
use Juwri\Modules\Payments\Jobs\RelayOutboxJob;
use Juwri\Modules\Payments\Mail\PaymentHealthAlertMail;
use Juwri\Modules\Payments\Mail\WalletPurchaseReceiptMail;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Policies\Admin\TransactionPolicy;
use Juwri\Modules\Payments\Services\AbandonedCheckout;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\InApp\GooglePlayHttp;
use Juwri\Modules\Payments\Services\ManualStoreTransfers;
use Juwri\Modules\Payments\Services\PendingPlatformCharges;
use Juwri\Modules\Payments\Services\PublicApi;
use Juwri\Modules\Payments\Services\StoreCustomerGateways;
use Juwri\Modules\Payments\Services\StoreGatewayKeyList;
use Juwri\Modules\Payments\Services\StorePayments;
use Juwri\Modules\Payments\Services\StoreTransactionFulfiller;
use Juwri\Modules\Payments\Services\Terminal\SoftPos\SoftPos;
use Juwri\Modules\Payments\Services\Terminal\StoreTerminals;
use Juwri\Modules\Payments\Services\WalletPurchaseReceipt;
use Juwri\Modules\Payments\Services\Webhooks\TransactionalOutbox;
use Juwri\Modules\Payments\Services\Webhooks\WebhookDeduplicationApi;
use Juwri\Modules\Payments\Support\GatewayPageProbes;

/**
 * Payments module provider (P1-08b). Core may not import Payments, so what Core needs from it is wired here.
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Payments', new PaymentsStoreData);
        ModuleRoutes::register('Payments', __DIR__.'/../Routes');
        $this->app->singleton(OutstandingPlatformCharges::class, PendingPlatformCharges::class);
        // In-person card terminal (SoftPOS, P5-21): the store's own Geidea / EdfaPay account; every provider flag is off by
        // default and offline card stays off until OP-0 (StoreTerminals answers like NoTerminal without a provider).
        $this->app->singleton(SoftPos::class);
        $this->app->singletonIf(StoreGatewayKeys::class, StoreGatewayKeyList::class); // J52b: legacy importer
        $this->app->singletonIf(PurchaseReceipts::class, WalletPurchaseReceipt::class); // P6-252
        $this->app->singletonIf(TerminalPayments::class, fn ($app) => $app->make(SoftPos::class));
        $this->app->singletonIf(PaymentTerminal::class, StoreTerminals::class);

        // P1-08 L1 public surface: what higher modules may use (pure delegation to the services).
        $this->app->singleton(PublicApi::class);
        foreach ([Wallet::class, Ledger::class, Gateways::class] as $contract) {
            $this->app->alias(PublicApi::class, $contract);
        }

        // J25-7: one outbox per process (handlers are registered by the modules at boot).
        $this->app->singleton(Outbox::class, TransactionalOutbox::class);
        // P1-13 13g: Community fulfilment of a settled Transaction (a business's own customer payments); Billing (ee,
        // registered earlier) binds the platform checkout, which wins.
        $this->app->singletonIf(TransactionFulfiller::class, StoreTransactionFulfiller::class);
        $this->app->singletonIf(GooglePlayBilling::class, GooglePlayHttp::class); // P5-17
        $this->app->singleton(StoreCredits::class, StorePayments::class); // P1-13 13g
        $this->app->singleton(CustomerGateways::class, StoreCustomerGateways::class); // P1-23
        $this->app->singleton(StoreTransfers::class, ManualStoreTransfers::class); // P1-23 part 6
        $this->app->singleton(AbandonedCheckouts::class, AbandonedCheckout::class); // BL-038 review
        $this->app->bind(WebhookDeduplication::class, WebhookDeduplicationApi::class);
        TenantJobs::classify(platform: [RelayOutboxJob::class, PaymentHealthAlertMail::class, WalletPurchaseReceiptMail::class]); // each outbox message carries its own ids
    }

    public function boot(): void
    {
        // J25-7 part 2: a webhook-confirmed payment's credit runs (and is retried) through the outbox.
        $this->callAfterResolving(Outbox::class, fn (Outbox $outbox) => $outbox->handle(Settlement::CREDIT, Settlement::creditFromOutbox(...)));
        // P1-13 13g: a purchased gift card's delivery (was Billing's PaidPlanSideEffects; same message type).
        $this->callAfterResolving(Outbox::class, fn (Outbox $outbox) => StorePayments::register($outbox));

        // PCI DSS 11.6.1: the gateway hand-off pages, rendered for the payment-page change-detection job.
        GatewayPageProbes::register();

        // P1-13 13g: the admin payments ledger is Community (was registered by Billing).
        Gate::policy(Transaction::class, TransactionPolicy::class);

        // The owner's platform payments (plans, add-ons, top-ups, …): was User::transactions() in Core.
        User::resolveRelationUsing('transactions', fn (User $user) => $user->hasMany(Transaction::class));

        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands.
            $this->commands([LedgerReconcile::class, RegisterDisputeWebhooks::class, PruneWebhooks::class, RelayOutbox::class, SettleHeldRenewal::class, PaymentsWatchCommand::class, SendMarkers::class]);

            // J25-7: keep receipts ≥ 7 days (default 30); retry outbox messages left by a crash or a failing handler.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('payments:prune-webhooks')->dailyAt('03:55')->onFailure(fn () => SchedulerAlert::fail('payments:prune-webhooks'));
                $schedule->command('payments:outbox-relay')->everyMinute()->withoutOverlapping(10)->onFailure(fn () => SchedulerAlert::fail('payments:outbox-relay'));
                // P6-93: state-based payments health alerts (stuck payments, rejections, refunds, ledger, payouts, outbox).
                $schedule->command('payments:watch')->everyFiveMinutes()->withoutOverlapping(10)->onFailure(fn () => SchedulerAlert::fail('payments:watch'));
            });
        }
    }
}
