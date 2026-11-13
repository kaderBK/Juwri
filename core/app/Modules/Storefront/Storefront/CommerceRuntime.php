<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use App\Models\Customer;
use App\Services\CustomerWalletService;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Support\Collection;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyComplianceService;

/**
 * P2-8: builds the server-side config the platform commerce runtime
 * (public/assets/commerce-runtime/<v>/commerce.js) reads as `window.MB_COMMERCE`,
 * plus the render registers the `{% commerce %}` tag emits. This is the ONLY
 * bridge between server state (routes, csrf, currency, menu) and the cart JS,
 * so a sandboxed theme needs zero JavaScript of its own.
 *
 * The config + POST field contract mirror the proven first-party themes
 * (crave/aurora) so the existing, untouched `restaurant.submitOrder` controller
 * keeps working.
 */
class CommerceRuntime
{
    /**
     * Theme Studio B0.10: runtime bundles a theme may pin via theme.json
     * `"commerce_runtime"`. v1 = the original bundle (crave/bazaar); v2 = v1
     * plus the vertical commerce layer (variant matrix, stock, booking slots,
     * age gate, fitment). Each lives at assets/commerce-runtime/<v>/commerce.js.
     */
    public const VERSIONS = ['v1', 'v2'];

    /** Vertical capabilities (config/verticals.php) the v2 runtime reads. */
    private const CAPABILITIES = ['variant_goods', 'expiry', 'age_gate', 'fitment', 'service'];

