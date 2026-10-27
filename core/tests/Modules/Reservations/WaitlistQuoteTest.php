<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\WaitlistEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Models\SmsLog;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C8 — waitlist quoted wait. The dashboard rows gain a LIVE "~N min" estimate
 * chip (position × avg-turn, recomputed) and the "table ready" notify SMS gains
 * the estimate. Distinct from the frozen `quoted_wait` captured at join time.
 */
class WaitlistQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'Plan '.Str::random(6),
            'description' => 'Test',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]),
            'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'Restaurant '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'Test',
            'description' => 'Test', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 Test St',
            'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEntry(Store $store, ?string $phone = null): WaitlistEntry
    {
        return WaitlistEntry::create([
            'store_id' => $store->id,
            'name' => 'Guest '.Str::random(4),
            'phone' => $phone,
            'party_size' => 2,
            'status' => 'waiting',
            'quoted_wait' => 20,
        ]);
    }

    /** Model: estimate = position × avg-turn, and the avg-turn is caller-supplied. */
    public function test_estimated_wait_scales_with_position_and_option(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $first = $this->makeEntry($store);
        $second = $this->makeEntry($store);

        // Default 20-min turn.
        $this->assertSame(20, $first->estimatedWait());
        $this->assertSame(40, $second->estimatedWait());

        // Override respected.
        $this->assertSame(15, $first->estimatedWait(15));
        $this->assertSame(30, $second->estimatedWait(15));
    }

    /** Dashboard chip reflects the option-overridden avg-turn for each queue row. */
    public function test_chip_renders_estimate_with_avg_turn_option(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        StoreSettings::set($store->id, 'waitlist_avg_turn', '15');

        $this->makeEntry($store); // position 1 → 15
        $this->makeEntry($store); // position 2 → 30

        $res = $this->actingAs($owner)->get(route('waitlist.index', $store->id));
        $res->assertOk();
        // Live chips, one per row, using the overridden 15-min turn.
        $res->assertSee('~15');
        $res->assertSee('~30');
        // Avg-turn header reflects the option, not the 20 default.
        $res->assertSee('15m');
    }

    /** The "table ready" notify SMS carries the estimate. */
    public function test_notify_message_contains_estimate(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $entry = $this->makeEntry($store, '+16505551234'); // position 1, default 20-min turn

        $this->actingAs($owner)
            ->post(route('waitlist.notify', [$store->id, $entry->id]))
            ->assertRedirect();

        $log = SmsLog::where('feature', 'waitlist')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('~20 min', $log->message_preview);
    }
}
