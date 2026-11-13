<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Storefront;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreTable;
use App\Services\Verticals\VerticalRegistry;
use App\Support\KioskContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\ViewErrorBag;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Services\TableKeyGuard;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Contracts\StorefrontBookings;
use Juwri\Modules\Storefront\Services\Theme\KitThemes;
use Juwri\Modules\Storefront\Services\Theme\KitView;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelBuilder;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelRequest;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;

/**
 * P3-05: everything a kit theme's views are handed. A kit theme reads only the view model (THEME-CONTRACT section 1);
 * this class is the controller-side seam that builds it from the HTTP request (language, scanned table, kiosk, branch,
 * signed-in customer, the item or order the page is about) and prepares the head data (title, canonical, hreflang,
 * share image) — so the Blade never reads a model, a setting or `request()`.
 *
 *   home()      the store page: view model, checkout form, head; `?item=<public id>` opens that item's sheet
 *   item()      only the item sheet's data (the fragment the theme JS opens in place)
 *   track()     the order tracking page for one order
 */
final class KitStorefront
{
    /** the products per page of a paged catalogue (the engine's default `per_page`) */
    private const PAGE = 48;

    public function __construct(private readonly ViewModelBuilder $builder, private readonly RetailListing $listing) {}

    /** The kit theme the store's storefront renders with (a marketplace theme or a legacy template: null). */
    public function themeFor(Store $store): ?string
    {
        $raw = StoreSettings::string((int) $store->id, 'store_template');
        $theme = resolve_store_theme($raw, $store->store_type ?? null);

        return KitThemes::isKit($theme) && MarketplaceThemeResolver::resolve($raw) === null ? (string) $theme : null;
    }

    public function isKit(?string $template): bool
    {
        return KitThemes::isKit($template);
    }

    /** @return array<string, mixed> view data of `store_templates.<theme>.index` */
    public function home(Store $store, Request $http, string $theme): array
    {
        $item = $http->query('item');
        $item = is_string($item) && preg_match('/^[0-9a-f-]{36}$/i', $item) === 1 ? strtolower($item) : null;

        $request = $this->request($store, $http, $item !== null ? 'item' : 'home', $theme, item: $item);
        $vm = $this->builder->build($request);
        $detail = ($vm['page']['kind'] ?? null) === 'item' ? $vm['page']['item'] : null;

        // P3-08 (aisle): the catalogue is listed by the server engine (search, filters, sort, cursor); a product is a page
        $retail = [];
        if ($theme === 'aisle') {
            $retail = $this->aisle($store, $http, $request, $vm, $detail);
        } elseif ($theme === 'basket' && $detail === null && empty($vm['context']['kiosk']['on'])) {
            $retail = $this->basket($store, $http, $request, $vm);
        }

        // P4-16: a very large food catalogue draws its first page; the rest loads in place (null = draw everything)
        $paging = null;
        if ($detail === null && in_array($theme, FoodCatalogPaging::THEMES, true) && empty($vm['context']['kiosk']['on'])) {
            $expand = $http->query('category');
            $paging = FoodCatalogPaging::plan((array) $vm['catalog'], is_string($expand) ? $expand : null);
            if ($paging !== null) {
                $paging['query'] = array_diff_key($http->query(), array_flip(['category', 'fragment', 'offset']));
            }
        }

        return $retail + [
            'paging' => $paging,
            'vm' => $vm,
            'kit' => KitView::forVm($vm),
            'theme' => $theme,
            'store' => $store,
            'head' => $this->head($store, $vm, $detail),
            'assetVersion' => $this->assetVersion(),
            'checkout' => $this->builder->checkoutForm($request),
            'item' => $detail,
            'open' => $detail !== null ? 'item' : null,
            'tableKey' => $this->tableKey($store, $http),
            'bookingMode' => $this->bookingMode($store, $theme),
            'laundryMode' => $this->laundryMode($store, $theme),
            'messages' => $this->bookingMode($store, $theme) || $this->laundryMode($store, $theme) ? $this->messages() : [],
        ];
    }

