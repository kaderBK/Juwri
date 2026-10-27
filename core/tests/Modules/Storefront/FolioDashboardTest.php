<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Folio (flip-book theme) — lane B dashboard wiring.
 *
 * B1: a folio store's menu page routes to the image editor (same editor as
 * flipbook — folio is image_menus-backed and display-only), while item-based
 * stores keep the item editor and flipbook keeps its behavior untouched.
 * B3: the image editor grows Images | PDF source tabs for folio stores ONLY.
 */
class FolioDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // APP_INSTALLED is unset under PHPUnit, so ViewServiceProvider never shares
        // the theme/settings bag and any full page render 500s.
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999],
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
    }

    private function store(User $owner, ?string $template = null): Store
    {
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($template !== null) {
            StoreOption::updateStoreOption($store->id, 'store_template', $template);
        }

        return $store;
    }

    // ------------------------------------------------------------- B1: editor switch

    public function test_folio_store_gets_the_image_editor(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'folio');

        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id));

        $res->assertOk();
        // The image editor's info alert — the item editor never renders it.
        $res->assertSee('The current store theme supports images only.');
    }

    public function test_item_based_store_keeps_the_item_editor(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'crave');

        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id));

        $res->assertOk();
        $res->assertSee('Lunch specials'); // the item editor's category placeholder
        $res->assertDontSee('The current store theme supports images only.');
    }

    public function test_flipbook_store_behavior_is_unchanged(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'flipbook');

        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id));

        $res->assertOk();
        $res->assertSee('The current store theme supports images only.');
    }

    // ------------------------------------------------------------- B3: source tabs

    public function test_folio_store_sees_the_images_pdf_source_tabs(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'folio');

        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id));

        $res->assertOk();
        $res->assertSeeHtml('data-folio-source-tabs');
        $res->assertSeeHtml('data-folio-pdf-pane');
        // Upload + source forms post to the folio endpoint
        $res->assertSeeHtml(route('theme.customizer.folioPdf', $store->id));
    }

    public function test_folio_pdf_pane_shows_the_current_pdf_card(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'folio');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_source', 'pdf');
        StoreOption::updateStoreOption($store->id, 'restaurant_folio_pdf', 'folio-pdf/'.$store->id.'-current.pdf');

        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id));

        $res->assertOk();
        $res->assertSee($store->id.'-current.pdf'); // filename on the card
        $res->assertSee('Replace PDF');
    }

    public function test_flipbook_store_does_not_see_the_source_tabs(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'flipbook');

        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id));

        $res->assertOk();
        $res->assertDontSeeHtml('data-folio-source-tabs');
        $res->assertDontSeeHtml('data-folio-pdf-pane');
    }

    // ------------------------------------------------------------- B4: customizer panel

    public function test_customizer_schema_carries_the_folio_state_with_defaults(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'folio');

        $res = $this->actingAs($owner)->getJson("/theme-customizer/{$store->id}");

        $res->assertOk()
            ->assertJsonPath('template', 'folio')
            ->assertJsonPath('folio.source', 'images')
            ->assertJsonPath('folio.display', 'auto')
            ->assertJsonPath('folio.hardcover', '0')
            ->assertJsonPath('folio.anim', 'flip')
            ->assertJsonPath('folio.tb_thumbs', '1')
            ->assertJsonPath('folio.tb_download', '0')
            ->assertJsonPath('folio.bg', '#1f2430')
            ->assertJsonPath('folio.accent', '#6d28d9');
    }

    public function test_customizer_save_writes_exactly_the_contract_options(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'folio');

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-options", [
            'source' => 'pdf', 'display' => 'double', 'hardcover' => '1', 'anim' => 'swipe',
            'tb_thumbs' => '0', 'tb_zoom' => '0', 'tb_fullscreen' => '0', 'tb_download' => '1',
            'bg' => '#101820', 'accent' => '#ff5722',
        ])->assertOk()->assertJson(['ok' => true]);

        // Exactly the §3 option names/values (rev/folio-theme/SPEC.md).
        $expected = [
            'restaurant_folio_source' => 'pdf',
            'restaurant_folio_display' => 'double',
            'restaurant_folio_hardcover' => '1',
            'restaurant_folio_anim' => 'swipe',
            'restaurant_folio_tb_thumbs' => '0',
            'restaurant_folio_tb_zoom' => '0',
            'restaurant_folio_tb_fullscreen' => '0',
            'restaurant_folio_tb_download' => '1',
            'restaurant_folio_bg' => '#101820',
            'restaurant_folio_accent' => '#ff5722',
        ];
        foreach ($expected as $option => $value) {
            $this->assertSame($value, (string) store_options($store->id, $option), $option);
        }
    }

    public function test_customizer_save_rejects_out_of_contract_values(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'folio');

        $this->actingAs($owner)->postJson("/theme-customizer/{$store->id}/folio-options", [
            'display' => 'triple', 'bg' => 'not-a-hex',
        ])->assertStatus(422);

        // Missing options read back falsy (nothing was written).
        $this->assertEmpty(store_options($store->id, 'restaurant_folio_display'));
        $this->assertEmpty(store_options($store->id, 'restaurant_folio_bg'));
    }

    public function test_customizer_save_is_tenant_scoped(): void
    {
        $victimOwner = $this->owner();
        $victim = $this->store($victimOwner, 'folio');
        $attacker = $this->owner();

        $this->actingAs($attacker)->postJson("/theme-customizer/{$victim->id}/folio-options", [
            'display' => 'double',
        ])->assertNotFound();

        $this->assertEmpty(store_options($victim->id, 'restaurant_folio_display'));
    }
}
