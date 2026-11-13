<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use Juwri\Core\Models\Branch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\RefundSwitches;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Menu\Contracts\QuoteItems;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Contracts\Shipping;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Ordering\Data\StorefrontQuote;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;

/**
 * P3-06b (REVIEW, Opus: money + tenancy) — the headless cart (kit/api `CartRequest`) priced by the server: the one
 * place `POST /quote` and `POST /orders` turn a cart into money, so a submit is priced exactly like the quote the
 * shopper saw (contract §5.2/§5.3).
 *
 *  - Lines name items, variants and options by public id; StorefrontOrdering resolves them strictly inside the
 *    token's store (another store's id is simply not orderable here, never read).
 *  - Prices come from StorefrontOrdering::quote — OrderApiService line resolution + Order::computeMoney, the
 *    derivation every order total reads — so quote total == order total.
 *  - A sellable-but-changed cart never fails a quote: an unorderable line becomes a warning (`out_of_stock` /
 *    `item_unavailable`) and the rest is priced. Only a cart with no orderable line at all is refused (422).
 *  - `quote_hash` is an HMAC (app key) over the store, the priced inputs (order type, branch, lines, coupon, tip)
 *    and every priced amount, so a submit can prove it saw these exact totals; `payment_mode` and client
 *    `line_id`s are not priced and not in it (README §2.12).
 */
final class StorefrontCart
{
    /** Kit order-type keys (View Model `context.order_types[].key`). */
    public const ORDER_TYPE_KEYS = ['dine_in', 'takeaway', 'delivery', 'pickup', 'pickup_slot', 'appointment', 'at_home', 'ship', 'room_service', 'walk_in'];

    public function __construct(
        private readonly ViewModelBuilder $builder,
        private readonly StorefrontOrdering $ordering,
        private readonly Shipping $shippingQuotes,
        private readonly RetailCatalog $retail,
        private readonly GroceryCatalog $grocery,
        private readonly DeliverySlotBook $slots,
    ) {}

