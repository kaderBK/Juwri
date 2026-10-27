<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Platform E2E lane B — B4 (Publish, QR, links).
 *
 * Discriminating server contracts behind the `b-publish.js` journey, driven
 * HTTP-through-controller (the Blade-direct trap: these must exercise the real
 * route + middleware, not the view in isolation):
 *
 *   • Publish gate — a store whose OWNER is active (`user.status == 1`) serves
 *     the crave menu; flip the owner inactive and the same public URL 404s. This
 *     is the store-visibility seam StoreController@index enforces
 *     (`$store->user->status == 1` … else `abort(404)`), asserted before/after.
 *   • QR downloads carry real IMAGE BYTES, not just a 200 — the designer PNG
 *     download opens with the PNG magic number, and the SVG variant is real SVG.
 *   • Saving a QR design persists the store's single default `qr_designs` row —
 *     the seam every QR surface then renders through `store_qr_settings()`.
 *   • The table-QR list, the branch-QR page and the custom-domain settings page
 *     each render for the owner (the last WITHOUT any verification round-trip).
 *
 * Harness mirrors CraveCoverStorefrontTest (crave renders in-suite) +
 * BioLinkQrTargetTest (the `admin_allow_qr_designer` kill-switch config idiom).
 */
class PublishQrLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Global kill-switches ON — the three dashboard surfaces gate on
        // FeatureRegistry::isEnabled('admin_allow_<key>'); none is plan_gated, so
        // the config flip alone opens the qr-designer + custom-domain routes
        // (table_qr additionally needs a per-owner entitlement, granted below).
        config([
            'settings.admin_allow_qr_designer' => true,
            'settings.admin_allow_custom_domain' => true,
            'settings.admin_allow_table_qr' => true,
        ]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** @return array{0: User, 1: Store} owner + a crave store with one item. */
    private function craveStore(int $ownerStatus = 1): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => $ownerStatus, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'Publish House', 'sub_title' => 'T', 'description' => 'A crave menu',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Signature Burger', 'price' => 42.00, 'active' => '1']);

        return [$owner, $store];
    }

    // ── 1. Publish gate (the discriminating before/after) ──────────────────────

    /** A published (active-owner) crave store serves the menu; an unpublished one 404s. */
    public function test_active_owner_publishes_menu_and_inactive_owner_returns_404(): void
    {
        [$owner, $store] = $this->craveStore();

        // PUBLISHED — anonymous diner sees the crave menu with the item.
        $this->get('/'.$store->slug)->assertOk()->assertSeeHtml('Signature Burger')->assertSeeHtml('crave'); // crave asset path proves the template rendered

        // UNPUBLISH via the owner-status gate.
        $owner->update(['status' => 0]);

        $this->get('/'.$store->slug)->assertNotFound();

        // RE-PUBLISH restores it.
        $owner->update(['status' => 1]);
        $this->get('/'.$store->slug)->assertOk()->assertSeeHtml('Signature Burger');
    }

    // ── 2. QR download BYTES ───────────────────────────────────────────────────

    /** The QR designer PNG download opens with the PNG magic number. */
    public function test_qr_designer_download_returns_png_magic_bytes(): void
    {
        [$owner, $store] = $this->craveStore();

        $res = $this->actingAs($owner)
            ->get(route('qr.designer.download', ['store_id' => $store->id]));

        $res->assertOk();
        $this->assertStringContainsString('image/png', strtolower($res->headers->get('content-type') ?? ''));
        $this->assertStringContainsString('attachment', strtolower($res->headers->get('content-disposition') ?? ''));

        $body = $res->getContent();
        $this->assertNotEmpty($body);
        // PNG magic: 89 50 4E 47 0D 0A 1A 0A.
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($body, 0, 8), 'QR download is not a PNG');
    }

    /** The SVG variant of the same download is real SVG markup. */
    public function test_qr_designer_download_svg_variant_is_svg(): void
    {
        [$owner, $store] = $this->craveStore();

        $res = $this->actingAs($owner)
            ->get(route('qr.designer.download', ['store_id' => $store->id, 'format' => 'svg']));

        $res->assertOk();
        $this->assertStringContainsString('image/svg+xml', strtolower($res->headers->get('content-type') ?? ''));
        $this->assertStringContainsString('<svg', $res->getContent());
    }

    // ── 3. QR designer save persists the design row ────────────────────────────

    /** Saving a design writes the store's one default qr_designs row. */
    public function test_qr_designer_save_persists_default_design_row(): void
    {
        [$owner, $store] = $this->craveStore();

        $res = $this->actingAs($owner)->postJson(
            route('qr.designer.save', ['store_id' => $store->id]),
            ['name' => 'Suite QR', 'fg_color' => '#0a0a0a', 'bg_color' => '#ffffff', 'eye_color' => '#0a0a0a'],
        );

        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('qr_designs', [
            'store_id' => $store->id,
            'is_default' => 1,
            'fg_color' => '#0a0a0a',
        ]);
    }

    /** A low-contrast design is refused (422) and never persisted — proves the save is guarded. */
    public function test_qr_designer_save_refuses_unscannable_design(): void
    {
        [$owner, $store] = $this->craveStore();

        $this->actingAs($owner)->postJson(
            route('qr.designer.save', ['store_id' => $store->id]),
            ['name' => 'Bad', 'fg_color' => '#fefefe', 'bg_color' => '#ffffff'],
        )->assertStatus(422);

        $this->assertDatabaseMissing('qr_designs', ['store_id' => $store->id]);
    }

    // ── 4. The three QR/link surfaces render ───────────────────────────────────

    /** The table-QR list renders for the owner (needs the table_qr entitlement). */
    public function test_table_qr_list_renders_for_owner(): void
    {
        [$owner, $store] = $this->craveStore();
        EntitlementWriter::grant($owner, 'table_qr');

        $this->actingAs($owner)
            ->get(route('stores.tables', $store))
            ->assertOk();
    }

    /** The branch-QR page renders for the owner. */
    public function test_branch_qr_page_renders_for_owner(): void
    {
        [$owner, $store] = $this->craveStore();

        $this->actingAs($owner)
            ->get(route('branches.qr', ['store' => $store->id]))
            ->assertOk();
    }

    /** The custom-domain settings page renders (no verification round-trip). */
    public function test_custom_domain_settings_page_renders(): void
    {
        [$owner, $store] = $this->craveStore();

        $this->actingAs($owner)
            ->get(route('stores.domains.index', $store))
            ->assertOk();
    }

    /** The QR builder page's server SVG carries real SVG bytes. */
    public function test_qr_builder_svg_endpoint_returns_svg_bytes(): void
    {
        [$owner, $store] = $this->craveStore();

        $res = $this->actingAs($owner)
            ->get(route('stores.qrSvg', $store).'?fg=%230a0a0a&bg=%23ffffff&padding=2');

        $res->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $res->getContent());
    }
}
