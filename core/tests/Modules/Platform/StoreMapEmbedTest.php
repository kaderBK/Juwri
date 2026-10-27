<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * google-suite Lane D · keyless store-map embed on the info page. Renders only
 * when the owner opts in (store_map_embed = '1', default OFF) AND there is
 * something to point at; branch coords win over the store address.
 */
class StoreMapEmbedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        // Use the shared helper (ViewComposerContractTest ratchets against
        // hand-rolled layout doubles).
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class]);
    }

    private function makeStore(string $address = '12 Market St, Testville', array $options = []): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => $address, 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        // An info page must have a body or the route 404s.
        StoreOption::updateStoreOption($store->id, 'sf_page_about', 'About this store.');
        foreach ($options as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    private function infoHtml(Store $store): string
    {
        return $this->get(route('store.infoPage', [$store->slug, 'about']))->assertOk()->getContent();
    }

    public function test_no_map_when_toggle_off(): void
    {
        $store = $this->makeStore(); // store_map_embed unset => OFF

        $this->assertStringNotContainsString('output=embed', $this->infoHtml($store));
    }

    public function test_address_embed_when_enabled_and_no_coords(): void
    {
        $store = $this->makeStore('12 Market St, Testville', ['store_map_embed' => '1']);

        $html = $this->infoHtml($store);

        $this->assertStringContainsString('www.google.com/maps?q=', $html);
        $this->assertStringContainsString('output=embed', $html);
        $this->assertStringContainsString(rawurlencode('12 Market St, Testville'), $html);
        // D4: coords-preferring "open in Maps" link (address form here). Blade
        // escapes & to &amp; in the href, so assert up to the first param.
        $this->assertStringContainsString('www.google.com/maps/search/?api=1', $html);
    }

    public function test_branch_coords_win_over_address(): void
    {
        $store = $this->makeStore('12 Market St, Testville', ['store_map_embed' => '1']);
        Branch::create([
            'store_id' => $store->id, 'name' => 'Main', 'active' => true, 'position' => 1,
            'latitude' => 25.2048000, 'longitude' => 55.2708000,
        ]);

        $html = $this->infoHtml($store);

        // Coords embed present; the address must NOT be the q= value.
        $this->assertStringContainsString('output=embed', $html);
        $this->assertStringContainsString(rawurlencode('25.2048000,55.2708000'), $html);
        $this->assertStringNotContainsString(rawurlencode('12 Market St, Testville'), $html);
    }

    public function test_inactive_branch_coords_ignored_falls_back_to_address(): void
    {
        $store = $this->makeStore('12 Market St, Testville', ['store_map_embed' => '1']);
        Branch::create([
            'store_id' => $store->id, 'name' => 'Closed', 'active' => false, 'position' => 1,
            'latitude' => 25.2048000, 'longitude' => 55.2708000,
        ]);

        $html = $this->infoHtml($store);

        $this->assertStringContainsString(rawurlencode('12 Market St, Testville'), $html);
        $this->assertStringNotContainsString(rawurlencode('25.2048000,55.2708000'), $html);
    }

    public function test_no_map_when_enabled_but_no_coords_and_no_address(): void
    {
        $store = $this->makeStore('', ['store_map_embed' => '1']);

        $this->assertStringNotContainsString('output=embed', $this->infoHtml($store));
    }
}
