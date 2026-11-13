<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Marketing;

use App\Mails\CampaignMail;
use App\Models\Customer;
use App\Models\Order;
use App\Support\OutboundText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Contracts\Sms;

/**
 * GAPS-RUN-2 B2-P1: multi-channel abandoned-cart recovery. Generalizes the
 * WhatsApp-only nudge to WhatsApp + email + SMS, fanned out by channel availability
 * and per-restaurant owner opt-ins. One nudge per order (wa_cart_recovery_at is the
 * one-shot marker); cart_recovery_channel records which channels fired.
 *
 * Consent rules (never contact without permission):
 *   - WhatsApp: owner opt customer_cart_whatsapp + a phone on the order.
 *   - Email:    owner opt customer_cart_email + a KNOWN customer with an email and
 *               marketing_consent.
 *   - SMS:      owner opt customer_cart_sms + a phone + (known customer with
 *               sms_consent). Guest orders (no customer) never get SMS.
 */
class CartRecoveryService
{
    public function __construct(private Sms $sms) {}

    /** @return int number of orders nudged */
    public function run(): int
    {
        // Superadmin kill-switch (same gate as WinBackService): a platform-wide OFF
        // stops recovery sends regardless of per-restaurant channel opt-ins.
        if (! feature_enabled('marketing_automation')) {
            return 0;
        }

        $after = (int) config('whatsapp.cart_recovery_after_minutes', 30);
        $window = (int) config('whatsapp.cart_recovery_window_hours', 24);

        // Only stores with at least one cart-recovery channel switched on are
        // eligible. Without this, an opted-out store's abandoned orders fill the
        // hourly 500-row batch and crowd out stores that can actually be nudged.
        // `store_options` was retired in P1-07; the values live in stores.settings,
        // so StoreSettings resolves the opt-in ids portably on sqlite/mysql/pgsql.
        $storeIds = $this->eligibleStoreIds();
        if ($storeIds === []) {
            return 0;
        }

        $orders = Order::where('status', 'unpaid')
            ->whereNull('wa_cart_recovery_at')
            ->whereNull('cart_recovery_checked_at')
            ->whereIn('store_id', $storeIds)
            ->where('created_at', '<=', now()->subMinutes($after))
            ->where('created_at', '>=', now()->subHours($window))
            ->with(['store', 'customer']) // avoid a Post + Customer query per order
            ->orderBy('id')
            ->limit(500)
            ->get();

        $nudged = 0;
        foreach ($orders as $order) {
            try {
                $channels = $this->nudge($order);

                if (! empty($channels)) {
                    $order->forceFill([
                        'wa_cart_recovery_at' => now(),
                        'cart_recovery_channel' => implode(',', $channels),
                        'cart_recovery_checked_at' => now(),
                    ])->save();
                    $nudged++;
                } else {
                    // Examined but nothing could be sent (missing customer data,
                    // no consent, no phone): remember it so it is not re-picked
                    // every hour. Intended — it stays checked permanently.
                    $order->forceFill(['cart_recovery_checked_at' => now()])->save();
                }
            } catch (\Throwable $e) {
                // One bad order must not abort the batch. Leave it unchecked so
                // the next hourly run retries it.
                Log::warning("[cart-recovery] order {$order->id} failed: ".$e->getMessage());
            }
        }

        return $nudged;
    }

    /**
     * Stores with at least one of the three cart-recovery channels enabled.
     * StoreSettings is the P1-07 replacement for the retired store_options rows
     * and reads stores.settings without any driver-specific JSON SQL.
     *
     * @return list<int>
     */
    private function eligibleStoreIds(): array
    {
        $ids = [];
        $truthy = static fn (string $value): bool => $value === '1'
            || filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;

        foreach (['customer_cart_whatsapp', 'customer_cart_email', 'customer_cart_sms'] as $option) {
            foreach (StoreSettings::storeIdsWhere($option, $truthy) as $id) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /** @return array<string> channels actually sent on for this order */
    private function nudge(Order $order): array
    {
        $store = $order->store; // eager-loaded
        if (! $store) {
            return [];
        }
        $rid = (int) $order->store_id;
        $phone = trim((string) $order->phone_number);
        $customer = $order->customer; // eager-loaded (null when guest)

        $link = $order->track_token ? route('store.orderTrack', $order->track_token) : '';
        // Speak the language the order was placed in (orders.locale). Customers
        // have no own locale column; when the order has none, keep the ambient
        // locale (the application default).
        $body = (string) $this->inOrderLocale($order, fn (): string => trim((string) ___(
            ':name: you left order #:id unfinished. Complete it here: :link',
            [
                'name' => OutboundText::name($store->title),
                'id' => $order->id,
                'link' => $link,
            ]
        )));
        $stop = (string) $this->inOrderLocale(
            $order,
            fn (): string => (string) ___('Reply STOP to unsubscribe.')
        );

        $sent = [];

        // WhatsApp. Cart recovery is a re-engagement (marketing) message, so it MUST go
        // through the opt-out + consent + daily-cap gate like the SMS and email branches
        // below and like the WhatsApp broadcast/campaign senders. Sent as 'status'/
        // marketing=false it bypassed all three (WhatsAppService only runs them when
        // $marketing), so a customer who replied STOP still got nudged — a WhatsApp
        // Business opt-out violation (Meta number-ban risk). marketing=true makes
        // WhatsAppService honor WhatsAppOptout + WhatsAppConsent + MessagingGuard.
        if (store_options($rid, 'customer_cart_whatsapp') && $phone !== '') {
            app(MessageQueue::class)->whatsApp(
                $phone, $body, $rid, 'cart_recovery', true,
                store_options($rid, 'whatsapp_cart_template') ?: null,
                [OutboundText::name($store->title), (string) $order->id, $link]
            );
            $sent[] = 'whatsapp';
        }

        // Email (known consenting customer only).
        if (store_options($rid, 'customer_cart_email') && $customer && $customer->email && $customer->marketing_consent) {
            try {
                $subject = ___(':name — your order is waiting', ['name' => $store->title]);
                $unsubscribeUrl = CampaignMail::unsubscribeUrlFor((int) $customer->id);
                Mail::to($customer->email)->send(new CampaignMail($subject, $body, $unsubscribeUrl));
                $sent[] = 'email';
            } catch (\Throwable $e) {
                Log::warning("[cart-recovery] email failed for order {$order->id}: ".$e->getMessage());
            }
        }

        // SMS (known consenting customer only).
        if (store_options($rid, 'customer_cart_sms') && $phone !== '' && $customer && $customer->sms_consent) {
            $this->sms->send($phone, $body."\n\n".$stop, $rid, 'cart_recovery', true);
            $sent[] = 'sms';
        }

        return $sent;
    }

    /**
     * Run $fn under orders.locale when it is a supported locale, restoring the
     * ambient locale afterwards. No stored locale → the ambient/default one.
     * Customers have no locale column, so the order is the best available signal.
     *
     * @param  callable(): mixed  $fn
     */
    private function inOrderLocale(Order $order, callable $fn): mixed
    {
        $target = strtolower(trim((string) ($order->locale ?? '')));
        $supported = config('laravellocalization.supportedLocales', []);
        if ($target === '' || ! array_key_exists($target, $supported)) {
            return $fn();
        }

        $previous = app()->getLocale();
        app()->setLocale($target);
        try {
            return $fn();
        } finally {
            app()->setLocale($previous);
        }
    }
}
