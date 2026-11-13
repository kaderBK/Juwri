<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Services\DevDocs\DocsCorpus;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P2-2 "smarter command palette" — the docs group, unified relevance ranking
 * (pages/orders/menu items/customers), the levenshtein typo fallback, and the
 * Arabic-Indic digit normalization added to DashboardController::search().
 * Mirrors DashboardSearchDeepLinkTest's fixture style; does not touch it or
 * DashboardNotificationsTest, and preserves every contract they pin (the
 * `orders`/`items` group keys, the `?order=`/`?item=` deep links).
 */
class DashboardSearchSmartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    private function makeOwnerAndStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);

        return [$owner, $store];
    }

    public function test_docs_group_surfaces_a_matching_help_center_guide(): void
    {
        [$owner] = $this->makeOwnerAndStore();

        // "chargeback" is exclusive-ish to resources/docs/users/disputes.md
        // (title "Disputes and chargebacks"), so it's a deterministic top hit.
        $json = $this->actingAs($owner)->getJson(route('dashboard.search', ['q' => 'chargeback']))->assertOk()->json();

        $docsGroup = collect($json['groups'])->firstWhere('key', 'docs');
        $this->assertNotNull($docsGroup, 'expected a docs group for a doc-matching query');
        $this->assertNotEmpty($docsGroup['items']);
        $this->assertSame(route('help.show', 'disputes'), $docsGroup['items'][0]['url']);
        $this->assertStringContainsString('Disputes', $docsGroup['items'][0]['label']);
    }

    public function test_relevance_ranking_puts_the_exact_match_first(): void
    {
        [$owner, $store] = $this->makeOwnerAndStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C', 'parent' => null, 'position' => 1]);

        // Inserted in an order where the OLD (DB/insertion-order) search would
        // have returned "Deluxe Pizza Feast" before the exact match "Pizza".
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Deluxe Pizza Feast',
            'description' => 'd', 'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);
        $exact = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Pizza',
            'description' => 'd', 'price' => 8, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 2,
        ]);

        $json = $this->actingAs($owner)->getJson(route('dashboard.search', ['q' => 'Pizza']))->assertOk()->json();

        $itemsGroup = collect($json['groups'])->firstWhere('key', 'items');
        $this->assertNotNull($itemsGroup);
        $this->assertSame('Pizza', $itemsGroup['items'][0]['label']);
        $this->assertStringContainsString('?item='.$exact->id, $itemsGroup['items'][0]['url']);
    }

    public function test_typo_query_still_matches_a_nav_page_via_levenshtein_fallback(): void
    {
        [$owner] = $this->makeOwnerAndStore();

        // "orderz" has no exact substring hit anywhere in the nav inventory —
        // the whole `pages` group scores zero on the exact pass, gating the
        // fuzzy fallback on (precedent: OvertureIngestService::
        // namesAreSimilar()). levenshtein('orderz', 'orders') === 1.
        $json = $this->actingAs($owner)->getJson(route('dashboard.search', ['q' => 'orderz']))->assertOk()->json();

        $pagesGroup = collect($json['groups'])->firstWhere('key', 'pages');
        $this->assertNotNull($pagesGroup, 'expected the typo fallback to still surface the Orders page');
        $urls = collect($pagesGroup['items'])->pluck('url')->all();
        $this->assertContains(route('stores.orders'), $urls);
    }

    public function test_arabic_indic_digit_query_matches_a_latin_stored_order_id(): void
    {
        [$owner, $store] = $this->makeOwnerAndStore();
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'customer_name' => 'Amir',
            'phone_number' => '555-99', 'status' => 'pending', 'seen' => 0, 'created_at' => now(),
        ]);

        // Arabic-Indic digits for the order's (Latin-stored) id — e.g. id=1 -> '١'.
        $arabicIndic = strtr((string) $order->id, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);

        $json = $this->actingAs($owner)->getJson(route('dashboard.search', ['q' => $arabicIndic]))->assertOk()->json();

        $ordersGroup = collect($json['groups'])->firstWhere('key', 'orders');
        $this->assertNotNull($ordersGroup, 'Arabic-Indic order id should still match the Latin-stored order');
        $this->assertStringContainsString('?order='.$order->id, $ordersGroup['items'][0]['url']);
    }

    /**
     * Direct DocsCorpus coverage for the Unicode-aware tokenizer (build item
     * 6): the OLD `[^a-z0-9_]` regex discarded Arabic script entirely, so an
     * Arabic query always hit the `$terms === []` early return. With the
     * `\p{L}\p{N}` tokenizer + the is_rtl()/locale-'ar' overlay switch, an
     * Arabic query matches the users/ar/ translation and is reported back
     * under its BASE (English) slug — the shape help.show's route accepts.
     */
    public function test_arabic_query_matches_the_arabic_doc_overlay_under_its_base_slug(): void
    {
        app()->setLocale('ar');

        $results = DocsCorpus::search('المحاسبة', 5, ['users']);

        $this->assertNotEmpty($results, 'Arabic query should tokenize and match the users/ar/accounting.md overlay');
        $this->assertSame('users/accounting', $results[0]['doc']);
    }
}
