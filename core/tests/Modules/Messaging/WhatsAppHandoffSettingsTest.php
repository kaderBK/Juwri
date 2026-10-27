<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * WA-S1: the owner decides what happens after checkout.
 *
 * Behaviour used to be hardcoded per theme and inconsistent — some popped a new
 * tab, some navigated in place, lime did nothing at all. These settings are the
 * single authority, and the defaults must reproduce today's behaviour so nothing
 * changes for a store that never touches them.
 */
class WhatsAppHandoffSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Handoff Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
    }

    public function test_the_defaults_reproduce_todays_behaviour(): void
    {
        $settings = whatsapp_handoff_settings($this->store()->id);

        // A store that never opens this page must behave exactly as before.
        $this->assertSame('auto', $settings['mode']);
        $this->assertSame('new', $settings['target']);
        $this->assertSame('branch', $settings['route']);
    }

    public function test_a_store_setting_wins(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_mode', 'button');
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_target', 'same');
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_route', 'fixed');

        $settings = whatsapp_handoff_settings($store->id);

        $this->assertSame('button', $settings['mode']);
        $this->assertSame('same', $settings['target']);
        $this->assertSame('fixed', $settings['route']);
    }

    public function test_the_platform_default_applies_when_the_store_has_none(): void
    {
        config(['settings.whatsapp_handoff_mode' => 'button']);

        $this->assertSame('button', whatsapp_handoff_settings($this->store()->id)['mode']);
    }

    public function test_seconds_become_milliseconds(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_delay', '1.5');

        // The owner thinks in seconds; the browser needs milliseconds.
        $this->assertSame(1500, whatsapp_handoff_settings($store->id)['delay']);
    }

    public function test_a_zero_delay_is_honoured_not_treated_as_absent(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_delay', '0');

        // "Open immediately" is a real choice, not a missing value.
        $this->assertSame(0, whatsapp_handoff_settings($store->id)['delay']);
    }

    public function test_the_delay_is_clamped_to_the_documented_range(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_delay', '99');

        $this->assertSame(5000, whatsapp_handoff_settings($store->id)['delay']);
    }

    public function test_do_nothing_clears_the_url_so_no_theme_can_hand_over(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_mode', 'none');

        // The storefront contract: "none" must remove the URL entirely rather than
        // trusting 17 themes to each honour a mode flag.
        $this->assertSame('none', whatsapp_handoff_settings($store->id)['mode']);
    }

    public function test_a_nonsense_value_falls_back_rather_than_reaching_the_browser(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_mode', 'explode');
        StoreOption::updateStoreOption($store->id, 'whatsapp_handoff_target', 'sideways');

        $settings = whatsapp_handoff_settings($store->id);

        $this->assertSame('auto', $settings['mode']);
        $this->assertSame('new', $settings['target']);
    }
}