    /**
     * @param  array<string,mixed>  $v  render-site variables (same shape as ThemeContext::build)
     * @return array<string,mixed>
     */
    public function config(array $v): array
    {
        $store = $v['store'];
        $o = $v['postOptions'] ?? (object) [];
        $flags = $v['flags'] ?? [];
        $customer = $v['customer'] ?? null;
        $table = $v['table'] ?? null;

        // Coupons & Wallets P7-5b: the logged-in diner's own store-credit balance
        // (account-bound, D3). Drives the pay-with-credit affordance; the JS posts
        // use_wallet=1 to the (already-wired) submitOrder endpoint, which the shared
        // concern honours server-side. Guests / zero balance get nothing.
        // Decimals follow the STORE's currency — the same `$o` bag that supplies
        // `currency_sign` below carries `currency_code`, so the balance keeps its
        // fils on a 3-decimal currency instead of rounding to 2.
        $currencyDecimals = currency_decimals($o->currency_code ?? null);
        // Bug batch 9 · 2/6: money (the balance offered, the previewed tax / tip) at the stored precision — the store
        // currency's exponent, what the order records; $currencyDecimals is for display only.
        $moneyDecimals = money_decimals($o->currency_code ?? null);

        $storeCreditBalance = ($customer instanceof Customer)
            ? round(CustomerWalletService::balanceFor($customer), $moneyDecimals)
            : 0.0;

        $capabilities = $this->capabilities($store);

        return [
            'storeId' => (int) $store->id,
            'restaurantSlug' => $store->slug,
            'currency' => [
                'sign' => trim((string) ($o->currency_sign ?? '')) !== '' ? $o->currency_sign : self::fallbackSign((string) ($o->currency_code ?? 'USD')),
                'pos' => (string) ($o->currency_pos ?? '1'),
                'code' => store_currency((int) $store->id),
                'rates' => DisplayRates::fromOptions($o, store_currency((int) $store->id))->rates, // MC-31: unified parser
                'decimals' => $currencyDecimals,    // bug batch 9 · 6: display precision of the store currency
                'moneyDecimals' => $moneyDecimals,  // bug batch 9 · 6: tax / tip preview precision (= the order's)
            ],
            'charges' => [
                'tax' => (float) ($o->restaurant_taxx_charge ?? 0),
                'taxType' => (string) ($o->restaurant_tax_type ?? 'percentage'), // bug batch 9 · 6: as Order::computeTax
                'taxLabel' => trim((string) ($o->restaurant_tax_label ?? '')) ?: 'Tax',
                'delivery' => (float) ($o->restaurant_delivery_charge ?? 0),
                'minOrder' => (float) ($o->min_order_value ?? 0),
            ],
            'orderTypes' => [
                'onTable' => (bool) ($flags['on_table'] ?? false),
                'takeaway' => (bool) ($flags['takeaway'] ?? false),
                'delivery' => (bool) ($flags['delivery'] ?? false),
            ],
            'flags' => [
                'allowOrder' => (bool) ($flags['allow_order'] ?? false),
                'allowPayment' => (bool) ($flags['payment'] ?? false),
                'allowCallWaiter' => (bool) (($o->allow_call_waiter ?? false) && ($flags['on_table'] ?? false)),
                'allowCoupons' => ((int) ($o->allow_coupons ?? 0)) === 1,
                // P7-5b: pay-with-credit offered only to a logged-in diner with a
                // positive balance. submitOrder honours use_wallet regardless; this
                // flag just gates the checkbox in the runtime UI.
                'allowStoreCredit' => $storeCreditBalance > 0,
                'allowTips' => ((int) ($o->allow_tips ?? 0)) === 1,
                'allowScheduling' => (bool) ($flags['scheduling'] ?? false),
                'forceTable' => (bool) $table,
                'defaultTable' => $table->table_no ?? null,
                'authMode' => $o->customer_auth_mode ?? 'guest',
                'isLoggedIn' => (bool) $customer,
                'customerName' => $customer->name ?? null,
                // The logged-in diner's own phone — used to auto-load their
                // order history in the account panel (shown only to them).
                'customerPhone' => $customer->phone ?? null,
            ],
            // P7-5b: store-credit balance for the runtime bridge. The submitOrder
            // POST contract accepts use_wallet=1 (honoured by the shared concern);
            // the JS shows the balance + checkbox when flags.allowStoreCredit.
            'storeCredit' => [
                'balance' => $storeCreditBalance,
                'formatted' => ($o->currency_sign ?? '$').number_format($storeCreditBalance, $currencyDecimals),
            ],
            'csrf' => csrf_token(),
            'endpoints' => [
                'submitOrder' => route('store.submitOrder', $store->id),
                'coupon' => route('store.applyCoupon', $store->slug),
                'waiter' => route('store.callTheWaiter', $store->slug),
                'itemEvent' => route('store.recordItemEvent', $store->slug),
                // B6: customer account panel — existing untouched controllers.
                // The customer.* routes read store_id from the POST body;
                // orderLookup keys on the slug like coupon/waiter.
                'login' => route('customer.login', $store->id),
                'register' => route('customer.register', $store->id),
                'logout' => route('customer.logout', $store->id),
                'otpRequest' => route('customer.otp.request', $store->id),
                'otpVerify' => route('customer.otp.verify', $store->id),
                'orderLookup' => route('store.orderLookup', $store->slug),
                // Logged-in account: profile + addresses (order history rides
                // the account payload). Delete URLs come per-address in it.
                'account' => route('customer.account', $store->id),
                'profile' => route('customer.profile.update', $store->id),
                'addressStore' => route('customer.addresses.store', $store->id),
            ] + $this->v2Endpoints($store, $capabilities),
            'items' => $this->items($v),
            'i18n' => $this->i18n(),
            // Theme Studio B0.10 (additive — v1 ignores it): store vertical +
            // capabilities + the age-gate/OTC compliance the v2 runtime enforces.
            'store' => $this->store($store, $capabilities),
        ];
    }

    /**
     * B0.10: the store's vertical capability flags (same source as the Liquid
     * `features` global — VerticalRegistry over `posts.store_type`).
     *
     * @return array<string,bool>
     */
    protected function capabilities(object $store): array
    {
        $type = $store->store_type ?? null;
        $registry = app(VerticalRegistry::class);
        $out = [];
        foreach (self::CAPABILITIES as $cap) {
            $out[$cap] = $registry->has(is_string($type) ? $type : null, $cap);
        }

        return $out;
    }

    /**
     * B0.10: extra endpoints for the v2 runtime — all EXISTING public routes.
     * scheduleSlots = the T1 slot feed (same one the Blade schedule-slot picker
     * and bookla/carely call); fitment feeds only for fitment-capable stores.
     *
     * @param  array<string,bool>  $capabilities
     * @return array<string,string>
     */
    protected function v2Endpoints(object $store, array $capabilities): array
    {
        $out = ['scheduleSlots' => route('store.scheduleSlots', $store->id)];
        if ($capabilities['fitment'] && (string) ($store->slug ?? '') !== '') {
            $out['fitmentOptions'] = route('storefront.fitment.options', $store->slug);
            $out['fitmentOem'] = route('storefront.fitment.oemSearch', $store->slug);
        }

        return $out;
    }

