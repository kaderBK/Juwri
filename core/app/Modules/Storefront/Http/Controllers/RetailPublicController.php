<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Menu\Contracts\StockAlertRequests;
use Juwri\Modules\Menu\Contracts\Wishlists;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Contracts\Returns;
use Juwri\Modules\Storefront\Services\Storefront\KitStorefront;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * P3-08 — the retail storefront endpoints a theme calls (named in the View Model `endpoints`):
 *
 *  - wishlist (aisle §12): `GET|POST {slug}/wishlist`, `DELETE {slug}/wishlist/{item}`. A signed-in customer of the
 *    store keeps their list on their account; a guest's list lives under a random browser token (cookie
 *    {@see GUEST_COOKIE}, only its hash is stored) and is merged into the customer's list at sign-in
 *    (MergeGuestWishlist listener).
 *  - back-in-stock (N5): `POST {slug}/stock-alerts` — needs the shopper's marketing opt-in.
 *  - size guide: `GET {slug}/size-guide/{guide}` — JSON for a theme's sheet, a plain table page otherwise.
 *  - returns (D7): `GET|POST order/track/{token}/returns` — the track token alone is the capability (the same model
 *    as the order tracking page).
 *
 * Every lookup is inside the store of the slug (or the order of the token): an id of another store is unknown (404).
 */
class RetailPublicController extends Controller
{
    public const GUEST_COOKIE = 'site_wishlist';

    public function wishlist(Request $request, string $slug, Wishlists $wishlists): JsonResponse|Response
    {
        $store = $this->store($slug);
        [$customerId, $guestKey] = $this->owner($request, $store);
        $items = $customerId === null && $guestKey === null ? [] : $wishlists->list($store, $customerId, $guestKey);

        // aisle §12: a browser opening the address sees the wishlist page of the store's kit theme; the theme's own script
        // (and any client asking for JSON) reads the list
        if (! $request->wantsJson() && $request->acceptsHtml()) {
            $kit = app(KitStorefront::class);
            $theme = $kit->themeFor($store);
            if ($theme !== null && view()->exists('store_templates.'.$theme.'.wishlist')) {
                return response()->view('store_templates.'.$theme.'.wishlist', $kit->wishlist($store, $request, $theme, $items))
                    ->header('Cache-Control', 'private, no-store')
                    ->header('X-Robots-Tag', 'noindex');
            }
        }

        return response()->json(['items' => $items]);
    }

    public function wishlistAdd(Request $request, string $slug, Wishlists $wishlists): JsonResponse
    {
        $store = $this->store($slug);
        $data = $request->validate([
            'item_id' => ['required', 'string', 'max:64'],
            'variant_id' => ['nullable', 'string', 'max:64'],
        ]);
        [$customerId, $guestKey] = $this->owner($request, $store);
        $cookie = null;
        if ($customerId === null && $guestKey === null) {
            $token = Str::random(48);
            $guestKey = $wishlists::guestKey($token);
            $cookie = cookie(self::GUEST_COOKIE, $token, 60 * 24 * 365, null, null, $request->isSecure(), true, false, 'lax');
        }
        try {
            $wishlists->add($store, $customerId, $guestKey, $data['item_id'], $data['variant_id'] ?? null);
        } catch (\DomainException $e) {
            return $this->refusal($e->getMessage());
        }
        $response = response()->json(['items' => $wishlists->list($store, $customerId, $guestKey)], 201);

        return $cookie instanceof Cookie ? $response->withCookie($cookie) : $response;
    }

    public function wishlistRemove(Request $request, string $slug, string $item, Wishlists $wishlists): JsonResponse
    {
        $store = $this->store($slug);
        [$customerId, $guestKey] = $this->owner($request, $store);
        if ($customerId === null && $guestKey === null) {
            return response()->json(['items' => []]);
        }
        $wishlists->remove($store, $customerId, $guestKey, $item);

        return response()->json(['items' => $wishlists->list($store, $customerId, $guestKey)]);
    }

