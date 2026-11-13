<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\TestCase;

/**
 * WA-E3: deleting the legacy WhatsApp options.
 *
 * This is the one irreversible step in the run, so what is tested here is mostly
 * what stops it: it is not in the default run, and it refuses while any live store
 * would lose its only number.
 */
class WhatsAppPurgeLegacyTest extends TestCase
{
    use RefreshDatabase;

    private function store(?string $legacyNumber = null): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA E3 Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '966500000000',
        ]);

        if ($legacyNumber !== null) {
            StoreOption::updateStoreOption($store->id, 'whatsapp_number', $legacyNumber);
        }

        return $store;
    }

    public function test_a_bare_run_never_deletes_anything(): void
    {
        $store = $this->store('447700900201');

        // The default run migrates; it must not be able to destroy.
        $this->artisan('whatsapp:migrate-legacy')->assertSuccessful();

        $this->assertTrue(
            StoreSettings::has((int) $store->id, 'whatsapp_number')
        );
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $store = $this->store('447700900201');
        Branch::where('store_id', $store->id)->update(['whatsapp' => '447700900201']);

        $this->artisan('whatsapp:migrate-legacy --only=purge --dry-run')->assertSuccessful();

        $this->assertTrue(
            StoreSettings::has((int) $store->id, 'whatsapp_number')
        );
    }

    public function test_it_refuses_while_a_live_store_still_has_no_branch_number(): void
    {
        $store = $this->store('447700900201');
        Branch::where('store_id', $store->id)->update(['whatsapp' => null]);

        // The option row is the only copy of that number. Deleting it here would
        // not retire the number, it would destroy it.
        $this->artisan('whatsapp:migrate-legacy --only=purge')->assertSuccessful();

        $this->assertTrue(
            StoreSettings::has((int) $store->id, 'whatsapp_number')
        );
    }

    public function test_it_purges_once_every_number_is_safely_on_a_branch(): void
    {
        $store = $this->store('447700900201');
        StoreOption::updateStoreOption($store->id, 'whatsapp_number3', '447700900203');
        StoreOption::updateStoreOption($store->id, 'quick_order_number', '447700900200');
        Branch::where('store_id', $store->id)->update(['whatsapp' => '447700900201']);

        $this->artisan('whatsapp:migrate-legacy --only=purge')->assertSuccessful();

        foreach (['whatsapp_number', 'whatsapp_number3', 'quick_order_number'] as $key) {
            $this->assertFalse(
                StoreSettings::has((int) $store->id, $key),
                $key.' should be gone'
            );
        }

        // The store still hands off — from the branch, which is the whole point.
        $this->assertSame('447700900201', storefront_whatsapp_number($store->id, null, false));
    }

    /* An orphaned row — one whose store has been deleted — cannot be reproduced
       here: the test database runs with DB_FOREIGN_KEYS=true, so store_options
       cascades away with its post. Production MySQL does not enforce it, which is
       exactly why 52 such rows exist there. The purge deletes them by option_name
       regardless of store_id, and the real run below reports the count. */

    public function test_running_it_twice_is_a_no_op(): void
    {
        $store = $this->store('447700900201');
        Branch::where('store_id', $store->id)->update(['whatsapp' => '447700900201']);

        $this->artisan('whatsapp:migrate-legacy --only=purge')->assertSuccessful();
        $this->artisan('whatsapp:migrate-legacy --only=purge')->assertSuccessful();

        $this->assertSame(0, count(StoreSettings::valuesFor('whatsapp_number')));
    }
}