    /**
     * P3-08: what the `aisle` pages add to the view data — the listing (the page's query echoed: sort, search, price, stock,
     * filter count), the filtered catalogue in `$vm`, and on a product page its related products and the variant a shared
     * link names (`?variant=`). All of it comes from the same builders the headless API reads.
     *
     * @param  array<string, mixed>  $vm
     * @param  array<string, mixed>|null  $detail
     * @return array<string, mixed> view data merged over the page's
     */
    private function aisle(Store $store, Request $http, ViewModelRequest $request, array &$vm, ?array $detail): array
    {
        $out = ['listing' => null, 'related' => [], 'variantId' => null, 'open' => null];
        $customer = auth('customer')->user();
        if ($detail !== null) {
            $out['related'] = $this->related($vm, (string) $detail['id']);
            $wanted = $http->query('variant');
            if (is_string($wanted) && $wanted !== '') {
                foreach ((array) ($detail['variants'] ?? []) as $variant) {
                    if (($variant['sku'] ?? null) === $wanted) {
                        $out['variantId'] = (string) $wanted;
                    }
                }
            }

            return $out;
        }
        $listed = $this->listing->run($store, $request, $http, $customer instanceof Customer ? $customer : null);
        if ($listed !== null) {
            $vm['catalog'] = $listed['catalog'];
            $out['listing'] = $listed['listing'];
        }

        return $out;
    }

    /**
     * P3-15 (basket, large catalogues): a grocery catalogue of up to one page (48 products) is drawn whole with the client-side
     * search and sort of the prototype; a larger one is listed by the server engine (search, category, sort, cursor) exactly
     * like `aisle`, so a 2,000-product store sends one page of cards ("Load more"), not the whole catalogue.
     *
     * @param  array<string, mixed>  $vm
     * @return array<string, mixed> view data merged over the page's (`listing` is null while the catalogue is drawn whole)
     */
    private function basket(Store $store, Request $http, ViewModelRequest $request, array &$vm): array
    {
        $out = ['listing' => null];
        if ((int) ($vm['catalog']['total'] ?? 0) <= self::PAGE) {
            return $out;
        }
        $customer = auth('customer')->user();
        $listed = $this->listing->run($store, $request, $http, $customer instanceof Customer ? $customer : null);
        if ($listed !== null) {
            $vm['catalog'] = $listed['catalog'];
            $out['listing'] = $listed['listing'] + ['paged' => true];
        }

        return $out;
    }

    /**
     * Other products of the product's category, as cards ("Complete the look"): the store curates nothing yet, so the
     * category is the relation. At most four.
     *
     * @param  array<string, mixed>  $vm
     * @return list<array<string, mixed>>
     */
    private function related(array $vm, string $itemId): array
    {
        foreach ($vm['catalog']['categories'] as $category) {
            if (in_array($itemId, $category['item_ids'], true)) {
                $ids = array_values(array_filter($category['item_ids'], fn (string $id) => $id !== $itemId && isset($vm['catalog']['items'][$id])));

                return array_map(fn (string $id) => $vm['catalog']['items'][$id], array_slice($ids, 0, 4));
            }
        }

        return [];
    }

    /**
     * P3-08 (aisle §12): the wishlist page — the saved products as cards (only those the storefront still offers), with the
     * variant each was saved with.
     *
     * @param  list<array{item_id: string, variant_id: string|null, added_at: string|null}>  $saved
     * @return array<string, mixed> view data of `store_templates.<theme>.wishlist`
     */
    public function wishlist(Store $store, Request $http, string $theme, array $saved): array
    {
        $request = $this->request($store, $http, 'home', $theme);
        $vm = $this->builder->build($request);
        $cards = [];
        foreach ($saved as $row) {
            if (isset($vm['catalog']['items'][$row['item_id']])) {
                $cards[$row['item_id']] = $vm['catalog']['items'][$row['item_id']];
            }
        }
        $vm['catalog'] = ['categories' => [], 'items' => $cards, 'total' => count($cards)];

        return [
            'vm' => $vm,
            'kit' => KitView::forVm($vm),
            'theme' => $theme,
            'store' => $store,
            'head' => ['robots' => 'noindex, nofollow'] + $this->head($store, $vm),
            'assetVersion' => $this->assetVersion(),
            'checkout' => $this->builder->checkoutForm($request),
            'wishlist' => $saved,
            'bodyClass' => 'p-wishlist',
        ];
    }

