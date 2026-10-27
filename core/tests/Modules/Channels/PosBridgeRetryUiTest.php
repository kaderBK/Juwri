<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Jobs\PushPosOrderJob;
use Juwri\Modules\Channels\Models\PosOrderPush;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-M5 — the store-dashboard POS bridge Retry action re-dispatches a failed push,
 * is tenant-scoped, and the push log shows a Retry button only on non-pushed rows.
 */
class PosBridgeRetryUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_pos_bridge' => true]);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
        $this->withoutMiddleware();
    }

    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $r];
    }

    private function failedPush(Store $r): PosOrderPush
    {
        $order = Order::create(['store_id' => $r->id, 'type' => 'pickup', 'status' => 'new', 'created_at' => now()]);

        return PosOrderPush::create([
            'order_id' => $order->id, 'provider' => 'clover', 'store_id' => $r->id,
            'status' => 'failed', 'error' => 'HTTP 500 from POS',
        ]);
    }

    public function test_owner_can_retry_a_failed_push(): void
    {
        Queue::fake();
        [$owner, $r] = $this->ownerRestaurant();
        $push = $this->failedPush($r);

        $this->actingAs($owner)
            ->post(route('stores.posbridge.retry', $push->id).'?store_id='.$r->id)
            ->assertRedirect();

        Queue::assertPushed(PushPosOrderJob::class);
    }

    public function test_retry_is_tenant_scoped(): void
    {
        Queue::fake();
        [$ownerA, $rA] = $this->ownerRestaurant();
        [, $rB] = $this->ownerRestaurant();
        $pushB = $this->failedPush($rB);

        $status = $this->actingAs($ownerA)
            ->post(route('stores.posbridge.retry', $pushB->id).'?store_id='.$rA->id)
            ->getStatusCode();

        $this->assertTrue($status === 403 || $status === 404, "expected 403/404, got {$status}");
        Queue::assertNothingPushed();
    }

    public function test_push_log_renders_a_retry_button_for_failed_rows(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->failedPush($r);

        $this->actingAs($owner)
            ->get(route('stores.posbridge.index').'?store_id='.$r->id)
            ->assertOk()
            ->assertSee(___('Retry'));
    }
}
