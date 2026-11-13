<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use App\Models\Customer;
use App\Models\StorefrontToken;
use Illuminate\Http\Request;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;

/**
 * P3-06b (kit/api README §2.5, §2.6, §2.11): what one headless storefront API call is about, resolved server-side —
 * never from a path or body id:
 *
 *  - the store and token StorefrontTokenAuth bound (container, not request input);
 *  - the optional `branch` query parameter: a branch public id that must belong to that store, else 404;
 *  - the response language from `Accept-Language`, among the store's menu languages (else the store's first);
 *  - the optional customer from `Authorization: Bearer <customer token>`, which must belong to the same store.
 *
 * Built by {@see self::fromRequest()}; a refusal is thrown as {@see StorefrontApiException} (the ApiError envelope).
 */
final class StorefrontApiContext
{
    private function __construct(
        public readonly Store $store,
        public readonly StorefrontToken $token,
        public readonly ?Branch $branch,
        public readonly string $locale,
        public readonly ?Customer $customer,
    ) {}

    /** @throws StorefrontApiException */
    public static function fromRequest(Request $request): self
    {
        /** @var Store $store */
        $store = app('api.storefront_post');
        /** @var StorefrontToken $token */
        $token = app('api.storefront_token');

        return new self($store, $token, self::branch($request, $store), self::locale($request, $store), self::customer($request, $store));
    }

    /** P3-06b (API-13, API-15, API-24d): is the caller an in-store kiosk device token? */
    public function isKiosk(): bool
    {
        return $this->token->isKiosk();
    }

    /**
     * P3-08 (aisle): the same context for the storefront's own browser calls (the kit theme's quote / shipping /
     * place endpoints): no storefront token — a transient, unsaved token whose only allowed origin is this request's,
     * so the one order path (idempotency, quote hash, stock take, hosted payment) is exactly the headless API's.
     * The store comes from the route, never from the body; the customer is the store's signed-in customer.
     */
    public static function forWeb(Store $store, Request $request, ?Customer $customer = null): self
    {
        // a kiosk browser session (the server-side marker, never a field) is a kiosk caller: no delivery / ship, no slot to choose,
        // the order is tagged `kiosk` (P3-09 basket)
        $scopes = \App\Support\KioskContext::on((int) $store->id) ? ['menu:read', 'order:write', 'kiosk'] : ['menu:read', 'order:write'];
        $token = new StorefrontToken(['store_id' => $store->id, 'scopes' => $scopes, 'active' => true, 'allowed_origins' => [$request->getSchemeAndHttpHost()]]);
        $ref = $request->input('branch', $request->query('branch'));
        $branch = is_string($ref) && $ref !== ''
            ? Branch::query()->where('store_id', $store->id)->where('public_id', $ref)->where('active', true)->first()
            : null;
        $customer = $customer !== null && (int) $customer->store_id === (int) $store->id ? $customer : null;

        return new self($store, $token, self::slotBranch($store, $branch), app()->getLocale(), $customer);
    }

    /**
     * The branch a time slot is kept under. A store with one branch has no branch to choose: its slots (and their capacity) are the
     * store's, the same ones the headless API counts (no `branch` parameter), so the storefront's own pages and an API client share
     * one capacity instead of two. A store with several branches keeps the branch the shopper named.
     */
    public static function slotBranch(Store $store, ?Branch $branch): ?Branch
    {
        if ($branch === null) {
            return null;
        }

        return Branch::query()->where('store_id', $store->id)->where('active', true)->count() <= 1 ? null : $branch;
    }

    /**
     * The view model request for this call (page, cart and the rest are the caller's).
     *
     * @param  array<string, mixed>  $extra
     */
    public function viewModel(string $page = 'home', array $extra = []): ViewModelRequest
    {
        return new ViewModelRequest(...array_merge([
            'store' => $this->store,
            'locale' => $this->locale,
            'page' => $page,
            'branch' => $this->branch,
            'customer' => $this->customer,
        ], $extra));
    }

    /** @throws StorefrontApiException */
    private static function branch(Request $request, Store $store): ?Branch
    {
        $ref = $request->query('branch');
        if ($ref === null || $ref === '') {
            return null;
        }
        $branch = is_string($ref)
            ? Branch::query()->where('store_id', $store->id)->where('public_id', $ref)->where('active', true)->first()
            : null;
        if ($branch === null) {
            throw StorefrontApiException::notFound();
        }

        return $branch;
    }

    private static function locale(Request $request, Store $store): string
    {
        $offered = array_values(array_unique(array_map('strval', StoreSettings::array((int) $store->id, 'menu_languages'))));
        $available = array_values(array_filter($offered, fn (string $code) => preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $code) === 1 && is_dir(lang_path($code))));
        if ($available === []) {
            // No menu languages chosen: every language the app speaks (the storefront's own fallback set).
            $available = array_values(array_filter(array_map('basename', glob(lang_path('*'), GLOB_ONLYDIR) ?: []), fn (string $c) => preg_match('/^[a-z]{2,3}$/', $c) === 1));
            $first = (string) config('app.locale', 'en');
            usort($available, fn (string $a, string $b) => ($b === $first) <=> ($a === $first));
            if ($available === []) {
                $available = [$first];
            }
        }

        foreach (self::acceptLanguages((string) $request->header('Accept-Language', '')) as $wanted) {
            foreach ($available as $code) {
                if (strcasecmp($code, $wanted) === 0 || strcasecmp($code, strtok($wanted, '-')) === 0) {
                    return $code;
                }
            }
        }

        $default = StoreSettings::string((int) $store->id, 'default_language');

        return in_array($default, $available, true) ? $default : $available[0];
    }

    /** @return list<string> language tags by preference (q-values honoured, `*` dropped) */
    private static function acceptLanguages(string $header): array
    {
        $tags = [];
        foreach (explode(',', $header) as $i => $part) {
            $bits = array_map('trim', explode(';', $part));
            $tag = $bits[0];
            if ($tag === '' || $tag === '*' || preg_match('/^[A-Za-z]{1,8}(-[A-Za-z0-9]{1,8})*$/', $tag) !== 1) {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (str_starts_with($param, 'q=') && is_numeric(substr($param, 2))) {
                    $q = (float) substr($param, 2);
                }
            }
            if ($q > 0) {
                $tags[] = [$q, -$i, $tag];
            }
        }
        rsort($tags);

        return array_map(fn (array $t) => (string) $t[2], $tags);
    }

    /** @throws StorefrontApiException */
    private static function customer(Request $request, Store $store): ?Customer
    {
        $raw = $request->bearerToken();
        if ($raw === null || $raw === '') {
            return null;
        }
        $token = CustomerToken::query()->where('token', hash('sha256', $raw))->first();
        $customer = $token?->customer()->first();
        // A customer is per store: another store's customer token is not a credential here (README §2.6).
        if ($token === null || $token->isExpired() || $customer === null || (int) $customer->store_id !== (int) $store->id) {
            throw new StorefrontApiException(401, 'unauthenticated', ___('The customer token is not valid for this store.'));
        }
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $customer;
    }
}
