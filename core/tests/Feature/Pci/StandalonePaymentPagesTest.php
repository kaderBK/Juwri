<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Pci;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Reservation;
use App\Models\StoreOption;
use App\Services\Catering\CateringService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\AssertsPaymentPagePolicy;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PCI DSS 4.0 req. 6.4.3: the standalone customer payment pages (gift-card purchase, reservation deposit, catering
 * deposit) are served with the strict payment CSP and only inventory scripts — their inline <style> carries the nonce
 * (it did not before, so an enforced policy dropped the deposit pages' styling).
 */
class StandalonePaymentPagesTest extends TestCase
{
    use AssertsPaymentPagePolicy;
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        view()->share(['languages' => collect(), 'pages' => collect()]);
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
        ]);

        $plan = TestPlans::create(['name' => 'P'.Str::random(6), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->store = Store::create(['user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['currency_code' => 'USD', 'restaurant_stripe_install' => '1', 'restaurant_stripe_title' => 'Card',
            'restaurant_stripe_secret_key' => 'sk_test_store_own'] as $key => $value) {
            StoreOption::updateStoreOption($this->store->id, $key, $value);
        }
    }

    public function test_the_gift_card_purchase_page(): void
    {
        config(['settings.admin_allow_gift_cards' => 1]);
        FeatureRegistry::clearCache();
        Feature::updateOrCreate(['key' => 'gift_cards'], ['label' => 'Gift Cards', 'plan_gated' => false]);
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
        StoreSettings::set($this->store->id, 'gift_cards_enabled', '1');

        $res = $this->get(route('store.giftcards.buy', $this->store->id))->assertOk();

        $this->assertStrictPaymentPolicy($res, 'giftcards.buy');
    }

    public function test_the_reservation_deposit_page(): void
    {
        foreach (['enable_reservations' => '1', 'reservation_deposit_enabled' => '1', 'reservation_deposit_amount' => 10,
            'reservation_deposit_per' => 'guest', 'reservation_deposit_min_party' => 1, 'reservation_deposit_refund' => 'full'] as $key => $value) {
            StoreOption::updateStoreOption($this->store->id, $key, $value);
        }
        $reservation = Reservation::create(['store_id' => $this->store->id, 'name' => 'Guest', 'party_size' => 2,
            'reserved_at' => now()->addDay(), 'status' => 'pending', 'token' => Str::random(40)]);

        $res = $this->get(route('publicView.reservation.deposit', ['slug' => $this->store->slug, 'token' => $reservation->token]))->assertOk();

        $this->assertStrictPaymentPolicy($res, 'reservations.deposit');
        $this->assertMatchesRegularExpression('/<style nonce="[^"]+">/', (string) $res->getContent());
    }

    public function test_the_catering_deposit_page(): void
    {
        config(['settings.admin_allow_catering' => true]);
        foreach (['catering_enabled' => '1', 'catering_deposit_enabled' => '1', 'catering_deposit_amount' => 100] as $key => $value) {
            StoreOption::updateStoreOption($this->store->id, $key, $value);
        }
        $service = new CateringService;
        $request = $service->createRequest($this->store->id, ['name' => 'Sam', 'phone' => '123', 'event_at' => now()->addDays(30)->toDateTimeString(), 'headcount' => 20]);
        $request = $service->armDeposit($request)->fresh();

        $res = $this->get(route('publicView.catering.deposit', ['slug' => $this->store->slug, 'token' => $request->token]))->assertOk();

        $this->assertStrictPaymentPolicy($res, 'catering.deposit');
        $this->assertMatchesRegularExpression('/<style nonce="[^"]+">/', (string) $res->getContent());
    }
}
