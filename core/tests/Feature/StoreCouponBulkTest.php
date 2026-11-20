<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Models\CouponBatch;
use Juwri\Modules\Loyalty\Services\CouponBulk;
use Tests\TestCase;

class StoreCouponBulkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        $this->shareLayoutGlobals();

        $storeA = Store::factory()->create();
        $this->owner = User::findOrFail($storeA->user_id);

        $storeB = Store::factory()->create();
        $this->otherOwner = User::findOrFail($storeB->user_id);
    }

    public function test_guest_cannot_access_store_coupon_bulk_routes(): void
    {
        $this->get(route('stores.coupons.bulk.create'))->assertRedirect();
        $this->post(route('stores.coupons.bulk.confirm'), [])->assertRedirect();
        $this->post(route('stores.coupons.bulk.store'), [])->assertRedirect();
        $this->get(route('stores.coupons.batches.index'))->assertRedirect();
        $this->post(route('stores.coupons.bulk.action'), ['action' => 'expire_now', 'apply_to_filtered' => 1])->assertRedirect();
        $this->get(route('stores.coupons.export'))->assertRedirect();
    }

    public function test_owner_can_view_bulk_create_confirm_and_generate_store_batch(): void
    {
        $this->actingAs($this->owner)
            ->get(route('stores.coupons.bulk.create'))
            ->assertOk();

        $payload = [
            'name' => 'Taste Launch',
            'prefix' => 'taste',
            'count' => 5,
            'length' => 8,
            'coupon_type' => 'percentage',
            'percentage' => 20,
            'limit' => 3,
            'per_customer_limit' => 1,
            'expiry_at' => now()->addMonth()->toDateString(),
        ];

        $this->actingAs($this->owner)
            ->post(route('stores.coupons.bulk.confirm'), $payload)
            ->assertOk()
            ->assertSee('TASTE-XXXXXXXX');

        $res = $this->actingAs($this->owner)
            ->post(route('stores.coupons.bulk.store'), $payload);

        $batch = CouponBatch::query()->latest('id')->firstOrFail();
        $res->assertRedirect(route('stores.coupons.batches.show', $batch->id));

        $this->assertSame('owner', $batch->issuer_scope);
        $this->assertSame($this->owner->id, (int) $batch->user_id);
        $this->assertNull($batch->reseller_id);
        $this->assertSame('TASTE', $batch->prefix);
        $this->assertSame(5, $batch->count);

        $coupons = Coupon::query()->where('batch_id', $batch->id)->get();
        $this->assertCount(5, $coupons);
        foreach ($coupons as $c) {
            $this->assertSame('owner', $c->issuer_scope);
            $this->assertSame($this->owner->id, (int) $c->user_id);
            $this->assertSame('food_order', $c->applies_to);
            $this->assertSame('u:'.$this->owner->id, $c->issuer_key);
            $this->assertEquals(20, $c->percentage);
            $this->assertSame(3, (int) $c->limit);
            $this->assertSame(1, (int) $c->per_customer_limit);
            $this->assertMatchesRegularExpression('/^TASTE-[A-Z2-9]{8}$/', $c->code);
        }
    }

    public function test_bulk_create_validates_inputs(): void
    {
        $this->actingAs($this->owner)
            ->post(route('stores.coupons.bulk.confirm'), [
                'prefix' => 'bad prefix!',
                'count' => 1001,
                'coupon_type' => 'percentage',
                'percentage' => 150,
                'limit' => 1,
                'expiry_at' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors(['prefix', 'count', 'percentage', 'expiry_at']);

        $this->assertSame(0, CouponBatch::query()->count());
    }

    public function test_batches_list_and_result_isolate_other_owners_and_platform(): void
    {
        $bulk = app(CouponBulk::class);
        $ownBatch = $bulk->generate(['scope' => 'store', 'user_id' => $this->owner->id], [
            'coupon_type' => 'percentage',
            'percentage' => 15,
            'limit' => 2,
            'expiry_at' => now()->addMonth(),
        ], 3, 'OWN', 8, 'Own Store Batch');

        $otherBatch = $bulk->generate(['scope' => 'store', 'user_id' => $this->otherOwner->id], [
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 1,
        ], 2, 'OTHR', 8, 'Other Owner Batch');

        $platformBatch = $bulk->generate(['scope' => 'platform'], [
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 1,
        ], 2, 'PLAT', 8, 'Platform Batch');

        $this->actingAs($this->owner)
            ->get(route('stores.coupons.batches.index'))
            ->assertOk()
            ->assertSee('Own Store Batch')
            ->assertDontSee('Other Owner Batch')
            ->assertDontSee('Platform Batch');

        $this->actingAs($this->owner)
            ->get(route('stores.coupons.batches.show', $ownBatch->id))
            ->assertOk()
            ->assertSee('OWN-');

        $this->actingAs($this->owner)
            ->get(route('stores.coupons.batches.show', $otherBatch->id))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->get(route('stores.coupons.batches.show', $platformBatch->id))
            ->assertNotFound();
    }

    public function test_index_filters_current_owner_coupons_only(): void
    {
        $bulk = app(CouponBulk::class);
        $batch = $bulk->generate(['scope' => 'store', 'user_id' => $this->owner->id], [
            'coupon_type' => 'fixed',
            'percentage' => 12,
            'limit' => 5,
            'expiry_at' => now()->addMonth(),
        ], 2, 'FLT', 8, 'Filter Batch');

        Coupon::create([
            'code' => 'OWNUSEDUP',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 5,
            'expiry_at' => now()->addMonth(),
            'issuer_scope' => 'owner',
            'user_id' => $this->owner->id,
            'applies_to' => 'food_order',
        ]);

        Coupon::create([
            'code' => 'OTHEROWNERCPN',
            'coupon_type' => 'fixed',
            'percentage' => 10,
            'limit' => 5,
            'used' => 0,
            'issuer_scope' => 'owner',
            'user_id' => $this->otherOwner->id,
            'applies_to' => 'food_order',
        ]);

        Coupon::create([
            'code' => 'PLATCPN',
            'coupon_type' => 'fixed',
            'percentage' => 10,
            'limit' => 5,
            'used' => 0,
            'issuer_scope' => 'platform',
            'applies_to' => 'plan_checkout',
        ]);

        $this->actingAs($this->owner)->get(route('stores.coupons.index', [
            'batch_id' => $batch->id,
            'type' => 'fixed',
            'status' => 'never_used',
        ]))
            ->assertOk()
            ->assertSee('FLT-')
            ->assertDontSee('OWNUSEDUP')
            ->assertDontSee('OTHEROWNERCPN')
            ->assertDontSee('PLATCPN');

        $this->actingAs($this->owner)->get(route('stores.coupons.index', [
            'status' => 'used_up',
        ]))
            ->assertOk()
            ->assertSee('OWNUSEDUP')
            ->assertDontSee('FLT-')
            ->assertDontSee('OTHEROWNERCPN');
    }

    public function test_bulk_actions_operate_on_current_owner_scope_only(): void
    {
        $unused = Coupon::create([
            'code' => 'OWNUNUSED',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 0,
            'expiry_at' => now()->addDays(5),
            'issuer_scope' => 'owner',
            'user_id' => $this->owner->id,
            'applies_to' => 'food_order',
        ]);
        $used = Coupon::create([
            'code' => 'OWNUSED',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 2,
            'expiry_at' => now()->addDays(5),
            'issuer_scope' => 'owner',
            'user_id' => $this->owner->id,
            'applies_to' => 'food_order',
        ]);
        $otherCoupon = Coupon::create([
            'code' => 'OTHERSAFE',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 0,
            'expiry_at' => now()->addDays(5),
            'issuer_scope' => 'owner',
            'user_id' => $this->otherOwner->id,
            'applies_to' => 'food_order',
        ]);
        $platformCoupon = Coupon::create([
            'code' => 'PLATSAFE',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 0,
            'expiry_at' => now()->addDays(5),
            'issuer_scope' => 'platform',
            'applies_to' => 'plan_checkout',
        ]);

        // Extend expiry on selected (including other owner + platform IDs to prove scope isolation)
        $newExpiry = now()->addMonths(3)->startOfMinute();
        $this->actingAs($this->owner)
            ->postJson(route('stores.coupons.bulk.action'), [
                'action' => 'extend_expiry',
                'ids' => [$unused->id, $used->id, $otherCoupon->id, $platformCoupon->id],
                'extend_to' => $newExpiry->toDateTimeString(),
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue(Carbon::parse($unused->fresh()->expiry_at)->equalTo($newExpiry));
        $this->assertFalse(Carbon::parse($otherCoupon->fresh()->expiry_at)->equalTo($newExpiry));
        $this->assertFalse(Carbon::parse($platformCoupon->fresh()->expiry_at)->equalTo($newExpiry));

        // Set limit to 1 (skips $used since used=2 > 1, updates $unused)
        $this->actingAs($this->owner)
            ->postJson(route('stores.coupons.bulk.action'), [
                'action' => 'set_limit',
                'ids' => [$unused->id, $used->id, $otherCoupon->id],
                'limit' => 1,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, (int) $unused->fresh()->limit);
        $this->assertSame(5, (int) $used->fresh()->limit);
        $this->assertSame(5, (int) $otherCoupon->fresh()->limit);

        // Expire now on filtered
        $this->actingAs($this->owner)
            ->postJson(route('stores.coupons.bulk.action'), [
                'action' => 'expire_now',
                'apply_to_filtered' => 1,
                'search' => 'OWNUSED',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue($used->fresh()->isExpiry());
        $this->assertFalse($otherCoupon->fresh()->isExpiry());
        $this->assertFalse($platformCoupon->fresh()->isExpiry());

        // Delete unused keeps used, other owner's coupon, and platform coupon
        $this->actingAs($this->owner)
            ->postJson(route('stores.coupons.bulk.action'), [
                'action' => 'delete_unused',
                'ids' => [$unused->id, $used->id, $otherCoupon->id, $platformCoupon->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('coupons', ['id' => $unused->id]);
        $this->assertDatabaseHas('coupons', ['id' => $used->id]);
        $this->assertDatabaseHas('coupons', ['id' => $otherCoupon->id]);
        $this->assertDatabaseHas('coupons', ['id' => $platformCoupon->id]);
    }

    public function test_csv_export_neutralizes_formulas_and_isolates_owner_scope(): void
    {
        $bulk = app(CouponBulk::class);
        $batch = $bulk->generate(['scope' => 'store', 'user_id' => $this->owner->id], [
            'coupon_type' => 'percentage',
            'percentage' => 20,
            'limit' => 10,
            'expiry_at' => now()->addMonth(),
        ], 1, 'EXP', 8, '=SUM(A1:A2)');

        Coupon::create([
            'code' => 'OTHEREXPORT',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 0,
            'issuer_scope' => 'owner',
            'user_id' => $this->otherOwner->id,
            'applies_to' => 'food_order',
        ]);

        $res = $this->actingAs($this->owner)
            ->get(route('stores.coupons.export', ['batch_id' => $batch->id]))
            ->assertOk();

        $csv = $res->streamedContent();
        $this->assertStringContainsString("'=SUM(A1:A2)", $csv);
        $this->assertStringContainsString('EXP-', $csv);
        $this->assertStringNotContainsString('OTHEREXPORT', $csv);
    }
}
