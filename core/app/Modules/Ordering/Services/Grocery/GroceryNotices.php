<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Grocery;

use App\Models\Order;
use App\Support\OutboundText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Modules\Messaging\Contracts\MessageQueue;

/**
 * P3-09 (kit notifications N3 / N4, basket §10): the grocery customer messages core sends itself, as one
 * transactional SMS each, only when the store sends order status messages (`customer_status_sms`, the owner's
 * existing opt-in) and the order has a phone number:
 *
 *  - `grocery.replacement_needed`: the picker proposed a replacement; the shopper answers on the tracking page (once per
 *    order and picking round).
 *  - `grocery.final_amount`: the card hold captured the final amount and released the rest (a release, never called a
 *    refund).
 *  - `grocery.final_amount_refunded` (N4): money captured up front was refunded after picking — the weighed difference
 *    (D7 switch on) or a removed line (API-31). Sent instead of `grocery.final_amount`, never both.
 *  - `grocery.ready_to_collect` (N3): a collection order with a slot turned `ready`; carries the collection code and the
 *    hold-until time (end of the slot).
 */
final class GroceryNotices
{
    public function __construct(private readonly MessageQueue $messages) {}

    public function replacementNeeded(Order $order): bool
    {
        if (! Cache::add('grocery:replacement-sms:'.$order->id, 1, now()->addHours(2))) {
            return false;
        }

        return $this->send($order, ___(':store: some items in order :order_number need your answer. Review: :link', $this->vars($order)));
    }

    public function finalAmount(Order $order, int $finalMinor, int $releasedMinor): bool
    {
        return $this->send($order, ___(':store: order :order_number final amount is :final_amount. :released_amount of the card hold was released. :link', $this->vars($order) + [
            'final_amount' => $this->money($order, $finalMinor),
            'released_amount' => $this->money($order, $releasedMinor),
        ]));
    }

    public function finalAmountRefunded(Order $order, int $refundMinor): bool
    {
        return $this->send($order, ___(':store: order :order_number is packed. We refunded :refund_amount to your card; it can take 5-10 business days. :link', $this->vars($order) + [
            'refund_amount' => $this->money($order, $refundMinor),
        ]));
    }

    public function readyToCollect(Order $order): bool
    {
        $vars = $this->vars($order) + ['collection_code' => (string) ($order->getAttribute('token_number') ?: $order->id)];
        $until = $order->slot_ends_at;
        if ($until === null) {
            return $this->send($order, ___(':store: order :order_number is ready to collect. Code :collection_code. :link', $vars));
        }
        $tz = (string) (store_options((int) $order->store_id, 'timezone') ?: config('app.timezone'));
        $vars['hold_until'] = CarbonImmutable::instance($until)->setTimezone($tz)->format('H:i');

        return $this->send($order, ___(':store: order :order_number is ready to collect. Code :collection_code. Held until :hold_until. :link', $vars));
    }

    private function send(Order $order, string $text): bool
    {
        $storeId = (int) $order->store_id;
        $phone = trim((string) ($order->phone_number ?? ''));
        if ($phone === '' || ! store_options($storeId, 'customer_status_sms')) {
            return false;
        }
        try {
            $this->messages->sms($phone, $text, $storeId, 'status', false);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }

    /** @return array{store: string, order_number: string, link: string} */
    private function vars(Order $order): array
    {
        $store = Store::query()->find((int) $order->store_id);
        $link = '';
        if ((string) $order->track_token !== '') {
            try {
                $link = route('store.orderTrack', ['token' => (string) $order->track_token]);
            } catch (\Throwable) {
                $link = '';
            }
        }

        return ['store' => OutboundText::name((string) ($store->title ?? '')), 'order_number' => '#'.$order->id, 'link' => $link];
    }

    private function money(Order $order, int $minor): string
    {
        $currency = strtoupper((string) ($order->currency_code ?: store_currency((int) $order->store_id)));

        return $currency.' '.number_format(Money::majorFromMinor($minor, $currency), Currencies::exponent($currency));
    }
}
