<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use App\Models\Order;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use App\Support\KioskContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Services\Grocery\GroceryPicking;
use Juwri\Modules\Storefront\Http\Controllers\StorefrontRetailCheckoutController;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Storefront\KitStorefront;
use Juwri\Modules\Storefront\Services\Theme\KitView;
use Tests\Support\StorefrontApi\ApiFixtures;

/**
 * P3-05: renders a kit theme's REAL Blade views (the store page, the item sheet, the cart and checkout dialogs, tracking, the
 * states board) for one preset x scheme x locale, and writes them into a conformance tree (the layout the kit's gates and
 * the browser job read: prototypes/<theme>/<locale>/<page>.html, kit/<theme>/css, tokens/, tools/ ...).
 *
 * The pages are what a request produces, post-processed only so a static server / file:// can show them: absolute app URLs
 * become relative, the inline token <style> blocks become files (the browser policy refuses inline styles), store photos
 * become the kit placeholders (the seeded photos are not in the tree), absolute links to other sites become data-href (as the
 * prototypes do: the gate forbids external hosts in theme files; the destinations are view-model values).
 */
final class KitTree
{
    public const PAGES = ['home', 'item', 'cart', 'checkout', 'track', 'states', 'kiosk', 'kiosk-start', 'kiosk-done', 'kiosk-states'];

    /** P3-08: the retail theme also draws the wishlist page */
    public const PAGES_AISLE = ['home', 'item', 'cart', 'checkout', 'track', 'wishlist', 'states', 'kiosk', 'kiosk-start', 'kiosk-done', 'kiosk-states'];

    /** Olive / M: the shirt variant that is not stock-tracked (every render places an order) */
    private const SHIRT_OLIVE_M = '019fe81c-5a00-771a-ae9b-4d2ee36f1b7b';

    /** @return list<string> */
    public static function pagesOf(string $theme): array
    {
        return $theme === 'aisle' ? self::PAGES_AISLE : self::PAGES;
    }

    /** @var array<string, string> hash => css text of inline <style> blocks of the current tree */
    private array $inlineCss = [];

    public function __construct(private readonly Store $store, private readonly string $theme = 'table') {}

    /**
     * The rendered pages of one variant.
     *
     * @return array<string, array<string, string>> locale => page => html
     */
    public function render(string $preset, string $scheme, array $locales = ['en', 'ar']): array
    {
        $scenario = new ViewModelScenario($this->store, $this->theme);
        $cart = match ($this->theme) {
            'aisle' => $this->retailCart(),
            'basket' => $this->basketCart(),
            default => $scenario->cart(),
        };
        $order = $this->theme === 'basket' ? $this->basketOrder($scenario, $cart) : $scenario->place($cart);

        $out = [];
        foreach ($locales as $locale) {
            $this->apply($preset, $scheme);
            app()->setLocale($locale);
            $out[$locale] = $this->pages($scenario, $cart, $order, $locale);
        }
        app()->setLocale('en');

        return $out;
    }

    /**
     * The retail demo cart: the shirt in Olive / M (a variant picked from the matrix) and the trousers in Navy / M.
     *
     * @return list<array{item: string, qty: int, variant: string, options: list<string>}>
     */
    private function retailCart(): array
    {
        return [
            ['item' => ApiFixtures::SHIRT, 'qty' => 1, 'variant' => self::SHIRT_OLIVE_M, 'options' => []],
            ['item' => ApiFixtures::TROUSERS, 'qty' => 1, 'variant' => ApiFixtures::TROUSERS_NAVY_M, 'options' => []],
        ];
    }

