<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use App\Mails\CampaignMail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B2-P1: multi-channel cart recovery.
 */
class CartRecoveryMultiChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_marketing_automation' => true]);
    }

    private function storeFixture(array $opts = []): Store
    {
        $store = Store::create([
            'user_id' => User::factory()->create(['status' => 1])->id,
            'slug' => Str::slug('R').'-'.Str::random(5), 'color' => '#111', 'title' => 'Pizza', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($opts as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    private function customer(Store $store, bool $marketing, bool $sms): Customer
    {
        return Customer::create([
            'store_id' => $store->id, 'name' => 'Sam', 'email' => 'sam@x.com', 'phone' => '+15551234567',
            'marketing_consent' => $marketing, 'sms_consent' => $sms,
        ]);
    }

    private function order(Store $store, ?Customer $c = null, int $ageMinutes = 40): Order
    {
        $o = Order::create([
            'store_id' => $store->id, 'customer_name' => 'Sam', 'phone_number' => '+15551234567',
            'customer_id' => $c?->id, 'status' => 'unpaid', 'track_token' => Str::random(32),
        ]);
        $o->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $o;
    }

    public function test_whatsapp_only_when_opted_in(): void
    {
        Bus::fake();
        Mail::fake();
        $store = $this->storeFixture(['customer_cart_whatsapp' => '1']);
        $order = $this->order($store);

        $this->artisan('marketing:cart-recovery')->assertExitCode(0);

        Bus::assertDispatched(SendWhatsAppJob::class, 1);
        Mail::assertNothingOutgoing();
        $this->assertSame('whatsapp', $order->fresh()->cart_recovery_channel);
    }

    public function test_whatsapp_nudge_is_flagged_marketing_so_it_honors_opt_out(): void
    {
        Bus::fake();
        $store = $this->storeFixture(['customer_cart_whatsapp' => '1']);
        $this->order($store);

        $this->artisan('marketing:cart-recovery')->assertExitCode(0);

        // Cart recovery is a re-engagement (marketing) message. Dispatched transactional
        // (marketing=false) it bypassed WhatsAppService's opt-out + consent + daily cap,
        // so a customer who replied STOP still got nudged. It must go as marketing.
        Bus::assertDispatched(SendWhatsAppJob::class, fn ($job) => $job->marketing === true && $job->feature === 'cart_recovery');
    }

    public function test_email_only_for_consenting_customer(): void
    {
        Bus::fake();
        Mail::fake();
        $store = $this->storeFixture(['customer_cart_email' => '1']);
        $customer = $this->customer($store, marketing: true, sms: false);
        $order = $this->order($store, $customer);

        $this->artisan('marketing:cart-recovery')->assertExitCode(0);

        Mail::assertQueued(CampaignMail::class, 1);
        Bus::assertNotDispatched(SendWhatsAppJob::class);
        $this->assertSame('email', $order->fresh()->cart_recovery_channel);
    }

    public function test_email_skipped_without_marketing_consent(): void
    {
        Mail::fake();
        $store = $this->storeFixture(['customer_cart_email' => '1']);
        $customer = $this->customer($store, marketing: false, sms: false);
        $order = $this->order($store, $customer);

        $this->artisan('marketing:cart-recovery')->assertExitCode(0);

        Mail::assertNothingOutgoing();
        $this->assertNull($order->fresh()->cart_recovery_channel);
        $this->assertNull($order->fresh()->wa_cart_recovery_at, 'no channel sent → not marked');
    }

    public function test_sms_requires_known_consenting_customer(): void
    {
        $store = $this->storeFixture(['customer_cart_sms' => '1']);
        // Guest order (no customer) → no SMS despite the opt-in.
        $guest = $this->order($store, null);
        $this->artisan('marketing:cart-recovery')->assertExitCode(0);
        $this->assertNull($guest->fresh()->cart_recovery_channel);

        // Known customer with sms_consent → SMS.
        $customer = $this->customer($store, marketing: false, sms: true);
        $order = $this->order($store, $customer);
        $this->artisan('marketing:cart-recovery')->assertExitCode(0);
        $this->assertSame('sms', $order->fresh()->cart_recovery_channel);
    }

    public function test_multi_channel_fanout_and_one_shot(): void
    {
        Bus::fake();
        Mail::fake();
        $store = $this->storeFixture(['customer_cart_whatsapp' => '1', 'customer_cart_email' => '1']);
        $customer = $this->customer($store, marketing: true, sms: false);
        $order = $this->order($store, $customer);

        $this->artisan('marketing:cart-recovery')->assertExitCode(0);
        $this->assertSame('whatsapp,email', $order->fresh()->cart_recovery_channel);

        // Second run: already marked → nothing re-sent.
        Bus::fake();
        Mail::fake();
        $this->artisan('marketing:cart-recovery')->assertExitCode(0);
        Bus::assertNotDispatched(SendWhatsAppJob::class);
        Mail::assertNothingOutgoing();
    }

    public function test_legacy_whatsapp_command_still_works(): void
    {
        Bus::fake();
        $store = $this->storeFixture(['customer_cart_whatsapp' => '1']);
        $order = $this->order($store);

        $this->artisan('whatsapp:cart-recovery')->assertExitCode(0);
        Bus::assertDispatched(SendWhatsAppJob::class, 1);
        $this->assertSame('whatsapp', $order->fresh()->cart_recovery_channel);
    }

    public function test_kill_switch_stops_all_recovery(): void
    {
        Bus::fake();
        config(['settings.admin_allow_marketing_automation' => false]);
        $store = $this->storeFixture(['customer_cart_whatsapp' => '1']);
        $order = $this->order($store);

        $this->artisan('marketing:cart-recovery')->assertExitCode(0);

        Bus::assertNotDispatched(SendWhatsAppJob::class);
        $this->assertNull($order->fresh()->cart_recovery_channel);
    }
}
