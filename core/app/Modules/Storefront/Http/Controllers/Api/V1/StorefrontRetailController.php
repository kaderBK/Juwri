<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Api\V1;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Menu\Contracts\StockAlertRequests;
use Juwri\Modules\Menu\Contracts\Wishlists;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Contracts\Returns;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiContext;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiException;

/**
 * P3-08 / P3-06b phase B — juwri's retail endpoints beside the kit contract (the kit has none for these; additive,
 * same conventions: the token's store only, ApiError bodies, `Cache-Control: no-store`):
 *
 *  - wishlist: `GET|POST /wishlist`, `DELETE /wishlist/{item_id}`, `POST /wishlist/sync`. The owner is the customer of
 *    the `Authorization: Bearer` customer token, else a guest list named by the client's own random
 *    `X-Wishlist-Token` (32–128 characters; only its hash is stored). Sync (customer required) merges the guest list
 *    and the client's own item ids into the customer's list — the sign-in sync of a headless client.
 *  - back-in-stock (N5): `POST /stock-alerts` — the shopper's marketing opt-in is required.
 *  - size guides: `GET /size-guides/{id}`.
 *  - returns (D7): `GET|POST /orders/{track_token}/returns`.
 */
class StorefrontRetailController extends Controller
{
    public function wishlist(Request $request, Wishlists $wishlists): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        [$customerId, $guestKey] = $this->owner($request, $ctx, false);

