<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * WA-C3: the ordering on/off switch lives on the core WhatsApp settings page.
 *
 * The dangerous case is a legacy store: 1,172 of them are still on the old
 * `quickorder_enable` key. If the form rendered the toggle from the raw NEW option
 * they would all show as "off", and the first time an owner saved any unrelated
 * setting on that page, their WhatsApp ordering would silently switch off.
 */
class WhatsAppOrderingToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        /* APP_INSTALLED is deliberately false in phpunit.xml, so the Installed
           middleware bounces every owner route to /install and the '*' layout
           composer never registers. Same treatment the other owner-route tests use. */
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function store(): Store
    {
        $this->owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $this->owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Toggle Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
    }

    public function test_a_legacy_store_renders_the_toggle_as_enabled(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');

        $res = $this->actingAs($this->owner)->get(route('stores.whatsappSettings', $store->id));

        $res->assertOk();
        // The select must come back with "1" chosen, not the default "0".
        $res->assertSeeHtml('whatsapp_ordering_enable');
        $this->assertTrue(whatsapp_ordering_enabled($store->id));
    }

    public function test_saving_the_page_does_not_switch_a_legacy_store_off(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');

        // Owner saves the form with the toggle at the value the page rendered.
        $this->actingAs($this->owner)
            ->post(route('stores.whatsappSettings', $store->id), [
                'whatsapp_ordering_enable' => '1',
                'whatsapp_provider' => 'link',
            ])
            ->assertRedirect();

        $this->assertTrue(whatsapp_ordering_enabled($store->id), 'ordering must survive a save');
    }

    public function test_the_toggle_can_actually_turn_ordering_off(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');

        $this->actingAs($this->owner)
            ->post(route('stores.whatsappSettings', $store->id), [
                'whatsapp_ordering_enable' => '0',
                'whatsapp_provider' => 'link',
            ])
            ->assertRedirect();

        // The new key wins over the legacy one, so "off" really means off.
        $this->assertFalse(whatsapp_ordering_enabled($store->id));
    }

    public function test_the_old_plugin_url_redirects_to_the_settings_page(): void
    {
        $store = $this->store();

        $this->actingAs($this->owner)
            ->get(url('stores/'.$store->id.'/whatsapp-ordering'))
            ->assertRedirect(route('stores.whatsappSettings', $store->id));
    }
}
