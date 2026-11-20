<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Providers;

use App\Models\Order;
use Illuminate\Support\ServiceProvider;
use Juwri\Core\Pci\PaymentPageProbes;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\TenantJobs;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\ModuleRoutes;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Ordering\Console\Commands\BackfillOrderCurrencyCodes;
use Juwri\Modules\Ordering\Console\Commands\ExpireHostedPayments;
use Juwri\Modules\Ordering\Console\Commands\OrdersSoftLimitNotify;
use Juwri\Modules\Ordering\Console\Commands\PruneOrphanVoiceNotes;
use Juwri\Modules\Ordering\Console\Commands\ReleaseScheduledOrders;
use Juwri\Modules\Ordering\Console\Commands\RepairModifierUndercharge;
use Juwri\Modules\Ordering\Console\Commands\ResolveStuckCapture;
use Juwri\Modules\Ordering\Console\Commands\SnapshotOrderTotals;
use Juwri\Modules\Ordering\Contracts\CheckoutPhoneGate;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Contracts\HeldSlotCounts;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Contracts\OrderCancellations;
use Juwri\Modules\Ordering\Contracts\OrderTax;
use Juwri\Modules\Ordering\Contracts\Returns;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;
use Juwri\Modules\Ordering\Contracts\ScheduledSlots;
use Juwri\Modules\Ordering\Contracts\Shipping;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Ordering\Jobs\SendCustomerPushJob;
use Juwri\Modules\Ordering\Jobs\SendStaffOrderPushJob;
use Juwri\Modules\Ordering\Jobs\TranscribeVoiceNote;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Services\Grocery\GroceryNotices;
use Juwri\Modules\Ordering\Services\OrderFiscalInvoices;
use Juwri\Modules\Ordering\Services\OrderPaidSideEffects;
use Juwri\Modules\Ordering\Services\OrderPaymentSettler;
use Juwri\Modules\Ordering\Services\Orders\CheckoutOtpGate;
use Juwri\Modules\Ordering\Services\Orders\NoHeldSlots;
use Juwri\Modules\Ordering\Services\Orders\NoSalesRestrictions;
use Juwri\Modules\Ordering\Services\Orders\ScheduledSlotsService;
use Juwri\Modules\Ordering\Services\Orders\SharedOrderCancellations;
use Juwri\Modules\Ordering\Services\Payments\CardHoldOrders;
use Juwri\Modules\Ordering\Services\Payments\HostedOrderPayments;
use Juwri\Modules\Ordering\Services\Returns\ReturnsService;
use Juwri\Modules\Ordering\Services\Shipping\ShippingQuotes;
use Juwri\Modules\Ordering\Services\Slots\SlotBook;
use Juwri\Modules\Ordering\Services\Slots\SlotHolds;
use Juwri\Modules\Ordering\Services\Storefront\StorefrontOrderingService;
use Juwri\Modules\Ordering\Services\Tax\EngineOrderTax;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;
use Juwri\Modules\Payments\Contracts\OrderPaymentHandler;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Models\Transaction;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ordering module provider (P1-08d; one provider — lane A's OrderingModuleServiceProvider merged in here).
 * Ordering sits above Payments and Fiscal, so its side of those links is wired here: the confirmed store-order
 * payment handler, and FiscalDocument::order() (Fiscal stores `order_id` only — J25-8).
 */
class OrderingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        StoreDataRegistry::register('Ordering', new OrderingStoreData);
        ModuleRoutes::register('Ordering', __DIR__.'/../Routes');
        // Payments hands a confirmed store-order payment over here.
        $this->app->singleton(OrderPaymentHandler::class, OrderPaymentSettler::class);
        // P1-23: sales rules of a store type (the Pharmacy module binds the AUP 3.1 rules over this default).
        $this->app->singletonIf(SalesRestrictions::class, NoSalesRestrictions::class);
        // P3-02: order types, cart quote, order money and slots for the storefront view model.
        $this->app->bindIf(HeldSlotCounts::class, NoHeldSlots::class);
        // P3-11: scheduling rules (lead time, interval, capacity, hours) for a module that books slots of its own (Laundry).
        $this->app->bind(ScheduledSlots::class, ScheduledSlotsService::class);
        $this->app->bind(StorefrontOrdering::class, StorefrontOrderingService::class);
        // P3-06b: the hosted payment of a headless storefront order (Storefront reaches it through the contract).
        $this->app->bind(HostedPayments::class, HostedOrderPayments::class);
        // P3-08: shipping methods per zone with dates, cut-offs and fees; goods returns.
        $this->app->bind(Shipping::class, ShippingQuotes::class);
        $this->app->bind(CheckoutPhoneGate::class, CheckoutOtpGate::class);
        $this->app->bind(Returns::class, ReturnsService::class);
        // P6-126: the VAT engine as other modules may see it.
        $this->app->bind(OrderTax::class, EngineOrderTax::class);
        // P6-205: the shared cancel routine as other modules (POS reject) may call it.
        $this->app->bind(OrderCancellations::class, SharedOrderCancellations::class);
        // P3-09: delivery / collection slots with capacity, express and radius; 10-minute slot holds.
        $this->app->bind(DeliverySlotBook::class, SlotBook::class);

        TenantJobs::classify(tenant: [SendCustomerPushJob::class, SendStaffOrderPushJob::class, TranscribeVoiceNote::class]);
    }

    public function boot(): void
    {
        // P6-186 (J44): store settings save — a locked VAT-per-line toggle is dropped; a store moving onto the VAT
        // engine keeps its earlier orders on the legacy tax.
        Hooks::on('store.options.saving', 'tax-engine', [TaxEngine::class, 'onStoreOptionsSaving']);

        // J25-8: FiscalDocument::order() — owned by Ordering, not Fiscal.
        FiscalDocument::resolveRelationUsing('order', fn (FiscalDocument $doc) => $doc->belongsTo(Order::class, 'order_id'));

        // PCI DSS 11.6.1: the storefront order payment page, rendered for the payment-page change-detection job with an
        // unsaved order payment (id 0) of the probe store — the real controller, view, layout and scripts.
        PaymentPageProbes::register('storefront.pay', function (): ?Response {
            $store = PaymentPageProbes::store();
            if ($store === null) {
                return null;
            }
            $tx = new Transaction;
            $tx->forceFill(['id' => 0, 'store_id' => $store->id, 'amount' => 10, 'product_name' => 'Payment page check', 'transaction_method' => 'order',
                'currency_code' => (string) (store_options($store->id)->currency_code ?? config('settings.currency_code') ?: 'USD')]);

            return response(app(PaymentController::class)->index(request(), $tx));
        });

        // P3-09: a cancelled or rejected order gives its delivery / collection slot back (slot seats + its hold), whoever
        // cancelled it (the owner, the payment-window sweep, the shopper's hold release).
        Order::updated(function (Order $order): void {
            if ($order->wasChanged('status') && in_array((string) $order->status, ['cancelled', 'rejected', 'declined'], true)
                && $order->getAttribute('slot_hold_id') !== null) {
                app(SlotHolds::class)->releaseForOrder($order);
            }
            // P3-09: a cancelled order releases its weighed-basket card hold (nothing is charged; API `voided`)
            if ($order->wasChanged('status') && in_array((string) $order->status, ['cancelled', 'rejected', 'declined'], true)
                && $order->getAttribute('hold_status') === 'authorised') {
                try {
                    app(CardHoldOrders::class)->voidOnCancel($order);
                } catch (\Throwable $e) {
                    report($e); // the gateway lets the authorisation lapse on its own; the order stays cancelled
                }
            }
            // P3-09 (N3): a collection order with a slot is ready — `grocery.ready_to_collect`
            if ($order->wasChanged('status') && (string) $order->status === 'ready' && (string) $order->type !== 'delivery'
                && $order->getAttribute('slot_starts_at') !== null) {
                try {
                    app(GroceryNotices::class)->readyToCollect($order);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        // J25-7: the outbox handlers for a paid store order's side effects (PaymentController::paySuccess).
        $this->callAfterResolving(Outbox::class, fn (Outbox $outbox) => OrderPaidSideEffects::register($outbox));

        // P4-01: the e-invoice of a paid order (created paid or becoming paid) and the credit note of a refund / void —
        // outbox messages after commit, best-effort (a fiscal failure never fails a payment or a refund).
        $this->callAfterResolving(Outbox::class, fn (Outbox $outbox) => OrderFiscalInvoices::register($outbox));
        $invoice = static function (Order $order): void {
            try {
                OrderFiscalInvoices::paid($order);
            } catch (\Throwable $e) {
                report($e);
            }
        };
        Order::created(function (Order $order) use ($invoice): void {
            if ($order->is_paid) {
                $invoice($order);
            }
        });
        Order::updated(function (Order $order) use ($invoice): void {
            if ($order->wasChanged('is_paid') && $order->is_paid) {
                $invoice($order);
            }
        });
        OrderAdjustment::created(function (OrderAdjustment $adjustment): void {
            try {
                OrderFiscalInvoices::adjusted($adjustment);
            } catch (\Throwable $e) {
                report($e);
            }
        });

        if ($this->app->runningInConsole()) {
            // app/Console/Kernel only auto-loads app/Console/Commands (MealsGenerateDue, PruneCustomerNotifications
            // stay registered there by name).
            $this->commands([
                BackfillOrderCurrencyCodes::class, OrdersSoftLimitNotify::class, PruneOrphanVoiceNotes::class,
                ReleaseScheduledOrders::class, RepairModifierUndercharge::class, SnapshotOrderTotals::class,
                ExpireHostedPayments::class, ResolveStuckCapture::class,
            ]);
        }
    }
}