    /**
     * P3-06: the kiosk start screen of a store on a kit theme (`{slug}/kiosk`), or null when the store's theme is not a kit theme
     * that draws kiosk screens (the legacy attract screen then answers). The language buttons stay on this screen; "Tap to start"
     * opens the store page in kiosk mode.
     */
    public function kioskStart(Store $store, Request $http): ?View
    {
        $id = (int) $store->id;
        $theme = $this->themeFor($store);
        if ($theme === null || ! view()->exists('store_templates.'.$theme.'.kiosk-start')) {
            return null;
        }

        $http->attributes->set('kiosk.themed', true);
        $vm = $this->builder->build($this->request($store, $http, 'home', $theme));
        $slug = (string) $store->slug;
        $reopens = null;
        if (empty($vm['store']['status']['open']) && ! empty($vm['store']['status']['next_change_at'])) {
            $reopens = Carbon::parse((string) $vm['store']['status']['next_change_at'])
                ->setTimezone((string) ($vm['store']['timezone'] ?? 'UTC'))->locale($vm['store']['locale'])->translatedFormat('g:i A');
        }

        return view('store_templates.'.$theme.'.kiosk-start', [
            'vm' => $vm,
            'kit' => KitView::forVm($vm),
            'theme' => $theme,
            'store' => $store,
            'head' => ['title' => $vm['store']['name'], 'robots' => 'noindex, nofollow'],
            'assetVersion' => $this->assetVersion(),
            'start' => [
                'url' => route('publicView', $slug, false).'?kiosk=1',
                'setup' => StoreSettings::string($id, 'kiosk_setup_pin') !== '',
                'reopens' => $reopens,
                'languages' => array_map(fn (array $l) => [
                    'code' => $l['code'],
                    'name' => $l['name'],
                    'current' => $l['code'] === $vm['store']['locale'],
                    'url' => '/'.$slug.'/kiosk?lang='.rawurlencode($l['code']),
                ], (array) ($vm['store']['locales'] ?? [])),
            ],
        ]);
    }

    /** @return array<string, mixed>|null the data of the item sheet fragment, or null when the item is not this store's */
    public function item(Store $store, Request $http, string $theme, string $itemPublicId): ?array
    {
        $request = $this->request($store, $http, 'item', $theme, item: $itemPublicId);
        $vm = $this->builder->build($request);
        if (($vm['page']['kind'] ?? null) !== 'item') {
            return null;
        }

        return ['vm' => $vm, 'kit' => KitView::forVm($vm), 'item' => $vm['page']['item'], 'open' => null, 'bookingMode' => $this->bookingMode($store, $theme)];
    }

    /** @return array<string, mixed> view data of `store_templates.<theme>.track` */
    public function track(Store $store, Order $order, string $theme): array
    {
        $kiosk = KioskContext::on((int) $store->id);
        if ($kiosk) {
            request()->attributes->set('kiosk.themed', true);
        }
        $request = new ViewModelRequest(store: $store, locale: app()->getLocale(), page: 'track', theme: $theme, kiosk: $kiosk, order: $order);
        $vm = $this->builder->build($request);
        $head = $this->head($store, $vm);
        $head['robots'] = 'noindex, nofollow';

        return ['vm' => $vm, 'kit' => KitView::forVm($vm), 'theme' => $theme, 'store' => $store, 'head' => $head, 'assetVersion' => $this->assetVersion()];
    }

    /**
     * P3-11: the view data of one step of the booking flow on the `book` theme (choose a time, confirm, pay the deposit,
     * the booking page). `$data` is what the Bookings step computed; the view model words and shapes it.
     *
     * @param  'time'|'reschedule'|'details'|'pay'|'manage'  $page
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> view data of `store_templates.<theme>.<page>`
     */
    public function bookingPage(Store $store, Request $http, string $theme, string $page, array $data): array
    {
        $data['messages'] = $this->messages();
        $request = $this->request($store, $http, $page, $theme, booking: $data);
        $vm = $this->builder->build($request);
        $head = $this->head($store, $vm);
        $head['robots'] = 'noindex, nofollow';

        return [
            'vm' => $vm,
            'kit' => KitView::forVm($vm),
            'theme' => $theme,
            'store' => $store,
            'head' => $head,
            'assetVersion' => $this->assetVersion(),
            'bookingMode' => true,
        ];
    }

