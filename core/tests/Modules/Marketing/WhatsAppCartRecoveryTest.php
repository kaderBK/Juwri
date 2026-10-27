<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Jobs\SendWhatsAppJob;
use Tests\TestCase;

class WhatsAppCartRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_marketing_automation' => true]);
    }

    private function storeFixture(bool $optIn = true): Store
    {
        $store = Store::create([
            'user_id' => User::factory()->create(['status' => 1])->id,
            'slug' => Str::slug('R').'-'.Str::random(5), 'color' => '#111', 'title' => 'Pizza', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($optIn) {
            StoreOption::updateStoreOption($store->id, 'customer_cart_whatsapp', '1');
        }

        return $store;
    }

    private function order(Store $store, string $status, int $ageMinutes): Order
    {
        $o = Order::create([
            'store_id' => $store->id, 'customer_name' => 'Sam', 'phone_number' => '+15551234567',
            'status' => $status, 'track_token' => Str::random(32),
        ]);
        $o->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $o;
    }

    public function test_recovers_eligible_unpaid_order_once(): void
    {
        Bus::fake();
        $store = $this->storeFixture();
        $eligible = $this->order($store, 'unpaid', 40);
        $tooRecent = $this->order($store, 'unpaid', 5);
        $paid = $this->order($store, 'pending', 40);

        $this->artisan('whatsapp:cart-recovery')->assertExitCode(0);

        Bus::assertDispatched(SendWhatsAppJob::class, 1);
        $this->assertNotNull($eligible->fresh()->wa_cart_recovery_at);
        $this->assertNull($tooRecent->fresh()->wa_cart_recovery_at);
        $this->assertNull($paid->fresh()->wa_cart_recovery_at);

        // Second run does not re-send (already marked).
        Bus::fake();
        $this->artisan('whatsapp:cart-recovery')->assertExitCode(0);
        Bus::assertNotDispatched(SendWhatsAppJob::class);
    }

    public function test_skips_when_owner_not_opted_in(): void
    {
        Bus::fake();
        $store = $this->storeFixture(optIn: false);
        $this->order($store, 'unpaid', 40);

        $this->artisan('whatsapp:cart-recovery')->assertExitCode(0);
        Bus::assertNotDispatched(SendWhatsAppJob::class);
    }
}
