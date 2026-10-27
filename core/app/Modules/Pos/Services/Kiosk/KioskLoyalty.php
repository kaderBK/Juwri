<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Kiosk;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\FeatureAccess;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Contracts\ItemPricing;
use Juwri\Modules\Menu\Contracts\Recommendations;
use Juwri\Modules\Menu\Data\Recommendation;
use Juwri\Modules\Menu\Data\RecommendationCustomer;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;

/**
 * Item 26, kiosk ↔ loyalty. A shared in-store tablet, so:
 *  - Opt-in: nothing happens unless the customer ticks "use my account" (opt_in) on this visit.
 *  - Proof before data: a phone gets a one-time code (scope kiosk:<store>, never a storefront login); a QR is a short-lived,
 *    single-use pass the signed-in customer shows from their own phone. Until one of them is proved the kiosk answers the
 *    same thing for a known and an unknown phone, and shows no name, points or orders.
 *  - Forgotten with the visit: the recognition lives in this kiosk session for 10 minutes at most and is dropped on the idle
 *    return, the staff exit and "not me".
 *  - Favourites come from the Menu recommendation engine (kiosk channel, favourite rule); a reorder is priced from TODAY's
 *    menu (ItemPricing), drops what is gone or sold out, and leaves lines with choices for the customer to pick again; the
 *    cart is quoted by the server at checkout anyway.
 */
class KioskLoyalty
{
    public const TTL_SECONDS = 600;

    public const PASS_TTL_SECONDS = 300;

    public function __construct(
        private readonly SmsOtp $otp,
        private readonly Sms $sms,
        private readonly Recommendations $recommendations,
        private readonly ItemPricing $pricing,
    ) {}

    public static function enabled(int $storeId): bool
    {
        return ! empty(store_options($storeId, 'kiosk_loyalty'));
    }

    private static function key(int $storeId): string
    {
        return 'kiosk.loyalty.'.$storeId;
    }

    /** Send a code when the phone belongs to a customer of this store. The answer never says which. */
    public function requestCode(Store $store, string $phone): void
    {
        if ($this->customerByPhone($store, $phone) !== null) {
            $this->otp->request($phone, OtpScope::kiosk((int) $store->id));
        }
    }

    public function verifyCode(Store $store, string $phone, string $code, Session $session): bool
    {
        $customer = $this->customerByPhone($store, $phone);
        // verify() is asked even for an unknown phone (same work, and a stray code is consumed)
        $ok = $this->otp->verify($phone, $code, OtpScope::kiosk((int) $store->id));
        if (! $ok || $customer === null) {
            return false;
        }
        $this->remember($store, $customer, $session);

        return true;
    }

    /** A pass for the signed-in customer's own phone (shown as a QR, scanned by the kiosk). */
    public function issuePass(Store $store, Customer $customer): string
    {
        abort_unless((int) $customer->store_id === (int) $store->id, 404);
        $body = self::b64(json_encode(['s' => (int) $store->id, 'c' => (int) $customer->id, 'e' => now()->getTimestamp() + self::PASS_TTL_SECONDS, 'n' => bin2hex(random_bytes(12))]) ?: '');

        return $body.'.'.self::b64(hash_hmac('sha256', 'kiosk-pass|'.$body, self::secret(), true));
    }

