<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use App\Models\Order;
use App\Models\StoreTable;
use App\Services\OrderApiService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Ordering\Data\SlotException;
use Juwri\Modules\Ordering\Models\SlotHold;

/**
 * P3-06b (REVIEW, Opus: idempotency + money + tenancy + payments) — `POST /api/v1/storefront/orders` with the new
 * body (kit/api OrderSubmitRequest). In this order:
 *
 *  1. `Idempotency-Key` is required and must be a UUIDv7 (README §2.7, J27-A2); else 422 `validation_failed`.
 *  2. The body validates (StorefrontCart rules) — 422 `validation_failed`.
 *  3. Idempotency: the key is the order's `client_uuid` (UNIQUE per store) and the request hash (sha256 of the
 *     canonical body) its `client_order_hash`. The same key + the same body answers the first order again
 *     (`Idempotent-Replayed: true`, `replayed: true`); the same key + another body → 409 `idempotency_conflict`. A
 *     race between two submits with one key is settled by the unique index inside OrderApiService (replay or 409).
 *  4. Payment checks that need no pricing: `payment_mode` (if sent) equals `payment.mode` (else 422
 *     `validation_failed`); a hosted `return_url` must be on an origin in the token's allowlist (422
 *     `return_url_not_allowed`).
 *  5. The cart is priced again (StorefrontCart): any unorderable line refuses the order (422 `out_of_stock`,
 *     `details.line_ids`) — a submit never drops a line silently; a changed total answers 409 `quote_changed` with the
 *     new quote in `details.quote`; the store must be open (409 `store_closed`) unless the order is scheduled; the
 *     minimum order applies (422 `below_minimum`); `payment.mode` must be one of the quote's `payment_modes` (422
 *     `payment_mode_unavailable`) and `payment.method_id` one of its methods for that mode (422
 *     `payment_method_unavailable`).
 *  6. The order is created by THE order path (OrderApiService::createOrReplay, source `storefront_api`) from the
 *     same resolved lines the quote priced: `pay_later` → placed now; `hosted` → `unpaid` with the 15-minute window
 *     and a pending payment on the core-hosted page (HostedPayments::open).
 */
