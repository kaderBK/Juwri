<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C10 — RTL / non-English rendering. Every hotel test previously pinned locale 'en', so
 * the module's primary market (Arabic) was never rendered in CI. These pin that the three
 * guest-facing surfaces render under 'ar': guest-info and guest-folio emit dir="rtl", and
 * the injected guest layer renders its markers (its CSS is already logical-property based).
 */
class HotelGuestRtlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('ar');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function hotelStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'hotel' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreSettings::set($store->id, 'hotel_mode', '1');
        StoreSettings::set($store->id, 'hotel_settings', json_encode([
            'wifi' => ['name' => 'HotelNet', 'password' => 'sekret'],
            'house_rules' => 'No smoking',
        ]));

        return $store;
    }

    private function room(Store $store): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => 204, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Room 204',
        ]);
    }

    public function test_guest_info_renders_rtl(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);

        $this->get(route('hotel.guest.info', $store->slug).'?table='.$room->key)->assertOk()->assertSeeHtml('dir="rtl"');
    }

    public function test_guest_folio_renders_rtl(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        $stay = HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');

        $this->get(route('hotel.guest.folio', ['slug' => $store->slug, 'token' => $stay->token]))->assertOk()->assertSeeHtml('dir="rtl"');
    }

    public function test_guest_layer_renders_under_ar(): void
    {
        $store = $this->hotelStore();
        $room = $this->room($store);
        HotelFolioService::checkIn($store->id, $room, 'Sam Carter', '555-1');
        $html = Blade::render(
            "@include('store_templates._partials.hotel-guest')",
            ['store' => $store, 'table' => $room, 'roomVerified' => true],
            deleteCachedView: true
        );

        // Structural (locale-independent) markers must survive an RTL render.
        $this->assertStringContainsString('id="mbHotelChip"', $html);
        $this->assertStringContainsString('id="mbHotelChargeToggle"', $html);
        // The layer's CSS is logical-property based, so it mirrors correctly under RTL.
        $this->assertStringContainsString('text-align:start', $html);
    }
}
