<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Directory\OpeningHoursService;
use App\Services\FeatureAccess;
use App\Services\Storefront\StorefrontMenuCache;
use App\Services\ThemeCustomizerService;
use App\Services\Verticals\VerticalRegistry;
use App\Storefront\StorefrontExtras;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\Store\StoreStatus;
use Juwri\Core\Settings\RefundSwitches;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Contracts\StorefrontBookings;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Menu\Contracts\StorefrontCatalog;
use Juwri\Modules\Menu\Contracts\Wishlists;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Contracts\HostedPayments;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Ordering\Data\StorefrontQuote;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Api\StorefrontApiContext;
use Juwri\Modules\Storefront\Services\Api\StorefrontSlotView;
use Juwri\Modules\Storefront\Services\Storefront\OfflineQueuePolicy;
use Juwri\Modules\Storefront\Services\Theme\KitThemes;
use Juwri\Modules\Storefront\Services\Theme\ThemeAliases;
use Juwri\Modules\Verticals\Contracts\VehicleFitments;

/**
 * P3-02 — THE storefront view model (THEME-CONTRACT §4, tools/themes/kit/viewmodel/viewmodel.schema.json): one
 * builder per request, one read-only array a kit theme renders from and nothing else (no `$postOptions`, no
 * `store_options()`, no models — grep gate `vm-bypass`).
 *
 * Reads the catalogue through Menu's StorefrontCatalog, ordering facts and money through Ordering's
 * StorefrontOrdering, settings through Core StoreSettings. Strictly one store: every row comes from a query scoped
 * to `$request->store` (tenancy test). Ids are public ids (UUIDv7) only; money is `{minor, currency, formatted}`
 * with `formatted` made on the server (MoneyFormat); URLs are relative paths of existing routes.
 *
 * Built in the request's language: the app locale and the storefront content language (the
 * `Quick_user_lang_code` value the menu models translate with) are set for the build and restored afterwards.
 */
final class ViewModelBuilder
{
    /** Core strings the storefront JS needs (cart, offline queue, errors); themes add none (§4). */
    private const I18N = [
        'Add more', 'Nothing in your order yet', 'Total to pay', 'Store closed', 'Ordering is paused',
        'Waiting for connection', "You're offline", 'Prices changed while you were offline.',
        'Please confirm your order again', "Couldn't update total — Retry", 'Sold out', 'Free',
        // P3-03: the storefront core JS (cart announcer, offline queue states, kiosk idle) — keys are read by assets/storefront/*.js
        'Added to your order', 'Removed from your order', 'Sending your order', 'Needs your confirmation', 'Order placed',
        'Order expired', 'Could not place the order', 'Connect to place this order', 'Offline: prices may change',
        'Some items are no longer available',
        'Waiting orders were removed because offline ordering was switched off.', 'Still there? Starting over in :n seconds',
        // P3-06: the kiosk screens (idle dialog, staff PIN pad, done countdown)
        'Incorrect PIN', 'Too many attempts. Wait a moment.', 'Device setup PIN', 'Enter the staff PIN', ':count of :total digits entered',
        'Starting over in :seconds s', 'Continue', 'Exit kiosk', 'Connection lost — pay at the counter to order now', 'Ordering is paused until the connection returns',
    ];