    /**
     * The grocery demo basket: 2 x Bananas (a pack), Tomatoes 500 g (sold by weight), Strawberries 250 g, Full-fat milk 2 L.
     *
     * @return list<array{item: string, qty: int, options: list<string>}>
     */
    private function basketCart(): array
    {
        return [
            ['item' => ApiFixtures::BANANAS, 'qty' => 2, 'options' => []],
            ['item' => ApiFixtures::TOMATOES, 'qty' => 1, 'options' => [ApiFixtures::TOMATO_STEPS[500]]],
            ['item' => '019f3fab-0380-73c2-89a4-21d8a38a1c3f', 'qty' => 1, 'options' => []],
            ['item' => '019feb8b-4200-7e01-bb06-c6019e258487', 'qty' => 1, 'options' => []],
        ];
    }

    /**
     * The grocery order the tracking page shows: placed as a delivery, picking started, the Strawberries replaced by a proposal the
     * shopper has not answered ("Changes to review").
     *
     * @param  list<array{item: string, qty: int, options: list<string>}>  $cart
     */
    private function basketOrder(ViewModelScenario $scenario, array $cart): Order
    {
        $order = $scenario->place($cart, ['type' => 'delivery', 'address' => 'Al Olaya, King Fahd Rd, Bldg 12']);
        $picking = app(GroceryPicking::class);
        $picking->start($order);
        $items = $order->items()->orderBy('id')->get();
        $picking->pickAsOrdered($order, (int) $items[0]->id);
        $picking->propose($order, (int) $items[2]->id, 'Raspberries 125 g', 1250, null, false);

        return $order->fresh() ?? $order;
    }

    /**
     * The grocery basket of the store: the quote the page's own `retail/quote` endpoint answers (a delivery, express, so no time is asked).
     *
     * @param  list<array<string, mixed>>  $cart
     * @return array<string, mixed>
     */
    private function basketQuote(array $cart): array
    {
        $request = Request::create('/'.$this->store->slug.'/retail/quote', 'POST', ['lines' => $cart, 'order_type' => 'delivery', 'express' => true]);
        $answer = app(StorefrontRetailCheckoutController::class)->quote($request, (string) $this->store->slug);

        return (array) json_decode((string) $answer->getContent(), true, 512, JSON_THROW_ON_ERROR)['quote'];
    }

    /**
     * The bag of the retail store: the quote the page's own `retail/quote` endpoint answers (Standard shipping to Riyadh).
     *
     * @param  list<array<string, mixed>>  $cart
     * @return array<string, mixed>
     */
    private function retailQuote(array $cart): array
    {
        $request = Request::create('/'.$this->store->slug.'/retail/quote', 'POST', [
            'lines' => $cart, 'order_type' => 'ship', 'shipping_method' => ApiFixtures::SHIP_STANDARD,
            'address' => ['country' => 'SA', 'city' => 'Riyadh', 'line1' => 'Olaya St 1', 'postal_code' => '12211'],
        ]);
        $answer = app(StorefrontRetailCheckoutController::class)->quote($request, (string) $this->store->slug);

        return (array) json_decode((string) $answer->getContent(), true, 512, JSON_THROW_ON_ERROR)['quote'];
    }

    private function apply(string $preset, string $scheme): void
    {
        $schema = ThemeCustomizerService::schemaFor($this->theme);
        $values = ThemeCustomizerService::presetValues($schema, $preset) ?? [];
        $values['scheme.mode'] = $scheme;
        ThemeSetting::updateOrCreate(['store_id' => $this->store->id, 'template' => $this->theme], ['settings' => $values]);
        request()->attributes->remove('__theme_setting_memo');
        request()->attributes->remove('__theme_setting_template');
    }