    /**
     * B0.10: store-level data for the v2 runtime. The age gate mirrors carely:
     * the `age_gate` capability turns on the per-item confirm for
     * `age_restricted` items; the store-ENTRY interstitial additionally needs
     * the owner's PharmacyComplianceService toggle (default on).
     *
     * @param  array<string,bool>  $capabilities
     * @return array<string,mixed>
     */
    protected function store(object $store, array $capabilities): array
    {
        $ageGate = ['enabled' => $capabilities['age_gate'], 'entry' => false, 'disclaimer' => ''];
        if ($capabilities['age_gate'] && $store instanceof Store) {
            $compliance = app(PharmacyComplianceService::class);
            $ageGate['entry'] = $compliance->entryGateEnabled($store);
            $ageGate['disclaimer'] = $compliance->disclaimerText($store);
        }

        $type = $store->store_type ?? null;

        return [
            'type' => is_string($type) && $type !== '' ? $type : 'restaurant',
            'capabilities' => $capabilities,
            'ageGate' => $ageGate,
        ];
    }

    /**
     * The sign shown when a store never saved one: "$" for USD (the historical
     * runtime default), otherwise the ISO code — never an empty string.
     */
    public static function fallbackSign(string $code): string
    {
        $code = strtoupper(trim($code));

        return $code === '' || $code === 'USD' ? '$' : $code;
    }

    /**
     * Translated UI strings for the runtime-rendered surfaces (cart, product,
     * account, confirmed…), keyed by the English source so the JS falls back to
     * English if a key is missing. Uses the app's `___()` translator, so the
     * runtime UI speaks the same language the diner picked.
     *
     * @return array<string,string>
     */
    protected function i18n(): array
    {
        $strings = [
            'Add', 'Cart', 'Call waiter', 'Extras', 'Back', 'Sold out', 'You might also like',
            'Dine-in', 'Takeaway', 'Delivery', 'Your name', 'Table', 'Phone', 'Delivery address',
            'Coupon', 'Apply', 'Notes', 'Tip', 'None', 'Custom', 'Subtotal', 'Discount', 'Total',
            'Place order', 'Clear cart', 'Select an order type.', 'Please enter your name.',
            'Please enter a delivery address.', 'Please sign in to order.', 'Something went wrong.',
            'Network error. Please try again.', 'Order confirmed', 'Your order has been placed successfully.',
            'Track order', 'Back to menu', 'Tap the heart on any dish to save it here', 'Browse menu',
            'Account', 'Close', 'Signed in as', 'Log out', 'Log in', 'Register', 'SMS code', 'Email',
            'Password', 'Name', 'Phone (optional)', 'Confirm password', 'Create account', 'Code',
            'Send code', 'Verify & log in', 'Your orders', 'Phone or email', 'Find', 'No orders found.',
            'Enter your phone number.', 'Profile', 'Save profile', 'Saved addresses', 'Default', 'Remove',
            'Label (Home, Work)', 'Address', 'City', 'Add address', 'Could not save.',
            'Could not save address.', 'No orders yet.', 'Set as default', 'Minimum order is',
            'Load more', 'Payment not completed',
            // B0.10 (v2 runtime): stock, variant matrix, booking, age gate, fitment.
            'In stock', 'Out of stock', 'Only :count left', 'Unavailable', 'Choose an option',
            'Choose a time', 'Please choose a time.', 'As soon as possible', 'Today', 'Tomorrow',
            'No times available', 'Ready in :hours h', 'per kg',
            'Before you continue', 'Continue', 'Cancel', 'Age-restricted item',
            'This item is age-restricted. Please confirm you meet the minimum legal age to buy it.',
            'I confirm', 'Make', 'Model', 'Year', 'Part number', 'Search', 'Reset',
            ':count parts fit your vehicle', 'No parts match your vehicle.', 'Fits your vehicle',
            // Theme Studio follow-ups: data-mb-share copy-link fallback.
            'Link copied', 'Could not copy the link',
        ];

        $out = [];
        foreach ($strings as $s) {
            $out[$s] = ___($s);
        }

        return $out;
    }

