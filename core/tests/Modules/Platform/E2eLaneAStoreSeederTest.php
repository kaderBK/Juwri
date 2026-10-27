<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Database\Seeders\E2eLaneAStoreSeeder;
use Tests\TestCase;

/**
 * The fixture store the e2e journeys use when the Community store limit refuses a second one (tests/e2e/lib/lane-a.js
 * ensureStore): it must behave like a store made in the dashboard wizard, i.e. take orders (before: no ordering options,
 * so b-accounting / d-kiosk / e-driver found "not accepting orders").
 */
class E2eLaneAStoreSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_fixture_store_is_created_with_the_wizards_ordering_defaults_and_is_idempotent(): void
    {
        $owner = User::factory()->create();
        putenv('E2E_STORE_OWNER_EMAIL='.$owner->email);
        putenv('E2E_STORE_SLUG=e2ea-fixture');
        putenv('E2E_STORE_TITLE=Fixture');
        try {
            (new E2eLaneAStoreSeeder)->run();
            (new E2eLaneAStoreSeeder)->run();
        } finally {
            putenv('E2E_STORE_OWNER_EMAIL');
            putenv('E2E_STORE_SLUG');
            putenv('E2E_STORE_TITLE');
        }

        $this->assertSame(1, Store::withoutGlobalScopes()->where('slug', 'e2ea-fixture')->count());
        $store = Store::withoutGlobalScopes()->where('slug', 'e2ea-fixture')->firstOrFail();
        $this->assertTrue(StoreSettings::bool((int) $store->id, 'restaurant_on_table_order'));
        $this->assertTrue(StoreSettings::bool((int) $store->id, 'open_close_store'));
    }
}