    /**
     * @param  list<array{item: string, qty: int, options: list<string>}>  $cart
     * @return array<string, string>
     */
    private function pages(ViewModelScenario $scenario, array $cart, Order $order, string $locale): array
    {
        $kit = app(KitStorefront::class);
        $slug = (string) $this->store->slug;
        $http = Request::create('/'.$slug);
        $theme = $this->theme;

        $home = $kit->home($this->store, $http, $theme);
        // the grocery's product sheet is the weighed one (the weight steps, the estimate, the out-of-stock choice)
        $itemData = $kit->home($this->store, Request::create('/'.$slug, 'GET', ['item' => $cart[$this->theme === 'basket' ? 1 : 0]['item']]), $theme);
        $quote = match ($this->theme) {
            'aisle' => $this->retailQuote($cart),
            'basket' => $this->basketQuote($cart),
            default => $scenario->build($locale, 'cart', ['cart' => $cart])['page']['cart'],
        };

        $pages = [
            'home' => $this->view("store_templates.{$theme}.index", $home),
            'item' => $this->view($theme === 'aisle' ? 'store_templates.aisle.item' : "store_templates.{$theme}.index", $itemData),
            'cart' => $this->view("store_templates.{$theme}.index", ['cart' => $quote, 'open' => 'cart'] + $home),
            'checkout' => $this->view("store_templates.{$theme}.index", ['cart' => $quote, 'open' => 'checkout'] + $home),
            'track' => $this->view("store_templates.{$theme}.track", $kit->track($this->store, $order, $theme)),
        ];

        if ($theme === 'aisle') {
            $saved = [['item_id' => $cart[0]['item'], 'variant_id' => $cart[0]['variant'], 'added_at' => null], ['item_id' => $cart[1]['item'], 'variant_id' => null, 'added_at' => null]];
            $pages['wishlist'] = $this->view('store_templates.aisle.wishlist', $kit->wishlist($this->store, $http, $theme, $saved));
        }

        // the states board is the closed-store view model plus the price-changed card
        Branch::where('store_id', $this->store->id)->update(['accepting_orders' => false]);
        $closed = $kit->home($this->store, $http, $theme);
        Branch::where('store_id', $this->store->id)->update(['accepting_orders' => true]);
        $pages['states'] = $this->view("store_templates.{$theme}.states", $closed + ['cartTotal' => $quote['total'], 'newTotal' => $quote['total']]);

        // P3-06: the kiosk screens (the ordering screen with the order panel, the start screen, the done screen, the screens board);
        // a kiosk session of this store: the server-side marker the kiosk route sets, the owner's switch and the feature kill-switch.
        $id = (int) $this->store->id;
        config(['settings.admin_allow_kiosk' => true]);
        StoreOption::updateStoreOption($id, 'kiosk_enabled', '1');
        KioskContext::mark($id);
        try {
            $kioskHome = $kit->home($this->store, Request::create('/'.$slug), $theme);
            $pages['kiosk'] = $this->view("store_templates.{$theme}.index", ['cart' => $quote] + $kioskHome);
            $start = $kit->kioskStart($this->store, Request::create('/'.$slug.'/kiosk'));
            $pages['kiosk-start'] = $start->render();
            $done = $kit->track($this->store, $order, $theme);
            $pages['kiosk-done'] = $this->view("store_templates.{$theme}.track", $done);
            $pages['kiosk-states'] = $this->view("store_templates.{$theme}.kiosk-states", [
                'start' => $start->getData()['start'], 'orderData' => $done['vm']['page']['order'],
            ] + $kioskHome);
        } finally {
            KioskContext::clear($id);
            StoreOption::updateStoreOption($id, 'kiosk_enabled', '0');
        }

        return $pages;
    }

    /** @param  array<string, mixed>  $data */
    private function view(string $name, array $data): string
    {
        return View::make($name, $data)->render();
    }

