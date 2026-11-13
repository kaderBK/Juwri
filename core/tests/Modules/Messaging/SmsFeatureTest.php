<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Messaging\Jobs\SendSmsJob;
use Juwri\Modules\Messaging\Models\SmsLog;
use Juwri\Modules\Messaging\Models\SmsOptout;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Juwri\Modules\Messaging\Services\SmsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    #[DataProvider('phoneCases')]
    public function test_phone_normalization(string $in, string $out): void
    {
        $this->assertSame($out, app(SmsService::class)->normalizePhone($in));
    }

    public static function phoneCases(): array
    {
        return [
            ['+1 (650) 555-1234', '+16505551234'],
            ['00966 50 123 4567', '+966501234567'],
            ['0501234567', '0501234567'],
            ['  ', ''],
        ];
    }

    public function test_marketing_send_is_suppressed_for_opted_out_number(): void
    {
        $phone = '+16505550000';
        SmsOptout::create(['phone' => $phone, 'store_id' => null, 'source' => 'reply']);

        $ok = app(SmsService::class)->send($phone, 'Promo!', 1, 'campaign', true);

        $this->assertTrue($ok); // handled (suppressed), not a failure
        $this->assertDatabaseHas('sms_logs', ['to' => $phone, 'status' => 'skipped_optout', 'feature' => 'campaign']);
    }

    public function test_transactional_send_ignores_optout(): void
    {
        $phone = '+16505550000';
        SmsOptout::create(['phone' => $phone, 'store_id' => null, 'source' => 'reply']);

        app(SmsService::class)->send($phone, 'Order received', 1, 'order', false);

        // No creds in test env -> simulated send still happens (transactional).
        $this->assertDatabaseHas('sms_logs', ['to' => $phone, 'status' => 'simulated', 'feature' => 'order']);
    }

    public function test_otp_request_and_verify_end_to_end(): void
    {
        $svc = app(SmsOtpService::class);
        $scope = OtpScope::store(1);
        [$ok] = $svc->request('+16505551234', $scope);
        $this->assertTrue($ok);

        // The simulated send logs the message, which contains the 6-digit code.
        $preview = SmsLog::where('feature', 'otp')->latest('id')->value('message_preview');
        $this->assertMatchesRegularExpression('/\d{6}/', $preview);
        preg_match('/(\d{6})/', $preview, $m);

        $this->assertFalse($svc->verify('+16505551234', '000000', $scope)); // wrong
        $this->assertTrue($svc->verify('+16505551234', $m[1], $scope));      // correct
        // Single use: cannot verify again.
        $this->assertFalse($svc->verify('+16505551234', $m[1], $scope));
    }

    public function test_otp_request_is_rate_limited(): void
    {
        $svc = app(SmsOtpService::class);
        $this->assertTrue($svc->request('+16505559999', OtpScope::store(1))[0]);
        $this->assertTrue($svc->request('+16505559999', OtpScope::store(1))[0]);
        $this->assertTrue($svc->request('+16505559999', OtpScope::store(1))[0]);
        $this->assertFalse($svc->request('+16505559999', OtpScope::store(1))[0]); // 4th blocked
    }

    public function test_webhook_stop_records_optout_and_start_removes_it(): void
    {
        // This test isolates STOP/START semantics. Production signature
        // enforcement is covered separately in AgentSmsTest.
        config(['sms.inbound_allow_unsigned' => true]);
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => 'sms-optout-test', 'color' => '#111',
            'title' => 'SMS', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Credential::create([
            'store_id' => $store->id,
            'feature' => 'sms',
            'provider' => 'twilio',
            'active' => true,
            'secrets' => ['twilio_from' => '+15559990000'],
        ]);

        $this->post('/webhook/sms/inbound', [
            'From' => '+1 650 555 7777', 'To' => '+15559990000', 'Body' => 'STOP',
        ])
            ->assertOk();
        $this->assertTrue(SmsOptout::isOptedOut('+16505557777'));

        $this->post('/webhook/sms/inbound', [
            'From' => '+16505557777', 'To' => '+15559990000', 'Body' => 'START',
        ])
            ->assertOk();
        $this->assertFalse(SmsOptout::isOptedOut('+16505557777'));
    }

    public function test_order_status_change_dispatches_sms_when_enabled(): void
    {
        Queue::fake();

        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'Bistro', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreSettings::set($store->id, 'customer_status_sms', '1');

        $order = Order::create([
            'store_id' => $store->id, 'phone_number' => '+16505551234', 'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->postJson(route('stores.updateOrderStatus', $order->id), ['status' => 'ready'])
            ->assertOk();

        Queue::assertPushed(SendSmsJob::class, fn ($j) => $j->feature === 'status' && $j->to === '+16505551234');
    }
}
