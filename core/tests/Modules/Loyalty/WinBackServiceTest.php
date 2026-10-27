<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Mails\CampaignMail;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\StoreOption;
use App\Services\Marketing\WinBackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B2-P2: win-back engine.
 */
class WinBackServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_marketing_automation' => true]);
    }

    private function storeFixture(array $opts = ['winback_enabled' => '1']): Store
    {
        $store = Store::create([
            'user_id' => User::factory()->create(['status' => 1])->id,
            'slug' => Str::slug('R').'-'.Str::random(5), 'color' => '#111', 'title' => 'Pizza', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($opts as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    private function customer(Store $store, array $attrs): Customer
    {
        return Customer::create(array_merge([
            'store_id' => $store->id, 'name' => 'Sam', 'email' => 'sam'.Str::random(4).'@x.com',
            'phone' => UniquePhone::next('+1555'), 'marketing_consent' => true, 'sms_consent' => false,
        ], $attrs));
    }

    public function test_lapsed_consenting_customer_gets_one_coupon(): void
    {
        Mail::fake();
        $store = $this->storeFixture();
        $c = $this->customer($store, ['last_order_at' => now()->subDays(45)]);

        $res = app(WinBackService::class)->run();

        $this->assertSame(1, $res['offers']);
        $this->assertSame(1, Coupon::where('user_id', $store->user_id)->where('code', 'like', 'WB%')->count());
        Mail::assertQueued(CampaignMail::class, 1);
        $this->assertNotNull($c->fresh()->last_winback_at);
    }

    public function test_recent_customer_below_threshold_skipped(): void
    {
        $store = $this->storeFixture();
        $this->customer($store, ['last_order_at' => now()->subDays(10)]); // < 30 default

        $this->assertSame(0, app(WinBackService::class)->run()['offers']);
    }

    public function test_non_consenting_customer_never_contacted(): void
    {
        Mail::fake();
        $store = $this->storeFixture();
        $this->customer($store, ['last_order_at' => now()->subDays(60), 'marketing_consent' => false, 'sms_consent' => false]);

        $this->assertSame(0, app(WinBackService::class)->run()['offers']);
        Mail::assertNothingOutgoing();
    }

    public function test_idempotent_within_frequency_window(): void
    {
        Mail::fake();
        $store = $this->storeFixture();
        $this->customer($store, ['last_order_at' => now()->subDays(60)]);

        $this->assertSame(1, app(WinBackService::class)->run()['offers']);
        // Second run same day → frequency cap blocks it.
        $this->assertSame(0, app(WinBackService::class)->run()['offers']);
        $this->assertSame(1, Coupon::where('code', 'like', 'WB%')->count());
    }

    public function test_reissues_after_frequency_window(): void
    {
        Mail::fake();
        $store = $this->storeFixture(['winback_enabled' => '1', 'winback_frequency_days' => '90']);
        $c = $this->customer($store, ['last_order_at' => now()->subDays(120), 'last_winback_at' => now()->subDays(100)]);

        $this->assertSame(1, app(WinBackService::class)->run()['offers']);
    }

    public function test_disabled_owner_and_killswitch_are_noop(): void
    {
        $store = $this->storeFixture(['winback_enabled' => '0']);
        $this->customer($store, ['last_order_at' => now()->subDays(60)]);
        $this->assertSame(0, app(WinBackService::class)->run()['offers']);

        // Owner enabled but platform kill-switch off.
        StoreOption::updateStoreOption($store->id, 'winback_enabled', '1');
        config(['settings.admin_allow_marketing_automation' => false]);
        $this->assertSame(0, app(WinBackService::class)->run()['offers']);
    }

    public function test_command_runs(): void
    {
        $this->artisan('marketing:win-back')->assertExitCode(0);
    }
}
