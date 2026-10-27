<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\CapsPerPage;
use App\Http\Controllers\Api\V1\Concerns\FormatsOrders;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Order;
use App\Services\CustomerWalletService;
use App\Services\GoogleWallet\GoogleWalletService;
use App\Services\OrderApiService;
use App\Services\OrderIntakeGate;
use App\Services\Verticals\FulfillmentRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Store\StoreStatus;
use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Modules\Customers\Http\Middleware\CustomerTokenAuth;
use Juwri\Modules\Customers\Http\Requests\CustomerAddressRequest;
use Juwri\Modules\Customers\Models\CustomerAddress;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Payments\Models\LedgerEntry;

/**
 * Standalone customer app, authenticated by {@see CustomerTokenAuth}.
 * Every action is scoped to the authenticated customer and their restaurant.
 */
class CustomerAppController extends Controller
{
    use CapsPerPage; // A5-3
    use FormatsOrders;

    private function customer(): Customer
    {
        return app('api.customer');
    }

    /** GET /customer/me */
    public function me()
    {
        $c = $this->customer();

        // Coupons & Wallets P7-3b: expose the account-bound store-credit balance
        // (D3) so the customer app can show "pay with credit" affordances.
        // Decimals from the customer's own restaurant. This endpoint runs outside
        // a storefront render, where config()'s currency_code is the PLATFORM's, so
        // it must be resolved explicitly.
        $decimals = money_decimals(store_currency($c->store_id)); // P6-208
        $balance = CustomerWalletService::balanceFor($c);

        return response()->json([
            'id' => $c->id,
            'name' => $c->name,
            'email' => $c->email,
            'phone' => $c->phone,
            'birthday' => $c->birthday,
            'store_id' => $c->store_id,
            'orders_count' => $c->orders_count,
            'total_spent' => $c->total_spent,
            'store_credit' => [
                'balance' => money_round($balance, $decimals),
                'formatted' => store_money_plain($balance, $c->store_id),
            ],
        ]);
    }

    /** GET /customer/store-credit — balance + recent ledger for this customer. */
    public function storeCredit()
    {
        $c = $this->customer();
        $balance = CustomerWalletService::balanceFor($c);

        $entries = LedgerEntry::where(
            'ledger_account_id',
            CustomerWalletService::accountFor($c)->id
        )->orderByDesc('id')->limit(30)->get(['amount_minor', 'currency_code', 'type', 'reason', 'created_at']); // P1-05: JSON keeps `amount`

        return response()->json([
            'balance' => money_round($balance, money_decimals(store_currency($c->store_id))), // P6-208
            'formatted' => store_money_plain($balance, $c->store_id),
            'entries' => $entries,
        ]);
    }

