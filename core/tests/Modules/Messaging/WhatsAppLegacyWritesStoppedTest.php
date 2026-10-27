<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * WA-E1: core no longer writes the legacy `whatsapp_number*` options.
 *
 * They belong to branches now. A write here would resurrect a row the migration
 * had retired, and would make WA-D4's read-log — Lane E's evidence that nothing
 * depends on these options — describe a world we ourselves keep re-creating.
 * Reads still fall back while the rows exist; only the write is gone.
 */
class WhatsAppLegacyWritesStoppedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
        $this->owner = User::factory()->create(['status' => 1]);
    }

    private function store(): Store
    {
        return Store::create([
            'user_id' => $this->owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA E1 Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '966500000000',
        ]);
    }

    public function test_saving_the_settings_page_leaves_the_legacy_rows_untouched(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '447700900201');
        StoreOption::updateStoreOption($store->id, 'whatsapp_number2', '447700900202');

        $this->actingAs($this->owner)
            ->post(route('stores.whatsappSettings', $store->id), [
                'whatsapp_provider' => 'wame',
                'whatsapp_message' => 'Hello',
                // A hostile or stale form posting the old fields must not win.
                'whatsapp_number' => '999999999999',
                'whatsapp_number2' => '',
            ])
            ->assertRedirect();

        // Loose compare: store_options() hands numeric strings back as ints.
        $this->assertEquals('447700900201', store_options($store->id, 'whatsapp_number'));
        $this->assertEquals('447700900202', store_options($store->id, 'whatsapp_number2'));
        // The page still saves what it does own.
        $this->assertSame('Hello', store_options($store->id, 'whatsapp_message'));
    }

    public function test_a_store_with_no_legacy_row_does_not_gain_one(): void
    {
        $store = $this->store();

        $this->actingAs($this->owner)
            ->post(route('stores.whatsappSettings', $store->id), [
                'whatsapp_provider' => 'wame',
                'whatsapp_number' => '447700900201',
            ])
            ->assertRedirect();

        // getStoreOption() returns FALSE for an absent option, not null.
        $this->assertFalse(store_options($store->id, 'whatsapp_number'));
    }

    public function test_reads_still_fall_back_to_the_legacy_option(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '447700900201');
        Branch::where('store_id', $store->id)->update(['whatsapp' => null]);

        // E1 stops the write, not the read — E3 is what removes the row.
        $this->assertSame('447700900201', storefront_whatsapp_number($store->id, null, false));
    }

    public function test_the_order_path_resolves_through_the_shared_helper(): void
    {
        /* WA-E3 regression. StoreController used to re-implement the number chain
           inline and, unlike storefront_whatsapp_number(), had no final fallback to
           the store phone. The moment E3 deleted the legacy options, an order that
           carried no branch went out as "phone=" with nothing after it — order
           #99804 did exactly that on the live dev data.

           A grep, not a checkout: it pins the shape that caused the outage, and it
           fails loudly if anyone reintroduces a direct read. */
        $source = file_get_contents(app_path('Http/Controllers/User/StoreController.php'));

        $this->assertStringContainsString(
            'storefront_whatsapp_number($store',
            $source,
            'the order path must resolve the WhatsApp number through the shared helper'
        );

        $this->assertStringNotContainsString(
            'store_options(\$store->id, \'whatsapp_number\')',
            $source,
            'the order path must not read the legacy option directly — it has no store-phone fallback'
        );
    }
}
