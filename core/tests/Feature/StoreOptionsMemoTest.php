<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\RequestEpoch;
use Tests\TestCase;

/**
 * P1 sub-item 4 — the keyless store_options($id) bulk read is memoized per request and
 * seeds the single-key memo, so a storefront render that calls store_options($id) plus
 * several store_options($id,$key) issues one store_options SELECT for that store.
 */
class StoreOptionsMemoTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreSettings::set($store->id, 'currency_sign', '$');
        StoreSettings::set($store->id, 'currency_code', 'USD');
        StoreSettings::set($store->id, 'default_language', 'en');

        return $store;
    }

    public function test_bulk_and_single_key_reads_issue_one_stores_select(): void
    {
        $store = $this->store();

        // Warm the bulk memo (also seeds the single-key memo).
        store_options($store->id);

        $q = 0;
        DB::listen(function ($sql) use (&$q) {
            if (preg_match('/from\s+["`]?stores["`]?/i', $sql->sql)) {
                $q++;
            }
        });

        // Repeat bulk read + several single-key reads — all served from memo.
        store_options($store->id);
        $this->assertSame('$', store_options($store->id, 'currency_sign'));
        $this->assertSame('USD', store_options($store->id, 'currency_code'));
        $this->assertSame('en', store_options($store->id, 'default_language'));

        $this->assertSame(0, $q, 'bulk + single-key reads must not re-query stores after warm-up');
    }

    public function test_writing_an_option_busts_the_memo(): void
    {
        $store = $this->store();
        store_options($store->id); // warm

        StoreOption::updateStoreOption($store->id, 'currency_sign', '€');

        // The write flushed the memo, so the next read reflects the change.
        $this->assertSame('€', store_options($store->id, 'currency_sign'));
        $this->assertSame('€', store_options($store->id)->currency_sign);
    }

    /**
     * p4p: store_currency() read before the store's currency_code is written (a branch saved during store creation)
     * memoised the platform fallback; the settings version restarting at 0 in a new unit of work let the write land on
     * the stamped version, so the fallback survived the write.
     */
    public function test_an_early_currency_read_never_survives_the_currency_write(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < 3; $i++) {
            StoreSettings::set($store->id, 'currency_sign', '$'.$i);
        }
        $seen = StoreSettings::version();

        RequestEpoch::bump(); // a new unit of work
        $this->assertSame(strtoupper((string) config('settings.currency_code', 'USD')), strtoupper(store_currency($store->id)));
        // writes until the version would reach the one stamped before the epoch change
        while (StoreSettings::version() < $seen - 1) {
            StoreSettings::set($store->id, 'default_language', 'en');
        }
        StoreSettings::set($store->id, 'currency_code', 'SAR');

        $this->assertSame('SAR', store_currency($store->id));
        $this->assertSame('SAR', store_options($store->id, 'currency_code'));
    }
}