    /** @return list<array{kind: string, text: string}> what the last booking step answered: the errors of a refused step, the notice of a done one */
    private function messages(): array
    {
        $rows = [];
        $bag = session('errors');
        if ($bag instanceof ViewErrorBag) {
            foreach (array_values(array_unique($bag->all())) as $text) {
                $rows[] = ['kind' => 'error', 'text' => (string) $text];
            }
        }
        if (is_string(session('success')) && session('success') !== '') {
            $rows[] = ['kind' => 'success', 'text' => (string) session('success')];
        }

        return $rows;
    }

    /**
     * P3-11: the view data of one step of the laundry booking on the `book` theme (choose the pickup and delivery times, confirm).
     *
     * @param  'schedule'|'details'  $page
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> view data of `store_templates.<theme>.laundry-<page>`
     */
    public function laundryPage(Store $store, Request $http, string $theme, string $page, array $data): array
    {
        $data['messages'] = $this->messages();
        $vm = $this->builder->build($this->request($store, $http, $page, $theme, booking: $data));
        $head = $this->head($store, $vm);
        $head['robots'] = 'noindex, nofollow';

        return [
            'vm' => $vm,
            'kit' => KitView::forVm($vm),
            'theme' => $theme,
            'store' => $store,
            'head' => $head,
            'assetVersion' => $this->assetVersion(),
            'bookingMode' => true,
            'laundryMode' => true,
        ];
    }

    /** a `book` theme store that is a laundry: a service store with no bookable services, which sells by the piece and the kilo */
    public function laundryMode(Store $store, string $theme): bool
    {
        $verticals = app(VerticalRegistry::class);

        return $theme === 'book' && ! $this->bookingMode($store, $theme) && $verticals->has($store->store_type ?? null, 'service')
            && in_array('pickup_slot', (array) ($verticals->config((string) $store->store_type)['fulfillment'] ?? []), true);
    }

    /** a `book` theme store that sells through the booking engine (its pages and sheet are the booking ones) */
    public function bookingMode(Store $store, string $theme): bool
    {
        return $theme === 'book' && app(StorefrontBookings::class)->inUse($store);
    }

    /** @param  array<string, mixed>|null  $booking */
    private function request(Store $store, Request $http, string $page, string $theme, ?string $item = null, ?array $booking = null): ViewModelRequest
    {
        $id = (int) $store->id;
        $kiosk = KioskContext::on($id);
        if ($kiosk) {
            $http->attributes->set('kiosk.themed', true);
        }

        $branch = null;
        $param = $http->query('branch');
        if (is_string($param) && $param !== '') {
            $branch = Branch::query()->where('store_id', $id)->where('public_id', $param)->first();
        } elseif ($http->filled('branch_id')) {
            $branch = Branch::query()->where('store_id', $id)->where('id', (int) $http->query('branch_id'))->first();
        }

        $customer = auth('customer')->user();

        return new ViewModelRequest(
            store: $store,
            locale: app()->getLocale(),
            page: $page,
            theme: $theme,
            branch: $branch,
            table: $this->tableLabel($store, $http),
            kiosk: $kiosk,
            customer: $customer instanceof Customer ? $customer : null,
            item: $item,
            booking: $booking,
            onlyCategory: $http->query('fragment') === 'cat' && is_string($http->query('category')) && $http->query('category') !== '' ? (string) $http->query('category') : null,
            vehicle: is_string($http->query('vehicle')) && trim((string) $http->query('vehicle')) !== '' ? trim((string) $http->query('vehicle')) : null, // P3-08b (aisle §13)
        );
    }

    private function scannedTable(Store $store, Request $http): ?StoreTable
    {
        $key = $http->query('table');
        if (! is_string($key) || $key === '' || TableKeyGuard::blocked((int) $store->id)) {
            return null; // blocked: "no table" without a lookup, same as the core page (TableKeyGuard)
        }

        $table = StoreTable::query()->where(['store_id' => $store->id, 'key' => $key])->first();
        if ($table === null) {
            TableKeyGuard::miss((int) $store->id);
        }

        return $table;
    }

    /** the order's table key (what the checkout posts as `table`); only for a table this store really has */
    private function tableKey(Store $store, Request $http): ?string
    {
        $table = $this->scannedTable($store, $http);

        return $table !== null ? (string) $table->key : null;
    }

    private function tableLabel(Store $store, Request $http): ?string
    {
        $table = $this->scannedTable($store, $http);
        if ($table === null) {
            return null;
        }
        $label = trim((string) $table->label);

        return $label !== '' ? $label : ($table->table_no !== null ? ___('Table').' '.$table->table_no : (string) $table->key);
    }