    public function stockAlert(Request $request, string $slug, StockAlertRequests $alerts): JsonResponse
    {
        $store = $this->store($slug);
        $data = $request->validate([
            'item_id' => ['required', 'string', 'max:64'],
            'variant_id' => ['nullable', 'string', 'max:64'],
            'channel' => ['required', 'in:email,whatsapp,sms'],
            'contact' => ['required', 'string', 'max:191'],
            'marketing_opt_in' => ['required', 'boolean'],
        ]);
        $item = Menu::query()->where('store_id', $store->id)->where('public_id', $data['item_id'])->first();
        $variant = null;
        if ($item !== null && ! empty($data['variant_id'])) {
            // the page names a variant by what the View Model publishes: its sku (the public id when it has none)
            $variant = MenuVariant::query()->where('menu_id', $item->id)->where(fn ($q) => Str::isUuid((string) $data['variant_id']) ? $q->where('public_id', $data['variant_id'])->orWhere('sku', $data['variant_id']) : $q->where('sku', $data['variant_id']))->first();
            if ($variant === null) {
                return $this->refusal('not_found');
            }
        }
        if ($item === null) {
            return $this->refusal('not_found');
        }
        try {
            $alert = $alerts->subscribe($store, $item, $variant, $data['channel'], $data['contact'], (bool) $data['marketing_opt_in'], $this->customerId($store), app()->getLocale());
        } catch (\DomainException $e) {
            return $this->refusal($e->getMessage());
        }

        return response()->json([
            'id' => $alert->public_id,
            'status' => $alert->status,
            'message' => ___('We will let you know when it is back in stock.'),
        ], 201);
    }

    public function sizeGuide(Request $request, string $slug, string $guide, RetailCatalog $catalog): JsonResponse|View
    {
        $store = $this->store($slug);
        $row = $catalog->sizeGuideById($store, $guide);
        abort_if($row === null, 404);
        $body = [
            'id' => $row->public_id,
            'title' => (string) $row->title,
            'unit' => $row->unit,
            'columns' => (array) $row->columns,
            'rows' => (array) $row->rows,
            'note' => $row->note,
        ];

        return $request->wantsJson() ? response()->json($body) : view('shop.goods.size-guide', ['store' => $store, 'guide' => $body]);
    }

    public function returnsShow(string $token, Returns $returns): JsonResponse
    {
        return response()->json($returns->eligibility($this->trackedOrder($token)));
    }

    public function returnsStore(Request $request, string $token, Returns $returns): JsonResponse
    {
        $order = $this->trackedOrder($token);
        $data = $request->validate([
            'resolution' => ['required', 'in:exchange,store_credit,refund'],
            'reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.order_item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        try {
            $return = $returns->request($order, array_values($data['lines']), $data['resolution'], $data['reason'] ?? null);
        } catch (\DomainException $e) {
            return $this->refusal($e->getMessage());
        }

        return response()->json([
            'id' => $return->public_id,
            'status' => $return->status,
            'resolution' => $return->resolution,
            'amount_minor' => (int) $return->amount_minor,
            'currency' => $return->currency_code,
            'message' => ___('Your return request was sent to the store.'),
        ], 201);
    }

    private function store(string $slug): Store
    {
        return Store::query()->where('slug', $slug)->firstOrFail();
    }

    private function trackedOrder(string $token): Order
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token) === 1, 404);

        return Order::query()->where('track_token', $token)->firstOrFail();
    }

    /** The signed-in customer of THIS store (a customer of another store is a guest here). */
    private function customerId(Store $store): ?int
    {
        $customer = auth('customer')->user();

        return $customer !== null && (int) $customer->getAttribute('store_id') === (int) $store->id ? (int) $customer->getAuthIdentifier() : null;
    }

    /** @return array{0: int|null, 1: string|null} the list owner: the store's signed-in customer, else the guest token's key */
    private function owner(Request $request, Store $store): array
    {
        $customerId = $this->customerId($store);
        if ($customerId !== null) {
            return [$customerId, null];
        }
        $token = $request->cookie(self::GUEST_COOKIE);

        return [null, is_string($token) ? app(Wishlists::class)::guestKey($token) : null];
    }

    private function refusal(string $code): JsonResponse
    {
        [$status, $message] = match ($code) {
            'not_found' => [404, ___('That item is not available.')],
            'alerts_off' => [422, ___('Back-in-stock messages are not offered by this store.')],
            'in_stock' => [422, ___('This item is in stock.')],
            'consent_required' => [422, ___('Please agree to receive the message.')],
            'too_many' => [422, ___('You are already waiting for too many items at this store.')],
            'invalid_contact' => [422, ___('Enter a valid email address or phone number.')],
            'returns_closed' => [422, ___('Returns are closed for this order.')],
            'resolution' => [422, ___('This store does not offer that kind of return.')],
            'lines', 'line_qty' => [422, ___('Choose items and quantities from this order that can still be returned.')],
            default => [422, ___('This request could not be completed.')],
        };

        return response()->json(['code' => $code, 'message' => $message], $status);
    }
}