    /**
     * Write a tree for the rendered pages of one variant (both locales). $dir is created fresh.
     *
     * @param  array<string, array<string, string>>  $rendered  locale => page => html
     */
    public function write(string $dir, array $rendered): void
    {
        ThemeConformance::rrmdir($dir);
        mkdir($dir, 0775, true);
        $repo = ThemeConformance::repoRoot();
        $snap = ThemeConformance::toolsDir().'/kit-snapshot';

        // the kit's inputs (tokens, generators, view-model fixtures, browser tools, design prototypes) as the gates expect them
        ThemeConformance::copyTree($snap, $dir);
        // the real theme files replace the prototype's
        $theme = $this->theme;
        copy($repo.'/assets/store_templates/_kit/kit.css', $dir.'/prototypes/_kit/kit.css');
        copy($repo."/assets/store_templates/{$theme}/css/theme.css", $dir."/prototypes/{$theme}/theme.css");
        copy($repo."/assets/store_templates/{$theme}/css/tokens.css", $dir."/kit/{$theme}/css/tokens.css");
        copy($repo."/assets/store_templates/{$theme}/theme.json", $dir."/kit/{$theme}/theme.json");
        @symlink($repo.'/assets', $dir.'/assets');

        $this->inlineCss = [];
        foreach ($rendered as $locale => $pages) {
            @mkdir($dir."/prototypes/{$theme}/{$locale}", 0775, true);
            foreach ($pages as $page => $html) {
                file_put_contents($dir."/prototypes/{$theme}/{$locale}/{$page}.html", $this->tree($html, $dir, $page));
            }
        }
        foreach ($this->inlineCss as $hash => $css) {
            file_put_contents($dir."/kit/{$theme}/css/inline-{$hash}.css", $css);
        }
    }

    /**
     * The RTL / visual parity pages: the SAME view-model fixtures the design prototypes are generated from, rendered through
     * the real Blade views (no database, the default preset), so a pixel diff against the prototypes measures the port and
     * nothing else. Only the screens the Blade theme renders as the prototype does are produced (home, item, track, states);
     * the cart and the checkout are dialogs over the store page here and standalone pages in the prototypes.
     *
     * @return array<string, string> page => html
     */
    public function fixturePages(string $locale): array
    {
        if ($this->theme === 'book') {
            return $this->bookFixturePages($locale);
        }
        app()->setLocale($locale);
        $fx = function (?string $name) use ($locale): array {
            $vm = json_decode((string) file_get_contents(ThemeConformance::toolsDir().'/kit-snapshot/kit/viewmodel/fixtures/'.$this->theme.'.'.$locale.($name ? '.'.$name : '').'.json'), true, 512, JSON_THROW_ON_ERROR);
            $vm['endpoints']['home'] ??= '/'.$vm['store']['slug']; // the fixtures predate the `home` endpoint of the real view model
            if ($this->theme === 'brew') {
                if (isset($vm['page']['order']['location'])) {
                    $vm['page']['order']['location']['url'] ??= '#directions'; // the real view model carries the maps link
                }
                $vm['features']['line_note'] ??= ['max_length' => 20]; // the cafe fixtures draw the "Name on the cup" field; the real view model carries the switch
            }

            return $vm;
        };
        $home = $fx(null);
        $cartVm = $fx('cart');
        $checkVm = $fx('checkout');
        $cart = $cartVm['page']['cart'];
        $checkout = $checkVm['page']['checkout'];
        $theme = $this->theme;
        $data = fn (array $vm, array $more = []) => $more + ['vm' => $vm, 'kit' => KitView::forVm($vm), 'theme' => $theme, 'head' => [], 'assetVersion' => '1',
            'checkout' => $checkout, 'cart' => $cart, 'item' => null, 'open' => null, 'tableKey' => null,
            'listing' => null, 'related' => [], 'variantId' => null];

        $item = $fx('item');
        $closed = $fx('closed');

        return [
            'home' => $this->view("store_templates.{$theme}.index", $data($home)),
            'item' => $theme === 'aisle'
                ? $this->view('store_templates.aisle.item', $data($item, ['item' => $item['page']['item'], 'related' => []]))
                : $this->view("store_templates.{$theme}.index", $data($item, ['item' => $item['page']['item'], 'open' => 'item'])),
            'track' => $this->view("store_templates.{$theme}.track", $data($fx('track'))),
            'states' => $this->view("store_templates.{$theme}.states", $data($closed, ['cartTotal' => $cart['total'], 'newTotal' => $checkout['quote']['total']])),
            // P3-06: the kiosk ordering screen (the kit's `kiosk` fixture: the view model of a kiosk session)
            'kiosk' => $this->view("store_templates.{$theme}.index", $data($fx('kiosk'))),
        ];
    }