    /**
     * Render registers consumed by the `{% commerce %}` tag. Not reachable as
     * theme variables (a theme cannot forge the config, csrf, or runtime URL).
     *
     * @param  array<string,mixed>  $v
     * @param  MarketplaceTheme|null  $theme  the rendering theme (picks its pinned runtime bundle)
     * @return array<string,mixed>
     */
    public function registers(array $v, ?MarketplaceTheme $theme = null): array
    {
        // B0.10: a theme may opt into another bundle via theme.json
        // `"commerce_runtime"`; without a theme this is exactly the old path.
        $version = $theme !== null
            ? $theme->commerceRuntimeVersion()
            : (string) config('platform.commerce.runtime_version', 'v1');
        $rel = 'assets/commerce-runtime/'.$version.'/commerce.js';
        // Cache-bust by file mtime so a runtime update is picked up immediately
        // (the bundle URL is otherwise stable and long-cached by browsers).
        $abs = public_path($rel);
        $bust = is_file($abs) ? '?v='.filemtime($abs) : '';

        return [
            'commerce_config' => $this->config($v),
            'commerce_runtime_url' => asset($rel).$bust,
            'csp_nonce' => $this->nonce(),
        ];
    }

    /**
     * Item map the runtime uses for the sheet + price math. Variants are
     * absolute prices; extras + option-group choices are additive (the crave
     * contract). No DB work — reads only relations already loaded on the item.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function items(array $v): array
    {
        $storeId = $v['store']->id ?? null;
        $out = [];

        foreach (($v['total_menus'] ?? []) as $m) {
            // Variants are ABSOLUTE-price combinations; `options` is a map
            // {variantOptionId: choiceIdx} — build the human label by joining
            // the chosen labels from the item's variant dimensions.
            $dimensions = $this->rel($m, 'variantOptions')->keyBy('id');
            $variants = [];
            $axes = []; // B0.10: dimension name => [value => true], encounter order
            foreach ($this->rel($m, 'variants') as $vr) {
                $parts = [];
                foreach ((array) ($vr->options ?? []) as $groupId => $idx) {
                    $group = $dimensions->get((int) $groupId);
                    $choices = $group ? (array) $group->options : [];
                    if (isset($choices[$idx])) {
                        $parts[] = $choices[$idx];
                    }
                }
                $dims = $this->variantDims($vr, $dimensions);
                foreach ($dims as $axis => $value) {
                    $axes[$axis][$value] = true;
                }
                $variants[] = [
                    'id' => (int) $vr->id,
                    'label' => $parts !== [] ? implode(' · ', $parts) : 'Option',
                    'price' => (float) $vr->price,
                    // B0.10 (additive): matrix cell + per-variant stock (null = untracked).
                    'dims' => (object) $dims,
                    'sku' => ($vr->sku ?? '') !== '' ? (string) $vr->sku : null,
                    'stock' => $vr->stock_qty !== null ? (int) $vr->stock_qty : null,
                ];
            }
            // B0.10: a readable label for matrix-shaped variants (Lane A2
            // `{"Size":"M"}`), whose v1 `label` falls back to "Option".
            foreach ($variants as $i => $row) {
                $dimValues = array_values((array) $row['dims']);
                $variants[$i]['displayLabel'] = ($row['label'] === 'Option' && $dimValues !== [])
                    ? implode(' · ', $dimValues)
                    : $row['label'];
            }

            $extras = [];
            foreach ($this->rel($m, 'extras') as $e) {
                $extras[] = ['id' => (int) $e->id, 'title' => (string) $e->title, 'price' => (float) $e->price];
            }

            // ADDITIVE groups = attached global (library) groups only — the
            // per-item variantOptions are the variant dimensions above.
            $groups = [];
            foreach ($this->rel($m, 'globalGroups') as $g) {
                $choices = [];
                $prices = (array) ($g->option_prices ?? []);
                $i = 0;
                foreach ((array) ($g->options ?? []) as $label) {
                    $choices[] = ['i' => $i, 'label' => (string) $label, 'price' => (float) ($prices[$i] ?? 0)];
                    $i++;
                }
                $groups[] = [
                    'id' => (int) $g->id,
                    'title' => (string) $g->title,
                    'required' => (bool) $g->required,
                    'min' => (int) ($g->min_select ?? 0),
                    'max' => (int) ($g->max_select ?? 0),
                    'options' => $choices,
                ];
            }

            // Gallery: main image first, then any extra gallery_images.
            $gallery = [];
            if (! empty($m->image)) {
                $gallery[] = media_url('storage/menu/', (string) $m->image, $storeId);
            }
            foreach ((array) ($m->gallery_images ?? []) as $g) {
                if (! empty($g)) {
                    $gallery[] = media_url('storage/menu/', (string) $g, $storeId);
                }
            }

            $out[(int) $m->id] = [
                'id' => (int) $m->id,
                'name' => (string) $m->name,
                'desc' => (string) ($m->description ?? ''),
                'price' => (float) $m->price,
                'img' => media_url('storage/menu/', (string) ($m->image ?? ''), $storeId),
                'gallery' => $gallery,
                'rating' => round((float) ($m->rating_avg ?? 0), 2),
                'popular' => (bool) $m->is_popular,
                'isSoldOut' => (bool) $m->is_sold_out,
                'variants' => $variants,
                'extras' => $extras,
                'groups' => $groups,
                // B0.10 (additive — v1 ignores these): vertical item data.
                'dimensions' => $this->dimensionList($axes, count($variants)),
                'stock' => $this->stockOf($m),
                'lowStock' => (int) ($m->low_stock_threshold ?? 5) ?: 5,
                'sku' => $this->skuOf($m),
                'ageRestricted' => (bool) ($m->age_restricted ?? false),
                'pricingUnit' => ($m->pricing_unit ?? 'each') === 'kg' ? 'kg' : 'each',
                'turnaroundHours' => $m->turnaround_hours !== null ? (int) $m->turnaround_hours : null,
            ];
        }

        return $out;
    }

    /**
     * B0.10: one variant's matrix cell as {dimension => value}. Handles both
     * stored shapes (same rule as zevoa/shelf): Lane A2 matrix rows keep
     * `{"Size":"M","Color":"Red"}`; legacy rows keep `{variantOptionId: idx}`
     * resolved through the item's variant dimensions.
     *
     * @param  Collection<int,mixed>  $dimensions  variantOptions keyed by id
     * @return array<string,string>
     */
    protected function variantDims(MenuVariant $variant, Collection $dimensions): array
    {
        $dims = [];
        foreach ((array) ($variant->options ?? []) as $key => $val) {
            if (is_string($key) && ! ctype_digit($key)) {
                if (is_scalar($val) && (string) $val !== '') {
                    $dims[$key] = (string) $val;
                }

                continue;
            }
            $group = $dimensions->get((int) $key);
            $choices = $group ? (array) $group->options : [];
            if (is_scalar($val) && isset($choices[$val]) && is_scalar($choices[$val])) {
                $title = trim((string) ($group->title ?? ''));
                $dims[$title !== '' ? $title : 'Option '.((int) $key)] = (string) $choices[$val];
            }
        }

        return $dims;
    }