    /**
     * Laravel rules of a CartRequest (openapi.json); `$submit` adds OrderSubmitRequest's quote_hash / customer / payment.
     *
     * @return array<string, mixed>
     */
    public static function rules(bool $submit = false): array
    {
        $uuid7 = 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
        $rules = [
            'order_type' => ['required', 'string', 'in:'.implode(',', self::ORDER_TYPE_KEYS)],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*' => ['array'],
            'lines.*.line_id' => ['sometimes', 'string', 'max:64'],
            'lines.*.item_id' => ['required', 'string', 'max:64'],
            'lines.*.variant_id' => ['sometimes', 'string', 'max:64'],
            'lines.*.option_ids' => ['sometimes', 'array', 'max:50'],
            'lines.*.option_ids.*' => ['string', $uuid7],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'lines.*.note' => ['sometimes', 'string', 'max:200'],
            'lines.*.substitution' => ['sometimes', 'string', $uuid7],
            'coupon' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tip' => ['sometimes', 'array'],
            'tip.choice_id' => ['sometimes', 'string', 'max:32'],
            'tip.minor' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'address' => ['sometimes', 'array'],
            'address.country' => ['required_with:address', 'string', 'regex:/^[A-Z]{2}$/'],
            'address.address_id' => ['sometimes', 'string', 'max:64'],
            'address.line1' => ['sometimes', 'string', 'max:255'],
            'address.line2' => ['sometimes', 'string', 'max:255'],
            'address.city' => ['sometimes', 'string', 'max:120'],
            'address.region' => ['sometimes', 'string', 'max:120'],
            'address.postal_code' => ['sometimes', 'string', 'max:32'],
            'address.lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'address.lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'address.notes' => ['sometimes', 'string', 'max:300'],
            'shipping_method_id' => ['sometimes', 'string', 'max:64'],
            'slot_id' => ['sometimes', 'string', 'max:64'],
            'return_slot_id' => ['sometimes', 'string', 'max:64'],
            'hold_id' => ['sometimes', 'string', 'max:64'],
            'express' => ['sometimes', 'boolean'],
            'substitution_preference' => ['sometimes', 'string', $uuid7],
            'table' => ['sometimes', 'string', 'max:120'],
            'scheduled_at' => ['sometimes', 'date'],
            'payment_method_id' => ['sometimes', 'string', 'max:32'],
            'payment_mode' => ['sometimes', 'in:pay_later,hosted'],
        ];
        if ($submit) {
            $rules += [
                'quote_hash' => ['required', 'string', 'max:64'],
                'customer' => ['sometimes', 'array'],
                'customer.name' => ['sometimes', 'nullable', 'string', 'max:120'],
                'customer.phone' => ['sometimes', 'nullable', 'string', 'max:25'],
                'customer.email' => ['sometimes', 'nullable', 'email', 'max:191'],
                'customer.marketing_opt_in' => ['sometimes', 'boolean'],
                'payment' => ['required', 'array'],
                'payment.mode' => ['required', 'in:pay_later,hosted'],
                'payment.method_id' => ['sometimes', 'string', 'max:32'],
                'payment.return_url' => ['required_if:payment.mode,hosted', 'string', 'url:https,http', 'max:2000'],
            ];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> the validated body
     *
     * @throws StorefrontApiException 422 validation_failed
     */
    public static function validate(array $body, bool $submit = false): array
    {
        $validator = Validator::make($body, self::rules($submit));
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
            throw StorefrontApiException::validation($errors);
        }

        return $body;
    }

    /**
     * Laundry's delivery-back slot (API-23) arrives with the laundry variant (P3-11): a client that sends one gets a
     * field error instead of an order that silently ignores it.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws StorefrontApiException
     */
    private static function refuseLaterInputs(array $body): void
    {
        if (array_key_exists('return_slot_id', $body)) {
            throw StorefrontApiException::validation(['return_slot_id' => [___('This store does not offer that choice.')]]);
        }
    }

    /**
     * Price a validated cart for the API context. Throws 422 `invalid_order_type` for a type the store does not
     * offer now, 422 `out_of_stock` (details.line_ids) when no line is orderable.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws StorefrontApiException
     */
    public function price(StorefrontApiContext $ctx, array $body): PricedCart
    {
        // Load test follow-up: each item is loaded once (with its choice groups and add-ons) for the whole quote.
        return app(QuoteItems::class)->during(fn () => $this->priceCart($ctx, $body));
    }

    /** @param  array<string, mixed>  $body */
    private function priceCart(StorefrontApiContext $ctx, array $body): PricedCart
    {
        self::refuseLaterInputs($body);
        $core = $this->coreOrderType($ctx, (string) $body['order_type']);

        $lines = [];
        foreach (array_values((array) $body['lines']) as $i => $line) {
            $lines[] = [
                'line_id' => isset($line['line_id']) ? (string) $line['line_id'] : 'ln_'.($i + 1),
                'item' => (string) $line['item_id'],
                'qty' => (int) $line['qty'],
                'variant' => isset($line['variant_id']) ? (string) $line['variant_id'] : null,
                'options' => array_values(array_map('strval', (array) ($line['option_ids'] ?? []))),
                'note' => isset($line['note']) ? (string) $line['note'] : null,
                'substitution' => isset($line['substitution']) ? (string) $line['substitution'] : null,
            ];
        }
        app(QuoteItems::class)->prime((int) $ctx->store->id, array_column($lines, 'item'));
        // P3-09: the out-of-stock choices (API-22) and the delivery / collection slot (API-3) — checked before pricing
        $preference = $this->substitutions($ctx, $lines, $body);
        $slotChoice = $this->slotChoice($ctx, $core, $body);
        $coupon = isset($body['coupon']) && trim((string) $body['coupon']) !== '' ? trim((string) $body['coupon']) : null;

        // Orderable lines only; the others become warnings (quote) or a refusal (submit, the caller's choice). The
        // whole cart is tried first; only a cart with a bad line is probed line by line.
        $orderable = $lines;
        $refused = [];
        if ($this->ordering->quote($ctx->store, array_map(fn (array $l) => self::orderingLine($l), $lines), $core, $ctx->branch) === null) {
            $orderable = [];
            foreach ($lines as $line) {
                if ($this->ordering->quote($ctx->store, [self::orderingLine($line)], $core, $ctx->branch) !== null) {
                    $orderable[] = $line;
                } else {
                    $refused[$line['line_id']] = $this->lineRefusal($ctx, $line);
                }
            }
        }
        if ($orderable === []) {
            throw new StorefrontApiException(422, 'out_of_stock', ___('None of the items in this cart can be ordered right now.'), null, ['line_ids' => array_keys($refused)]);
        }

        // P3-08: a ship cart is priced with the chosen shipping method for its address (fee, free threshold).
        $methodId = isset($body['shipping_method_id']) && $body['shipping_method_id'] !== '' ? (string) $body['shipping_method_id'] : null;
        $address = isset($body['address']) && is_array($body['address']) ? (array) $body['address'] : [];
        $shipping = $core === 'ship' && $methodId !== null ? ['method' => $methodId, 'address' => $address] : null;

        $tipMinor = 0;
        $fee = $slotChoice['fee_minor'];
        $quoted = $this->quoteLines($ctx, $orderable, $core, $coupon, 0, $shipping, $fee);
        if ($this->tipsAllowed($ctx, $core) && isset($body['tip']) && is_array($body['tip'])) {
            $tipMinor = $this->tipMinor($body['tip'], $quoted['priced']);
            if ($tipMinor > 0) {
                $quoted = $this->quoteLines($ctx, $orderable, $core, $coupon, $tipMinor, $shipping, $fee);
            }
        }
        $problem = null;
        if ($core === 'ship' && ($shipping === null || $quoted['priced']->shippingRefused)) {
            $problem = $address !== [] && $this->shippingQuotes->zoneFor($ctx->store, $address) === null
                && ($methodId === null || $quoted['priced']->shippingRefused) && ! $this->pickupOffered($ctx, $methodId)
                ? 'out_of_zone' : 'shipping_method';
        }

        // The view model numbers its lines ln_1…; the API echoes the client's line ids (same order).
        foreach ($quoted['quote']['lines'] as $i => $presented) {
            $quoted['quote']['lines'][$i]['id'] = $orderable[$i]['line_id'] ?? $presented['id'];
        }
        $quoted['quote'] = $this->groceryQuote($ctx, $quoted['quote'], $slotChoice, $preference, $orderable);

        return new PricedCart(
            ctx: $ctx,
            orderType: $core,
            lines: $orderable,
            refused: $refused,
            coupon: $coupon,
            tipMinor: $tipMinor,
            priced: $quoted['priced'],
            quote: $quoted['quote'],
            hash: $this->hash($ctx, $core, $orderable, $coupon, $tipMinor, $quoted['priced'], $slotChoice),
            shippingMethodId: $quoted['priced']->shipping?->publicId(),
            shippingProblem: $problem,
            slot: $slotChoice['slot'],
            holdId: $slotChoice['hold_id'],
            express: $slotChoice['express'],
            substitutionPreference: $preference,
            weighed: $quoted['weighed'] ?? null,
            slotFull: $slotChoice['full'],
        );
    }

    /**
     * P3-09 (API-22): the out-of-stock choices. A line's `substitution` must be an option of its item's substitution
     * group (else 422 on that line); the order's `substitution_preference` an option of the store's group (else 422).
     * Returns the order preference (public id), else the store default, else null when the store takes none.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $body
     *
     * @throws StorefrontApiException
     */
    private function substitutions(StorefrontApiContext $ctx, array $lines, array $body): ?string
    {
        $errors = [];
        foreach ($lines as $i => $line) {
            if ($line['substitution'] === null) {
                continue;
            }
            $item = Menu::query()->where('store_id', $ctx->store->id)->where('public_id', $line['item'])->first();
            $group = $item !== null ? $this->grocery->substitutionGroupFor($ctx->store, $item) : null;
            if ($group === null || $group->modifiers->first(fn ($m) => (string) $m->public_id === $line['substitution']) === null) {
                $errors["lines.$i.substitution"] = [___('Choose one of this item\'s out-of-stock options.')];
            }
        }
        $preference = isset($body['substitution_preference']) ? (string) $body['substitution_preference'] : null;
        if ($preference !== null && $this->grocery->substitutionOption($ctx->store, $preference) === null) {
            $errors['substitution_preference'] = [___('Choose one of the store\'s out-of-stock options.')];
        }
        if ($errors !== []) {
            throw StorefrontApiException::validation($errors);
        }

        return $preference ?? $this->grocery->defaultSubstitution($ctx->store)?->public_id;
    }

    /**
     * P3-09 (API-3, basket §9): the delivery / collection time of the cart — a slot of this store (`slot_id`, with the
     * hold that keeps it, `hold_id`), or express (delivery only). The fee of an express delivery is the store's
     * delivery fee + the express fee; a slot keeps the store's fee (null = Order::deliveryCharge's own).
     *
     * @param  array<string, mixed>  $body
     * @return array{slot: array<string, mixed>|null, hold_id: string|null, express: bool, fee_minor: int|null, full: bool}
     *
     * @throws StorefrontApiException
     */
    private function slotChoice(StorefrontApiContext $ctx, string $core, array $body): array
    {
        $out = ['slot' => null, 'hold_id' => null, 'express' => false, 'fee_minor' => null, 'full' => false];
        $slotId = isset($body['slot_id']) && $body['slot_id'] !== '' ? (string) $body['slot_id'] : null;
        $holdId = isset($body['hold_id']) && $body['hold_id'] !== '' ? (string) $body['hold_id'] : null;
        $express = ! empty($body['express']);
        if ($slotId === null && $holdId === null && ! $express) {
            return $out;
        }
        // lane C: a food store's optional schedule-ahead slots (pre-orders) are held and quoted the same way
        if (! $this->slots->offers($ctx->store, $ctx->branch, $core) && ! $this->slots->schedules($ctx->store, $ctx->branch, $core)) {
            $field = $slotId !== null ? 'slot_id' : ($holdId !== null ? 'hold_id' : 'express');
            throw StorefrontApiException::validation([$field => [___('This store does not offer that choice.')]]);
        }
        if ($express) {
            $offer = $this->slots->express($ctx->store, $ctx->branch, $core);
            if ($offer === null || $slotId !== null || $holdId !== null) {
                throw StorefrontApiException::validation(['express' => [___('Express delivery is not available for this order.')]]);
            }

            return ['express' => true, 'fee_minor' => $this->storeDeliveryFeeMinor($ctx) + $offer['fee_minor']] + $out;
        }
        $hold = $holdId !== null ? $this->slots->findHold($ctx->store, $holdId) : null;
        if ($holdId !== null && $hold === null) {
            throw StorefrontApiException::validation(['hold_id' => [___('This hold was not found.')]]);
        }
        $slotId ??= $hold?->slot_id;
        $slot = $slotId !== null ? $this->slots->find($ctx->store, $ctx->branch, $slotId) : null;
        if ($slot === null || $slot['order_type'] !== $core) {
            throw StorefrontApiException::validation(['slot_id' => [___('This time is not available. Pick another time.')]]);
        }
        if ($hold !== null && $hold->slot_id !== $slotId) {
            throw StorefrontApiException::validation(['hold_id' => [___('The held time is not the time in this order.')]]);
        }
        $live = $hold !== null && $hold->isLive();

        return ['slot' => $slot, 'hold_id' => $holdId, 'express' => false, 'fee_minor' => null, 'full' => ! $live && $slot['state'] === 'full'];
    }

    private function storeDeliveryFeeMinor(StorefrontApiContext $ctx): int
    {
        // P5-31: the branch's own delivery fee when it has one
        $fee = Branch::effectiveDeliveryFee((int) $ctx->store->id, $ctx->branch);

        return Money::minorFromMajor($fee, strtoupper(store_currency((int) $ctx->store->id)));
    }

    /**
     * P3-09: the grocery parts of the presented quote — the delivery row names the slot (or express), the quote names
     * the order's out-of-stock choice, a line its own.
     *
     * @param  array<string, mixed>  $quote
     * @param  array{slot: array<string, mixed>|null, express: bool}  $slotChoice
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function groceryQuote(StorefrontApiContext $ctx, array $quote, array $slotChoice, ?string $preference, array $lines): array
    {
        $label = null;
        if ($slotChoice['slot'] !== null) {
            $tz = $this->slots->timezone($ctx->store, $slotChoice['slot']['branch'] ?? $ctx->branch);
            $label = $slotChoice['slot']['starts_at']->copy()->setTimezone($tz)->format('H:i').' – '.$slotChoice['slot']['ends_at']->copy()->setTimezone($tz)->format('H:i');
        } elseif ($slotChoice['express']) {
            $label = ___('Express');
        }
        if ($label !== null) {
            foreach ($quote['rows'] as $i => $row) {
                if (($row['key'] ?? null) === 'delivery') {
                    $quote['rows'][$i]['label'] = ___('Delivery · :window', ['window' => $label]);
                }
            }
        }
        if ($preference !== null) {
            $option = $this->grocery->substitutionOption($ctx->store, $preference);
            if ($option !== null) {
                $quote['substitution_preference'] = (string) $option->title;
            }
        }
        foreach ($lines as $i => $line) {
            if (($line['substitution'] ?? null) !== null && isset($quote['lines'][$i])) {
                $option = $this->grocery->substitutionOption($ctx->store, (string) $line['substitution']);
                if ($option !== null) {
                    $quote['lines'][$i]['substitution'] = (string) $option->title;
                }
            }
        }

        return $quote;
    }

    /** @return array<string, mixed>|null QuoteResponse.weighed */
    private function weighedBlock(PricedCart $cart): ?array
    {
        if ($cart->weighed === null) {
            return null;
        }
        $money = new MoneyFormat($cart->ctx->store);

        return [
            'estimate' => $money->money($cart->weighed['estimate']),
            'cap' => $money->money($cart->weighed['cap']),
            'cap_percent' => $this->grocery->capPercent($cart->ctx->store),
        ];
    }

    /** Is `$methodId` one of the store's active pickup-in-store methods (offered whatever the address)? */
    private function pickupOffered(StorefrontApiContext $ctx, ?string $methodId): bool
    {
        if ($methodId === null) {
            return false;
        }
        foreach ($this->shippingQuotes->options($ctx->store, [], 0) as $option) {
            if ($option->publicId() === $methodId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The QuoteResponse body of a priced cart (kit/api openapi.json).
     *
     * @param  array<string, mixed>  $body  the validated CartRequest
     * @return array<string, mixed>
     */
    public function response(PricedCart $cart, array $body): array
    {
        $ctx = $cart->ctx;
        $modes = $this->paymentModes($cart);
        $warnings = [];
        foreach ($cart->refused as $lineId => $code) {
            $warnings[] = ['code' => $code, 'line_id' => (string) $lineId, 'message' => $code === 'out_of_stock'
                ? ___('This item is sold out.') : ___('This item is not available right now.')];
        }
        if ($cart->coupon !== null && ! $cart->priced->couponApplied) {
            $warnings[] = ['code' => 'coupon_invalid', 'message' => (string) ($cart->priced->couponMessage ?: ___('This coupon cannot be used.'))];
        }
        $minimum = $this->minimumMinor($cart);
        if ($minimum !== null && $cart->priced->subtotal < $minimum) {
            $warnings[] = ['code' => 'below_minimum', 'message' => ___('The minimum order is :amount.', ['amount' => $this->format($cart, $minimum)])];
        }
        if ($cart->shippingProblem === 'out_of_zone') {
            $warnings[] = ['code' => 'out_of_zone', 'message' => ___('We can\'t ship to this address. Try another address or pick up in store.')];
        }
        if ($cart->slotFull) {
            $warnings[] = ['code' => 'slot_full', 'message' => ___('This delivery time is now full. Pick another time.')];
        }
        if ($cart->slot === null && $cart->orderType === 'delivery' && ! empty($body['address']) && is_array($body['address'])
            && $this->slots->inRadius($ctx->store, $ctx->branch, (array) $body['address']) === false) {
            $warnings[] = ['code' => 'out_of_zone', 'message' => ___('This address is outside our delivery area.')];
        } elseif ($cart->slot !== null && $cart->orderType === 'delivery' && ! empty($body['address']) && is_array($body['address'])
            && $this->slots->inRadius($ctx->store, $cart->slot['branch'] ?? $ctx->branch, (array) $body['address']) === false) {
            $warnings[] = ['code' => 'out_of_zone', 'message' => ___('This address is outside our delivery area.')];
        }
        $mode = $body['payment_mode'] ?? null;
        if (is_string($mode) && ! in_array($mode, $modes, true)) {
            $warnings[] = ['code' => 'payment_mode_unavailable', 'message' => ___('This payment option is not available for this order.')];
        }

        return [
            'quote' => $cart->quote,
            'quote_hash' => $cart->hash,
            'expires_at' => Carbon::now($this->timezone($ctx))->addSeconds((int) config('api.storefront.quote_ttl_seconds', 300))->toIso8601String(),
            'weighed' => $this->weighedBlock($cart),
            'payment_methods' => $this->paymentMethods($cart, $modes, $body),
            'warnings' => $warnings,
            'requires' => $this->requires($cart, $body),
            'payment_modes' => $modes,
        ];
    }

    /**
     * Payment modes the store allows for this cart (API-2): `pay_later` always (cash / card at the counter or on
     * delivery, as the storefront checkout offers), `hosted` when online payment is on for the store and at least
     * one store gateway can take this total.
     *
     * @return list<string>
     */
    public function paymentModes(PricedCart $cart): array
    {
        $modes = ['pay_later'];
        $store = $cart->ctx->store;
        $hosted = app(HostedPayments::class);
        $amount = Money::majorFromMinor($cart->holdAmountMinor(), $cart->priced->currency);
        if (config('settings.admin_allow_online_payment') && StoreSettings::bool((int) $store->id, 'restaurant_online_payment')
            && $cart->priced->total > 0
            && ($cart->isWeighed()
                // D7 (P3-09): a weighed basket pays online only through a gateway that authorises the cap and captures the
                // actual amount — or any gateway when the store refunds the weighed difference (then the cap is charged)
                ? ($hosted->storeCanHold($store, $amount) || (RefundSwitches::allows((int) $store->id, 'weighed_difference') && $hosted->storeCanTake($store, $amount)))
                : $hosted->storeCanTake($store, Money::majorFromMinor($cart->priced->total, $cart->priced->currency)))) {
            $modes[] = 'hosted';
        }

        return $modes;
    }

    /**
     * Is `$methodId` one of the quote's methods, and does it belong to `$mode`? The ids are the kit contract's
     * (`card` online; `cod` cash on delivery, `counter` paid at the store); the theme View Model's `online` / `cash`
     * are accepted as the same choices.
     */
    public static function methodMatchesMode(string $methodId, string $mode, string $orderType = '', bool $pickup = false): bool
    {
        if ($mode === 'hosted') {
            return in_array($methodId, ['card', 'online'], true);
        }
        if ($mode !== 'pay_later') {
            return false;
        }

        return $methodId === 'cash' || $methodId === (self::cashOnDelivery($orderType, $pickup) ? 'cod' : 'counter');
    }

    /** Paid on delivery (a delivery, or a shipped order that is not picked up in store) rather than at the counter. */
    public static function cashOnDelivery(string $orderType, bool $pickup = false): bool
    {
        return $orderType === 'delivery' || ($orderType === 'ship' && ! $pickup);
    }

    /** @return int|null the store's (P5-31: or the branch's own) minimum order (subtotal) in minor units, null when none */
    public function minimumMinor(PricedCart $cart): ?int
    {
        $min = Branch::effectiveMinOrder((int) $cart->ctx->store->id, $cart->ctx->branch);
        if ($min === null) {
            return null;
        }

        return Money::minorFromMajor($min, $cart->priced->currency);
    }

    /**
     * basket §9 (D7): what the card does for a weighed basket, in the words of the store's refund switch — a gateway that
     * authorises and captures holds the cap and charges the final amount (the rest is released); any other gateway is
     * charged the cap and the weighed difference is refunded, which only offers itself when `weighed_difference` is on.
     */
    private function weighedCardNote(PricedCart $cart): string
    {
        $store = $cart->ctx->store;
        $amount = $this->format($cart, $cart->holdAmountMinor());
        $major = Money::majorFromMinor($cart->holdAmountMinor(), $cart->priced->currency);
        if (app(HostedPayments::class)->storeCanHold($store, $major)) {
            return ___('We hold up to :amount on your card and charge the final amount after we weigh your items. The rest is released.', ['amount' => $amount]);
        }
        if (RefundSwitches::allows((int) $store->id, 'weighed_difference')) {
            return ___('We charge up to :amount now and refund the difference after we weigh your items.', ['amount' => $amount]);
        }

        return ___('Pay now, securely');
    }

    /**
     * @param  list<string>  $modes
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>> Choice[]
     */
    private function paymentMethods(PricedCart $cart, array $modes, array $body): array
    {
        $methods = [];
        if (in_array('hosted', $modes, true)) {
            $methods[] = ['id' => 'card', 'label' => ___('Pay online'), 'icon' => 'credit-card', 'sub' => $cart->isWeighed() ? $this->weighedCardNote($cart) : ___('Pay now, securely')];
        }
        $methods[] = self::cashOnDelivery($cart->orderType, $cart->isPickup())
            ? ['id' => 'cod', 'label' => ___('Cash on delivery'), 'icon' => 'banknote']
            : ['id' => 'counter', 'label' => ___('Pay at the counter'), 'icon' => 'store'];
        $picked = $body['payment_method_id'] ?? null;
        $index = 0;
        foreach ($methods as $i => $method) {
            if ($method['id'] === $picked) {
                $index = $i;
            }
        }
        $methods[$index]['selected'] = true;

        return $methods;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function requires(PricedCart $cart, array $body): array
    {
        $fulfillment = (array) config('verticals.fulfillment.'.$cart->orderType, []);
        $out = [];
        if ((! empty($fulfillment['needs_address']) || $cart->orderType === 'delivery') && empty($body['address']) && ! $cart->isPickup()) {
            $out[] = 'address';
        }
        if ($cart->orderType === 'ship' && $cart->shippingProblem !== null) {
            $out[] = 'shipping_method'; // P3-08: choose one of POST /shipping/quote's methods for this address
        }
        // P3-09: a store with delivery / collection slots needs one (or express); others keep the schedule-ahead time
        $slotted = $this->slots->offers($cart->ctx->store, $cart->ctx->branch, $cart->orderType);
        // a kiosk collects now: it never picks a time
        if ($slotted ? ($cart->slot === null && ! $cart->express && ! $cart->ctx->isKiosk()) : (! empty($fulfillment['needs_slot']) && empty($body['scheduled_at']) && $cart->slot === null)) {
            $out[] = 'slot';
        }
        if ($cart->orderType === 'on-table' && empty($body['table'])) {
            $out[] = 'table';
        }
        if (($body['payment_mode'] ?? null) === 'hosted') {
            $out[] = 'return_url';
        }

        return $out;
    }

    /** @throws StorefrontApiException 422 invalid_order_type */
    private function coreOrderType(StorefrontApiContext $ctx, string $kit): string
    {
        $core = OrderTypeKeys::toCoreFor($kit, $this->ordering->orderTypes($ctx->store));
        // THEME-CONTRACT §2.5: a kiosk never takes delivery, ship, at-home or room-service orders
        if ($core === null || ($ctx->isKiosk() && in_array($core, OrderTypeKeys::NOT_AT_KIOSK, true))) {
            throw new StorefrontApiException(422, 'invalid_order_type', ___('The selected order type is not available for this store.'));
        }

        return $core;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{item: string, qty: int, variant: string|null, options: list<string>, note: string|null}
     */
    private static function orderingLine(array $line): array
    {
        return ['item' => $line['item'], 'qty' => $line['qty'], 'variant' => $line['variant'], 'options' => $line['options'], 'note' => $line['note']];
    }

    /** @param  array<string, mixed>  $line */
    private function lineRefusal(StorefrontApiContext $ctx, array $line): string
    {
        $item = Menu::query()->where('store_id', $ctx->store->id)->where('public_id', $line['item'])->first();
        if ($item === null) {
            return 'item_unavailable';
        }
        if ((int) $item->is_sold_out === 1) {
            return 'out_of_stock';
        }
        // P3-08: a cell (or an item) without enough stock for the line
        $variant = $line['variant'] !== null
            ? MenuVariant::query()->where('menu_id', $item->id)->where(fn ($q) => Str::isUuid((string) $line['variant']) ? $q->where('public_id', $line['variant'])->orWhere('sku', $line['variant']) : $q->where('sku', $line['variant']))->first()
            : null;

        return $this->retail->canSell($item, $variant, (int) $line['qty']) ? 'item_unavailable' : 'out_of_stock';
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>|null  $shipping
     * @return array{priced: StorefrontQuote, quote: array<string, mixed>, weighed?: array{estimate: int, cap: int}|null}
     */
    private function quoteLines(StorefrontApiContext $ctx, array $lines, string $core, ?string $coupon, int $tipMinor, ?array $shipping = null, ?int $deliveryFeeMinor = null): array
    {
        $result = $this->builder->apiQuote($ctx->viewModel('cart', [
            'orderType' => $core,
            'cart' => array_map(fn (array $l) => self::orderingLine($l), $lines),
            'coupon' => $coupon,
            'tipMinor' => $tipMinor,
            'shipping' => $shipping,
            'deliveryFeeMinor' => $deliveryFeeMinor,
        ]));
        if ($result === null) {
            // Every line was orderable on its own; together they are not (e.g. a per-order medicine cap).
            throw new StorefrontApiException(422, 'validation_failed', ___('This cart cannot be ordered as it is.'), ['lines' => [___('This cart cannot be ordered as it is.')]]);
        }

        return $result;
    }

    private function tipsAllowed(StorefrontApiContext $ctx, string $core): bool
    {
        return StoreSettings::bool((int) $ctx->store->id, 'allow_tips') && in_array($core, ['delivery', 'takeaway', 'on-table'], true);
    }

    /** @param  array<string, mixed>  $tip */
    private function tipMinor(array $tip, StorefrontQuote $priced): int
    {
        if (isset($tip['minor'])) {
            return max(0, (int) $tip['minor']);
        }
        if (isset($tip['choice_id']) && preg_match('/^tip_(\d{1,3})$/', (string) $tip['choice_id'], $m) === 1) {
            return Money::ofMinor($priced->subtotal, $priced->currency)->percent((float) $m[1])->minor();
        }

        return 0;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array{slot: array<string, mixed>|null, express: bool}  $slotChoice
     */
    private function hash(StorefrontApiContext $ctx, string $core, array $lines, ?string $coupon, int $tipMinor, StorefrontQuote $q, array $slotChoice = ['slot' => null, 'express' => false]): string
    {
        $canonical = [
            'store' => (int) $ctx->store->id,
            'branch' => $ctx->branch?->id,
            'type' => $core,
            'lines' => array_map(function (array $l) {
                $options = $l['options'];
                sort($options);

                return [$l['item'], $l['variant'], $options, $l['qty'], (string) $l['note']];
            }, $lines),
            'coupon' => $coupon !== null ? mb_strtoupper($coupon) : null,
            'tip' => $tipMinor,
            // P3-08: the shipping method and the delivery window it was quoted with
            'shipping' => $q->shipping !== null ? [$q->shipping->publicId(), $q->shipping->earliest->toDateString(), $q->shipping->latest->toDateString()] : null,
            'money' => [$q->currency, $q->subtotal, $q->discount, $q->delivery, $q->fee, $q->service, $q->tax, $q->tip, $q->donation, $q->total],
            // P3-09: the slot (its fee) and express are priced inputs; the out-of-stock choices are not
            'slot' => $slotChoice['slot']['id'] ?? null,
            'express' => $slotChoice['express'],
        ];

        return 'qh_'.substr(hash_hmac('sha256', (string) json_encode($canonical), (string) config('app.key')), 0, 32);
    }

    private function timezone(StorefrontApiContext $ctx): string
    {
        return (string) ($ctx->branch?->timezone ?: store_tz((int) $ctx->store->id));
    }

    private function format(PricedCart $cart, int $minor): string
    {
        return (new MoneyFormat($cart->ctx->store))->format($minor);
    }
}
