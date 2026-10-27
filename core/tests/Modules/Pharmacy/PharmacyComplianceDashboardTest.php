<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pharmacy;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\FeatureAccess;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane F1/F2 — the pharmacy OTC compliance dashboard:
 * age-gate flag (F1) + batch/expiry tracking + low-expiry alert + optional
 * hide-when-expired (F2). A brand-new surface (PharmacyComplianceController),
 * never the shared retail item form — a store without the `age_gate`/`expiry`
 * capability 404s on every route here.
 */
class PharmacyComplianceDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999], 'status' => 1]);

        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        return $user;
    }

    private function makeStore(User $owner, string $type = 'pharmacy'): Store
    {
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ── F1: age-gate ─────────────────────────────────────────────────────

    public function test_owner_can_flag_an_item_as_age_restricted(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'pharmacy');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cough & Cold']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Cough Syrup', 'price' => 7.5, 'active' => 1]);

        $this->assertFalse((bool) $item->age_restricted);

        $this->actingAs($owner)->get(route('pharmacy.compliance.edit', [$store, $item]))
            ->assertOk()->assertSee('Age-restricted item');

        $this->actingAs($owner)->put(route('pharmacy.compliance.update', [$store, $item]), [
            'age_restricted' => '1',
        ])->assertRedirect(route('pharmacy.compliance.index', $store));

        $this->assertTrue((bool) $item->fresh()->age_restricted);

        // Unchecking must persist too (a checkbox omits itself when unchecked).
        $this->actingAs($owner)->put(route('pharmacy.compliance.update', [$store, $item]), [])->assertRedirect();
        $this->assertFalse((bool) $item->fresh()->age_restricted);
    }

    public function test_a_store_without_the_age_gate_or_expiry_capability_gets_a_404(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'retail'); // retail: age_gate=false, expiry=false
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Snacks']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Chips', 'price' => 2, 'active' => 1]);

        $this->actingAs($owner)->get(route('pharmacy.compliance.index', $store))->assertNotFound();
        $this->actingAs($owner)->get(route('pharmacy.compliance.edit', [$store, $item]))->assertNotFound();
        $this->actingAs($owner)->put(route('pharmacy.compliance.update', [$store, $item]), ['age_restricted' => '1'])->assertNotFound();

        $this->assertFalse((bool) $item->fresh()->age_restricted, 'a non-age_gate vertical must never persist age_restricted');
    }

    public function test_another_owners_store_cannot_be_reached(): void
    {
        $ownerA = $this->makeUser();
        $ownerB = $this->makeUser();
        $store = $this->makeStore($ownerA, 'pharmacy');

        $this->actingAs($ownerB)->get(route('pharmacy.compliance.index', $store))->assertNotFound();
    }

    // ── F2: batch/expiry ─────────────────────────────────────────────────

    public function test_owner_can_set_batch_and_expiry_and_sees_expired_and_expiring_soon_alerts(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'pharmacy');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Vitamins']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Vitamin C', 'price' => 9.99, 'active' => 1]);

        $this->actingAs($owner)->put(route('pharmacy.compliance.update', [$store, $item]), [
            'batch_no' => 'VT-001',
            'expiry_date' => now()->addDays(10)->toDateString(),
        ])->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame('VT-001', $fresh->batch_no);
        $this->assertSame(now()->addDays(10)->toDateString(), $fresh->expiry_date->toDateString());
        $this->assertTrue($fresh->isExpiringWithin(30));
        $this->assertFalse($fresh->is_expired);

        // Now expire it and confirm the dashboard surfaces it.
        $expired = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Old Lotion', 'price' => 5, 'active' => 1, 'expiry_date' => now()->subDay()->toDateString(), 'batch_no' => 'OLD-1']);
        $this->assertTrue($expired->fresh()->is_expired);

        $resp = $this->actingAs($owner)->get(route('pharmacy.compliance.index', $store));
        $resp->assertOk()->assertSeeHtml('item(s) are past their expiry date')->assertSeeHtml('item(s) expire within 30 days')
            ->assertSee('Old Lotion')
            ->assertSee('Vitamin C');
    }

    public function test_updating_expiry_date_alone_does_not_clobber_an_existing_batch_no(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'pharmacy');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Vitamins']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Vitamin D', 'price' => 8, 'active' => 1, 'batch_no' => 'VD-1']);

        // A partial save that only touches expiry_date must leave batch_no alone.
        $this->actingAs($owner)->put(route('pharmacy.compliance.update', [$store, $item]), [
            'expiry_date' => now()->addMonth()->toDateString(),
        ])->assertRedirect();

        $this->assertSame('VD-1', $item->fresh()->batch_no, 'a partial update must never clobber a field it did not touch');
    }

    public function test_hide_when_expired_setting_deactivates_an_expired_item_on_save(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'pharmacy');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'First Aid']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Antiseptic', 'price' => 4, 'active' => 1]);

        // Setting is OFF by default: saving a past expiry_date does not hide it.
        $this->actingAs($owner)->put(route('pharmacy.compliance.update', [$store, $item]), [
            'expiry_date' => now()->subDay()->toDateString(),
        ])->assertRedirect();
        $this->assertSame('1', $item->fresh()->getRawOriginal('active'));

        // Turn the setting ON, then re-save (touches expiry_date again) — now it hides.
        $this->actingAs($owner)->put(route('pharmacy.compliance.settings', $store), [
            'hide_expired_items' => '1',
        ])->assertRedirect();

        $this->actingAs($owner)->put(route('pharmacy.compliance.update', [$store, $item]), [
            'expiry_date' => now()->subDay()->toDateString(),
        ])->assertRedirect();
        $this->assertSame('0', $item->fresh()->getRawOriginal('active'), 'hide-when-expired must deactivate an expired item once the store opts in');
    }

    public function test_sweep_command_hides_expired_items_only_for_opted_in_stores(): void
    {
        $owner = $this->makeUser();
        $storeOn = $this->makeStore($owner, 'pharmacy');
        $storeOff = $this->makeStore($owner, 'pharmacy');
        $cat1 = MenuCategory::create(['store_id' => $storeOn->id, 'name' => 'Cat']);
        $cat2 = MenuCategory::create(['store_id' => $storeOff->id, 'name' => 'Cat']);

        $itemOn = Menu::create(['store_id' => $storeOn->id, 'category_id' => $cat1->id, 'name' => 'Expired A', 'price' => 5, 'active' => 1, 'expiry_date' => now()->subDay()->toDateString()]);
        $itemOff = Menu::create(['store_id' => $storeOff->id, 'category_id' => $cat2->id, 'name' => 'Expired B', 'price' => 5, 'active' => 1, 'expiry_date' => now()->subDay()->toDateString()]);

        app(PharmacyComplianceService::class)->setHideExpiredEnabled($storeOn->fresh(), true);

        $this->artisan('pharmacy:sweep-expired')->assertExitCode(0);

        $this->assertSame('0', $itemOn->fresh()->getRawOriginal('active'), 'opted-in store: expired item hidden');
        $this->assertSame('1', $itemOff->fresh()->getRawOriginal('active'), 'opted-out store: untouched');
    }

    public function test_a_restaurant_store_gets_a_404_on_every_pharmacy_route(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner, 'restaurant');

        $this->actingAs($owner)->get(route('pharmacy.compliance.index', $store))->assertNotFound();
    }
}
