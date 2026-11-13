<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C1 — the reservation deposit notice. ONE shared partial
 * (store_templates/_partials/reservation-deposit-notice.blade.php) is @included by all
 * 17 theme booking sheets, so a diner sees the deposit requirement BEFORE submitting
 * (publicBook redirects to the deposit page after). These render-level tests prove the
 * wiring across representative themes: the notice appears when a deposit is configured
 * and is absent when it is not (so pre-fix — no partial, no @include — they fail).
 */
class ReservationDepositNoticeTest extends TestCase
{
    use RefreshDatabase;

    /** Themes spanning a standard form (crave), aurora, and the JS-driven sheet (picnic). */
    private const THEMES = ['crave', 'aurora', 'picnic'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->shareLayoutGlobals();

        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $theme, array $opts): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        foreach ($opts as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Club', 'price' => 42.00, 'active' => '1']);

        return $store;
    }

    public function test_notice_renders_when_a_deposit_is_configured(): void
    {
        foreach (self::THEMES as $theme) {
            $store = $this->store($theme, [
                'enable_reservations' => '1',
                'reservation_deposit_enabled' => '1',
                'reservation_deposit_amount' => 10,
                'reservation_deposit_per' => 'guest',
                'reservation_deposit_min_party' => 2,
                'currency_code' => 'USD',
            ]);

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();

            $this->assertStringContainsString('data-deposit-notice', $html, "$theme renders the shared deposit notice");
            $this->assertStringContainsString('10.00', $html, "$theme shows the deposit amount");
        }
    }

    public function test_notice_absent_when_no_deposit_configured(): void
    {
        foreach (self::THEMES as $theme) {
            // Reservations on, but NO deposit configured.
            $store = $this->store($theme, ['enable_reservations' => '1']);

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();

            $this->assertStringNotContainsString('data-deposit-notice', $html, "$theme shows no notice without a deposit");
        }
    }
}
