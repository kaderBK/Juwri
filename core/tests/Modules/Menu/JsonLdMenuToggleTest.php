<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-suite Lane B (B3): the per-store menu_seo_jsonld toggle backend +
 * self-contained toggle-field partial. HTTP-through-controller (not blade-direct)
 * so the route + ownership + save contract are proven, plus a partial render.
 */
class JsonLdMenuToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makeOwnerAndRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'allow_ordering' => 1, 'table_qr' => 0, 'allow_pro' => '0', 'allow_advanced' => '0',
                'allow_on_free' => '0', 'allow_on_try' => '0', 'allow_on_main' => '0',
                'allow_viewonly' => '0', 'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');

        return [$owner, $store];
    }

    /** Default OFF; the show endpoint reports the current state for the owner. */
    public function test_show_reports_default_off(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();

        $this->actingAs($owner)->getJson(route('jsonld.menu.toggle.show', $store->id))
            ->assertOk()
            ->assertJson(['menu_seo_jsonld' => false]);
    }

    /** Owner turns it on (checkbox=1) then off (field absent); stored as the house '1'/'0' string. */
    public function test_owner_saves_toggle_on_and_off(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();

        // On.
        $this->actingAs($owner)->postJson(route('jsonld.menu.toggle.save', $store->id), ['menu_seo_jsonld' => '1'])
            ->assertOk()
            ->assertJson(['ok' => true, 'menu_seo_jsonld' => true]);
        $this->assertSame('1', StoreSettings::legacy((int) $store->id, 'menu_seo_jsonld'));

        // Off — an unchecked checkbox simply omits the field.
        $this->actingAs($owner)->postJson(route('jsonld.menu.toggle.save', $store->id), [])
            ->assertOk()
            ->assertJson(['ok' => true, 'menu_seo_jsonld' => false]);
        $this->assertSame('0', StoreSettings::legacy((int) $store->id, 'menu_seo_jsonld'));
    }

    /** A plain (non-JSON) form submit — the partial's path — redirects back. */
    public function test_plain_form_submit_redirects_back(): void
    {
        [$owner, $store] = $this->makeOwnerAndRestaurant();

        $this->actingAs($owner)->post(route('jsonld.menu.toggle.save', $store->id), ['menu_seo_jsonld' => '1'])
            ->assertStatus(302);
        $this->assertSame('1', StoreSettings::legacy((int) $store->id, 'menu_seo_jsonld'));
    }

    /** A store owned by someone else is not editable (firstOrFail → 404). */
    public function test_cross_tenant_blocked(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();
        $other = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($other)->postJson(route('jsonld.menu.toggle.save', $store->id), ['menu_seo_jsonld' => '1'])
            ->assertNotFound();
        $this->actingAs($other)->getJson(route('jsonld.menu.toggle.show', $store->id))
            ->assertNotFound();
    }

    /** The self-contained partial renders its own form, reflects state, and no-ops without an id. */
    public function test_toggle_field_partial_renders_and_reflects_state(): void
    {
        [, $store] = $this->makeOwnerAndRestaurant();

        // Off (default): unchecked.
        $off = view('store_templates._partials.jsonld-toggle-field', ['storeId' => $store->id])->render();
        $this->assertStringContainsString(route('jsonld.menu.toggle.save', $store->id), $off);
        $this->assertStringContainsString('name="menu_seo_jsonld"', $off);
        $this->assertStringNotContainsString('checked', $off);

        // On: checkbox reflects it.
        StoreOption::updateStoreOption($store->id, 'menu_seo_jsonld', '1');
        $on = view('store_templates._partials.jsonld-toggle-field', ['storeId' => $store->id])->render();
        $this->assertStringContainsString('checked', $on);

        // No id in scope → renders nothing (safe for @includeIf).
        $empty = view('store_templates._partials.jsonld-toggle-field', ['storeId' => null])->render();
        $this->assertStringNotContainsString('<form', $empty);
    }
}
