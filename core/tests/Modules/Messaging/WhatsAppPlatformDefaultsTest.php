<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * WA-S2: platform defaults and the kill-switch.
 *
 * The precedence that matters: kill-switch > store setting > platform default >
 * shipped default. The kill-switch exists so an outage can be stopped from one
 * place instead of editing 1,172 stores, so it has to outrank the store.
 */
class WhatsAppPlatformDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Platform Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
    }

    public function test_the_kill_switch_is_off_when_the_option_was_never_set(): void
    {
        // An absent option must never read as "killed" — that would take the
        // hand-off away from every store on deploy.
        $this->assertFalse(whatsapp_handoff_killed());
        $this->assertSame('auto', whatsapp_handoff_settings($this->store()->id)['mode']);
    }

    public function test_the_kill_switch_beats_a_store_that_asked_for_auto(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_mode', 'auto');
        config(['settings.whatsapp_handoff_kill' => '1']);

        $this->assertSame('none', whatsapp_handoff_settings($store->id)['mode']);
    }

    public function test_the_kill_switch_also_closes_the_ordering_gate(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_ordering_enable', '1');
        $this->assertTrue(whatsapp_ordering_enabled($store->id));

        config(['settings.whatsapp_handoff_kill' => '1']);

        // Defence in depth: the URL is never built in the first place, so a theme
        // that ignores the mode flag still cannot hand over.
        $this->assertFalse(whatsapp_ordering_enabled($store->id));
    }

    public function test_a_store_setting_beats_the_platform_default(): void
    {
        $store = $this->store();
        config(['settings.whatsapp_handoff_target' => 'same']);
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_target', 'new');

        $this->assertSame('new', whatsapp_handoff_settings($store->id)['target']);
    }

    public function test_every_setting_has_a_platform_layer(): void
    {
        config([
            'settings.whatsapp_handoff_mode' => 'button',
            'settings.whatsapp_handoff_target' => 'same',
            'settings.whatsapp_handoff_delay' => '2',
            'settings.whatsapp_handoff_route' => 'fixed',
        ]);

        $settings = whatsapp_handoff_settings($this->store()->id);

        $this->assertSame('button', $settings['mode']);
        $this->assertSame('same', $settings['target']);
        $this->assertSame(2000, $settings['delay']);
        $this->assertSame('fixed', $settings['route']);
    }

    public function test_a_nonsense_platform_value_still_falls_back(): void
    {
        config(['settings.whatsapp_handoff_mode' => 'explode']);

        $this->assertSame('auto', whatsapp_handoff_settings($this->store()->id)['mode']);
    }

    public function test_the_feature_controls_page_offers_all_five_controls(): void
    {
        /* The partial, not the whole settings page: APP_INSTALLED is false in
           phpunit.xml, so the shared $settings object the other twenty tabs read
           does not exist, and faking all of it would test the fake. */
        $html = view('admin.settings.feature-controls', ['settings' => (object) []])->render();

        foreach ([
            'whatsapp_handoff_kill',
            'whatsapp_handoff_mode',
            'whatsapp_handoff_target',
            'whatsapp_handoff_delay',
            'whatsapp_handoff_route',
        ] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }
    }

    public function test_the_admin_form_rejects_an_out_of_range_delay(): void
    {
        /* Only the install gate is skipped — APP_INSTALLED is deliberately false in
           phpunit.xml, and a blanket withoutMiddleware() would drop the rest. */
        $this->withoutMiddleware(Installed::class);

        $admin = User::factory()->create([
            'user_type' => 'admin',
            'status' => 1,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.settings.update'), [
            'general_setting' => '1',
            'site_title' => 'juwri',
            'whatsapp_handoff_delay' => '99',
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('success'));
    }
}
