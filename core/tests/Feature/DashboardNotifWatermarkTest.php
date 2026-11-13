<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\MenuReview;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D11-3 — notification bell read watermark. A count the owner has already seen
 * stops lighting the badge until it increases; the watermark persists per user.
 */
class DashboardNotifWatermarkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    private function ownerWithPendingReviews(int $n): array
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        for ($i = 0; $i < $n; $i++) {
            MenuReview::create(['menu_id' => 1, 'store_id' => $r->id, 'status' => 'pending', 'stars' => 5, 'body' => 'x']);
        }
        return [$owner, $r];
    }

    public function test_badge_clears_once_seen_and_relights_on_increase(): void
    {
        [$owner] = $this->ownerWithPendingReviews(2);

        // Initially the badge is lit (total 2 > watermark 0).
        $json = $this->actingAs($owner)->getJson(route('dashboard.notifications'))->assertOk()->json();
        $this->assertSame(2, $json['total']);
        $this->assertTrue($json['badge']);

        // Opening the bell stamps the watermark to the current total.
        $this->actingAs($owner)->postJson(route('dashboard.notifications.seen'))->assertOk();
        $this->assertSame('2', (string) UserOption::getUserOption($owner->id, 'dashnotif_seen', 0));

        Cache::flush(); // drop the 30s counts cache so we re-read
        $json = $this->actingAs($owner)->getJson(route('dashboard.notifications'))->assertOk()->json();
        $this->assertSame(2, $json['total']);
        $this->assertFalse($json['badge']); // seen → dark

        // A new pending review lifts the total above the watermark → relit.
        MenuReview::create(['menu_id' => 1, 'store_id' => \Juwri\Core\Models\Store::where('user_id', $owner->id)->first()->id, 'status' => 'pending', 'stars' => 4, 'body' => 'y']);
        Cache::flush();
        $json = $this->actingAs($owner)->getJson(route('dashboard.notifications'))->assertOk()->json();
        $this->assertSame(3, $json['total']);
        $this->assertTrue($json['badge']);
    }
}