    /**
     * GET /customer/gift-cards — the gift cards this customer PURCHASED at their
     * restaurant. Each row carries balance/status/expiry, plus a Google Wallet
     * `wallet_save_url` when the store has Wallet enabled AND the card is still
     * redeemable (else null). The save-link is the customer's own to add — the same
     * value the storefront "Add to Google Wallet" button fetches. Recipient-by-email
     * cards (no purchaser link) are not listed here.
     */
    public function giftCards(): JsonResponse
    {
        $c = $this->customer();
        $store = Store::findOrFail($c->store_id);
        $decimals = money_decimals(store_currency($c->store_id)); // P6-208
        $walletEnabled = GoogleWalletService::enabledForStore($store);

        $cards = GiftCard::where('store_id', $c->store_id)
            ->where('purchaser_customer_id', $c->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'wallet_enabled' => $walletEnabled,
            'gift_cards' => $cards->map(function ($card) use ($store, $decimals, $walletEnabled) {
                $bal = (float) $card->balance();
                $saveUrl = ($walletEnabled && $card->isRedeemable())
                    ? GoogleWalletService::giftCardSaveUrl($store, $card)
                    : null;

                return [
                    'id' => (int) $card->id,
                    'code' => (string) $card->code,
                    'balance' => money_round($bal, $decimals),
                    'formatted' => store_money_plain($bal, $store->id),
                    'status' => (string) $card->status,
                    'expired' => $card->isExpired(),
                    'redeemable' => $card->isRedeemable(),
                    'expires_at' => optional($card->expires_at)->toIso8601String(),
                    'wallet_save_url' => $saveUrl,
                ];
            })->values(),
        ]);
    }

    /** PUT /customer/me */
    public function updateMe(Request $request)
    {
        $c = $this->customer();

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->where('store_id', $c->store_id)->ignore($c->id)],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('customers', 'phone')->where('store_id', $c->store_id)->ignore($c->id)],
            'birthday' => 'nullable|date',
            'marketing_consent' => 'nullable|boolean',
            'sms_consent' => 'nullable|boolean',
        ]);

        $c->update($data);

        return $this->me();
    }

    /** GET /customer/orders — the customer's own order history. */
    public function orders(Request $request)
    {
        $c = $this->customer();

        $orders = Order::where('store_id', $c->store_id)
            ->where('customer_id', $c->id)
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 20));

        return response()->json($orders->through(fn ($o) => $this->orderDetail($o)));
    }

    /** POST /customer/orders — place an order as this customer. */
    public function placeOrder(Request $request)
    {
        $c = $this->customer();
        $store = Store::find($c->store_id);
        $ownerId = (int) ($store->user_id ?? 0);

        // rev/mv-surface-api P0: `type` was hardcoded to the food modes
        // (on-table/takeaway/delivery), so this endpoint 422-rejected every
        // non-food fulfillment mode (ship/pickup_slot/appointment/in_store) — a
        // retail/pharmacy/laundry customer app could not place an order at all.
        // Validate against what THIS store's vertical actually allows
        // (FulfillmentRegistry::modesFor), unioned with the legacy food literals so
        // an existing restaurant integration never breaks.
        $allowedTypes = array_values(array_unique(array_merge(
            ['on-table', 'takeaway', 'delivery'],
            app(FulfillmentRegistry::class)->modesFor($store->store_type ?? null)
        )));

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in($allowedTypes)],
            'table_number' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:255',
            'delivery_lat' => 'nullable|numeric|between:-90,90',
            'delivery_lng' => 'nullable|numeric|between:-180,180',
            'tip_amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:1000',
            'client_uuid' => 'nullable|string|max:64',
            'scheduled_at' => 'nullable|string|max:40', // T1: optional pre-order time
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.variation' => 'nullable|integer',
            'items.*.extras' => 'nullable|array',
            'items.*.extras.*' => 'integer',
            'items.*.note' => 'nullable|string|max:255',
            // Coupons & Wallets P7-3a: owner coupon + pay-with-credit intent.
            'coupon_code' => 'nullable|string|max:64',
            'use_wallet' => 'nullable|boolean',
            'wallet_amount' => 'nullable|numeric|min:0',
        ]);

        // Force customer identity — the body can never spoof another customer.
        $data['customer_id'] = $c->id;
        $data['customer_name'] = $c->name;
        $data['phone'] = $c->phone;
        $data['source'] = 'customer_app';
        // This is an authenticated customer, so a use_wallet intent is honoured
        // (store credit is account-bound, D3) and applied by the shared concern.

        // A2: honour the standard Idempotency-Key header (retry / offline replay).
        // J27-A2: the key (body client_order_id / client_uuid or the header) must be a UUID; a resend with the same
        // cart returns the same order, another cart under the same key is a 409.
        $data['client_uuid'] = ClientOrderId::fromRequest($request);

        // P6-348: the customer app is a customer channel, so it passes the SAME intake gate
        // as the web checkout (OrderIntakeGate): closed store, paused/closed branch, and the
        // minimum basket. Scheduled pre-orders may be placed while closed, as on the web.
        // A resend of an order that already exists skips the gate so the replay (or 409)
        // answer from create() is unchanged even if the store has closed since.
        $isResend = ! empty($data['client_uuid'])
            && Order::where('store_id', $c->store_id)->where('client_uuid', $data['client_uuid'])->exists();
        if ($store !== null && ! $isResend) {
            $gateBranch = StoreStatus::for($store)['branch'] ?? null;
            if (($closed = OrderIntakeGate::closedMessage($store, $gateBranch, ! empty($data['scheduled_at']))) !== null) {
                return response()->json(['error' => 'store_closed', 'message' => $closed], 422);
            }
            if (OrderIntakeGate::minimum($store, $gateBranch) !== null) {
                // Server-priced exactly as the order will be (variants, extras, scheduled prices);
                // an invalid line yields null and create() below answers with its own 422.
                $preview = OrderApiService::previewOrder((int) $c->store_id, $data['items'], $data['type'], null);
                if ($preview !== null
                    && ($below = OrderIntakeGate::belowMinimumMessage($store, $gateBranch, (float) $preview->itemsSubtotal())) !== null) {
                    return response()->json(['error' => 'below_minimum', 'message' => $below], 422);
                }
            }
        }

        try {
            $order = OrderApiService::create($ownerId, $c->store_id, $data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422); // T1: scheduling rule violation
        } catch (ClientOrderConflict $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json($this->orderDetail($order), 201);
    }

    // ── Addresses ────────────────────────────────────────────────────────────

    public function addresses()
    {
        $addresses = $this->customer()->addresses()
            ->orderByDesc('is_default')->orderBy('id')->get();

        return response()->json(['data' => $addresses]);
    }

    public function storeAddress(CustomerAddressRequest $request)
    {
        $c = $this->customer();

        // P6-294 Customer address book: a customer may keep at most 20 saved
        // addresses. Throwing ValidationException here renders Laravel's
        // standard 422 JSON, which is what the API contract needs.
        if ($c->addresses()->count() >= 20) {
            throw ValidationException::withMessages([
                'line' => ___('You can save a maximum of :max addresses.', ['max' => 20]),
            ]);
        }

        $data = $request->validated();

        $makeDefault = ($data['is_default'] ?? false) || ! $c->addresses()->exists();
        if ($makeDefault) {
            $c->addresses()->update(['is_default' => 0]);
        }

        $address = $c->addresses()->create(array_merge($data, ['is_default' => $makeDefault ? 1 : 0]));

        return response()->json($address, 201);
    }

    public function updateAddress(Request $request, int $id): JsonResponse
    {
        $c = $this->customer();
        $address = CustomerAddress::where('customer_id', $c->id)->findOrFail($id);

        $data = $request->validate([
            'label' => 'nullable|string|max:80',
            'line' => 'required|string|max:255',
            'city' => 'nullable|string|max:120',
            'state' => 'nullable|string|max:120',
            'zip' => 'nullable|string|max:40',
            'country' => 'nullable|string|max:120',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'is_default' => 'nullable|boolean',
        ]);

        $makeDefault = ! empty($data['is_default']);
        if ($makeDefault) {
            $c->addresses()->where('id', '!=', $address->id)->update(['is_default' => 0]);
        }

        $address->update(array_merge(
            Arr::except($data, ['is_default']),
            ['is_default' => $makeDefault ? 1 : $address->is_default]
        ));

        return response()->json($address->fresh());
    }

    public function destroyAddress(int $id)
    {
        $c = $this->customer();
        $address = CustomerAddress::where('customer_id', $c->id)->findOrFail($id);

        $wasDefault = $address->is_default;
        $address->delete();
        if ($wasDefault) {
            $c->addresses()->orderBy('id')->first()?->update(['is_default' => 1]);
        }

        return response()->json(['deleted' => true]);
    }

    /** POST /customer/logout — revoke the token used for this request. */
    public function logout()
    {
        /** @var CustomerToken $token */
        $token = app('api.customer_token');
        $token->delete();

        return response()->json(['success' => true]);
    }
}