    /** The signed-in customer when they belong to the store being built (a customer is per store). */
    private ?Customer $customer = null;

    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly StorefrontOrdering $ordering,
        private readonly VerticalRegistry $verticals,
        private readonly OfflineQueuePolicy $offlineQueue,
    ) {}

    /** @return array<string, mixed> */
    public function build(ViewModelRequest $request): array
    {
        $previousLocale = App::getLocale();
        $hadCookie = array_key_exists('Quick_user_lang_code', $_COOKIE);
        $previousCookie = $_COOKIE['Quick_user_lang_code'] ?? null;
        App::setLocale($request->locale);
        $_COOKIE['Quick_user_lang_code'] = $request->locale;

        try {
            return $this->assemble($request);
        } finally {
            App::setLocale($previousLocale);
            if ($hadCookie) {
                $_COOKIE['Quick_user_lang_code'] = $previousCookie;
            } else {
                unset($_COOKIE['Quick_user_lang_code']);
            }
        }
    }

    /**
     * Only the cart quote (the `page.cart` payload) of a request — what the quote endpoint returns on every cart
     * change, without the catalogue. Null when the cart is empty or a line is not orderable at this store.
     *
     * @return array<string, mixed>|null
     */
    public function cartQuote(ViewModelRequest $request): ?array
    {
        $previousLocale = App::getLocale();
        $hadCookie = array_key_exists('Quick_user_lang_code', $_COOKIE);
        $previousCookie = $_COOKIE['Quick_user_lang_code'] ?? null;
        App::setLocale($request->locale);
        $_COOKIE['Quick_user_lang_code'] = $request->locale;

        try {
            $store = $request->store;
            $status = StoreStatus::for($store);
            $branch = $this->branch($request, $status['branch'] ?? null);
            $offered = $this->ordering->orderTypes($store, $request->room);
            if ($request->kiosk) {
                $offered = array_values(array_diff($offered, OrderTypeKeys::NOT_AT_KIOSK));
            }
            $quote = $this->quote($request, $this->selectedType($request, $offered), $branch);
            if ($quote === null) {
                return null;
            }
            $money = new MoneyFormat($store);
            $orders = new OrderPresenter($store, $money, (string) ($branch?->timezone ?: store_tz((int) $store->id)));
            $cart = $orders->quote($quote, ___('Total'));
            $cart['cta_label'] = ___('Go to checkout · :amount', ['amount' => $money->format($quote->total)]);

            return $cart;
        } finally {
            App::setLocale($previousLocale);
            if ($hadCookie) {
                $_COOKIE['Quick_user_lang_code'] = $previousCookie;
            } else {
                unset($_COOKIE['Quick_user_lang_code']);
            }
        }
    }

    /**
     * P3-05: the checkout dialog's form of a kit theme — contact fields, payment methods, tip choices, the "when"
     * choices — for the selected order type, WITHOUT a cart (the cart lives in the browser until the shopper
     * places it; the quote comes from the quote endpoint). Same shape as `page.checkout` minus `quote` and
     * `place_label`, so the dialog and the standalone checkout page are one markup.
     *
     * @return array<string, mixed>
     */
    public function checkoutForm(ViewModelRequest $request): array
    {
        $previousLocale = App::getLocale();
        $hadCookie = array_key_exists('Quick_user_lang_code', $_COOKIE);
        $previousCookie = $_COOKIE['Quick_user_lang_code'] ?? null;
        App::setLocale($request->locale);
        $_COOKIE['Quick_user_lang_code'] = $request->locale;

        try {
            $store = $request->store;
            $status = StoreStatus::for($store);
            $branch = $this->branch($request, $status['branch'] ?? null);
            $offered = $this->ordering->orderTypes($store, $request->room);
            if ($request->kiosk) {
                $offered = array_values(array_diff($offered, OrderTypeKeys::NOT_AT_KIOSK));
            }
            $orderType = $this->selectedType($request, $offered);
            $money = new MoneyFormat($store);
            $timezone = (string) ($branch?->timezone ?: store_tz((int) $store->id));
            $this->customer = $request->customer !== null && (int) $request->customer->store_id === (int) $store->id ? $request->customer : null;
            $food = $store->isFoodVertical();
            $config = $this->verticals->config((string) ($store->store_type ?: $this->verticals->default()));
            $catalog = new CatalogPresenter($this->catalog, $store, $money, $food, (bool) ($config['age_gate'] ?? false));

            return $this->checkout($request, null, $catalog, new OrderPresenter($store, $money, $timezone), $money, $orderType, $branch, $timezone);
        } finally {
            App::setLocale($previousLocale);
            if ($hadCookie) {
                $_COOKIE['Quick_user_lang_code'] = $previousCookie;
            } else {
                unset($_COOKIE['Quick_user_lang_code']);
            }
        }
    }

    /**
     * P3-03: what the storefront core JS needs on EVERY page, without the catalogue (cheap): the store's public id and
     * slug, the current branch, the selected order type, the kiosk block, the offline-queue block (effective switch,
     * queueable kit order-type keys, expiry), the named endpoints and the core strings. A subset of the view model,
     * built by the same private pieces so the two never disagree.
     *
     * @return array<string, mixed>
     */
    public function boot(ViewModelRequest $request): array
    {
        $previousLocale = App::getLocale();
        $hadCookie = array_key_exists('Quick_user_lang_code', $_COOKIE);
        $previousCookie = $_COOKIE['Quick_user_lang_code'] ?? null;
        App::setLocale($request->locale);
        $_COOKIE['Quick_user_lang_code'] = $request->locale;

        try {
            $store = $request->store;
            $status = StoreStatus::for($store);
            $branch = $this->branch($request, $status['branch'] ?? null);
            $offered = $this->ordering->orderTypes($store, $request->room);
            if ($request->kiosk) {
                $offered = array_values(array_diff($offered, OrderTypeKeys::NOT_AT_KIOSK));
            }
            $selected = $this->selectedType($request, $offered);
            $context = $this->context($store, $request, $offered, $selected, new MoneyFormat($store), $branch);

            $out = [
                'v' => 1,
                'store' => ['public_id' => (string) $store->public_id, 'slug' => (string) $store->slug],
                'locale' => $request->locale,
                'context' => array_filter([
                    'order_type' => $context['selected_order_type'] !== '' ? $context['selected_order_type'] : null,
                    'order_types' => array_column($context['order_types'], 'key'),
                    'table' => $context['table'],
                    'kiosk' => $context['kiosk'] ?? null,
                ], fn ($v) => $v !== null),
                'offline_queue' => $context['offline_queue'],
                'endpoints' => $this->endpoints($store, $request),
                'i18n' => $this->i18n(),
            ];
            if ($branch !== null && $branch->public_id !== null) {
                $out['branch'] = (string) $branch->public_id;
            }

            return $out;
        } finally {
            App::setLocale($previousLocale);
            if ($hadCookie) {
                $_COOKIE['Quick_user_lang_code'] = $previousCookie;
            } else {
                unset($_COOKIE['Quick_user_lang_code']);
            }
        }
    }

    /**
     * P3-06b: the blocks one headless storefront API read answers (kit/api openapi.json), built by the same private
     * pieces as the full view model so a theme and a headless client read the same labels and money:
     *
     *  - `store`:   store, branch, context, money, features (StoreResponse)
     *  - `catalog`: catalog (CatalogResponse)
     *  - `catalog_index` (P3-08): catalog + `index` (item id => price, in-stock, facet values, position, category,
     *               newest) + `facets` (RetailFacets) for the filtered / sorted / paged GET /catalog
     *  - `item`:    item (ItemDetail of `$request->item`) + `model` (the Menu row, for the API's machine fields);
     *               null when the item is not this store's, inactive or hidden.
     *
     * @return array<string, mixed>|null
     */
    public function apiBlocks(ViewModelRequest $request, string $kind): ?array
    {
        $previousLocale = App::getLocale();
        $hadCookie = array_key_exists('Quick_user_lang_code', $_COOKIE);
        $previousCookie = $_COOKIE['Quick_user_lang_code'] ?? null;
        App::setLocale($request->locale);
        $_COOKIE['Quick_user_lang_code'] = $request->locale;

        try {
            $store = $request->store;
            $type = (string) ($store->store_type ?: $this->verticals->default());
            $config = $this->verticals->config($type);
            $food = $store->isFoodVertical();
            $money = new MoneyFormat($store);
            $this->customer = $request->customer !== null && (int) $request->customer->store_id === (int) $store->id ? $request->customer : null;
            $status = StoreStatus::for($store);
            $branch = $this->branch($request, $status['branch'] ?? null);
            $timezone = (string) ($branch?->timezone ?: store_tz((int) $store->id));
            $offered = $this->ordering->orderTypes($store, $request->room);
            if ($request->kiosk) {
                $offered = array_values(array_diff($offered, OrderTypeKeys::NOT_AT_KIOSK));
            }
            $orderType = $this->selectedType($request, $offered);
            $catalog = new CatalogPresenter($this->catalog, $store, $money, $food, (bool) ($config['age_gate'] ?? false));

            $vehicle = $this->vehicle($request);
            if ($kind === 'item') {
                $item = $request->item !== null ? $this->catalog->item($store, $request->item, $branch, $orderType) : null;
                if ($item !== null && $vehicle !== null) {
                    $catalog->forVehicle(app(VehicleFitments::class)->fits($store, [(int) $item->id], $vehicle));
                }

                return $item === null ? null : ['item' => $catalog->detail($item), 'model' => $item];
            }

            // P6-24: a food catalogue answer (cards + search index) is cached whole, so a hit does not load every item model
            if ($kind === 'catalog_index' && $food && $vehicle === null) {
                $facets = new RetailFacets($this->catalog, app(RetailCatalog::class), $store);
                // P6-49: the items are loaded only when the cards are not cached (a hit never hydrates the 2,000 models)
                $items = $this->lazyItems($store, $branch, $orderType, null);
                $both = $this->cardsCached($request, $store, $branch, $orderType, null, null, fn () => $this->foodCatalogIndex($catalog, $facets, $items(), $food), 'index');

                return ['catalog' => $both['catalog'], 'index' => $both['index'], 'facets' => $facets, 'food' => $food, 'vehicle' => null];
            }

            // P6-49: the store block needs only the featured list (cached); the catalogue kinds below load the items
            $lazy = $this->lazyItems($store, $branch, $orderType, null);
            $items = $kind === 'store' ? collect() : $lazy();
            $fits = $vehicle !== null ? app(VehicleFitments::class)->fits($store, $items->pluck('id')->map(fn ($id) => (int) $id)->all(), $vehicle) : null;
            if ($fits !== null) {
                $catalog->forVehicle($fits);
            }
            if ($kind === 'catalog') {
                return ['catalog' => $this->cardsCached($request, $store, $branch, $orderType, null, $vehicle, fn () => $catalog->catalog($items), '')];
            }
            if ($kind === 'catalog_index') {
                // P3-08 (kit/api GET /catalog filters): the catalogue plus, per item, what the filters and sorts read.
                $built = $this->cardsCached($request, $store, $branch, $orderType, null, $vehicle, fn () => $catalog->catalog($items), '');
                $facets = new RetailFacets($this->catalog, app(RetailCatalog::class), $store);
                $index = $this->catalogIndex($facets, $items, $built, $food, $fits);

                return ['catalog' => $built, 'index' => $index, 'facets' => $facets, 'food' => $food, 'vehicle' => $vehicle];
            }

            return [
                'store' => $this->store($store, $request, $type, $config, $status, $branch, $timezone),
                'branch' => $this->branchBlock($store, $branch),
                'context' => $this->context($store, $request, $offered, $orderType, $money, $branch),
                'money' => $money->block() + $this->tax($store),
                'features' => $this->features($store, $this->featuredIds($request, $store, $branch, $orderType, null, $vehicle, $lazy), $config),
            ];
        } finally {
            App::setLocale($previousLocale);
            if ($hadCookie) {
                $_COOKIE['Quick_user_lang_code'] = $previousCookie;
            } else {
                unset($_COOKIE['Quick_user_lang_code']);
            }
        }
    }

    /**
     * P3-06b: the server quote of a headless cart (kit/api `POST /quote`, `POST /orders`): the Ordering module's
     * priced cart and the view model Quote presented from it ("Total to pay"). Null when a line is not orderable at
     * this store or the cart is empty. `$request->orderType` is the core fulfilment key the caller checked.
     *
     * @return array{priced: StorefrontQuote, quote: array<string, mixed>, weighed: array{estimate: int, cap: int}|null}|null
     */
    public function apiQuote(ViewModelRequest $request): ?array
    {
        return $this->localized($request, function () use ($request) {
            $store = $request->store;
            $status = StoreStatus::for($store);
            $branch = $this->branch($request, $status['branch'] ?? null);
            $priced = $this->ordering->quote($store, $request->cart, $request->orderType, $branch, $request->coupon, $request->tipMinor, $request->shipping, $request->deliveryFeeMinor);
            if ($priced === null) {
                return null;
            }
            $orders = new OrderPresenter($store, new MoneyFormat($store), (string) ($branch?->timezone ?: store_tz((int) $store->id)));

            // P3-09: the weighed lines' estimate and cap (kit/api QuoteResponse.weighed)
            return ['priced' => $priced, 'quote' => $orders->quote($priced, ___('Total to pay')), 'weighed' => $orders->weighedTotals($priced)];
        });
    }

    /**
     * P3-06b: a placed order of the store for the headless API (`POST /orders`, `GET /orders/{track_token}`): the
     * view model Order (track page payload), its Quote, the priced money and the shopper-facing number. Null for
     * another store's order.
     *
     * @return array{view: array<string, mixed>, quote: array<string, mixed>, priced: StorefrontQuote, number: string}|null
     */
    public function apiOrder(ViewModelRequest $request, Order $order): ?array
    {
        return $this->localized($request, function () use ($request, $order) {
            $store = $request->store;
            $priced = $this->ordering->orderQuote($store, $order);
            if ($priced === null) {
                return null;
            }
            $branch = $order->branch_id ? Branch::query()->where('store_id', $store->id)->whereKey($order->branch_id)->first() : null;
            $orders = new OrderPresenter($store, new MoneyFormat($store), (string) ($branch?->timezone ?: store_tz((int) $store->id)));

            return [
                'view' => $orders->order($order, $priced),
                'quote' => $orders->quote($priced, ___('Total to pay')),
                'priced' => $priced,
                'number' => $orders->number($order),
            ];
        });
    }

    /**
     * Runs `$build` in the request's language (app locale + storefront content language), restoring both after.
     *
     * @template T
     *
     * @param  \Closure(): T  $build
     * @return T
     */
    private function localized(ViewModelRequest $request, \Closure $build): mixed
    {
        $previousLocale = App::getLocale();
        $hadCookie = array_key_exists('Quick_user_lang_code', $_COOKIE);
        $previousCookie = $_COOKIE['Quick_user_lang_code'] ?? null;
        App::setLocale($request->locale);
        $_COOKIE['Quick_user_lang_code'] = $request->locale;

        try {
            return $build();
        } finally {
            App::setLocale($previousLocale);
            if ($hadCookie) {
                $_COOKIE['Quick_user_lang_code'] = $previousCookie;
            } else {
                unset($_COOKIE['Quick_user_lang_code']);
            }
        }
    }

    /** The view model as JSON for the storefront JS (empty maps as `{}`). */
    public function json(ViewModelRequest $request): string
    {
        return ViewModelJson::encode($this->build($request));
    }

    /**
     * P4-16 (owner decision 2026-10-08): the built catalogue cards are kept for `perf.storefront_catalog_cache_ttl` seconds (60), per store,
     * language, branch, order type and category, under the store's menu-cache token, so any menu / category / branch / settings /
     * theme write (the model saves bump the token) shows at once. A write that bypasses the models (a bulk update) or a stock change
     * waits at most the TTL. This is display only: the cart quote and the order read the items again and refuse a sold-out item.
     * Not cached for a vehicle search (the fits are per visitor).
     *
     * @param  array<string, mixed>|null  $vehicle
     * @param  \Closure():array<mixed>  $build
     * @return array<string, mixed>
     */
    private function cardsCached(ViewModelRequest $r, Store $store, ?Branch $branch, ?string $orderType, ?int $categoryId, ?array $vehicle, \Closure $build, string $part): array
    {
        $ttl = (int) config('perf.storefront_catalog_cache_ttl', 60);
        if ($ttl <= 0 || $vehicle !== null) {
            return $build();
        }
        $key = 'sfcards:'.hash('sha256', implode('|', [
            (int) $store->id, $r->locale, (int) $branch?->id, (string) $orderType, (int) $categoryId, $part,
            StorefrontMenuCache::version((int) $store->id),
            /* P6-49: the cards carry decorated (time-of-day) prices and availability. Everything time-dependent that
               decorate() reads is folded into one signature worked out WITHOUT loading the items (live schedules, open
               category and item windows, the kill-switch); everything else changes only by an edit, which bumps the version
               above. Same version + same signature = same cards, so a hit costs a few small queries, not 2,000 models
               (P6-24c fingerprinted the decorated items, which needed them loaded and decorated on every request). */
            $this->catalog->decorationSignature($store, $orderType),
            StoreSettings::bool((int) $store->id, 'menu_show_unavailable') ? 'u' : '-',
        ]));

        /* One key always maps to the same cards, so serving the previous copy while one worker refreshes it is exact; the
           lock inside flexible() keeps the other workers from rebuilding the same answer in parallel. Fresh for half the TTL,
           then served while refreshed, never older than the TTL (as before). */
        return Cache::flexible($key, [max(1, intdiv($ttl, 2)), $ttl], $build);
    }

    /**
     * P6-49: the first eight popular / daily-special items (the `featured` list), cached with the cards under the same key
     * so a hit does not load the items either.
     *
     * @param  \Closure(): \Illuminate\Support\Collection<int, Menu>  $items
     * @param  array<string, mixed>|null  $vehicle
     * @return list<string>
     */
    private function featuredIds(ViewModelRequest $r, Store $store, ?Branch $branch, ?string $orderType, ?int $categoryId, ?array $vehicle, \Closure $items): array
    {
        return array_values($this->cardsCached($r, $store, $branch, $orderType, $categoryId, $vehicle, fn () => $items()
            ->filter(fn (Menu $m) => (bool) $m->is_popular || (int) $m->is_daily_special > 0)
            ->take(8)->map(fn (Menu $m) => (string) $m->public_id)->values()->all(), 'featured'));
    }

    /**
     * P6-49: the store's shaped items, loaded on the first call only (and once per request).
     *
     * @return \Closure(): \Illuminate\Support\Collection<int, \Juwri\Modules\Menu\Models\Menu>
     */
    private function lazyItems(Store $store, ?Branch $branch, ?string $orderType, ?int $categoryId, bool $none = false): \Closure
    {
        $loaded = null;

        return function () use (&$loaded, $store, $branch, $orderType, $categoryId, $none) {
            return $loaded ??= $none ? collect() : $this->catalog->items($store, $branch, $orderType, $categoryId);
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Juwri\Modules\Menu\Models\Menu>  $items
     * @return array{catalog: array<string, mixed>, index: array<string, array<string, mixed>>}
     */
    private function foodCatalogIndex(CatalogPresenter $catalog, RetailFacets $facets, $items, bool $food): array
    {
        $built = $catalog->catalog($items);

        return ['catalog' => $built, 'index' => $this->catalogIndex($facets, $items, $built, $food, null)];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Juwri\Modules\Menu\Models\Menu>  $items
     * @param  array<string, mixed>  $built
     * @param  array<int, mixed>|null  $fits
     * @return array<string, array<string, mixed>> per item: what the catalogue filters and sorts read
     */
    private function catalogIndex(RetailFacets $facets, $items, array $built, bool $food, ?array $fits): array
    {
        $index = [];
        $position = 0;
        $byCategory = [];
        foreach ($built['categories'] as $category) {
            foreach ($category['item_ids'] as $itemId) {
                $byCategory[$itemId] ??= $category['id'];
            }
        }
        foreach ($items as $item) {
            $id = (string) $item->public_id;
            if (! isset($built['items'][$id])) {
                continue;
            }
            $index[$id] = $facets->index($item, $built['items'][$id], $food) + ['position' => $position++, 'category' => $byCategory[$id] ?? null]
                + ($fits !== null ? ['fits' => $fits[(int) $item->id] ?? null] : []);
        }

        return $index;
    }

    /** @return array<string, mixed> */
    private function assemble(ViewModelRequest $r): array
    {
        $store = $r->store;
        $id = (int) $store->id;
        $type = (string) ($store->store_type ?: $this->verticals->default());
        $config = $this->verticals->config($type);
        $food = $store->isFoodVertical();
        $money = new MoneyFormat($store);
        $this->customer = $r->customer !== null && (int) $r->customer->store_id === $id ? $r->customer : null;
        $status = StoreStatus::for($store);
        $branch = $this->branch($r, $status['branch'] ?? null);
        $timezone = (string) ($branch?->timezone ?: store_tz($id));

        $theme = $this->theme($store, $r, $type);
        $booking = $this->bookingMode($store, $theme['slug'], $money, $r->locale);
        $laundry = $booking === null && $this->laundryMode($store, $theme['slug']);

        $offered = $booking !== null ? ['appointment'] : $this->ordering->orderTypes($store, $r->room);
        if ($laundry) {
            $offered = array_values(array_intersect($offered, ['pickup_slot', 'delivery'])); // the laundry's own flow: drop off, or collect and bring back
        }
        if ($r->kiosk) {
            $offered = array_values(array_diff($offered, OrderTypeKeys::NOT_AT_KIOSK));
        }
        $orderType = $this->selectedType($r, $offered);

        // P4-16: a category's "Load more" fragment reads and presents that category only (was every item of the store: 1.1 s for 2,000)
        $categoryId = null;
        if ($r->onlyCategory !== null && $booking === null) {
            $wanted = collect($this->catalog->categories($store))->first(fn ($c) => (string) $c->public_id === $r->onlyCategory);
            $categoryId = $wanted === null ? 0 : (int) $wanted->id; // 0 = unknown category: no items
        }
        // P6-49: the items are loaded only when something needs them (cards or featured not cached, a vehicle search, the laundry)
        $items = $this->lazyItems($store, $branch, $orderType, $categoryId, $booking !== null);
        $catalog = new CatalogPresenter($this->catalog, $store, $money, $food, (bool) ($config['age_gate'] ?? false));
        $vehicle = $booking === null ? $this->vehicle($r) : null;
        if ($vehicle !== null) {
            $catalog->forVehicle(app(VehicleFitments::class)->fits($store, $items()->pluck('id')->map(fn ($id) => (int) $id)->all(), $vehicle));
        }
        $orders = new OrderPresenter($store, $money, $timezone);
        $laundryVm = $laundry ? new LaundryViewModel($store, $orders) : null;

        $vm = [
            'store' => $this->store($store, $r, $type, $config, $status, $branch, $timezone),
            'branch' => $this->branchBlock($store, $branch),
            'context' => $booking !== null ? $booking->context($r->orderType) : $this->context($store, $r, $offered, $orderType, $money, $branch),
            'theme' => $theme,
            'catalog' => $booking !== null ? $booking->catalog() : ($laundryVm !== null ? $laundryVm->catalog($catalog->catalog($items()), $items()) : $this->cardsCached($r, $store, $branch, $orderType, $categoryId, $vehicle, fn () => $catalog->catalog($items()), '')),
            'features' => $this->features($store, $booking !== null ? [] : $this->featuredIds($r, $store, $branch, $orderType, $categoryId, $vehicle, $items), $config),
            'customer' => $this->customerBlock($store),
            'endpoints' => $this->endpoints($store, $r),
            'money' => $money->block() + $this->tax($store),
            'i18n' => $this->i18n(),
        ];
        if ($booking !== null) {
            $vm['store']['status']['accepting_bookings'] = app(StorefrontBookings::class)->open($store); // D13: no hours, no bookings
            $vm['endpoints'] = $this->bookingEndpoints($store, $vm['endpoints']);
        }

        if ($laundryVm !== null) {
            $vm['endpoints'] = $this->laundryEndpoints($store, $vm['endpoints']);
        }

        // P3-07 (brew): the kitchen's own prep time (hidden by the theme when unknown), the one-line note of a cafe, and the
        // signed-in customer's earlier lines for the `reorder` section (only built when the theme shows that section)
        if ($food) {
            $prep = $this->ordering->prepTimeMinutes($store, $branch); // P5-31: the branch's own prep time
            if ($prep !== null) {
                $vm['context']['prep_time_min'] = $prep;
            }
        }
        if ($type === 'cafe') {
            $vm['features']['line_note'] = ['max_length' => self::LINE_NOTE_MAX];
        }
        if ($this->customer !== null && in_array('reorder', $vm['theme']['sections'], true)) {
            $recent = $this->recentItems($store, $vm['catalog']['items']);
            if ($recent !== []) {
                $vm['customer']['recent_items'] = $recent;
            }
        }

        $page = $booking !== null
            ? $this->bookingPage($r, $booking)
            : ($laundryVm !== null && in_array($r->page, ['schedule', 'details'], true)
                ? $this->laundryPage($r, $laundryVm)
                : $this->page($r, $catalog, $orders, $money, $orderType, $branch, $timezone, $vm['context']));
        if ($page !== null) {
            $vm['page'] = $page;
        }

        return $vm;
    }

    /**
     * P3-11: the booking-engine view model of this request, or null when the store does not use it (it renders the
     * `book` theme, and has at least one bookable service).
     */
    private function bookingMode(Store $store, string $themeSlug, MoneyFormat $money, string $locale): ?BookingViewModel
    {
        if ($themeSlug !== 'book') {
            return null;
        }
        $bookings = app(StorefrontBookings::class);

        return $bookings->inUse($store) ? new BookingViewModel($store, $money, $bookings, $locale) : null;
    }

    /** P3-11: a `book` store that is a laundry (a service store without bookable services): the laundry's flow, not the booking engine's. */
    private function laundryMode(Store $store, string $themeSlug): bool
    {
        if ($themeSlug !== 'book' || ! $this->verticals->has($store->store_type ?? null, 'service')) {
            return false;
        }

        // a service store that takes laundry by a pickup slot or delivery; the appointments vertical (`services`) is the booking engine's
        return in_array('pickup_slot', (array) ($this->verticals->config((string) $store->store_type)['fulfillment'] ?? []), true);
    }

    /**
     * @param  array<string, string>  $endpoints
     * @return array<string, string>
     */
    private function laundryEndpoints(Store $store, array $endpoints): array
    {
        $slug = (string) $store->slug;
        $endpoints['lines'] = route('laundry.book.lines', ['slug' => $slug], false);
        $endpoints['times'] = route('laundry.book.schedule', ['slug' => $slug], false);
        $endpoints['hold'] = route('laundry.book.hold', ['slug' => $slug], false);
        $endpoints['place'] = route('laundry.book.place', ['slug' => $slug], false);

        return $endpoints;
    }

    /** @return array<string, mixed> the `page` of a laundry step (choose the times, confirm) */
    private function laundryPage(ViewModelRequest $r, LaundryViewModel $laundry): array
    {
        if ($r->booking === null) {
            return ['kind' => 'states'];
        }

        return ['kind' => 'checkout', 'checkout' => $r->page === 'schedule'
            ? $laundry->schedule($r->booking)
            : $laundry->details($r->booking, $this->customer->name ?? null, $this->customer->phone ?? null)];
    }

    /**
     * @param  array<string, string>  $endpoints
     * @return array<string, string>
     */
    private function bookingEndpoints(Store $store, array $endpoints): array
    {
        $slug = (string) $store->slug;
        $endpoints['quote'] = route('store.bookingQuote', ['store' => $slug], false);
        $endpoints['times'] = route('bookings.times', ['slug' => $slug], false);
        $endpoints['hold'] = route('bookings.hold', ['slug' => $slug], false);
        $endpoints['book'] = route('bookings.book', ['slug' => $slug], false);
        $endpoints['place'] = $endpoints['book'];

        return $endpoints;
    }

    /** @return array<string, mixed>|null the `page` of a booking-engine request */
    private function bookingPage(ViewModelRequest $r, BookingViewModel $booking): ?array
    {
        $location = $r->orderType === 'at_home' ? 'at_home' : 'venue';
        switch ($r->page) {
            case 'item':
                $item = $r->item !== null ? $booking->item($r->item) : null;

                return $item === null ? ['kind' => 'states'] : ['kind' => 'item', 'item' => $item];

            case 'cart':
                $cart = $booking->cart(array_map(fn (array $line) => (string) $line['item'], $r->cart), $location);

                return $cart === null ? ['kind' => 'states'] : ['kind' => 'cart', 'cart' => $cart];

            case 'time':
            case 'reschedule':
                return $r->booking === null ? ['kind' => 'states'] : ['kind' => 'checkout', 'checkout' => $booking->times($r->booking, $r->page === 'reschedule')];

            case 'details':
                return $r->booking === null ? ['kind' => 'states'] : ['kind' => 'checkout', 'checkout' => $booking->details($r->booking, $this->customer->name ?? null, $this->customer->phone ?? null)];

            case 'pay':
                return $r->booking === null ? ['kind' => 'states'] : ['kind' => 'checkout', 'checkout' => $booking->pay($r->booking)];

            case 'manage':
                return $r->booking === null ? ['kind' => 'states'] : ['kind' => 'track', 'order' => $booking->manage($r->booking)];
        }

        return null;
    }

    /**
     * P3-11: only the cart quote of a booking-engine store (the booking quote endpoint): the chosen services' lines,
     * tax and total. Null when the cart is empty or a service cannot be booked.
     *
     * @return array<string, mixed>|null
     */
    public function bookingCartQuote(ViewModelRequest $request): ?array
    {
        $previousLocale = App::getLocale();
        App::setLocale($request->locale);

        try {
            $booking = $this->bookingMode($request->store, 'book', new MoneyFormat($request->store), $request->locale);

            return $booking?->cart(array_map(fn (array $line) => (string) $line['item'], $request->cart), $request->orderType === 'at_home' ? 'at_home' : 'venue');
        } finally {
            App::setLocale($previousLocale);
        }
    }

    /**
     * P3-08b (aisle §13): the shopper's vehicle (`$request->vehicle`), only for a store that sells by vehicle and a key
     * that is valid; anything else is no vehicle (a stale link never breaks the page).
     *
     * @return array<string, mixed>|null
     */
    private function vehicle(ViewModelRequest $r): ?array
    {
        if ($r->vehicle === null || $r->vehicle === '') {
            return null;
        }

        return app(VehicleFitments::class)->resolve($r->store, $r->vehicle);
    }

    /** @return array<string, mixed>|null the signed-in customer (P3-08: with their wishlist at a goods store) */
    private function customerBlock(Store $store): ?array
    {
        if ($this->customer === null) {
            return null;
        }
        $out = ['name' => (string) $this->customer->name];
        if ($this->sellsGoods($store)) {
            $out['wishlist'] = array_column(app(Wishlists::class)->list($store, (int) $this->customer->id, null), 'item_id');
        }

        return $out;
    }

    /** brew §7: "Name on the cup" is one short line */
    public const LINE_NOTE_MAX = 20;

    /**
     * brew §4 `reorder`: the customer's earlier lines, only those whose item is in this page's catalogue (so only this
     * store's, and only what the current branch and order type offer) and that the storefront would accept now.
     *
     * @param  array<string, array<string, mixed>>  $items  the view model's catalogue items by public id
     * @return list<array<string, mixed>>
     */
    private function recentItems(Store $store, array $items): array
    {
        $out = [];
        foreach ($this->ordering->recentLines($store, (int) $this->customer->id, 12) as $line) {
            $card = $items[$line['item']] ?? null;
            if ($card === null || ! empty($card['sold_out'])) {
                continue;
            }
            $row = [
                'item_id' => $line['item'],
                'label' => $line['label'],
                'reorder_url' => (string) $card['url'],
                'variant' => $line['variant'],
                'options' => $line['options'],
                'complete' => $line['complete'],
            ];
            if ($line['choices_label'] !== '') {
                $row['choices_label'] = $line['choices_label'];
            }
            $out[] = $row;
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    // ---- store ------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function store(Store $store, ViewModelRequest $r, string $type, array $config, array $status, ?Branch $branch, string $timezone): array
    {
        $id = (int) $store->id;
        $out = [
            'public_id' => (string) $store->public_id,
            'slug' => (string) $store->slug,
            'name' => (string) $store->title,
        ];
        if (! empty($store->main_image) && $store->main_image !== 'default.png') {
            $out['logo'] = ['src' => media_url('storage/store/logo/', (string) $store->main_image, $id), 'alt' => (string) $store->title];
        }
        if (! empty($store->cover_image) && $store->cover_image !== 'default.png') {
            $out['cover'] = ['src' => media_url('storage/store/cover/', (string) $store->cover_image, $id), 'alt' => (string) $store->title];
        }
        if (trim((string) $store->sub_title) !== '') {
            $out['tagline'] = trim((string) $store->sub_title);
        }
        $out += [
            'locale' => $r->locale,
            'dir' => is_rtl($r->locale) ? 'rtl' : 'ltr',
            'locales' => $this->locales($store, $r->locale),
            'timezone' => $timezone,
            'vertical_set' => in_array($config['themes'] ?? null, ['food', 'retail', 'service', 'hotel'], true) ? $config['themes'] : 'food',
            'store_type' => $type,
            'status' => $this->status($store, $status, $branch, $timezone),
        ];

        $contact = array_filter([
            'phone' => trim((string) $store->phone) ?: null,
            'whatsapp' => storefront_whatsapp_number($store, null, false) ?: null,
            'address' => trim((string) $store->address) ?: null,
        ]);
        if (isset($contact['address'])) {
            $contact['map_url'] = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode((string) $contact['address']);
        }
        $out['contact'] = $contact;

        $social = [];
        foreach (['instagram' => 'instagram_url', 'facebook' => 'facebook_url'] as $network => $setting) {
            $url = trim(StoreSettings::string($id, $setting));
            if ($url !== '' && preg_match('#^https://#i', $url) === 1) {
                $social[] = ['network' => $network, 'url' => $url];
            }
        }
        if ($social !== []) {
            $out['social'] = $social;
        }

        $legal = [];
        foreach (['privacy' => ___('Privacy policy'), 'terms' => ___('Terms and conditions')] as $page => $label) {
            if (trim(StoreSettings::string($id, 'sf_page_'.$page)) !== '') {
                $legal[] = ['label' => $label, 'url' => route('store.infoPage', ['store' => $store->slug, 'page' => $page], false)];
            }
        }
        $out['legal_links'] = $legal;

        $identity = $this->identity($store);
        if ($identity !== null) {
            $out['identity'] = $identity;
        }
        $out['powered_by'] = ! StorefrontExtras::platform($store)['hide_branding'];

        $hours = $this->hours($branch);
        if ($hours !== []) {
            $out['hours'] = $hours;
        }

        if ($this->sellsGoods($store)) {
            // P3-08 (aisle): the returns policy and the lowest standard shipping rate (SEO / merchant listing).
            $days = StoreSettings::int($id, 'returns_window_days');
            if ($days > 0) {
                $out['returns'] = ['days' => $days];
            }
            $rate = ShippingMethod::query()->where('store_id', $id)->where('active', true)->where('kind', 'standard')
                ->where('currency_code', strtoupper(store_currency($id)))->min('fee_minor');
            if ($rate !== null) {
                $out['shipping_rate'] = (new MoneyFormat($store))->money((int) $rate);
            }
        }

        return $out;
    }

    /** @return list<array{code: string, name: string, url: string}> */
    private function locales(Store $store, string $current): array
    {
        $codes = StoreSettings::array((int) $store->id, 'menu_languages');
        $codes = $codes === [] ? [$current] : array_values(array_unique(array_map('strval', $codes)));
        $languages = Language::query()->whereIn('code', $codes)->where('active', 1)->get()->keyBy('code');
        $base = route('publicView', ['slug' => $store->slug], false);

        $out = [];
        foreach ($codes as $code) {
            $language = $languages->get($code);
            if ($language === null && $code !== $current) {
                continue;
            }
            $out[] = ['code' => $code, 'name' => (string) ($language->name ?? $code), 'url' => $base.'?lang='.rawurlencode($code)];
        }

        return $out;
    }

    /**
     * Open / closed / paused, with the next change in the store's time: the branch-hours seam (StoreStatus) plus
     * the owner's manual "closed" switch.
     *
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function status(Store $store, array $status, ?Branch $branch, string $timezone): array
    {
        $manualClosed = StoreSettings::has((int) $store->id, 'open_close_store')
            && ! StoreSettings::bool((int) $store->id, 'open_close_store', true);
        $paused = $branch !== null && ! (bool) ($branch->accepting_orders ?? true);
        $hours = is_array($branch?->hours) ? $branch->hours : null;
        $state = OpeningHoursService::isOpenAt($hours, $timezone);
        $open = (bool) $status['open'] && ! $manualClosed;

        $nextAt = null;
        $time = $state['closes_at'] ?? $state['opens_at'] ?? null;
        if (is_string($time) && preg_match('/^\d{1,2}:\d{2}$/', $time) === 1) {
            $nextAt = Carbon::now($timezone)->setTimeFromTimeString($time);
            if (isset($state['opens_at']) && $nextAt->lte(Carbon::now($timezone))) {
                $nextAt->addDay();
            }
        }
        $timeLabel = $nextAt?->locale(App::getLocale())->translatedFormat('g:i A');

        $label = match (true) {
            $paused => ___('Ordering is paused'),
            $open && isset($state['closes_at']) && $timeLabel !== null => ___('Open · closes :time', ['time' => $timeLabel]),
            $open => null,
            isset($state['opens_at']) && $timeLabel !== null && ! $manualClosed => ___('Closed now · opens :time', ['time' => $timeLabel]),
            default => ___('Store closed'),
        };

        $out = ['open' => $open && ! $paused];
        if ($label !== null) {
            $out['label'] = $label;
        }
        $out['next_change_at'] = $nextAt?->toIso8601String();
        $out['paused_reason'] = $paused ? (trim((string) ($branch->closed_message ?? '')) ?: ___('Ordering is paused')) : null;

        return $out;
    }

    /** @return array<string, mixed>|null §4.1: legal identity rows, only those the store filled; none = no block */
    private function identity(Store $store): ?array
    {
        $id = (int) $store->id;
        $legalName = trim(StoreSettings::string($id, 'fiscal_legal_name'));
        if ($legalName === '') {
            return null;
        }
        $out = ['legal_name' => $legalName];
        $cr = trim(StoreSettings::string($id, 'fiscal_cr_number'));
        if ($cr !== '') {
            $out['commercial_registration'] = ['number' => $cr, 'label' => ___('Commercial registration')];
        }
        $vat = trim(StoreSettings::string($id, 'fiscal_vat_number'));
        if ($vat !== '') {
            $out['tax_registration'] = ['number' => $vat, 'label' => ___('Tax number')];
        }
        if (trim((string) $store->address) !== '') {
            $out['address'] = trim((string) $store->address);
        }

        return $out;
    }

    /** @return list<array{days: list<string>, opens: string, closes: string}> schema.org day codes Mo … Su */
    private function hours(?Branch $branch): array
    {
        $out = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $h = is_array($branch?->hours) ? ($branch->hours[$day] ?? null) : null;
            if (! is_array($h) || ! empty($h['closed']) || ! isset($h['open'], $h['close'])) {
                continue;
            }
            $out[] = ['days' => [ucfirst(substr($day, 0, 2))], 'opens' => (string) $h['open'], 'closes' => (string) $h['close']];
        }

        return $out;
    }

    // ---- branch / context -------------------------------------------------------------------------------------

    private function branch(ViewModelRequest $r, ?Branch $primary): ?Branch
    {
        if ($r->branch !== null && (int) $r->branch->store_id === (int) $r->store->id && $r->branch->active) {
            return $r->branch;
        }

        return $primary;
    }

    /** @return array<string, mixed> */
    private function branchBlock(Store $store, ?Branch $branch): array
    {
        // A store always has a branch in practice; without one the store itself is the current location.
        $current = $branch !== null && $branch->public_id !== null
            ? ['public_id' => (string) $branch->public_id, 'name' => (string) $branch->name]
            : ['public_id' => (string) $store->public_id, 'name' => (string) $store->title];
        $address = trim((string) ($branch->address ?? $store->address));
        if ($address !== '') {
            $current['address'] = $address;
        }
        $out = ['current' => $current];

        if (StoreSettings::bool((int) $store->id, 'allow_multi_branch_switch', true)) {
            $all = Branch::query()->where('store_id', $store->id)->where('active', true)->orderBy('position')->orderBy('id')->get();
            if ($all->count() > 1) {
                $base = route('publicView', ['slug' => $store->slug], false);
                $out['switchable'] = $all->map(fn (Branch $b) => [
                    'public_id' => (string) $b->public_id, 'name' => (string) $b->name, 'url' => $base.'?branch='.$b->public_id,
                ])->values()->all();
            }
        }

        return $out;
    }

    /** @param  list<string>  $offered */
    private function selectedType(ViewModelRequest $r, array $offered): ?string
    {
        if ($r->orderType !== null && in_array($r->orderType, $offered, true)) {
            return $r->orderType;
        }
        if ($r->table !== null && in_array($r->room ? 'room_service' : 'on-table', $offered, true)) {
            return $r->room ? 'room_service' : 'on-table';
        }
        // brew (cafe): pickup is the default flow (THEME sections/brew.md, "Flow differences"); the others follow
        if ($this->themeSlug($r) === 'brew') {
            foreach (['takeaway', 'pickup'] as $preferred) {
                if (in_array($preferred, $offered, true)) {
                    return $preferred;
                }
            }
        }

        return $offered[0] ?? null;
    }

    /**
     * @param  list<string>  $offered
     * @return array<string, mixed>
     */
    private function context(Store $store, ViewModelRequest $r, array $offered, ?string $selected, MoneyFormat $money, ?Branch $branch = null): array
    {
        $id = (int) $store->id;
        $fulfillment = (array) config('verticals.fulfillment', []);
        $types = [];
        foreach ($offered as $core) {
            $key = OrderTypeKeys::toKit($core);
            if ($key === null) {
                continue;
            }
            $types[] = [
                'key' => $key,
                'label' => ___((string) ($fulfillment[$core]['label'] ?? $core)),
                'needs_address' => (bool) ($fulfillment[$core]['needs_address'] ?? false),
                'needs_slot' => (bool) ($fulfillment[$core]['needs_slot'] ?? false),
            ];
        }

        $out = [
            'order_types' => $types,
            'selected_order_type' => $selected !== null ? (string) OrderTypeKeys::toKit($selected) : '',
            'table' => $r->table !== null && ! $r->room ? ['label' => $r->table] : null,
            'room' => $r->table !== null && $r->room ? ['label' => $r->table] : null,
            'schedule_ahead' => $this->ordering->schedulingEnabled($store),
        ];
        $min = Branch::effectiveMinOrder($id, $branch); // P5-31: the branch's own minimum when it has one
        if ($min !== null) {
            $out['min_order'] = $money->major($min);
        }

        if ($r->kiosk) {
            $width = StoreSettings::int($id, 'kiosk_printer_width', 48);
            $out['kiosk'] = [
                'on' => true,
                'idle_seconds' => max(15, StoreSettings::int($id, 'kiosk_idle_seconds', 60) ?: 60),
                'show_identity' => false,
                'order_types' => array_column($types, 'key'),
                'print' => ['auto' => StoreSettings::bool($id, 'kiosk_auto_print'), 'width' => in_array($width, [32, 48], true) ? $width : 48],
            ];
            if (! empty(store_options($id, 'kiosk_loyalty'))) {
                $out['kiosk']['loyalty'] = true; // item 26: the customer may use their account here (opt-in, proved by code or pass)
            }
        }

        // P3-14 (D7): every per-flow refund switch, each off by default; themes only change copy with them (weighed
        // hold vs refund copy, "Deposits are not refunded", returns as exchange / store credit or refund).
        $out['refunds'] = RefundSwitches::forStore($id);

        $vehicle = $this->vehicle($r);
        if ($vehicle !== null) {
            $out['vehicle'] = array_intersect_key($vehicle, array_flip(['label', 'year', 'make', 'model'])); // P3-08b (aisle §13)
        }

        $queueTypes = array_values(array_filter(array_map(
            fn (string $core) => OrderTypeKeys::toKit($core),
            $this->offlineQueue->orderTypes($offered)
        )));
        $out['offline_queue'] = [
            'enabled' => $this->offlineQueue->enabled($store) && $queueTypes !== [],
            'order_types' => $queueTypes,
            'expiry_min' => $this->offlineQueue->expiryMinutes($store),
        ];

        return $out;
    }

    // ---- theme ------------------------------------------------------------------------------------------------

    /** The kit theme this request renders: the one asked for, else the store's own kit template, else its store type's. */
    private function themeSlug(ViewModelRequest $r): string
    {
        if ($r->theme !== null && KitThemes::isKit($r->theme)) {
            return $r->theme;
        }
        $template = StoreSettings::string((int) $r->store->id, 'store_template');

        return KitThemes::isKit($template)
            ? $template
            : KitThemes::forStoreType((string) ($r->store->store_type ?: $this->verticals->default()));
    }

    /** @return array<string, mixed> */
    private function theme(Store $store, ViewModelRequest $r, string $type): array
    {
        $template = StoreSettings::string((int) $store->id, 'store_template');
        $slug = $this->themeSlug($r);

        $schema = ThemeCustomizerService::schemaFor($slug);
        $values = ThemeCustomizerService::defaults($schema);
        // P3-12: a store saved on an old theme slug aliased to this kit theme gets the alias preset, with its own saved values on top
        $aliased = ThemeAliases::target($template) !== null && ThemeAliases::effective($template) === $slug
            ? ThemeAliases::values((int) $store->id, $template) : null;
        if ($aliased !== null) {
            $values = $aliased;
        } else {
            $row = ThemeSetting::query()->where('store_id', $store->id)->where('template', ThemeCustomizerService::settingsKey($slug))->first();
            if ($row !== null) {
                $values = array_merge($values, (array) $row->settings);
            }
        }

        $settings = [];
        $texts = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, 'sections.')) {
                continue;
            }
            if (str_starts_with($key, 'texts.')) {
                $text = is_array($value) ? ($value[$r->locale] ?? null) : $value;
                if (is_string($text) && trim($text) !== '') {
                    $texts[substr($key, 6)] = $text;
                }

                continue;
            }
            if (is_scalar($value) || $value === null) {
                $settings[$key] = $value;
            }
        }

        $sections = [];
        $known = [];
        foreach ($schema['sections'] ?? [] as $section) {
            $known[(string) $section['id']] = $section;
        }
        $order = is_array($values['sections._order'] ?? null) ? array_values(array_intersect($values['sections._order'], array_keys($known))) : [];
        foreach (array_keys($known) as $sid) {
            if (! in_array($sid, $order, true)) {
                $order[] = $sid;
            }
        }
        foreach ($order as $sid) {
            $section = $known[$sid];
            $on = empty($section['toggleable']) ? true : (bool) ($values['sections.'.$sid] ?? $section['default'] ?? true);
            if ($on) {
                $sections[] = $sid;
            }
        }

        return ['slug' => $slug, 'settings' => $settings, 'sections' => $sections, 'texts' => $texts];
    }

    // ---- features / endpoints / money / i18n ------------------------------------------------------------------

    /**
     * @param  list<string>  $featured  public ids of the featured items
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function features(Store $store, array $featured, array $config): array
    {
        $id = (int) $store->id;
        $out = [];
        if (StoreSettings::bool($id, 'store_announcement_active')) {
            $text = trim(StoreSettings::string($id, 'store_announcement'));
            if ($text !== '') {
                $out['announcement'] = ['text' => $text];
            }
        }
        if ($featured !== []) {
            $out['featured'] = $featured;
        }
        $reservations = StoreSettings::bool($id, 'enable_reservations') && FeatureAccess::storeAllows($id, 'reservations');
        $waitlist = StoreSettings::bool($id, 'enable_waitlist');
        if ($reservations || $waitlist) {
            $out['reservations'] = ['table' => $reservations, 'waitlist' => $waitlist];
        }
        if (StoreSettings::bool($id, 'gift_cards_enabled') && FeatureAccess::storeAllows($id, 'gift_cards')) {
            $out['gift_cards'] = true;
        }
        if (! empty($config['fitment'])) {
            $out['fitment'] = true;
        }
        if ($this->sellsGoods($store)) {
            $out['wishlist'] = true; // P3-08 (aisle §12)
        }

        return $out;
    }

    /** @return array<string, string> named relative URLs of routes that exist; themes never build URLs */
    private function endpoints(Store $store, ViewModelRequest $r): array
    {
        $slug = (string) $store->slug;
        $home = route('publicView', ['slug' => $slug], false);
        $out = [
            'home' => $home,
            // the cart and the order-tracking entry are sheets of the store page (P3-03 core JS opens them)
            'cart' => $home.'#cart',
            'quote' => route('store.quote', ['store' => $slug], false),
            'track' => $home.'#track',
            // These four routes bind `{store}` as a Store by its numeric id (a slug is "not found", Store::resolveRouteBinding),
            // exactly as every storefront form has always posted to them; the page and quote routes take the slug.
            'place' => route('store.submitOrder', ['store' => $store->getRouteKey()], false),
            'coupon' => route('store.applyCoupon', ['store' => $store->getRouteKey()], false),
            'slots' => route('store.scheduleSlots', ['store' => $store->getRouteKey()], false),
            'order_lookup' => route('store.orderLookup', ['store' => $store->getRouteKey()], false),
        ];
        if (StoreSettings::bool((int) $store->id, 'enable_reservations')) {
            $out['reservation'] = route('publicView.reservation.book', ['slug' => $slug], false);
        }
        if ($r->kiosk) {
            $out['kiosk_reset'] = route('publicView.kiosk.reset', ['slug' => $slug], false);
            $out['kiosk_start'] = route('publicView.kiosk', ['slug' => $slug], false);
            $out['kiosk_exit'] = route('publicView.kiosk.exit', ['slug' => $slug], false);
            $out['kiosk_setup'] = route('publicView.kiosk.setup', ['slug' => $slug], false);
            if (! empty(store_options((int) $store->id, 'kiosk_loyalty'))) {
                foreach (['code', 'verify', 'pass', 'me', 'reorder', 'forget'] as $k) {
                    $out['kiosk_loyalty_'.$k] = route('publicView.kiosk.loyalty.'.$k, ['slug' => $slug], false);
                }
            }
        }
        if ($r->order !== null && (int) $r->order->store_id === (int) $store->id && ! empty($r->order->track_token)) {
            $out['track'] = route('store.orderTrack', ['token' => $r->order->track_token], false);
            if ($this->sellsGoods($store) && StoreSettings::int((int) $store->id, 'returns_window_days') > 0) {
                $out['returns'] = route('storefront.returns.store', ['token' => $r->order->track_token], false);
            }
            if ($this->groceryWeb($store, $r)) {
                $out['replacement'] = route('store.orderReplacement', ['token' => $r->order->track_token], false); // basket §10: keep or refuse a proposed replacement
            }
        }
        if ($this->sellsGoods($store)) {
            // P3-08: the retail endpoints of the storefront (session + guest wishlist cookie; CSRF like every form)
            $out['wishlist'] = route('storefront.wishlist.index', ['slug' => $slug], false);
            $out['stock_alerts'] = route('storefront.stockAlerts.store', ['slug' => $slug], false);
            if ($this->groceryWeb($store, $r)) {
                // P3-09 (basket): the grocery checkout of the storefront's own pages — the goods quote / order with delivery or
                // collection slots, express, the out-of-stock choice and the weighed card hold (StorefrontRetailCheckoutController),
                // the hourly slots and their 10-minute holds (StorefrontGrocerySlotsController)
                $out['quote'] = route('storefront.retail.quote', ['slug' => $slug], false);
                $out['place'] = route('storefront.retail.place', ['slug' => $slug], false);
                $out['slots'] = route('storefront.grocery.slots', ['slug' => $slug], false);
                $out['hold'] = route('storefront.grocery.hold', ['slug' => $slug], false);
            } elseif (in_array('ship', $this->ordering->orderTypes($store), true)) {
                // the goods checkout of the storefront's own pages: quote with the shipping method + address, the methods
                // for an address (dates, fees), the order with an optional hosted payment (StorefrontRetailCheckoutController)
                $out['quote'] = route('storefront.retail.quote', ['slug' => $slug], false);
                $out['shipping_quote'] = route('storefront.retail.shippingQuote', ['slug' => $slug], false);
                $out['place'] = route('storefront.retail.place', ['slug' => $slug], false);
            } else {
                $out['shipping_quote'] = route('storefront.shipping.quote', ['slug' => $slug], false);
            }
        }

        return $out;
    }

    /** @return array{tax?: array{mode: 'exclusive', rate: float, label: string}} tax is added on top (Order::computeTax) */
    private function tax(Store $store): array
    {
        $id = (int) $store->id;
        $rate = StoreSettings::get($id, 'restaurant_taxx_charge');
        $type = (string) (StoreSettings::get($id, 'restaurant_tax_type') ?: 'percentage');
        if ($type === 'fixed' || ! is_numeric($rate) || (float) $rate <= 0) {
            return [];
        }
        $label = trim(StoreSettings::string($id, 'restaurant_tax_label'));

        return ['tax' => ['mode' => 'exclusive', 'rate' => (float) $rate, 'label' => $label !== '' ? $label : ___('Tax')]];
    }

    /** @return array<string, string> */
    private function i18n(): array
    {
        $out = [];
        foreach (self::I18N as $text) {
            $out[Str::slug($text, '_')] = (string) ___($text);
        }

        return $out;
    }

    // ---- pages ------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function page(ViewModelRequest $r, CatalogPresenter $catalog, OrderPresenter $orders, MoneyFormat $money, ?string $orderType, ?Branch $branch, string $timezone, array $context): ?array
    {
        $store = $r->store;

        switch ($r->page) {
            case 'home':
                return null;

            case 'item':
                $item = $r->item !== null ? $this->catalog->item($store, $r->item, $branch, $orderType) : null;

                return $item === null ? ['kind' => 'states'] : ['kind' => 'item', 'item' => $catalog->detail($item)];

            case 'cart':
                $quote = $this->quote($r, $orderType, $branch);
                if ($quote === null) {
                    return ['kind' => 'states'];
                }
                $cart = $orders->quote($quote, ___('Total'));
                $cart['cta_label'] = ___('Go to checkout · :amount', ['amount' => $money->format($quote->total)]);

                return ['kind' => 'cart', 'cart' => $cart];

            case 'checkout':
            case 'time':
                $quote = $this->quote($r, $orderType, $branch);
                if ($quote === null) {
                    return ['kind' => 'states'];
                }

                return ['kind' => 'checkout', 'checkout' => $this->checkout($r, $quote, $catalog, $orders, $money, $orderType, $branch, $timezone)];

            case 'track':
                $order = $r->order;
                $quote = $order !== null ? $this->ordering->orderQuote($store, $order) : null;

                if ($order === null || $quote === null) {
                    return ['kind' => 'states'];
                }
                $out = $orders->order($order, $quote);
                $review = $this->groceryWeb($store, $r) ? $orders->review($quote) : [];
                if ($review !== []) {
                    $out['review'] = $review; // basket §10: replacements the picker proposed
                }
                // brew's pickup ticket: the kitchen's own prep time while the order is not ready, and where to collect it
                $prep = $context['prep_time_min'] ?? null;
                if ($prep !== null && in_array((string) $order->status, ['', 'unpaid', 'pending', 'new', 'preparing'], true) && in_array((string) $order->type, ['pickup', 'takeaway', 'dine_in'], true)) {
                    $out['eta_label'] = ___('Ready in about :minutes min', ['minutes' => $prep]);
                }
                if (in_array((string) $order->type, ['pickup', 'takeaway'], true)) {
                    // the branch the order was placed at; an order without one is collected at the store itself
                    $where = $order->branch_id ? Branch::query()->where('store_id', $store->id)->whereKey($order->branch_id)->first() : null;
                    $name = (string) ($where->name ?? $store->title);
                    $address = trim((string) ($where->address ?? $store->address));
                    $out['location'] = ['icon' => 'map-pin', 'label' => $name]
                        + ($address !== '' ? ['sub' => $address, 'url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address)] : []);
                }

                return ['kind' => 'track', 'order' => $out];

            case 'reschedule':
                $quote = $r->order !== null ? $this->ordering->orderQuote($store, $r->order) : null;
                if ($quote === null) {
                    return ['kind' => 'states'];
                }

                return ['kind' => 'checkout', 'checkout' => $this->reschedule($r, $quote, $orders, $branch, $timezone)];
        }

        return null;
    }

    private function quote(ViewModelRequest $r, ?string $orderType, ?Branch $branch): ?StorefrontQuote
    {
        if ($r->cart === []) {
            return null;
        }

        return $this->ordering->quote($r->store, $r->cart, $orderType, $branch, $r->coupon, $r->tipMinor, $r->shipping);
    }

    /** @return array<string, mixed> Checkout */
    private function checkout(ViewModelRequest $r, ?StorefrontQuote $quote, CatalogPresenter $catalog, OrderPresenter $orders, MoneyFormat $money, ?string $orderType, ?Branch $branch, string $timezone): array
    {
        $store = $r->store;
        $id = (int) $store->id;
        $fulfillment = (array) config('verticals.fulfillment.'.$orderType, []);
        $out = [];

        if ($r->page === 'time' && $quote !== null) {
            $out['step_label'] = ___('Step :current of :total', ['current' => '2', 'total' => '3']);
            $out['summary_rows'] = array_map(function ($item) {
                $menu = $item->menu;
                $attributes = json_decode((string) json_encode($menu?->getAttribute('attributes')), true);
                $row = ['icon' => 'calendar', 'label' => (string) ($menu->name ?? '')];
                if (is_array($attributes) && isset($attributes['duration_min']) && is_numeric($attributes['duration_min'])) {
                    $row['sub'] = ___(':minutes min', ['minutes' => (string) (int) $attributes['duration_min']]);
                }

                return $row;
            }, $quote->order->items->values()->all());
        }

        $grocery = $this->groceryWeb($store, $r);
        $book = app(DeliverySlotBook::class);
        $slotBranch = StorefrontApiContext::slotBranch($store, $branch);
        if ($grocery && $orderType !== null && ! $r->kiosk && $book->offers($store, $slotBranch, $orderType)) {
            // P3-09 (basket §9): the store's hourly delivery / collection slots with live capacity and the express option
            // (the same picker the headless GET /delivery/slots answers); a kiosk collects now and picks no time
            $out['slots'] = app(StorefrontSlotView::class)
                ->build($store, $slotBranch, $orderType, $book->days($store, $slotBranch, $orderType, null, 3), $book->express($store, $slotBranch, $orderType))['picker'];
        } elseif ($grocery && $r->kiosk) {
            // nothing: a kiosk order is collected now
        } elseif ($orderType !== null && ! empty($fulfillment['needs_slot'])) {
            $slots = $this->slotPicker($store, $branch, $orderType, $r->date, null, $timezone);
            if ($slots !== null) {
                $out['slots'] = $slots;
            }
        } elseif ($orderType !== null && $this->ordering->schedulingEnabled($store, $orderType)) {
            $out['delivery_methods'] = [
                ['id' => 'asap', 'label' => ___('As soon as possible'), 'selected' => true],
                ['id' => 'schedule', 'label' => ___('Schedule')],
            ];
        }

        $payments = [];
        $online = config('settings.admin_allow_online_payment') && StoreSettings::bool($id, 'restaurant_online_payment');
        if ($online && $orderType === 'ship') {
            // P3-08 (F3): a goods order is paid online on the core pay page of one of the store's own gateways — offered only
            // when one can take this total (the same test the headless API's `payment_modes` makes)
            $online = app(HostedPayments::class)->storeCanTake($store, $quote !== null ? Money::majorFromMinor($quote->total, $quote->currency) : 1.0);
        }
        if ($online) {
            $payments[] = ['id' => 'online', 'label' => ___('Pay online'), 'sub' => ___('Pay now, securely'), 'icon' => 'credit-card'];
        }
        $payments[] = in_array($orderType, ['delivery', 'ship'], true)
            ? ['id' => 'cash', 'label' => ___('Cash on delivery'), 'icon' => 'banknote']
            : ['id' => 'cash', 'label' => ___('Pay at the counter'), 'icon' => 'banknote'];
        $payments[0]['selected'] = true;
        $out['payment_methods'] = $payments;

        // a kiosk shows no tip (THEME-CONTRACT 2.5, sections/kiosk.md: hidden unless the owner turns it on for the kiosk — no such setting yet)
        if (! $r->kiosk && StoreSettings::bool($id, 'allow_tips') && in_array($orderType, ['delivery', 'takeaway', 'on-table'], true)) {
            $options = [['id' => 'tip_0', 'label' => ___('No tip')] + ($r->tipMinor === 0 ? ['selected' => true] : [])];
            foreach (array_filter(array_map('trim', explode(',', StoreSettings::string($id, 'tip_presets', '10,15,20')))) as $percent) {
                if (! is_numeric($percent) || (float) $percent <= 0) {
                    continue;
                }
                $option = ['id' => 'tip_'.(int) $percent, 'label' => rtrim(rtrim((string) $percent, '0'), '.').'%'];
                if ($quote !== null) {
                    $amount = Money::ofMinor($quote->subtotal, $quote->currency)->percent((float) $percent)->minor();
                    $option['amount'] = $money->money($amount);
                    if ($r->tipMinor > 0 && $r->tipMinor === $amount) {
                        $option['selected'] = true;
                    }
                }
                $options[] = $option;
            }
            $out['tip'] = ['options' => $options];
        }

        $fields = [
            ['id' => 'name', 'label' => ___('Name'), 'value' => (string) ($this->customer->name ?? ''), 'type' => 'text', 'required' => true],
            ['id' => 'mobile', 'label' => ___('Mobile (for order updates)'), 'value' => (string) ($this->customer->phone ?? ''), 'type' => 'tel', 'dir' => 'ltr', 'required' => true],
        ];
        if ($grocery && $orderType === 'delivery') {
            // P3-09 (basket): the address of a delivery — the goods checkout matches the store's delivery radius on it
            $fields[] = ['id' => 'country', 'label' => ___('Country code'), 'value' => strtoupper(StoreSettings::string($id, 'fiscal_country')), 'type' => 'hidden', 'dir' => 'ltr', 'required' => true];
            $fields[] = ['id' => 'address', 'label' => ___('Delivery address'), 'type' => 'text', 'required' => true];
            $fields[] = ['id' => 'city', 'label' => ___('City'), 'type' => 'text', 'required' => true];
            $fields[] = ['id' => 'notes', 'label' => ___('Note for the driver'), 'type' => 'text', 'help' => ___('Gate code, floor, where to leave the bags')];
        } elseif ($orderType === 'ship') {
            // P3-08 (aisle): a shipped order needs the structured address the shipping zone is matched on (country, region,
            // city, postal code); picking up in store drops them in the theme (the shipping method says so).
            $fields[] = ['id' => 'email', 'label' => ___('Email (optional)'), 'value' => (string) ($this->customer->email ?? ''), 'type' => 'email', 'dir' => 'ltr'];
            $fields[] = ['id' => 'country', 'label' => ___('Country code'), 'value' => strtoupper(StoreSettings::string($id, 'fiscal_country')), 'type' => 'text', 'dir' => 'ltr', 'required' => true, 'help' => ___('Two letters, e.g. SA')];
            $fields[] = ['id' => 'address', 'label' => ___('Street address'), 'type' => 'text', 'required' => true];
            $fields[] = ['id' => 'city', 'label' => ___('City'), 'type' => 'text', 'required' => true];
            $fields[] = ['id' => 'region', 'label' => ___('Region'), 'type' => 'text'];
            $fields[] = ['id' => 'postal_code', 'label' => ___('Postal code'), 'type' => 'text', 'dir' => 'ltr'];
        } elseif (! empty($fulfillment['needs_address'])) {
            $fields[] = ['id' => 'address', 'label' => ___('Delivery address'), 'type' => 'text', 'required' => true];
        }
        if ($r->kiosk) {
            // a shared device keeps no personal data: only an optional name ("We'll call this name"), no phone, no address
            $fields = [['id' => 'name', 'label' => ___('Name for your order (optional)'), 'type' => 'text', 'help' => ___("We'll call this name")]];
        }
        $out['fields'] = array_map(fn (array $f) => array_filter($f, fn ($v) => $v !== ''), $fields);
        if (! $r->kiosk && $orderType === 'delivery') {
            $center = app(DeliverySlotBook::class)->radiusCenter($store, $branch);
            if ($center !== null) {
                // the delivery radius is checked on the order's pin (server side, StoreController::submitOrder): ask for one
                $out['pin'] = ['required' => true, 'center' => $center];
            }
        }

        if ($grocery && ! $r->kiosk) {
            // P3-09 (API-22): the store's "If it's out of stock" choice for the whole order (an item may carry its own)
            $groceryCatalog = app(GroceryCatalog::class);
            $group = $groceryCatalog->substitutionGroup($store);
            if ($group !== null) {
                $out['substitution'] = GroceryPresenter::substitutionGroup($group, $groceryCatalog->defaultSubstitution($store));
            }
        }

        if ($quote !== null) {
            $out['quote'] = $orders->quote($quote, ___('Total to pay'));
            $out['place_label'] = ___('Place order · :amount', ['amount' => $money->format($quote->total)]);
        }

        return $out;
    }

    /** @return array<string, mixed> Checkout payload of the reschedule page */
    private function reschedule(ViewModelRequest $r, StorefrontQuote $quote, OrderPresenter $orders, ?Branch $branch, string $timezone): array
    {
        $order = $r->order;
        $type = (string) $order?->type;
        $current = $order?->scheduled_at !== null ? Carbon::parse($order->scheduled_at)->setTimezone($timezone) : null;

        $rows = [];
        if ($current !== null) {
            $rows[] = ['icon' => 'calendar', 'label' => $current->copy()->locale(App::getLocale())->translatedFormat('D j M · g:i A'), 'sub' => ___('Times are shown in :timezone', ['timezone' => $timezone])];
        }
        foreach ($quote->order->items as $item) {
            $rows[] = ['icon' => 'calendar', 'label' => (string) ($item->menu->name ?? '')];
        }

        $out = ['summary_rows' => $rows];
        $slots = $this->slotPicker($r->store, $branch, $type, $r->date ?? $current?->format('Y-m-d'), $current, $timezone);
        if ($slots !== null) {
            $out['slots'] = $slots;
        }
        $out['payment_methods'] = [];
        $out['quote'] = $orders->quote($quote, ___('Total'));
        $out['place_label'] = ___('Change time');

        return $out;
    }

    /**
     * Days (next week of the booking horizon) and the chosen day's free times grouped into morning / afternoon /
     * evening, in the store's time. Slot ids are the values sent back (Y-m-d, Y-m-d\TH:i), never row ids.
     *
     * @return array<string, mixed>|null
     */
    private function slotPicker(Store $store, ?Branch $branch, string $orderType, ?string $date, ?Carbon $selected, string $timezone): ?array
    {
        if (! $this->ordering->schedulingEnabled($store, $orderType)) {
            return null;
        }
        $today = Carbon::now($timezone)->startOfDay();
        $days = [];
        $perDay = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $today->copy()->addDays($i)->format('Y-m-d');
            $perDay[$day] = $this->ordering->slots($store, $branch, $orderType, $day);
        }
        if ($date === null || ! array_key_exists($date, $perDay)) {
            $date = null;
            foreach ($perDay as $day => $slots) {
                if (array_filter($slots, fn (array $s) => ! $s['full']) !== []) {
                    $date = $day;
                    break;
                }
            }
            $date ??= array_key_first($perDay);
        }

        $locale = App::getLocale();
        foreach ($perDay as $day => $slots) {
            $free = count(array_filter($slots, fn (array $s) => ! $s['full']));
            $state = match (true) {
                $day === $date => 'selected',
                $slots === [] => 'closed',
                $free === 0 => 'full',
                $free <= 3 => 'few',
                default => 'available',
            };
            $at = Carbon::createFromFormat('Y-m-d', $day, $timezone);
            $days[] = ['id' => $day, 'label' => $at->locale($locale)->translatedFormat('D'), 'sub' => $at->format('j'), 'state' => $state];
        }

        $groups = [];
        foreach ($perDay[$date] ?? [] as $slot) {
            /** @var Carbon $at */
            $at = $slot['at']->copy()->setTimezone($timezone);
            $part = (int) $at->format('G') < 12 ? ___('Morning') : ((int) $at->format('G') < 17 ? ___('Afternoon') : ___('Evening'));
            $state = $slot['full'] ? 'full' : ($selected !== null && $selected->format('Y-m-d H:i') === $at->format('Y-m-d H:i') ? 'selected' : 'available');
            $groups[$part][] = ['id' => $at->format('Y-m-d\TH:i'), 'label' => $at->locale($locale)->translatedFormat('g:i A'), 'state' => $state];
        }

        $out = [
            'title' => Carbon::createFromFormat('Y-m-d', (string) $date, $timezone)->locale($locale)->translatedFormat('F Y'),
            'days' => $days,
            'groups' => array_map(fn (string $label, array $slots) => ['label' => $label, 'slots' => $slots], array_keys($groups), array_values($groups)),
            'timezone_note' => ___('Times are shown in :timezone', ['timezone' => $timezone]),
        ];
        if ($groups === []) {
            $out['note'] = ___('Fully booked');
        }

        return $out;
    }

    /** P3-09: a goods store drawn by the `basket` theme: its checkout is the grocery one (slots, holds, weighed items, substitutions). */
    private function groceryWeb(Store $store, ViewModelRequest $r): bool
    {
        return $this->sellsGoods($store) && $this->themeSlug($r) === 'basket';
    }

    /** P3-08: a store that sells goods (retail-form verticals): wishlist, back-in-stock, shipping, returns. */
    private function sellsGoods(Store $store): bool
    {
        return app(VerticalRegistry::class)->itemForm($store->store_type ?? null) === 'retail';
    }
}
