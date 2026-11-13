<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A2 — the platform E2E `a-order` journey drives each storefront through a
 * per-template DOM contract (root class, category nav, search input, order-type
 * segment, coupon input, cash tender, checkout/place control). Those anchors are
 * server-rendered by the theme's blade when ordering is enabled; the puppeteer
 * journey then relies on them existing. This test pins that same server contract
 * for the A2 templates (matcha/glow/aurora/vivid), the A3 templates
 * (feast/velvet/dough/plate) and the A4 templates (lime/picnic/orbit/velora)
 * through the real storefront controller (GET `/{slug}`), so a theme dropping or
 * renaming an ordering anchor fails here BEFORE the browser journey silently
 * loses a control.
 *
 * Discriminating: each needle is the exact selector the a-order contract targets;
 * deleting the corresponding element from the theme blade turns the matching
 * assertion red. Blade-direct rendering would miss the controller's ordering
 * gates, so this drives the full HTTP path (HANDOFF §9 "Blade-direct tests miss
 * the controller contract").
 */
class AOrderTemplateContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** A fully orderable store on `$theme`: all three order types, coupons, one item. */
    private function store(string $theme): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => $theme.'-c-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => ucfirst($theme).' House', 'sub_title' => 'T', 'description' => 'Some description',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        StoreOption::updateStoreOption($store->id, 'restaurant_delivery_order', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        StoreOption::updateStoreOption($store->id, 'restaurant_on_table_order', '1');
        StoreOption::updateStoreOption($store->id, 'allow_coupons', '1');
        FeatureAccess::flush();

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1']);

        return $store;
    }

    /**
     * The a-order DOM contract per template. Every needle is server-rendered by
     * the theme when ordering is on; the puppeteer journey targets each one.
     *
     * @return array<string, array{0:string, 1:string[]}>
     */
    public static function contracts(): array
    {
        return [
            // cv family (crave fork): the #cv… SPA shell + place/checkout controls
            'matcha' => ['matcha', [
                'class="matcha"', 'id="matcha"', 'id="cvGrid"', 'id="cvSearchInput"',
                'id="cvOrderTypeSeg"', 'data-ot="delivery"', 'data-ot="takeaway"', 'data-ot="on-table"',
                'id="cvCouponInput"', 'id="cvGoCheckout"', 'id="cvPlaceOrder"', 'data-pm="pay_on_counter"',
            ]],
            'glow' => ['glow', [
                'class="glow"', 'id="glow"', 'id="cvGrid"', 'id="cvSearchInput"',
                'id="cvOrderTypeSeg"', 'data-ot="delivery"', 'data-ot="takeaway"', 'data-ot="on-table"',
                'id="cvCouponInput"', 'id="cvGoCheckout"', 'id="cvPlaceOrder"', 'data-pm="pay_on_counter"',
            ]],
            // sheet family (au-/vd-): order form in the cart sheet, [data-type] types,
            // a name="pay_via" tender, an AJAX checkout button, server-rendered cards.
            'aurora' => ['aurora', [
                'class="aurora"', 'id="aurora"', 'id="au-catbar"', 'id="au-menu-root"', 'id="au-search-input"',
                'id="au-order-form"', 'id="au-ordertype-seg"', 'data-type="delivery"', 'data-type="takeaway"', 'data-type="on-table"',
                'id="au-coupon-code"', 'id="au-checkout-btn"', 'name="pay_via"', 'data-open-item=',
            ]],
            'vivid' => ['vivid', [
                'id="vd"', 'class="vd"', 'id="vd-catnav"', 'id="vd-menu-root"', 'id="vd-search-input"',
                'id="vd-order-form"', 'id="vd-ordertype-seg"', 'data-type="delivery"', 'data-type="takeaway"', 'data-type="on-table"',
                'id="vd-coupon-code"', 'id="vd-checkout-btn"', 'name="pay_via"', 'data-open-item=',
            ]],
            // ffform family (feast/velvet): the checkout form lives in the cart drawer;
            // the order type is a `.…-tab-btn[data-type]` segment that toggles the
            // `#…-group-{table,phone,address}` field blocks; cash is a pay_via radio.
            // feast search is an overlay (#ff-search-open); velvet is inline (#v-search-input).
            'feast' => ['feast', [
                'id="feast-app"', 'id="ff-cats"', 'id="ff-search-open"', 'id="ff-cart-toggle"',
                'id="ff-checkout-form"', 'id="ff-coupon-code"', 'id="ff-coupon-apply-btn"',
                'data-type="delivery"', 'data-type="takeaway"', 'data-type="on-table"',
                'id="ff-group-address"', 'id="ff-group-table"', 'id="ff-submit-order-btn"', 'value="pay_on_counter"',
            ]],
            'velvet' => ['velvet', [
                'id="velvet-app"', 'id="v-cats"', 'id="v-search-input"', 'id="v-cart-toggle"',
                'id="v-checkout-form"', 'id="v-coupon-code"', 'id="v-coupon-apply-btn"',
                'data-type="delivery"', 'data-type="takeaway"', 'data-type="on-table"',
                'id="v-group-address"', 'id="v-group-table"', 'id="v-submit-order-btn"', 'value="pay_on_counter"',
            ]],
            // spa family (dough/plate): the #…-view SPA shell — grid + overlay search,
            // the #otSeg [data-ot] segment + coupon in the cart, contact fields in the
            // checkout, and a default-selected cash tender.
            'dough' => ['dough', [
                'id="dough"', 'id="cats"', 'id="grid"', 'id="searchBtn"', 'id="soInput"', 'id="bagBtn"',
                'id="couponInput"', 'id="otSeg"', 'data-ot="delivery"', 'data-ot="takeaway"', 'data-ot="on-table"',
                'id="goCheckout"', 'id="placeOrder"', 'id="coName"', 'data-pm="cash"', 'data-pv="pay_on_counter"',
            ]],
            'plate' => ['plate', [
                'id="plate"', 'id="cats"', 'id="grid"', 'id="searchBtn"', 'id="searchInput"', 'id="cartBtn"',
                'id="couponInput"', 'id="otSeg"', 'data-ot="delivery"', 'data-ot="takeaway"', 'data-ot="on-table"',
                'id="checkoutBtn"', 'id="placeBtn"', 'id="ckName"', 'data-pay="pay_on_counter"',
            ]],
            // spa family (A4: lime/picnic/orbit/velora): the same `[data-view]` SPA
            // shape as dough/plate but with per-fork ids, and three contract
            // differences the journey encodes — picnic names its pickup/dine-in
            // types `pickup`/`dinein` (not takeaway/on-table), picnic/orbit build
            // their tender list client-side (so only the `#pmList` container is
            // server-rendered), and velora's cart badge is an id-less
            // `i.bagcount.nav-badge`. lime's search is a whole tab view
            // (`[data-tab="search"]` → `#searchInput` → `#searchGrid`); the other
            // three open an overlay into their own results box.
            'lime' => ['lime', [
                'class="lime"', 'id="lime"', 'id="chips"', 'id="grid"', 'data-tab="search"',
                'id="searchInput"', 'id="searchGrid"', 'id="cartList"', 'id="bagBadge"',
                'id="otSeg"', 'data-ot="delivery"', 'data-ot="takeaway"', 'data-ot="on-table"',
                'id="couponInput"', 'id="goCheckout"', 'id="custName"', 'id="placeOrder"',
                'data-pm="pay_on_counter"',
            ]],
            'picnic' => ['picnic', [
                'id="phone"', 'id="cats"', 'id="grid"', 'id="searchBtn"', 'id="soInput"', 'id="soResults"',
                'id="cartList"', 'id="cartBadge"', 'id="orderTypeSeg"',
                'data-ot="delivery"', 'data-ot="pickup"', 'data-ot="dinein"',
                'id="couponInput"', 'id="goCheckout"', 'id="custName"', 'id="placeOrder"', 'id="pmList"',
            ]],
            'orbit' => ['orbit', [
                'class="orbit"', 'id="orbit"', 'id="arc"', 'id="grid"', 'id="searchOpen"',
                'id="searchInput"', 'id="searchResults"', 'id="cartList"', 'id="cartBadge"',
                'id="orderTypeSeg"', 'data-ot="delivery"', 'data-ot="takeaway"', 'data-ot="on-table"',
                'id="couponInput"', 'id="goCheckout"', 'id="coName"', 'id="tableField"',
                'id="placeOrder"', 'id="pmList"',
            ]],
            'velora' => ['velora', [
                'class="velora"', 'id="velora"', 'id="catRail"', 'id="grid"', 'id="searchOpen"',
                'id="soInput"', 'id="soResults"', 'id="cartList"', 'bagcount nav-badge',
                'id="orderTypeSeg"', 'data-ot="delivery"', 'data-ot="takeaway"', 'data-ot="on-table"',
                'id="couponInput"', 'id="goCheckout"', 'id="custName"', 'id="placeOrder"',
                'data-pm="pay_on_counter"',
            ]],
            // spa family (A5: crumb/soiree/scoop/ember): similar to dough/plate/lime
            // but with per-fork ids. crumb/soiree use id="app" class="crumb/soiree";
            // scoop names its pickup/dine-in types `pickup`/`dinein`; scoop's
            // place-order CTA total is #placeTotal. soiree's totals wrap in #totalsBox.
            // crumb's root element carries both a shared "app" class and its own
            // fork class (`class="app crumb"`), and its tender list is built
            // client-side into the server-rendered `#pmList` container (same
            // pattern as picnic/orbit above) rather than a static pay_on_counter
            // control — verified live: crumb still renders a full order path.
            'crumb' => ['crumb', [
                'class="app crumb"', 'id="app"', 'id="catRow"', 'id="featGrid"', 'id="searchOverlay"',
                'id="soInput"', 'id="soClose"', 'id="cartList"', 'id="cartBadge"',
                'id="orderTypeSeg"', 'data-ot="delivery"', 'data-ot="takeaway"', 'data-ot="on-table"',
                'id="couponInput"', 'id="goCheckout"', 'id="custName"', 'id="placeOrder"',
                'id="pmList"',
            ]],
            // soiree's search overlay is `#searchOv` (not `searchOverlay`); its cart
            // badge is an id-less `class="badge cc-badge"` counter on the dock's cart
            // button (same shape as velora's `bagcount nav-badge` above); the
            // cart-to-checkout control is a `data-paytile` button, not a `goCheckout`
            // id; and its tender is server-rendered with `data-pm=`, not a bare
            // `value=` attribute — verified live: soiree still renders a full order
            // path end to end.
            'soiree' => ['soiree', [
                'class="soiree"', 'id="app"', 'id="catChips"', 'id="browseChips"', 'id="searchOv"',
                'id="soInput"', 'id="soClose"', 'id="cartList"', 'class="badge cc-badge"',
                'id="orderTypeSeg"', 'data-ot="delivery"', 'data-ot="takeaway"', 'data-ot="on-table"',
                'id="couponInput"', 'data-paytile', 'id="custName"', 'id="placeOrder"',
                'data-pm="pay_on_counter"',
            ]],
            // scoop's root carries a shared "stage" class alongside its own fork
            // class (`class="stage scoop"`), and — like crumb — builds its tender
            // list client-side into the server-rendered `#pmList` container.
            // Verified live: scoop still renders a full order path.
            'scoop' => ['scoop', [
                'id="scoop"', 'class="stage scoop"', 'id="homeTabs"', 'id="grid"', 'id="searchBtn"',
                'id="searchInput"', 'id="cartList"', 'id="cartBadge"',
                'id="orderTypeSeg"', 'data-ot="delivery"', 'data-ot="pickup"', 'data-ot="dinein"',
                'id="couponInput"', 'id="goCheckout"', 'id="custName"', 'id="placeOrder"',
                'id="pmList"',
            ]],
            // ember's menu grid is `#menuList` (not `grid`), its cart badge is
            // `#cpCount` on the dock cart pill (not `cartBadge`), its order-type
            // segment is `#otSeg` (not `orderTypeSeg` — matching the dough/plate/
            // picnic/orbit naming, not the crumb/soiree/scoop one despite the
            // shared A5 grouping), and its tender is server-rendered with
            // `data-pm=`, not a bare `value=` attribute. Verified live: ember still
            // renders a full order path end to end.
            'ember' => ['ember', [
                'id="ember"', 'class="ember"', 'id="catRow"', 'id="menuList"', 'id="searchOpen"',
                'id="searchInput"', 'id="searchResults"', 'id="cartList"', 'id="cpCount"',
                'id="otSeg"', 'data-ot="delivery"', 'data-ot="takeaway"', 'data-ot="on-table"',
                'id="couponInput"', 'id="goCheckout"', 'id="coName"', 'id="placeOrder"',
                'data-pm="pay_on_counter"',
            ]],
        ];
    }

    /**
     * @param  string[]  $needles
     */
    #[DataProvider('contracts')]
    public function test_template_renders_the_a_order_contract(string $theme, array $needles): void
    {
        $store = $this->store($theme);
        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        foreach ($needles as $needle) {
            $this->assertStringContainsString(
                $needle,
                $html,
                "template [$theme] storefront is missing a-order contract anchor: {$needle}"
            );
        }
    }
}
