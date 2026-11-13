<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\User;
use Tests\TestCase;

/** P6-215: coupon codes are unique per issuer (owner / reseller / platform), case-insensitively — not across all stores. */
class CouponCodePerIssuerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function owner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    private function createCoupon(User $owner, string $code)
    {
        return $this->actingAs($owner)->from('/coupons/new')->post(route('stores.coupons.store'), [
            'code' => $code, 'coupon_type' => 'percentage', 'percentage' => 10, 'limit' => 99,
        ]);
    }

    public function test_two_owners_can_hold_the_same_code_and_each_resolves_their_own(): void
    {
        [$a, $b] = [$this->owner(), $this->owner()];
        $this->createCoupon($a, 'WELCOME10')->assertSessionHasNoErrors();
        $this->createCoupon($b, 'welcome10')->assertSessionHasNoErrors();
        $this->assertSame(2, Coupon::whereRaw('LOWER(code) = ?', ['welcome10'])->count());

        Coupon::where('user_id', $b->id)->firstOrFail()->forceFill(['percentage' => 30])->save();
        $svc = new CouponService;
        $ra = $svc->resolve('Welcome10', ['surface' => 'food_order', 'owner_id' => $a->id, 'subtotal' => 100]);
        $rb = $svc->resolve('WELCOME10', ['surface' => 'food_order', 'owner_id' => $b->id, 'subtotal' => 100]);
        $this->assertTrue($ra['ok'] && $rb['ok']);
        $this->assertSame($a->id, (int) $ra['coupon']->user_id);
        $this->assertSame($b->id, (int) $rb['coupon']->user_id);
        $this->assertEqualsWithDelta(10.0, $ra['discount'], 0.001);
        $this->assertEqualsWithDelta(30.0, $rb['discount'], 0.001);
    }

    public function test_same_owner_is_refused_in_any_case_without_naming_other_stores(): void
    {
        $a = $this->owner();
        $this->createCoupon($a, 'SUMMER')->assertSessionHasNoErrors();
        $this->createCoupon($a, 'summer')->assertSessionHasErrors(['code' => 'You already have a coupon with this code.']);
        $this->assertSame(1, Coupon::where('user_id', $a->id)->count());

        // Editing a coupon keeps its own code.
        $c = Coupon::where('user_id', $a->id)->firstOrFail();
        $this->actingAs($a)->put(route('stores.coupons.update', $c), [
            'code' => 'SUMMER', 'coupon_type' => 'percentage', 'percentage' => 15, 'limit' => 99,
        ])->assertSessionHasNoErrors();
    }

    public function test_the_database_enforces_per_issuer_uniqueness(): void
    {
        $base = ['percentage' => 5, 'limit' => 1, 'used' => 0, 'coupon_type' => 'percentage'];
        Coupon::create($base + ['code' => 'DUP', 'user_id' => 11]);
        Coupon::create($base + ['code' => 'DUP', 'user_id' => 12]);
        Coupon::create($base + ['code' => 'DUP', 'reseller_id' => 3, 'issuer_scope' => 'reseller']);
        Coupon::create($base + ['code' => 'DUP']); // platform
        $this->assertSame(4, Coupon::where('code', 'DUP')->count());

        $this->expectException(UniqueConstraintViolationException::class);
        Coupon::create($base + ['code' => 'dup', 'user_id' => 11]);
    }

    public function test_platform_and_reseller_codes_do_not_block_owner_codes(): void
    {
        $this->assertFalse(Coupon::codeTaken('X1', 5));
        Coupon::create(['code' => 'X1', 'percentage' => 5, 'limit' => 1, 'used' => 0, 'coupon_type' => 'percentage']);
        $this->assertFalse(Coupon::codeTaken('x1', 5));
        $this->assertTrue(Coupon::codeTaken('x1', null));
        $this->assertFalse(Coupon::codeTaken('x1', null, 9));
    }

    public function test_migration_backfill_keys_legacy_rows_and_suffixes_case_twins(): void
    {
        DB::table('coupons')->insert([
            ['code' => 'Legacy', 'user_id' => 7, 'percentage_rate' => 5, 'limit' => 1, 'used' => 0, 'coupon_type' => 'percentage', 'issuer_key' => null, 'code_key' => null],
            ['code' => 'PLAT', 'user_id' => null, 'percentage_rate' => 5, 'limit' => 1, 'used' => 0, 'coupon_type' => 'percentage', 'issuer_key' => null, 'code_key' => null],
        ]);
        $migration = require base_path('app/Modules/Loyalty/Database/Migrations/2026_10_18_120000_scope_coupon_code_unique_to_issuer.php');
        $migration->backfill();

        $legacy = DB::table('coupons')->where('user_id', 7)->orderBy('id')->first();
        $this->assertSame(['u:7', 'legacy'], [$legacy->issuer_key, $legacy->code_key]);
        $this->assertSame('p', DB::table('coupons')->where('code', 'PLAT')->value('issuer_key'));

        // A case twin inside one issuer (possible on case-sensitive drivers before) keeps its code, gets a suffixed key.
        DB::table('coupons')->insert(['code' => 'LEGACY', 'user_id' => 7, 'percentage_rate' => 5, 'limit' => 1, 'used' => 0, 'coupon_type' => 'percentage']);
        $migration->backfill();
        $twin = DB::table('coupons')->where('user_id', 7)->orderByDesc('id')->first(); // by id: MySQL compares codes case-insensitively
        $this->assertSame('legacy#'.$twin->id, $twin->code_key);
    }
}
