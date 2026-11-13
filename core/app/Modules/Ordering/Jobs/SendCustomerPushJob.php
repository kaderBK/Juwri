<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Jobs;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Models\CustomerPushSubscription;
use Juwri\Modules\Ordering\Services\CustomerPushService;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * P1: Web Push an order-status update to every device subscribed to one order.
 * Mirrors SendShopperPushJob — same VAPID keys (config/webpush.php) and the same
 * no-op-when-unconfigured guarantee, so an install without keys behaves exactly
 * as before (logs and returns; never prunes). NO PII in the payload: only the
 * store name + a status string.
 */
class SendCustomerPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public function __construct(
        public int $orderId,
        public int $storeId,
        public string $status,
        public bool $terminal = false,
    ) {}

    public function handle(): void
    {
        $public = (string) config('webpush.public_key');
        $private = (string) config('webpush.private_key');

        $subs = CustomerPushSubscription::where('order_id', $this->orderId)->whereNull('failed_at')->get();
        if ($subs->isEmpty()) {
            return;
        }

        // Not configured (owner has not generated VAPID keys) → no-op, never prune.
        if ($public === '' || $private === '' || ! class_exists(WebPush::class)) {
            Log::info('[customer-push] skipped — web push not configured', ['order' => $this->orderId]);
            $this->cleanupIfTerminal();

            return;
        }

        // A malformed subscription or a crypto/network error must never crash the
        // queued job or skip the terminal cleanup below — best-effort throughout.
        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => (string) config('webpush.subject'),
                'publicKey' => $public,
                'privateKey' => $private,
            ]]);

            $order = Order::find($this->orderId);
            $token = $order?->track_token;
            $store = (string) (Store::where('id', $this->storeId)->value('title') ?? '');
            $url = $token ? route('store.orderTrack', $token, false) : '/';

            // Diner-facing strings render under the ORDER's locale (never the
            // queue worker's ambient locale) — same rule as the inbox rows.
            [$title, $body] = $order
                ? CustomerPushService::inOrderLocale($order, fn () => [
                    $store !== '' ? $store : ___('Order update'),
                    self::message($this->status),
                ])
                : [$store !== '' ? $store : ___('Order update'), self::message($this->status)];

            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'url' => $url,
            ]);

            foreach ($subs as $sub) {
                try {
                    $webPush->queueNotification(
                        Subscription::create([
                            'endpoint' => $sub->endpoint,
                            'publicKey' => $sub->p256dh,
                            'authToken' => $sub->auth,
                            'contentEncoding' => $sub->content_encoding ?: 'aesgcm',
                        ]),
                        $payload
                    );
                } catch (\Throwable $e) {
                    Log::warning("[customer-push] queue failed for sub {$sub->id}: ".$e->getMessage());
                }
            }

            // Flush; mark gone endpoints (404/410) for pruning via failed_at.
            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess() && $report->isSubscriptionExpired()) {
                    CustomerPushSubscription::where('endpoint_hash', hash('sha256', $report->getEndpoint()))
                        ->update(['failed_at' => now()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[customer-push] send errored: '.$e->getMessage());
        }

        $this->cleanupIfTerminal();
    }

    /** The order is done — drop its subscriptions once the final push has gone out. */
    private function cleanupIfTerminal(): void
    {
        if ($this->terminal) {
            CustomerPushSubscription::where('order_id', $this->orderId)->delete();
        }
    }

    /** PII-free, localized status line. */
    private static function message(string $status): string
    {
        return match ($status) {
            'ready' => ___('Your order is ready.'),
            'out_for_delivery' => ___('Your order is on the way.'),
            'delivered' => ___('Your order has been delivered.'),
            'completed' => ___('Your order is complete.'),
            'cancelled' => ___('Your order was cancelled.'),
            default => ___('Your order status changed.'),
        };
    }
}