    /**
     * B0.10: the item's variant dimensions ([{name, values[]}]) in encounter
     * order. The runtime builds a matrix only when every variant carries a
     * value for every dimension; otherwise it keeps the flat v1 variant list.
     *
     * @param  array<string,array<string,bool>>  $axes
     * @return array<int,array{name:string,values:array<int,string>}>
     */
    protected function dimensionList(array $axes, int $variantCount): array
    {
        if ($variantCount === 0 || $axes === []) {
            return [];
        }
        $out = [];
        foreach ($axes as $name => $values) {
            $out[] = ['name' => (string) $name, 'values' => array_map('strval', array_keys($values))];
        }

        return $out;
    }

    /** B0.10: tracked stock units, or null when the item doesn't track inventory (ItemDrop rule). */
    protected function stockOf(Menu $m): ?int
    {
        return ($m->track_inventory ?? false) && $m->stock_qty !== null ? (int) $m->stock_qty : null;
    }

    protected function skuOf(Menu $m): ?string
    {
        $sku = ($m->plu ?? null) ?: ($m->external_sku ?? null);

        return $sku !== null && $sku !== '' ? (string) $sku : null;
    }

    protected function rel($model, string $name): Collection
    {
        return $model->relationLoaded($name)
            ? collect($model->getRelation($name))
            : collect();
    }

    protected function nonce(): ?string
    {
        try {
            return function_exists('csp_nonce') ? csp_nonce() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
