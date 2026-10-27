<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\MenuCategory;
use App\Models\MenuReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Tests\TestCase;

/**
 * Theme-review-drops WI-1: the Liquid `item.reviews` surface (ReviewsDrop).
 * Models the render site on `Menu::reviews()` (an approved-only, DB-scoped
 * relation — not a Drop-level filter) exactly like StoreController's real
 * `Menu::with([..., 'reviews', ...])` eager-load, so this pins the actual SQL
 * scope, not a hand-picked in-memory collection. Sibling to
 * ThemeContextDropTest (same in-memory-Drop-render approach), but this one
 * goes through a real DB round trip because the thing under test IS the
 * approved-only relation query.
 */
class LiquidReviewsDropTest extends TestCase
{
    use RefreshDatabase;

    private function renderer(): LiquidRenderer
    {
        return new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient);
    }

    public function test_item_reviews_shows_approved_hides_pending_and_leaks_no_pii(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => 'revdrop-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Rev Drop House', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $menu = Menu::create([
            'store_id' => $store->id, 'category_id' => $category->id,
            'name' => 'Burger', 'price' => 10, 'active' => '1',
        ]);

        // Approved — must show, with an XSS attempt in both free-text fields to
        // prove the Drop pre-escapes (defense in depth: reviews are anonymous,
        // diner-submitted content, unlike merchant-authored Drop strings).
        $approved = MenuReview::create([
            'menu_id' => $menu->id, 'store_id' => $store->id,
            'customer_id' => 555, 'session_hash' => 'super-secret-session-hash',
            'reviewer_name' => 'Ada <script>alert(1)</script>',
            'stars' => 5, 'body' => 'Amazing & fresh <b>bold</b> claim',
            'status' => 'approved', 'is_verified' => true,
        ]);

        // Pending — must NEVER reach the theme.
        MenuReview::create([
            'menu_id' => $menu->id, 'store_id' => $store->id,
            'reviewer_name' => 'Secret Pending Reviewer', 'stars' => 1,
            'body' => 'THIS SHOULD NEVER RENDER', 'status' => 'pending', 'is_verified' => false,
        ]);

        // Rejected — same guarantee, belt and suspenders.
        MenuReview::create([
            'menu_id' => $menu->id, 'store_id' => $store->id,
            'reviewer_name' => 'Secret Rejected Reviewer', 'stars' => 1,
            'body' => 'THIS SHOULD ALSO NEVER RENDER', 'status' => 'rejected', 'is_verified' => false,
        ]);

        // Mirrors StoreController::publicView's real eager-load
        // (Menu::with([..., 'reviews', ...])) — the render site, not the Drop,
        // does the DB work; Menu::reviews() itself is the approved-only scope.
        $freshMenu = Menu::with(['category', 'reviews'])->findOrFail($menu->id);

        $ctx = (new ThemeContext)->build([
            'store' => $store,
            'postOptions' => (object) [],
            'total_menus' => [$freshMenu->id => $freshMenu],
        ]);

        $tpl = '{% for c in menu.categories %}{% for i in c.items %}'
            .'{% for r in i.reviews %}[{{ r.rating }}]{{ r.author }}:{{ r.body }}:{% if r.is_verified %}V{% endif %};{% endfor %}'
            .'{% endfor %}{% endfor %}';
        $out = $this->renderer()->render($tpl, $ctx);

        // Exact match: proves both (a) exactly ONE review reached the theme —
        // if either hidden review had leaked, its own ";"-terminated entry
        // would appear here too — and (b) the free-text fields render
        // HTML-escaped rather than raw (a stored-XSS check: the literal
        // `<script>`/`<b>` tags must never appear unescaped).
        $this->assertSame(
            '[5]Ada &lt;script&gt;alert(1)&lt;/script&gt;:Amazing &amp; fresh &lt;b&gt;bold&lt;/b&gt; claim:V;',
            $out
        );

        // No reviewer PII reachable from the Drop at all — no getter exists for
        // these, so a live render resolves them to nothing (same contract as
        // CustomerDrop / StoreDrop's other-attribute tests).
        $pii = $this->renderer()->render(
            '{% for c in menu.categories %}{% for i in c.items %}{% for r in i.reviews %}'
            .'A[{{ r.customer_id }}]B[{{ r.session_hash }}]C[{{ r.email }}]D[{{ r.phone }}]E'
            .'{% endfor %}{% endfor %}{% endfor %}',
            $ctx
        );
        $this->assertSame('A[]B[]C[]D[]E', $pii);
        $this->assertStringNotContainsString('555', $pii);
        $this->assertStringNotContainsString('super-secret-session-hash', $pii);

        $this->assertTrue($approved->exists); // sanity: the fixture actually persisted
    }

    public function test_item_with_no_reviews_renders_an_empty_list(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => 'revdrop-empty-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Empty House', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $menu = Menu::create([
            'store_id' => $store->id, 'category_id' => $category->id,
            'name' => 'Fries', 'price' => 5, 'active' => '1',
        ]);

        $freshMenu = Menu::with(['category', 'reviews'])->findOrFail($menu->id);
        $ctx = (new ThemeContext)->build([
            'store' => $store,
            'postOptions' => (object) [],
            'total_menus' => [$freshMenu->id => $freshMenu],
        ]);

        $out = $this->renderer()->render(
            '{% for c in menu.categories %}{% for i in c.items %}'
            .'[{% for r in i.reviews %}{{ r.rating }}{% endfor %}]'
            .'{% endfor %}{% endfor %}',
            $ctx
        );
        $this->assertSame('[]', $out, 'no reviews → the inner for-loop body never runs');
    }
}