    /** @param  array<string, mixed>  $vm
     * @param  array<string, mixed>|null  $detail
     * @return array<string, mixed> */
    private function head(Store $store, array $vm, ?array $detail = null): array
    {
        $name = (string) $vm['store']['name'];
        $brand = active_brand_reseller() ? brand_title() : ___('alt_barcode');
        $description = trim((string) ($vm['store']['tagline'] ?? ''));
        if ($description === '') {
            $description = text_shorting(html_entity_decode(strip_tags((string) $store->description), ENT_QUOTES), 150);
        }

        $alternates = [];
        if (config('settings.userlangsel')) {
            foreach (store_indexable_langs((int) $store->id) as $code) {
                $alternates[] = ['code' => $code, 'url' => lang_url($code)];
            }
        }

        if ($detail !== null && $this->isProductPage($store)) {
            return $this->productHead($store, $vm, $detail, $brand, $description);
        }

        return [
            'title' => ($this->isProductPage($store) ? '' : ___('the_qr_menu_for').' ').$name.' | '.$brand,
            'description' => $description,
            'canonical' => store_canonical_url((int) $store->id),
            'alternates' => $alternates,
            'robots' => store_has_menu((int) $store->id) ? 'index, follow' : 'noindex, follow',
            'image' => ($vm['store']['cover']['src'] ?? $vm['store']['logo']['src'] ?? null),
            'favicon' => $vm['store']['logo']['src'] ?? null,
            'manifest' => route('publicView.manifest', $store->slug),
            'theme_color' => $store->color ?: null,
        ];
    }

    /** A product of a goods store is a page of its own (`?item=<public id>`, CatalogPresenter::itemUrl); a food item is a sheet. */
    private function isProductPage(Store $store): bool
    {
        return in_array('ship', (array) config('verticals.types.'.($store->store_type ?? '').'.fulfillment', []), true);
    }

    /**
     * P6-305: a product page names the product — its own title, description, canonical (the item URL on the store's primary
     * address), share image, og:type product and Product JSON-LD — instead of repeating the store home's head.
     *
     * @param  array<string, mixed>  $vm
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function productHead(Store $store, array $vm, array $detail, string $brand, string $storeDescription): array
    {
        $name = (string) $detail['name'];
        $storeName = (string) $vm['store']['name'];
        $description = text_shorting(html_entity_decode(strip_tags((string) ($detail['description'] ?? '')), ENT_QUOTES), 150);
        if ($description === '') {
            $description = $storeDescription;
        }
        $canonical = store_canonical_url((int) $store->id).'?item='.rawurlencode((string) $detail['id']);
        $images = array_values(array_filter(array_map(fn ($g) => $g['src'] ?? null, (array) ($detail['gallery'] ?? []))));
        $image = $images[0] ?? ($vm['store']['cover']['src'] ?? $vm['store']['logo']['src'] ?? null);

        $ld = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $name, 'url' => $canonical];
        if ($description !== '') {
            $ld['description'] = $description;
        }
        if ($images !== []) {
            $ld['image'] = $images;
        }
        $price = $detail['price'] ?? null;
        if (is_array($price) && isset($price['minor'], $price['currency'])) {
            $exp = Currencies::exponent((string) $price['currency']);
            $ld['offers'] = [
                '@type' => 'Offer',
                'price' => number_format(((int) $price['minor']) / (10 ** $exp), $exp, '.', ''),
                'priceCurrency' => (string) $price['currency'],
                'url' => $canonical,
                'availability' => 'https://schema.org/'.(in_array('sold_out', array_column((array) ($detail['badges'] ?? []), 'kind'), true) ? 'OutOfStock' : 'InStock'),
            ];
        }

        return [
            'title' => $name.' | '.$storeName,
            'description' => $description,
            'canonical' => $canonical,
            'alternates' => [],
            'robots' => store_has_menu((int) $store->id) ? 'index, follow' : 'noindex, follow',
            'image' => $image,
            'favicon' => $vm['store']['logo']['src'] ?? null,
            'manifest' => route('publicView.manifest', $store->slug),
            'theme_color' => $store->color ?: null,
            'og_type' => 'product',
            'site_name' => $storeName,
            'json_ld' => $ld,
        ];
    }

    private function assetVersion(): string
    {
        return (string) config('appinfo.version', '1');
    }
}