    /**
     * P3-11: the `book` theme's parity pages — the design's booking fixtures through the booking views (store page, service sheet,
     * choose a time, confirm, reschedule, booking page, states). The fixtures predate the step URLs the real view model carries
     * (`form`), which are filled in here.
     *
     * @return array<string, string> page => html
     */
    private function bookFixturePages(string $locale): array
    {
        app()->setLocale($locale);
        $slug = (string) $this->store->slug;
        $fx = function (?string $name) use ($locale, $slug): array {
            $vm = json_decode((string) file_get_contents(ThemeConformance::toolsDir().'/kit-snapshot/kit/viewmodel/fixtures/book.'.$locale.($name ? '.'.$name : '').'.json'), true, 512, JSON_THROW_ON_ERROR);
            $vm['endpoints']['home'] ??= '/'.$vm['store']['slug'];
            $vm['endpoints']['times'] ??= '/'.$slug.'/appointments/times';
            $vm['endpoints']['hold'] ??= '/'.$slug.'/appointments/hold';
            if (isset($vm['page']['checkout'])) {
                $vm['page']['checkout']['form'] ??= ['action' => '/'.$slug.'/appointments/hold', 'refresh' => '/'.$slug.'/appointments/times', 'back' => '/'.$slug, 'hidden' => []];
            }

            return $vm;
        };
        $data = fn (array $vm, array $more = []) => $more + ['vm' => $vm, 'kit' => KitView::forVm($vm), 'theme' => 'book', 'head' => [], 'assetVersion' => '1', 'bookingMode' => true, 'messages' => [], 'item' => null, 'open' => null];
        $home = $fx(null);
        $item = $fx('item');
        $closed = $fx('closed');

        return [
            'home' => $this->view('store_templates.book.index', $data($home)),
            'item' => $this->view('store_templates.book.index', $data($item, ['item' => $item['page']['item'], 'open' => 'item'])),
            'time' => $this->view('store_templates.book.time', $data($fx('time'))),
            'checkout' => $this->view('store_templates.book.details', $data($fx('checkout'))),
            'reschedule' => $this->view('store_templates.book.reschedule', $data($fx('reschedule'))),
            'track' => $this->view('store_templates.book.manage', $data($fx('track'))),
            'states' => $this->view('store_templates.book.states', $data($closed)),
        ];
    }

    /**
     * A tree whose home, item, track and states pages (en + ar) are the fixture renders of fixturePages(); every other file is
     * the kit snapshot's. proto.js (in the snapshot) supplies ?scheme= and ?bar=0, as it does on the prototypes themselves.
     */
    public function writeParity(string $dir): void
    {
        ThemeConformance::rrmdir($dir);
        mkdir($dir, 0775, true);
        $repo = ThemeConformance::repoRoot();
        ThemeConformance::copyTree(ThemeConformance::toolsDir().'/kit-snapshot', $dir);
        $theme = $this->theme;
        copy($repo.'/assets/store_templates/_kit/kit.css', $dir.'/prototypes/_kit/kit.css');
        copy($repo."/assets/store_templates/{$theme}/css/theme.css", $dir."/prototypes/{$theme}/theme.css");
        copy($repo."/assets/store_templates/{$theme}/css/tokens.css", $dir."/kit/{$theme}/css/tokens.css");
        @symlink($repo.'/assets', $dir.'/assets');
        $this->inlineCss = [];
        foreach (['en', 'ar'] as $locale) {
            foreach ($this->fixturePages($locale) as $page => $html) {
                // the "Cookie settings" row is a legal addition (P1-23) the design does not draw: the parity tree measures the port against the design without it
                $html = (string) preg_replace('#<li data-cookie-link>.*?</li>#s', '', $html);
                $html = $this->tree($html, $dir, 'static');
                $html = (string) preg_replace('#<script\b[^>]*type="module"[^>]*></script>#', '', $html);
                $html = str_replace('</body>', '<script src="../../_kit/proto.js" defer></script></body>', $html);
                file_put_contents($dir."/prototypes/{$theme}/{$locale}/{$page}.html", $html);
            }
        }
        foreach ($this->inlineCss as $hash => $css) {
            file_put_contents($dir."/kit/{$theme}/css/inline-{$hash}.css", $css);
        }
        app()->setLocale('en');
    }

