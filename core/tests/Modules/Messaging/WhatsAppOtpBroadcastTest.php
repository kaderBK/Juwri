<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Controllers\User\WhatsAppCampaignController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Juwri\Modules\Messaging\Models\WhatsAppConsent;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppBroadcast;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppOtp;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WhatsAppOtpBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('R').'-'.Str::random(5), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // --- OTP --------------------------------------------------------------

    public function test_otp_send_then_verify_consumes_code(): void
    {
        $otp = app(WhatsAppOtp::class);
        $otp->send('+15551234567');

        // Grab the code we stored, simulate the user entering it.
        $code = Cache::get('wa_otp:0:+15551234567');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertDatabaseHas('whatsapp_logs', ['feature' => 'otp']);

        $this->assertTrue($otp->verify('+15551234567', $code));
        // Second use fails (consumed).
        $this->assertFalse($otp->verify('+15551234567', $code));
    }

    public function test_otp_wrong_code_fails(): void
    {
        $otp = app(WhatsAppOtp::class);
        $otp->send('+15550000000');
        $this->assertFalse($otp->verify('+15550000000', '000000-wrong'));
    }

    // --- Broadcast --------------------------------------------------------

    public function test_broadcast_queues_only_opted_in_recipients(): void
    {
        Bus::fake();
        $store = $this->storeFixture(User::factory()->create(['status' => 1]));

        WhatsAppConsent::optIn('+1AA', $store->id, 'web');
        WhatsAppConsent::optIn('+1BB', $store->id, 'order');
        WhatsAppConsent::optIn('+1CC', $store->id, 'web');
        WhatsAppConsent::optOut('+1CC', $store->id); // withdrew

        $count = app(WhatsAppBroadcast::class)->send($store->id, 'Promo!', 'promo_offer');

        $this->assertSame(2, $count);
        Bus::assertDispatched(SendWhatsAppJob::class, 2);
    }

    public function test_campaign_controller_sends_and_authorises(): void
    {
        Bus::fake();
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);
        WhatsAppConsent::optIn('+1AA', $store->id, 'web');
        $this->actingAs($owner);

        app(WhatsAppCampaignController::class)->send(
            Request::create('/x', 'POST', ['body' => 'hi', 'template' => 'promo_offer']),
            $store,
            app(WhatsAppBroadcast::class)
        );

        Bus::assertDispatched(SendWhatsAppJob::class, 1);
    }

    public function test_campaign_controller_forbids_non_owner(): void
    {
        $store = $this->storeFixture(User::factory()->create(['status' => 1]));
        $this->actingAs(User::factory()->create(['status' => 1]));

        $this->expectException(HttpException::class);
        app(WhatsAppCampaignController::class)->send(
            Request::create('/x', 'POST', ['body' => 'hi']),
            $store,
            app(WhatsAppBroadcast::class)
        );
    }
}
