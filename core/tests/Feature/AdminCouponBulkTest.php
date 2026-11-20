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

class AdminCouponBulkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $nonAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        $this->admin = User::factory()->create([
            'group_id' => '1',
            'user_type' => 'admin',
            'status' => 1,
            'email_verified_at' => now(),
        ]);
        $this->nonAdmin = User::factory()->create([
            'group_id' => '2',
            'user_type' => 'user',
            'status' => 1,
            'email_verified_at' => now(),
        ]);
    }

    public function test_non_admin_cannot_access_bulk_coupon_routes(): void
    {
        $this->actingAs($this->nonAdmin)->get(route('admin.coupons.bulk.create'))->assertRedirect();
        $this->actingAs($this->nonAdmin)->post(route('admin.coupons.bulk.store'), [])->assertRedirect();
        $this->actingAs($this->nonAdmin)->get(route('admin.coupons.batches.index'))->assertRedirect();
        $this->actingAs($this->nonAdmin)->post(route('admin.coupons.bulk.action'), ['action' => 'expire_now', 'apply_to_filtered' => 1])->assertRedirect();
        $this->actingAs($this->nonAdmin)->get(route('admin.coupons.export'))->assertRedirect();
    }

    public function test_admin_can_view_bulk_create_confirm_and_generate_platform_batch(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.coupons.bulk.create'))
            ->assertOk();

        $payload = [
            'name' => 'Spring Launch',
            'prefix' => 'spring',
            'count' => 5,
            'length' => 8,
            'coupon_type' => 'percentage',
            'discount_percentage' => 25,
            'uses_limit' => 3,
            'expiry_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'applies_interval' => 'monthly',
            'first_period_only' => '1',
        ];

        $this->actingAs($this->admin)
            ->post(route('admin.coupons.bulk.confirm'), $payload)
            ->assertOk()
            ->assertSee('SPRING-XXXXXXXX');

        $res = $this->actingAs($this->admin)
            ->post(route('admin.coupons.bulk.store'), $payload);

        $batch = CouponBatch::query()->latest('id')->firstOrFail();
        $res->assertRedirect(route('admin.coupons.batches.show', $batch->id));

        $this->assertSame('platform', $batch->issuer_scope);
        $this->assertNull($batch->user_id);
        $this->assertNull($batch->reseller_id);
        $this->assertSame('SPRING', $batch->prefix);
        $this->assertSame(5, $batch->count);

        $coupons = Coupon::query()->where('batch_id', $batch->id)->get();
        $this->assertCount(5, $coupons);
        foreach ($coupons as $c) {
            $this->assertSame('platform', $c->issuer_scope);
            $this->assertNull($c->user_id);
            $this->assertNull($c->reseller_id);
            $this->assertSame('p', $c->issuer_key);
            $this->assertSame('monthly', $c->applies_interval);
            $this->assertSame(1, (int) $c->first_period_only);
            $this->assertEquals(25, $c->percentage);
            $this->assertSame(3, (int) $c->limit);
            $this->assertMatchesRegularExpression('/^SPRING-[A-Z2-9]{8}$/', $c->code);
        }
    }

    public function test_bulk_create_validates_inputs(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.coupons.bulk.confirm'), [
                'prefix' => 'bad prefix!',
                'count' => 1001,
                'coupon_type' => 'percentage',
                'discount_percentage' => 150,
                'uses_limit' => 1,
                'expiry_at' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors(['prefix', 'count', 'discount_percentage', 'expiry_at']);

        $this->assertSame(0, CouponBatch::query()->count());
    }

    public function test_batches_list_and_result_isolate_other_issuers(): void
    {
        $bulk = app(CouponBulk::class);
        $platformBatch = $bulk->generate(['scope' => 'platform'], [
            'coupon_type' => 'percentage',
            'percentage' => 15,
            'limit' => 2,
            'expiry_at' => now()->addMonth(),
        ], 3, 'PLAT', 8, 'Platform Batch');

        $store = Store::factory()->create();
        $storeBatch = $bulk->generate(['scope' => 'store', 'user_id' => $store->user_id], [
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 1,
        ], 2, 'STOR', 8, 'Store Batch');

        $this->actingAs($this->admin)
            ->get(route('admin.coupons.batches.index'))
            ->assertOk()
            ->assertSee('Platform Batch')
            ->assertDontSee('Store Batch');

        $this->actingAs($this->admin)
            ->get(route('admin.coupons.batches.show', $platformBatch->id))
            ->assertOk()
            ->assertSee('PLAT-');

        $this->actingAs($this->admin)
            ->get(route('admin.coupons.batches.show', $storeBatch->id))
            ->assertNotFound();
    }

    public function test_index_filters_platform_coupons_only(): void
    {
        $bulk = app(CouponBulk::class);
        $batch = $bulk->generate(['scope' => 'platform'], [
            'coupon_type' => 'fixed',
            'percentage' => 12,
            'limit' => 5,
            'expiry_at' => now()->addMonth(),
            'applies_interval' => 'yearly',
        ], 2, 'FLT', 8, 'Filter Batch');

        $other = Coupon::create([
            'code' => 'OTHERPLAT',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 5,
            'expiry_at' => now()->addMonth(),
            'issuer_scope' => 'platform',
            'applies_to' => 'plan_checkout',
            'applies_interval' => 'monthly',
        ]);

        $store = Store::factory()->create();
        Coupon::create([
            'code' => 'STORECPN',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 0,
            'issuer_scope' => 'owner',
            'user_id' => $store->user_id,
            'applies_to' => 'food_order',
        ]);

        $res = $this->actingAs($this->admin)->getJson(
            route('admin.coupons.index', [
                'draw' => 1,
                'start' => 0,
                'length' => 25,
                'batch_id' => $batch->id,
                'type' => 'fixed',
                'applies_interval' => 'yearly',
                'status' => 'never_used',
            ]),
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertOk();

        $rows = $res->json('data');
        $this->assertCount(2, $rows);
        $payload = json_encode($rows);
        $this->assertStringNotContainsString('OTHERPLAT', (string) $payload);
        $this->assertStringNotContainsString('STORECPN', (string) $payload);

        $usedUpRes = $this->actingAs($this->admin)->getJson(
            route('admin.coupons.index', [
                'draw' => 2,
                'start' => 0,
                'length' => 25,
                'status' => 'used_up',
            ]),
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertOk();

        $this->assertCount(1, $usedUpRes->json('data'));
        $this->assertStringContainsString((string) $other->code, (string) json_encode($usedUpRes->json('data')));
    }

    public function test_bulk_actions_operate_on_platform_scope_only(): void
    {
        $unused = Coupon::create([
            'code' => 'BULKUNUSED',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 0,
            'expiry_at' => now()->addDays(5),
            'issuer_scope' => 'platform',
            'applies_to' => 'plan_checkout',
        ]);
        $used = Coupon::create([
            'code' => 'BULKUSED',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 2,
            'expiry_at' => now()->addDays(5),
            'issuer_scope' => 'platform',
            'applies_to' => 'plan_checkout',
        ]);
        $store = Store::factory()->create();
        $storeCoupon = Coupon::create([
            'code' => 'STORESAFE',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 0,
            'expiry_at' => now()->addDays(5),
            'issuer_scope' => 'owner',
            'user_id' => $store->user_id,
            'applies_to' => 'food_order',
        ]);

        // Extend expiry on selected (including store coupon id to prove scope isolation)
        $newExpiry = now()->addMonths(3)->startOfMinute();
        $this->actingAs($this->admin)
            ->postJson(route('admin.coupons.bulk.action'), [
                'action' => 'extend_expiry',
                'ids' => [$unused->id, $used->id, $storeCoupon->id],
                'extend_to' => $newExpiry->toDateTimeString(),
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue(Carbon::parse($unused->fresh()->expiry_at)->equalTo($newExpiry));
        $this->assertFalse(Carbon::parse($storeCoupon->fresh()->expiry_at)->equalTo($newExpiry));

        // Set limit to 1 (skips $used since used=2 > 1, updates $unused)
        $this->actingAs($this->admin)
            ->postJson(route('admin.coupons.bulk.action'), [
                'action' => 'set_limit',
                'ids' => [$unused->id, $used->id],
                'limit' => 1,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, (int) $unused->fresh()->limit);
        $this->assertSame(5, (int) $used->fresh()->limit);

        // Expire now on filtered
        $this->actingAs($this->admin)
            ->postJson(route('admin.coupons.bulk.action'), [
                'action' => 'expire_now',
                'apply_to_filtered' => 1,
                'search' => 'BULKUSED',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue($used->fresh()->isExpiry());
        $this->assertFalse($storeCoupon->fresh()->isExpiry());

        // Delete unused keeps used and store coupon
        $this->actingAs($this->admin)
            ->postJson(route('admin.coupons.bulk.action'), [
                'action' => 'delete_unused',
                'ids' => [$unused->id, $used->id, $storeCoupon->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('coupons', ['id' => $unused->id]);
        $this->assertDatabaseHas('coupons', ['id' => $used->id]);
        $this->assertDatabaseHas('coupons', ['id' => $storeCoupon->id]);
    }

    public function test_csv_export_neutralizes_formulas_and_isolates_platform_scope(): void
    {
        $bulk = app(CouponBulk::class);
        $batch = $bulk->generate(['scope' => 'platform'], [
            'coupon_type' => 'percentage',
            'percentage' => 20,
            'limit' => 10,
            'expiry_at' => now()->addMonth(),
        ], 1, 'EXP', 8, '=SUM(A1:A2)');

        $store = Store::factory()->create();
        Coupon::create([
            'code' => 'STOREONLY',
            'coupon_type' => 'percentage',
            'percentage' => 10,
            'limit' => 5,
            'used' => 0,
            'issuer_scope' => 'owner',
            'user_id' => $store->user_id,
            'applies_to' => 'food_order',
        ]);

        $res = $this->actingAs($this->admin)
            ->get(route('admin.coupons.export', ['batch_id' => $batch->id]))
            ->assertOk();

        $csv = $res->streamedContent();
        $this->assertStringContainsString("'=SUM(A1:A2)", $csv);
        $this->assertStringContainsString('EXP-', $csv);
        $this->assertStringNotContainsString('STOREONLY', $csv);
    }
}
