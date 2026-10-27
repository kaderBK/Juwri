<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-020: the storefront's own QR scan redirect keeps every param except the scan token. It used to keep only
 * `table`, so a room QR (`qr-id` + `table` + `rt`) lost its room token on the scan and charge-to-room failed for a
 * store that requires the token; `src` and utm params were dropped too.
 */
class StorefrontScanRedirectParamsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    public function test_a_room_qr_scan_keeps_table_src_rt_and_other_params(): void
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'settings' => ['scan_limit' => 999, 'table_qr' => '1', 'hotel' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'store-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Store',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
        StoreSettings::set($store->id, 'hotel_charge_room_token_required', '1');
        $room = $store->tables()->create(['table_no' => 101, 'key' => 'rk-'.Str::random(10), 'seats' => 2, 'area_type' => 'room']);
        $token = app(RoomCharge::class)->mintToken($room);

        $printed = store_public_qr_url($store, $room->key, 'door');
        $this->assertStringContainsString('rt='.$token, $printed);
        $parts = parse_url($printed);

        $location = (string) $this->get($parts['path'].'?'.$parts['query'].'&utm_campaign=lobby')
            ->assertStatus(302)->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $kept);
        $this->assertSame(['src' => 'door', 'table' => $room->key, 'rt' => $token, 'utm_campaign' => 'lobby'], $kept);
        $this->assertSame(1, $store->views()->count());

        // The clean URL stashes the room token, so a later charge-to-room can verify it.
        $this->get($location)->assertOk();
        $this->assertSame($token, session('hotel_room_token'));
    }

    /** Juwri's own qr-id: one counted scan per client network per store per window; the redirect happens every time. */
    public function test_repeated_scans_from_one_client_count_once_per_window(): void
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'settings' => ['scan_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'store-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Store',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
        $parts = parse_url(store_public_qr_url($store));
        $url = $parts['path'].'?'.$parts['query'];

        foreach ([1, 2, 3] as $_) {
            $this->get($url)->assertStatus(302);
        }
        $this->assertSame(1, $store->views()->count());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->get($url)->assertStatus(302);
        $this->assertSame(2, $store->views()->count(), 'another client counts');

        $this->travel(31)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get($url)->assertStatus(302);
        $this->assertSame(3, $store->views()->count(), 'the same client counts again after the window');
    }
}