final class StorefrontOrderPlacement
{
    public function __construct(
        private readonly StorefrontCart $cart,
        private readonly StorefrontOrdering $ordering,
        private readonly HostedPayments $hosted,
        private readonly StorefrontOrderView $view,
        private readonly DeliverySlotBook $slots,
        private readonly GroceryCatalog $grocery,
        private readonly StorefrontSlotView $slotView,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array{0: array<string, mixed>, 1: bool} [OrderCreated body, replayed]
     *
     * @throws StorefrontApiException
     */
    public function place(StorefrontApiContext $ctx, Request $request, array $body): array
    {
        $key = self::idempotencyKey($request);
        $body = StorefrontCart::validate($body, true);
        $hash = self::requestHash($body);

        // 3. A key already used at this store: replay the first order, or refuse another body under it.
        $existing = Order::query()->where('store_id', $ctx->store->id)->where('client_uuid', $key)->first();
        if ($existing !== null) {
            if ($existing->client_order_hash !== null && ! hash_equals((string) $existing->client_order_hash, $hash)) {
                throw self::conflict();
            }

            return [$this->view->created($ctx, $existing, true), true];
        }

        // 4. Payment fields that need no price.
        $mode = (string) $body['payment']['mode'];
        if (isset($body['payment_mode']) && $body['payment_mode'] !== $mode) {
            throw StorefrontApiException::validation(['payment_mode' => [___('payment_mode must equal payment.mode.')]]);
        }
        $returnUrl = $mode === 'hosted' ? (string) $body['payment']['return_url'] : null;
        if ($returnUrl !== null && ! self::originAllowed($ctx, $returnUrl)) {
            throw new StorefrontApiException(422, 'return_url_not_allowed', ___('The return_url origin is not in this token\'s allowed origins.'));
        }

        // 5. Price again — exactly what the order will charge.
        $priced = $this->cart->price($ctx, $body);
        if ($priced->refused !== []) {
            throw new StorefrontApiException(422, 'out_of_stock', ___('Some items in this cart cannot be ordered right now.'), null, ['line_ids' => array_keys($priced->refused)]);
        }
        // P3-08: a delivered order (delivery, or shipped and not picked up in store) needs the address it goes to.
        if (in_array($priced->orderType, ['delivery', 'ship'], true) && ! $priced->isPickup() && empty($body['address'])) {
            throw StorefrontApiException::validation(['address' => [___('An address is required for this order type.')]]);
        }
        // P3-08: a ship order needs a shipping method the store offers for the address.
        if ($priced->shippingProblem === 'out_of_zone') {
            throw new StorefrontApiException(422, 'out_of_zone', ___('We can\'t ship to this address. Try another address or pick up in store.'));
        }
        if ($priced->shippingProblem === 'shipping_method') {
            throw StorefrontApiException::validation(['shipping_method_id' => [___('Choose one of the shipping methods offered for this address.')]]);
        }
        if (! hash_equals($priced->hash, (string) $body['quote_hash'])) {
            throw new StorefrontApiException(409, 'quote_changed', ___('The total changed. Check the new total and confirm again.'), null, ['quote' => $this->cart->response($priced, $body)]);
        }
        $scheduledAt = $this->scheduledAt($ctx, $body);
        // lane C: a food schedule-ahead slot (`pslot_`) is the order's scheduled time, in store time; its capacity is
        // the hold's seat, taken under the slot lock when it was held, so the order does not count the slot again
        $slotTime = ($priced->slot['source'] ?? null) === 'scheduling'
            ? CarbonImmutable::instance($priced->slot['local_starts_at'])->format('Y-m-d H:i:s') : null;
        if ($scheduledAt === null && $slotTime === null && ! $this->open($ctx)) {
            throw new StorefrontApiException(409, 'store_closed', ___('This store is not taking orders right now.'));
        }
        $minimum = $this->cart->minimumMinor($priced);
        if ($minimum !== null && $priced->priced->subtotal < $minimum) {
            throw new StorefrontApiException(422, 'below_minimum', ___('This order is below the store\'s minimum order.'));
        }
        if (! in_array($mode, $this->cart->paymentModes($priced), true)) {
            throw new StorefrontApiException(422, 'payment_mode_unavailable', ___('This payment option is not available for this order.'));
        }
        $methodId = $body['payment']['method_id'] ?? null;
        if ($methodId !== null && ! StorefrontCart::methodMatchesMode((string) $methodId, $mode, $priced->orderType, $priced->isPickup())) {
            throw new StorefrontApiException(422, 'payment_method_unavailable', ___('This payment method is not available for this order.'));
        }
        $hold = $this->slotHold($ctx, $request, $priced, $body);

        // 6. Create through the one order path, from the lines the quote priced.
        $items = $this->ordering->orderLines($ctx->store, array_map(fn (array $l) => [
            'item' => $l['item'], 'qty' => $l['qty'], 'variant' => $l['variant'], 'options' => $l['options'], 'note' => $l['note'],
        ], $priced->lines));
        if ($items === null) {
            throw new StorefrontApiException(422, 'out_of_stock', ___('Some items in this cart cannot be ordered right now.'), null, ['line_ids' => array_column($priced->lines, 'line_id')]);
        }
        $customer = (array) ($body['customer'] ?? []);
        $shipping = $priced->shipping();
        $pickupBranch = $shipping !== null && $shipping->method->kind === 'pickup_in_store' ? $shipping->method->pickup_branch_id : null;
        $data = [
            'type' => $priced->orderType,
            'items' => $items,
            // API-24d: a kiosk device's orders are tagged server-side (the body cannot set the source)
            'source' => $ctx->isKiosk() ? 'kiosk' : 'storefront_api',
            'client_uuid' => $key,
            'client_order_hash' => $hash,
            'customer_name' => $ctx->customer?->name ?: ($customer['name'] ?? null),
            'phone' => $customer['phone'] ?? ($ctx->customer?->phone ?: null),
            'customer_id' => $ctx->customer?->id,
            'coupon_code' => $priced->coupon,
            'tip_amount' => Money::majorFromMinor($priced->tipMinor, $priced->priced->currency),
            'branch_id' => $pickupBranch ?? $ctx->branch?->id,
            'address' => isset($body['address']) && ! $priced->isPickup() ? self::addressLine((array) $body['address']) : null,
            'delivery_lat' => $body['address']['lat'] ?? null,
            'delivery_lng' => $body['address']['lng'] ?? null,
            'table_number' => $priced->orderType === 'on-table' ? $this->table($ctx, $body) : null,
            'note' => null,
            'payment_mode' => $mode,
        ];
        if ($slotTime !== null) {
            $data['scheduled_at_trusted'] = $slotTime;
        } elseif ($scheduledAt !== null) {
            $data['scheduled_at'] = $scheduledAt;
        }
        if ($shipping !== null) {
            // P3-08: the method, fee and window the quote priced (server values; OrderApiService stores them).
            $data['shipping_method_id'] = (int) $shipping->method->id;
            $data['shipping_fee_trusted'] = Money::majorFromMinor($shipping->feeMinor, $priced->priced->currency);
            $data['shipping_eta_from'] = $shipping->earliest->toDateString();
            $data['shipping_eta_to'] = $shipping->latest->toDateString();
        }
        if ($mode === 'hosted') {
            $data['awaiting_payment'] = true;
        }
        // P3-09: committed with the order — the slot hold becomes the order's (a hosted order's until its window
        // ends), express, and the out-of-stock choices snapshotted on the order and on each line (API-22, API-25).
        $holdToken = (string) $request->header('X-Hold-Token', '');
        $data['after_persist'] = function (Order $order) use ($ctx, $priced, $hold, $holdToken) {
            if ($hold !== null) {
                $locked = $this->slots->lockForOrder($ctx->store, (string) $hold['hold']->public_id, $hold['token'] ?? $holdToken, null);
                $this->slots->bind($locked, $order, $order->payment_mode === 'hosted' ? $order->payment_expires_at : null);
            }
            $this->snapshotGrocery($ctx, $order, $priced);
        };

        try {
            [$order, $replayed] = OrderApiService::createOrReplay((int) $ctx->store->user_id, (int) $ctx->store->id, $data);
        } catch (ClientOrderConflict) {
            throw self::conflict();
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();
            // P3-08: the race-safe stock take refused a line (another shopper got the last units first).
            $short = [];
            foreach (array_keys($errors) as $field) {
                if (preg_match('/^items\.(\d+)\.stock$/', (string) $field, $m) === 1) {
                    $short[] = (string) ($priced->lines[(int) $m[1]]['line_id'] ?? 'ln_'.((int) $m[1] + 1));
                }
            }
            if ($short !== []) {
                throw new StorefrontApiException(422, 'out_of_stock', ___('Some items in this cart cannot be ordered right now.'), null, ['line_ids' => $short]);
            }
            throw StorefrontApiException::validation($errors);
        } catch (\InvalidArgumentException $e) {
            throw StorefrontApiException::validation(['scheduled_at' => [$e->getMessage()]]);
        } catch (SlotException $e) { // @phpstan-ignore catch.neverThrown (thrown by the slot step inside the order transaction, after_persist)
            throw $this->slotView->refusal($ctx, $e);
        }

        if ($mode === 'hosted') {
            // P3-09: a weighed basket authorises the cap (quote total + what the weighed lines may add)
            $this->hosted->open($ctx->store, $order, $returnUrl, $request->ip(), $priced->isWeighed() ? $priced->holdAmountMinor() : null);
        }

        return [$this->view->created($ctx, $order->fresh() ?? $order, $replayed), $replayed];
    }

    /**
     * P3-09 (API-3, API-11): the slot of the order, held before the order is written. A store with delivery /
     * collection slots needs one (or express); the cart's hold must be this store's with the `X-Hold-Token`, and a hold
     * whose 10 minutes ended is taken again while its slot has room (else 409 `slot_full` with the next free slot). A
     * slot chosen without a hold is held now, the same way. The radius applies when the address carries coordinates.
     *
     * @param  array<string, mixed>  $body
     * @return array{hold: SlotHold, token: string|null}|null
     *
     * @throws StorefrontApiException
     */
    private function slotHold(StorefrontApiContext $ctx, Request $request, PricedCart $priced, array $body): ?array
    {
        $slotted = $this->slots->offers($ctx->store, $ctx->branch, $priced->orderType);
        if ($slotted && $priced->slot === null && ! $priced->express && ! $ctx->isKiosk()) {
            throw StorefrontApiException::validation(['slot_id' => [$priced->orderType === 'delivery'
                ? ___('Choose a delivery time.') : ___('Choose a collection time.')]]);
        }
        $radiusBranch = $priced->slot['branch'] ?? $ctx->branch;
        if ($priced->orderType === 'delivery' && ! $ctx->isKiosk() && $this->slots->radiusApplies($ctx->store, $radiusBranch)
            && ! (isset($body['address']['lat'], $body['address']['lng']) && is_numeric($body['address']['lat']) && is_numeric($body['address']['lng']))) {
            // a radius is checked on the address's pin: an address without one cannot be checked, so it is refused
            throw new StorefrontApiException(422, 'pin_required', ___('Drop a pin on the map so we can check that we deliver to you.'));
        }
        if ($priced->orderType === 'delivery' && isset($body['address']) && is_array($body['address'])
            && $this->slots->inRadius($ctx->store, $priced->slot['branch'] ?? $ctx->branch, (array) $body['address']) === false) {
            throw new StorefrontApiException(422, 'out_of_zone', ___('This address is outside our delivery area.'));
        }
        if ($priced->slot === null) {
            return null;
        }
        try {
            if ($priced->holdId !== null) {
                $hold = $this->slots->ensureLive($ctx->store, $ctx->branch, $priced->holdId, (string) $request->header('X-Hold-Token', ''), (string) $priced->slot['id']);

                return ['hold' => $hold, 'token' => (string) $request->header('X-Hold-Token', '')];
            }
            $holder = hash('sha256', 'sft:'.$ctx->token->getKey().'|'.(string) $request->ip());
            $made = $this->slots->hold($ctx->store, $ctx->branch, (string) $priced->slot['id'], $holder, null);

            return ['hold' => $made['hold'], 'token' => $made['token']];
        } catch (SlotException $e) {
            throw $this->slotView->refusal($ctx, $e);
        }
    }

    /** P3-09: express + the out-of-stock choices, on the order and on each line (the effective option of a line). */
    private function snapshotGrocery(StorefrontApiContext $ctx, Order $order, PricedCart $priced): void
    {
        $order->forceFill(['express' => $priced->express])->save();
        $preference = $priced->substitutionPreference !== null ? $this->grocery->substitutionOption($ctx->store, $priced->substitutionPreference) : null;
        // API-22: an order without a preference follows the store default (and its lines their own choice, if any)
        $preference ??= $this->grocery->defaultSubstitution($ctx->store);
        if ($preference === null) {
            return;
        }
        $order->forceFill(['substitution_option_id' => $preference->id])->save();
        $items = $order->items()->orderBy('id')->get()->values();
        foreach ($priced->lines as $i => $line) {
            $item = $items->get($i);
            if ($item === null) {
                break;
            }
            $menu = $item->menu;
            if ($menu === null || $this->grocery->substitutionGroupFor($ctx->store, $menu) === null) {
                continue; // an item that takes no out-of-stock choice
            }
            $option = ($line['substitution'] ?? null) !== null ? $this->grocery->substitutionOption($ctx->store, (string) $line['substitution']) : null;
            $option ??= $preference;
            $item->forceFill([
                'substitution_option_id' => $option->id,
                'picker_action' => $option->picker_action,
                'picker_fallback' => $option->picker_action === 'call_customer' ? ($option->picker_fallback ?: 'remove_item') : null,
            ])->save();
        }
    }

    /**
     * The required `Idempotency-Key` header (README §2.7): a UUIDv7.
     *
     * @throws StorefrontApiException
     */
    public static function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            throw StorefrontApiException::validation(['Idempotency-Key' => [___('The Idempotency-Key header is required.')]]);
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key) !== 1) {
            throw StorefrontApiException::validation(['Idempotency-Key' => [___('The Idempotency-Key header must be a UUIDv7.')]]);
        }

        return strtolower($key);
    }

    /**
     * sha256 of the canonical request body (keys sorted recursively), stored next to the key: the same key with a
     * different body is a conflict, never a second order.
     *
     * @param  array<string, mixed>  $body
     */
    public static function requestHash(array $body): string
    {
        return ClientOrderId::fingerprint($body); // canonical: keys sorted recursively
    }

    public static function conflict(): StorefrontApiException
    {
        return new StorefrontApiException(409, 'idempotency_conflict', ___('This Idempotency-Key was already used with a different request body.'));
    }

    /** Is `$url`'s origin (scheme://host[:port]) in the storefront token's allowed origins? */
    public static function originAllowed(StorefrontApiContext $ctx, string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['https', 'http'], true)) {
            return false;
        }
        $origin = strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $allowed = array_map(fn ($o) => rtrim(strtolower((string) $o), '/'), (array) ($ctx->token->allowed_origins ?? []));

        return in_array($origin, $allowed, true);
    }

    /** @param  array<string, mixed>  $address */
    private static function addressLine(array $address): string
    {
        $parts = array_filter(array_map(fn ($k) => isset($address[$k]) ? trim((string) $address[$k]) : '', ['line1', 'line2', 'city', 'region', 'postal_code', 'country']));
        $line = implode(', ', $parts);
        if (! empty($address['notes'])) {
            $line .= ' ('.trim((string) $address['notes']).')';
        }

        return mb_substr($line, 0, 255);
    }

    /**
     * The requested time as the scheduling service takes it (Y-m-d H:i, store time), or null for as soon as possible.
     *
     * @param  array<string, mixed>  $body
     */
    private function scheduledAt(StorefrontApiContext $ctx, array $body): ?string
    {
        if (empty($body['scheduled_at'])) {
            return null;
        }
        $tz = (string) ($ctx->branch?->timezone ?: store_tz((int) $ctx->store->id));

        return Carbon::parse((string) $body['scheduled_at'])->setTimezone($tz)->format('Y-m-d H:i');
    }

    /**
     * The dine-in table: the QR table key (or the table number) of a table of THIS store (and the branch when one
     * is chosen); its number goes on the order. A table of another store is unknown here.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws StorefrontApiException
     */
    private function table(StorefrontApiContext $ctx, array $body): int|string
    {
        $ref = trim((string) ($body['table'] ?? ''));
        if ($ref === '') {
            throw StorefrontApiException::validation(['table' => [___('Scan the table\'s QR code to order at the table.')]]);
        }
        $table = StoreTable::query()
            ->where('store_id', $ctx->store->id)
            ->where('area_type', '!=', 'room')
            ->when($ctx->branch !== null, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $ctx->branch?->id)->orWhereNull('branch_id')))
            ->where(function ($q) use ($ref) {
                $q->where('key', $ref);
                if (ctype_digit($ref) && strlen($ref) < 9) {
                    $q->orWhere('table_no', (int) $ref); // an integer column: never compare it with text (PostgreSQL)
                }
            })
            ->first();
        if ($table === null) {
            throw StorefrontApiException::validation(['table' => [___('This table was not found at this store.')]]);
        }

        return is_numeric($table->table_no) ? (int) $table->table_no : (string) ($table->label ?: $table->table_no);
    }

    private function open(StorefrontApiContext $ctx): bool
    {
        // P6-346 + P6-347: delegate the store-level gate to the shared storefront
        // helper, which honours `prevent_order_on_close` (read here through StoreSettings,
        // the same way `open_close_store` was read before), the manual master switch,
        // a paused branch, the store-clock timezone for free-text timing, and the
        // "open if ANY active branch is open + accepting" semantic for structured hours.
        if ($ctx->branch !== null && ! (bool) ($ctx->branch->accepting_orders ?? true)) {
            return false; // the posted branch is paused
        }

        return store_accepts_orders_now($ctx->store);
    }
}
