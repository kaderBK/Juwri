<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * WA-C1: the order→WhatsApp hand-off is core-owned.
 *
 * It used to be gated on `is_plugin_enabled('quickorder')`, so deactivating that
 * plugin emptied `whatsapp_url` for every store that relied on it — silently, with
 * no error anywhere. These tests pin the new contract: the store's own flag decides,
 * the plugin is irrelevant, and the legacy key still works until WA-C4 renames it.
 */
class WhatsAppOrderingGateTest extends TestCase
{
    use RefreshDatabase;

    /** Post has no factory in this codebase — build one the way the suite does. */
    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Gate Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
    }

    /** Simulate the plugin being active or not, the way is_plugin_enabled() reads it. */
    private function pluginActive(bool $active): void
    {
        PlatformSettings::set('active_plugins', $active ? ['quickorder'] : []);
        config(['settings.active_plugins' => $active ? ['quickorder'] : []]);
    }

    public function test_legacy_flag_still_enables_ordering_with_the_plugin_off(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');
        $this->pluginActive(false);

        // The whole point of C1: no plugin, still enabled.
        $this->assertTrue(whatsapp_ordering_enabled($store->id));
    }

    public function test_plugin_state_does_not_change_the_answer(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');

        $this->pluginActive(true);
        $withPlugin = whatsapp_ordering_enabled($store->id);

        $this->pluginActive(false);
        $withoutPlugin = whatsapp_ordering_enabled($store->id);

        $this->assertTrue($withPlugin);
        $this->assertSame($withPlugin, $withoutPlugin);
    }

    public function test_core_flag_wins_over_the_legacy_key(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');
        StoreOption::updateStoreOption($store->id, 'whatsapp_ordering_enable', '0');

        // C4 will drop the legacy key; until then the new one is authoritative.
        $this->assertFalse(whatsapp_ordering_enabled($store->id));
    }

    public function test_core_flag_alone_enables_ordering(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_ordering_enable', '1');

        $this->assertTrue(whatsapp_ordering_enabled($store->id));
    }

    public function test_a_store_with_neither_flag_is_off(): void
    {
        $this->assertFalse(whatsapp_ordering_enabled($this->store()->id));
    }

    public function test_an_explicitly_disabled_store_is_off(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '0');

        $this->assertFalse(whatsapp_ordering_enabled($store->id));
    }
}