    public function redeemPass(Store $store, string $token, Session $session): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals(self::b64(hash_hmac('sha256', 'kiosk-pass|'.$parts[0], self::secret(), true)), $parts[1])) {
            return false;
        }
        $p = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        if (! is_array($p) || (int) ($p['s'] ?? 0) !== (int) $store->id || (int) ($p['e'] ?? 0) < now()->getTimestamp() || ! is_string($p['n'] ?? null)) {
            return false;
        }
        if (! Cache::add('kiosk-pass-used:'.$p['n'], 1, self::PASS_TTL_SECONDS + 60)) {
            return false; // single use: a photo of the code is no good once it was scanned
        }
        $customer = Customer::query()->where('store_id', $store->id)->find((int) ($p['c'] ?? 0));
        if ($customer === null) {
            return false;
        }
        $this->remember($store, $customer, $session);

        return true;
    }

    public function forget(int $storeId, Session $session): void
    {
        $session->forget(self::key($storeId));
    }

    /** The recognised customer of this kiosk session, or null (expired, other store, gone). */
    public function current(Store $store, Session $session): ?Customer
    {
        $s = $session->get(self::key((int) $store->id));
        if (! is_array($s) || (int) ($s['until'] ?? 0) < now()->getTimestamp()) {
            $this->forget((int) $store->id, $session);

            return null;
        }

        return Customer::query()->where('store_id', $store->id)->find((int) ($s['customer'] ?? 0));
    }

    /**
     * What the kiosk shows once the customer is recognised.
     *
     * @return array{name: string, points: int, reward: array{threshold: int, discount: array{minor: int, formatted: string}, can_redeem: bool}|null, favourites: list<array<string, mixed>>, orders: list<array<string, mixed>>}
     */
    public function summary(Store $store, Customer $customer): array
    {
        $ordered = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.store_id', $store->id)->where('orders.customer_id', $customer->id)->where('orders.status', '!=', 'cancelled')
            ->whereNotNull('order_items.item_id')
            ->groupBy('order_items.item_id')->orderByRaw('sum(order_items.quantity) desc')->orderBy('order_items.item_id')
            ->limit(30)->pluck('order_items.item_id')->map(fn ($id) => (int) $id)->all();
        $recs = $this->recommendations->recommend($store, Recommendations::CHANNEL_KIOSK, [], new RecommendationCustomer((int) $customer->id, $ordered));
        $ids = array_map(fn (Recommendation $r) => $r->itemId, $recs);
        $items = Menu::query()->where('store_id', $store->id)->whereIn('id', $ids)->get()->keyBy('id');
        $favourites = [];
        foreach ($ids as $id) {
            if (($m = $items[$id] ?? null) !== null) {
                $favourites[] = ['item' => (string) $m->public_id, 'name' => (string) $m->name, 'price' => $this->money($store, $this->pricing->unitPrice($m, (float) $m->price))];
            }
        }
        $orders = Order::query()->where('store_id', $store->id)->where('customer_id', $customer->id)->where('status', '!=', 'cancelled')
            ->orderByDesc('id')->limit(3)->get(['id', 'created_at']);
        $first = trim((string) strtok(trim((string) $customer->name), ' '));
        $points = (int) (LoyaltyAccount::query()->where('store_id', $store->id)->where('customer_id', $customer->id)->value('balance') ?? 0);

        return [
            'name' => $first,
            'points' => $points,
            'reward' => $this->reward($store, $points),
            'favourites' => $favourites,
            'orders' => $orders->map(fn (Order $o) => ['id' => (int) $o->id, 'date' => $o->created_at?->toDateString(),
                'items' => (int) OrderItem::query()->where('order_id', $o->id)->sum('quantity')])->values()->all(),
        ];
    }

    /**
     * One-tap reorder of one of the customer's orders at this store, priced from today's menu.
     *
     * @return array{add: list<array<string, mixed>>, choose: list<array<string, mixed>>, unavailable: list<string>}|null null = not their order here
     */
    public function reorder(Store $store, Customer $customer, int $orderId): ?array
    {
        $order = Order::query()->where('store_id', $store->id)->where('customer_id', $customer->id)->find($orderId);
        if ($order === null) {
            return null;
        }
        $out = ['add' => [], 'choose' => [], 'unavailable' => []];
        $lines = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get();
        $menus = Menu::query()->where('store_id', $store->id)->whereIn('id', $lines->pluck('item_id')->filter()->all())->get()->keyBy('id');
        foreach ($lines as $line) {
            $m = $line->item_id ? ($menus[(int) $line->item_id] ?? null) : null;
            if ($m === null || ! $m->active || $m->is_sold_out) {
                $out['unavailable'][] = $m !== null ? (string) $m->name : ___('An item no longer on the menu');

                continue;
            }
            if (! empty($line->options)) {
                $out['choose'][] = ['item' => (string) $m->public_id, 'name' => (string) $m->name]; // choices are picked again, today

                continue;
            }
            $variant = null;
            if ($line->variation) {
                $variant = MenuVariant::query()->where('menu_id', $m->id)->where('active', true)->find((int) $line->variation);
                if ($variant === null) {
                    $out['choose'][] = ['item' => (string) $m->public_id, 'name' => (string) $m->name];

                    continue;
                }
            }
            $base = $variant !== null && $variant->price !== null ? (float) $variant->price : (float) $m->price;
            $out['add'][] = ['item' => (string) $m->public_id, 'variant' => $variant?->public_id, 'qty' => max(1, (int) $line->quantity), 'name' => (string) $m->name,
                'unit_price' => $this->money($store, $this->pricing->unitPrice($m, $base))];
        }

        return $out;
    }

    /**
     * The store's points reward as the checkout redeems it (OrderPricing::redeemLoyalty: reward_threshold points for
     * reward_discount off, server-side, once per order). Display only — the kiosk sends `loyalty_redeem` and the order
     * path re-checks the program, the balance and the customer it recognised.
     *
     * @return array{threshold: int, discount: array{minor: int, formatted: string}, can_redeem: bool}|null
     */
    private function reward(Store $store, int $points): ?array
    {
        if (! FeatureAccess::storeAllowsCached((int) $store->id, 'loyalty')) {
            return null;
        }
        $lp = LoyaltyProgram::query()->where('store_id', $store->id)->where('active', true)->first();
        if ($lp === null || (float) $lp->reward_discount <= 0 || (int) $lp->reward_threshold <= 0) {
            return null;
        }

        return ['threshold' => (int) $lp->reward_threshold, 'discount' => $this->money($store, (float) $lp->reward_discount), 'can_redeem' => $points >= (int) $lp->reward_threshold];
    }

    private function remember(Store $store, Customer $customer, Session $session): void
    {
        $session->migrate(true); // a fresh session id once someone is recognised
        $session->put(self::key((int) $store->id), ['customer' => (int) $customer->id, 'until' => now()->getTimestamp() + self::TTL_SECONDS]);
    }

    private function customerByPhone(Store $store, string $phone): ?Customer
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }
        $variants = array_values(array_unique(array_filter([$phone, $this->sms->normalizePhone($phone)])));

        return Customer::query()->where('store_id', $store->id)->whereIn('phone', $variants)->first();
    }

    /** @return array{minor: int, formatted: string} */
    private function money(Store $store, float $major): array
    {
        $currency = (string) (store_options((int) $store->id, 'currency_code') ?: config('settings.currency_code', 'USD'));
        $minor = Money::minorFromMajor((string) $major, $currency);

        return ['minor' => $minor, 'formatted' => store_price_symbol(Money::majorFromMinor($minor, $currency), (int) $store->id)];
    }

    private static function secret(): string
    {
        return hash_hmac('sha256', 'juwri-kiosk-pass', (string) config('app.key'), true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