    /** the page as the static tree holds it (see the class note) */
    private function tree(string $html, string $dir, string $page): string
    {
        $origin = rtrim((string) url('/'), '/');
        $html = str_replace($origin, '', $html);

        // the core's cookie-consent banner style and script are a core partial (inline, nonce'd), not theme files: the static tree drops them
        $html = (string) preg_replace('#<style\b[^>]*>(?:(?!</style>).)*\.ckc\b.*?</style>#s', '', $html);
        $html = (string) preg_replace('#<script(?![^>]*\btype=)[^>]*\bnonce="[^"]*"[^>]*>.*?</script>#s', '', $html);

        // inline <style> -> file
        $html = (string) preg_replace_callback('#<style\b[^>]*>(.*?)</style>#s', function (array $m): string {
            $css = str_replace(["url('/assets/", 'url("/assets/', 'url(/assets/'], ["url('../../../assets/", 'url("../../../assets/', 'url(../../../assets/'], $m[1]);
            $hash = substr(sha1($css), 0, 12);
            $this->inlineCss[$hash] = $css;

            return '<link rel="stylesheet" href="../../../kit/'.$this->theme.'/css/inline-'.$hash.'.css">';
        }, $html);

        // store photos -> the kit placeholders; the app's own assets -> the tree's assets/
        $html = (string) preg_replace('#(src|href)="/storage/store/cover/[^"]*"#', '$1="../../_kit/img/placeholder-16x9.svg"', $html);
        $html = (string) preg_replace('#(src|href)="/storage/[^"]*"#', '$1="../../_kit/img/placeholder-1x1.svg"', $html);
        $html = (string) preg_replace('#(src|href)="/assets/store_templates/_kit/kit\.css[^"]*"#', '$1="../../_kit/kit.css"', $html);
        $html = (string) preg_replace('#(src|href)="/assets/store_templates/'.$this->theme.'/css/theme\.css[^"]*"#', '$1="../theme.css"', $html);
        $html = (string) preg_replace('#(src|href)="/assets/store_templates/'.$this->theme.'/css/tokens\.css[^"]*"#', '$1="../../../kit/'.$this->theme.'/css/tokens.css"', $html);
        $html = (string) preg_replace('#(src|href)="/assets/([^"?]*)(\?[^"]*)?"#', '$1="../../../assets/$2"', $html);

        // links to other sites keep their destination out of href (the gates forbid external hosts in theme files)
        $html = (string) preg_replace('#href="(https?://[^"]+)"#', 'href="#" data-href="$1"', $html);
        $html = (string) preg_replace('#<(link|meta)\b[^>]*(?:rel="(?:preload|canonical|alternate|manifest|icon|apple-touch-icon)"|property="og:[a-z:]+"|name="twitter:[a-z:]+")[^>]*>#', '', $html);

        // a page with a filled cart is a static state: the core would replace it with the (empty) cart of a fresh browser
        if (in_array($page, ['cart', 'checkout', 'kiosk-done'], true)) {
            $html = (string) preg_replace('#<script\b[^>]*data-juwri-boot[^>]*>.*?</script>#s', '', $html);
            $html = (string) preg_replace('#<script\b[^>]*type="module"[^>]*></script>#', '', $html);
        }

        return $html;
    }
}
