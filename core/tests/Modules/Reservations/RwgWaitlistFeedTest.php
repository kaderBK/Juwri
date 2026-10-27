<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\WaitlistServiceFeedBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane D (D5): waitlist Services-feed contribution snapshot.
 */
class RwgWaitlistFeedTest extends TestCase
{
    use RefreshDatabase;

    private function makeStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'Plan ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Restaurant ' . Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 Test St',
            'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Enabled store → Service entry matches the committed fixture (structure, no timestamps). */
    public function test_service_entry_matches_fixture(): void
    {
        $store = $this->makeStore();
        StoreSettings::set($store->id, 'rwg_waitlist_enabled', '1');
        StoreSettings::set($store->id, 'waitlist_max_party', '8');

        $out = new WaitlistServiceFeedBuilder()->buildService($store, 'store-3425');

        $expected = json_decode(file_get_contents(base_path('tests/Fixtures/rwg/waitlist_service.json')), true);
        $this->assertSame($expected, $out);
    }

    /** Default max party size (12) when the option is unset. */
    public function test_default_max_party_when_unset(): void
    {
        $store = $this->makeStore();
        StoreSettings::set($store->id, 'rwg_waitlist_enabled', '1');

        $out = new WaitlistServiceFeedBuilder()->buildService($store, 'm1');
        $this->assertSame(12, $out['waitlist_rules']['max_party_size']);
        $this->assertFalse($out['waitlist_rules']['supports_additional_request']);
    }

    /** Store that never opted in emits nothing (no Google waitlist offer). */
    public function test_null_when_not_opted_in(): void
    {
        $store = $this->makeStore();
        $this->assertNull(new WaitlistServiceFeedBuilder()->buildService($store, 'm1'));
    }
}