        return $this->json(['items' => $customerId === null && $guestKey === null ? [] : $wishlists->list($ctx->store, $customerId, $guestKey)], 200, $ctx);
    }

    public function wishlistAdd(Request $request, Wishlists $wishlists): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        $body = $this->validBody((array) $request->json()->all(), [
            'item_id' => ['required', 'string', 'max:64'],
            'variant_id' => ['nullable', 'string', 'max:64'],
        ]);
        [$customerId, $guestKey] = $this->owner($request, $ctx, true);
        try {
            $wishlists->add($ctx->store, $customerId, $guestKey, (string) $body['item_id'], isset($body['variant_id']) ? (string) $body['variant_id'] : null);
        } catch (\DomainException $e) {
            throw $this->refusal($e->getMessage());
        }

        return $this->json(['items' => $wishlists->list($ctx->store, $customerId, $guestKey)], 201, $ctx);
    }

    public function wishlistRemove(Request $request, string $item_id, Wishlists $wishlists): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        [$customerId, $guestKey] = $this->owner($request, $ctx, true);
        $wishlists->remove($ctx->store, $customerId, $guestKey, $item_id);

        return $this->json(['items' => $wishlists->list($ctx->store, $customerId, $guestKey)], 200, $ctx);
    }

    public function wishlistSync(Request $request, Wishlists $wishlists): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        if ($ctx->customer === null) {
            throw new StorefrontApiException(401, 'unauthenticated', ___('Sign in to keep your wishlist.'));
        }
        $body = $this->validBody((array) $request->json()->all(), [
            'item_ids' => ['nullable', 'array', 'max:200'],
            'item_ids.*' => ['string', 'max:64'],
        ]);
        $guest = $request->header('X-Wishlist-Token');
        $guestKey = is_string($guest) && $guest !== '' ? $wishlists::guestKey($guest) : null;
        $items = $wishlists->merge($ctx->store, (int) $ctx->customer->id, $guestKey, array_values(array_map('strval', (array) ($body['item_ids'] ?? []))));

        return $this->json(['items' => $items], 200, $ctx);
    }

    public function stockAlert(Request $request, StockAlertRequests $alerts): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        $body = $this->validBody((array) $request->json()->all(), [
            'item_id' => ['required', 'string', 'max:64'],
            'variant_id' => ['nullable', 'string', 'max:64'],
            'channel' => ['required', 'in:email,whatsapp,sms'],
            'contact' => ['required', 'string', 'max:191'],
            'marketing_opt_in' => ['required', 'boolean'],
        ]);
        $item = Menu::query()->where('store_id', $ctx->store->id)->where('public_id', (string) $body['item_id'])->first();
        if ($item === null) {
            throw StorefrontApiException::notFound();
        }
        $variant = null;
        if (! empty($body['variant_id'])) {
            $variant = MenuVariant::query()->where('menu_id', $item->id)->where('public_id', (string) $body['variant_id'])->first();
            if ($variant === null) {
                throw StorefrontApiException::notFound();
            }
        }
        try {
            $alert = $alerts->subscribe($ctx->store, $item, $variant, (string) $body['channel'], (string) $body['contact'], (bool) $body['marketing_opt_in'], $ctx->customer?->id, $ctx->locale);
        } catch (\DomainException $e) {
            throw $this->refusal($e->getMessage());
        }

        return $this->json(['id' => $alert->public_id, 'status' => $alert->status], 201, $ctx);
    }

    public function sizeGuide(Request $request, string $guide_id, RetailCatalog $catalog): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        $row = $catalog->sizeGuideById($ctx->store, $guide_id);
        if ($row === null) {
            throw StorefrontApiException::notFound();
        }

        return $this->json([
            'id' => $row->public_id,
            'title' => (string) $row->title,
            'unit' => $row->unit,
            'columns' => (array) $row->columns,
            'rows' => (array) $row->rows,
            'note' => $row->note,
        ], 200, $ctx);
    }

    public function returns(Request $request, string $track_token, Returns $returns): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);

        return $this->json($returns->eligibility($this->order($ctx, $track_token)), 200, $ctx);
    }

    public function returnsStore(Request $request, string $track_token, Returns $returns): JsonResponse
    {
        $ctx = StorefrontApiContext::fromRequest($request);
        $order = $this->order($ctx, $track_token);
        $body = $this->validBody((array) $request->json()->all(), [
            'resolution' => ['required', 'in:exchange,store_credit,refund'],
            'reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.order_item_id' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        try {
            $return = $returns->request($order, array_values((array) $body['lines']), (string) $body['resolution'], isset($body['reason']) ? (string) $body['reason'] : null);
        } catch (\DomainException $e) {
            throw $this->refusal($e->getMessage());
        }

        return $this->json([
            'id' => $return->public_id,
            'status' => $return->status,
            'resolution' => $return->resolution,
            'amount_minor' => (int) $return->amount_minor,
            'currency' => $return->currency_code,
        ], 201, $ctx);
    }

    private function order(StorefrontApiContext $ctx, string $token): Order
    {
        $order = strlen($token) <= 64 ? Order::query()->where('store_id', $ctx->store->id)->where('track_token', $token)->first() : null;
        if ($order === null) {
            throw StorefrontApiException::notFound();
        }

        return $order;
    }

    /** @return array{0: int|null, 1: string|null} */
    private function owner(Request $request, StorefrontApiContext $ctx, bool $required): array
    {
        if ($ctx->customer !== null) {
            return [(int) $ctx->customer->id, null];
        }
        $token = $request->header('X-Wishlist-Token');
        $key = is_string($token) && $token !== '' ? app(Wishlists::class)::guestKey($token) : null;
        if ($required && $key === null) {
            throw new StorefrontApiException(422, 'validation_failed', ___('Send a customer token or an X-Wishlist-Token of 32 to 128 letters, digits, - or _.'));
        }

        return [null, $key];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validBody(array $body, array $rules): array
    {
        $validator = Validator::make($body, $rules);
        if ($validator->fails()) {
            /** @var array<string, list<string>> $errors */
            $errors = $validator->errors()->toArray();
            throw new StorefrontApiException(422, 'validation_failed', (string) $validator->errors()->first(), $errors);
        }

        return $validator->validated();
    }

    private function refusal(string $code): StorefrontApiException
    {
        return match ($code) {
            'not_found' => StorefrontApiException::notFound(),
            'no_owner' => new StorefrontApiException(422, 'validation_failed', ___('Send a customer token or an X-Wishlist-Token of 32 to 128 letters, digits, - or _.')),
            'alerts_off' => new StorefrontApiException(422, 'alerts_off', ___('Back-in-stock messages are not offered by this store.')),
            'in_stock' => new StorefrontApiException(422, 'in_stock', ___('This item is in stock.')),
            'consent_required' => new StorefrontApiException(422, 'consent_required', ___('Please agree to receive the message.')),
            'too_many' => new StorefrontApiException(422, 'too_many_alerts', ___('You are already waiting for too many items at this store.')),
            'invalid_contact' => new StorefrontApiException(422, 'validation_failed', ___('Enter a valid email address or phone number.')),
            'returns_closed' => new StorefrontApiException(422, 'returns_closed', ___('Returns are closed for this order.')),
            'resolution' => new StorefrontApiException(422, 'resolution_not_offered', ___('This store does not offer that kind of return.')),
            default => new StorefrontApiException(422, 'validation_failed', ___('Choose items and quantities from this order that can still be returned.')),
        };
    }

    /** @param  array<string, mixed>  $body */
    private function json(array $body, int $status, StorefrontApiContext $ctx): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store', 'Content-Language' => $ctx->locale], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
