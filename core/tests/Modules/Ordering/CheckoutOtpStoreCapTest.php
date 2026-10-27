<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-261: the checkout OTP SMS cap is counted per STORE (the validated body store), not one shared bucket; no-store
 * sends have their own small bucket; one phone has a daily ceiling across stores; a global ceiling backs it all.
 * The OTP sender is a fake — no SMS leaves the test.
 */
class CheckoutOtpStoreCapTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0: string, 1: string}> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Cache::flush();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->withoutMiddleware(ThrottleRequests::class);
        $test = $this;
        $this->app->instance(SmsOtp::class, new class($test) implements SmsOtp
        {
            public function __construct(private $test) {}

            public function request(string $phone, OtpScope $scope): array
            {
                $this->test->sent[] = [$phone, $scope->key];

                return [true, 'sent'];
            }

            public function verify(string $phone, string $code, OtpScope $scope): bool
            {
                return false;
            }

            public function __call($name, $args)
            {
                return null;
            }
        });
        config([
            'abuse.modes.store_cap' => 'enforce',
            'abuse.store_daily_caps.sms' => 3,
            'abuse.store_daily_ip_caps.sms' => 0,
            'abuse.store_daily_caps.sms_platform' => 2,
            'abuse.store_daily_ip_caps.sms_platform' => 0,
            'abuse.checkout_otp.phone_daily' => 0,
            'abuse.store_daily_global_caps.sms' => 0,
        ]);
    }

    private function gatedStore(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'r'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'require_phone_verification', '1');

        return $store;
    }

    private function send(?int $storeId, string $phone)
    {
        return $this->postJson('/checkout/otp/send', array_filter(['phone' => $phone, 'store_id' => $storeId]));
    }

    public function test_store_a_exhausted_does_not_stop_store_b(): void
    {
        $a = $this->gatedStore();
        $b = $this->gatedStore();
        for ($i = 0; $i < 3; $i++) {
            $this->send($a->id, '+96650000000'.$i)->assertOk();
        }
        $this->send($a->id, '+966500000009')->assertStatus(429);
        $this->send($b->id, '+966500000009')->assertOk();
        $this->assertCount(4, $this->sent, 'the refused send never reached the SMS sender');
    }

    public function test_no_store_sends_have_their_own_small_bucket(): void
    {
        $a = $this->gatedStore();
        $this->send(null, '+966511111111')->assertOk();
        $this->send(null, '+966511111112')->assertOk();
        $this->send(null, '+966511111113')->assertStatus(429);
        $this->send($a->id, '+966511111113')->assertOk();
    }

    public function test_store_without_gate_is_refused_and_not_counted(): void
    {
        $a = $this->gatedStore();
        $off = $this->gatedStore();
        StoreOption::updateStoreOption($off->id, 'require_phone_verification', '0');
        for ($i = 0; $i < 5; $i++) {
            $this->send($off->id, '+96652222222'.$i)->assertStatus(422);
        }
        $this->send($a->id, '+966522222229')->assertOk();
        $this->assertCount(1, $this->sent);
    }

    public function test_one_phone_has_a_daily_ceiling_across_stores(): void
    {
        config(['abuse.checkout_otp.phone_daily' => 2]);
        $a = $this->gatedStore();
        $b = $this->gatedStore();
        $this->send($a->id, '+966533333333')->assertOk();
        $this->send($b->id, '+966533333333')->assertOk();
        $this->send($b->id, '+966533333333')->assertStatus(429);
        $this->send($b->id, '+966533333334')->assertOk();
    }

    public function test_global_ceiling_backs_every_store(): void
    {
        config(['abuse.store_daily_global_caps.sms' => 2]);
        $a = $this->gatedStore();
        $b = $this->gatedStore();
        $this->send($a->id, '+966544444441')->assertOk();
        $this->send($b->id, '+966544444442')->assertOk();
        $this->send($b->id, '+966544444443')->assertStatus(429);
    }
}
