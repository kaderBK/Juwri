<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Models\SupplierPayment;
use Juwri\Modules\Payments\Models\WalletTransaction;
use Juwri\Modules\Payments\Services\WalletService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D-P1-21-15 (J29-1): supplier_payments, coupons and wallet_transactions carry the tenant key account_id (supplier
 * payments also store_id). New rows are filled (from the supplier / the owner user), existing rows are backfilled by
 * the migrations, the app scope hides another account's rows, and the owner routes refuse another account's ids.
 */
class SupplierPaymentsCouponsWalletTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
    }

    protected function tearDown(): void
    {
        TenantContext::current()->end();
        parent::tearDown();
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 999], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner): Store
    {
        $t = 'S'.Str::random(5);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1']);
    }

    private function coupon(?User $owner, string $code, array $extra = []): Coupon
    {
        return Coupon::create($extra + [
            'code' => $code, 'percentage' => 10, 'limit' => 9, 'used' => 0, 'coupon_type' => 'percentage',
            'user_id' => $owner?->id, 'issuer_scope' => $owner ? 'owner' : 'platform', 'applies_to' => 'food_order',
            'expiry_at' => now()->addMonth(),
        ]);
    }

    /** @return array{0: User, 1: Store, 2: Supplier, 3: User, 4: Store, 5: Supplier} */
    private function twoAccounts(): array
    {
        $a = $this->owner();
        $sa = $this->store($a);
        $b = $this->owner();
        $sb = $this->store($b);

        return [$a->fresh(), $sa, Supplier::create(['store_id' => $sa->id, 'name' => 'A sup', 'active' => true]),
            $b->fresh(), $sb, Supplier::create(['store_id' => $sb->id, 'name' => 'B sup', 'active' => true])];
    }

    public function test_new_rows_get_their_tenant_keys(): void
    {
        [$a, $sa, $supA] = $this->twoAccounts();

        $pay = SupplierPayment::create(['supplier_id' => $supA->id, 'amount' => 12, 'method' => 'cash', 'paid_at' => now()]);
        $this->assertSame([(int) $sa->id, (int) $a->account_id], [(int) $pay->store_id, (int) $pay->account_id]);

        $this->assertSame((int) $a->account_id, (int) $this->coupon($a, 'OWNA')->account_id);
        $this->assertNull($this->coupon(null, 'PLAT')->account_id, 'a platform coupon is not tenant data');

        WalletService::credit($a, 5, 'promo', 'r-a');
        $this->assertSame((int) $a->account_id, (int) WalletTransaction::where('ref', 'r-a')->value('account_id'));

        // A user without an account yet (no store): NULL, adopted with the first store.
        $late = $this->owner();
        WalletService::credit($late, 3, 'promo', 'r-late');
        $early = $this->coupon($late, 'EARLY');
        $this->assertNull(WalletTransaction::where('ref', 'r-late')->value('account_id'));
        $this->assertNull($early->fresh()->account_id);
        $this->store($late);
        $account = (int) $late->fresh()->account_id;
        $this->assertGreaterThan(0, $account);
        $this->assertSame($account, (int) WalletTransaction::where('ref', 'r-late')->value('account_id'));
        $this->assertSame($account, (int) $early->fresh()->account_id);
    }

    public function test_migrations_backfill_existing_rows(): void
    {
        [$a, $sa, $supA, $b, $sb, $supB] = $this->twoAccounts();
        $payA = SupplierPayment::create(['supplier_id' => $supA->id, 'amount' => 1, 'paid_at' => now()]);
        $payB = SupplierPayment::create(['supplier_id' => $supB->id, 'amount' => 2, 'paid_at' => now()]);
        $cA = $this->coupon($a, 'BFA');
        $cP = $this->coupon(null, 'BFP');
        WalletService::credit($b, 4, 'promo', 'bf-b');
        // Rows written before the columns existed.
        DB::table('supplier_payments')->update(['store_id' => null, 'account_id' => null]);
        DB::table('coupons')->update(['account_id' => null]);
        DB::table('wallet_transactions')->update(['account_id' => null]);

        foreach (['Inventory' => '2026_10_05_130000_add_tenant_keys_to_supplier_payments', 'Loyalty' => '2026_10_05_130100_add_account_id_to_coupons', 'Payments' => '2026_10_05_130200_add_account_id_to_wallet_transactions'] as $module => $file) {
            $migration = require app_path("Modules/{$module}/Database/Migrations/{$file}.php");
            $migration->backfill();
            $migration->backfill(); // idempotent
        }

        $this->assertSame([(int) $sa->id, (int) $a->account_id], array_map('intval', array_values((array) DB::table('supplier_payments')->where('id', $payA->id)->first(['store_id', 'account_id']))));
        $this->assertSame([(int) $sb->id, (int) $b->account_id], array_map('intval', array_values((array) DB::table('supplier_payments')->where('id', $payB->id)->first(['store_id', 'account_id']))));
        $this->assertSame((int) $a->account_id, (int) DB::table('coupons')->where('id', $cA->id)->value('account_id'));
        $this->assertNull(DB::table('coupons')->where('id', $cP->id)->value('account_id'));
        $this->assertSame((int) $b->account_id, (int) DB::table('wallet_transactions')->where('ref', 'bf-b')->value('account_id'));
        $this->assertSame(2, DB::table('supplier_payments')->count(), 'no row lost');
    }

    public function test_the_app_scope_hides_another_accounts_rows(): void
    {
        [$a, , $supA, $b, , $supB] = $this->twoAccounts();
        SupplierPayment::create(['supplier_id' => $supA->id, 'amount' => 1, 'paid_at' => now()]);
        $payB = SupplierPayment::create(['supplier_id' => $supB->id, 'amount' => 2, 'paid_at' => now()]);
        $this->coupon($a, 'SCA');
        $cB = $this->coupon($b, 'SCB');
        $this->coupon(null, 'SCP');
        WalletService::credit($a, 1, 'x', 'sc-a');
        WalletService::credit($b, 1, 'x', 'sc-b');

        TenantContext::current()->actAs((int) $a->account_id);
        $this->assertSame([(int) $a->account_id], SupplierPayment::pluck('account_id')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertNull(SupplierPayment::find($payB->id));
        $this->assertSame(['SCA', 'SCP'], Coupon::orderBy('code')->pluck('code')->all(), 'own + platform coupons only');
        $this->assertNull(Coupon::find($cB->id));
        $this->assertSame(['sc-a'], WalletTransaction::pluck('ref')->all());
        $this->assertSame(1.0, WalletService::balanceFor($a));
        TenantContext::current()->end();
    }

    public function test_owner_routes_refuse_another_accounts_coupon(): void
    {
        [$a, , , $b] = $this->twoAccounts();
        $cB = $this->coupon($b, 'BOBONLY');

        $index = $this->actingAs($a)->get(route('stores.coupons.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('BOBONLY', $index);
        $this->assertContains($this->actingAs($a)->get(route('stores.coupons.edit', $cB->id))->status(), [403, 404]);
        $this->assertContains($this->actingAs($a)->put(route('stores.coupons.update', $cB->id), [
            'code' => 'STOLEN', 'coupon_type' => 'percentage', 'percentage' => 90, 'limit' => 99,
        ])->status(), [403, 404]);
        $this->assertContains($this->actingAs($a)->delete(route('stores.coupons.destroy', $cB->id))->status(), [403, 404]);

        $row = DB::table('coupons')->where('id', $cB->id)->first();
        $this->assertSame('BOBONLY', $row->code, "Bob's coupon unchanged");
        $this->assertSame((int) $b->account_id, (int) $row->account_id);
    }

    public function test_owner_routes_refuse_another_accounts_supplier_payments(): void
    {
        [$a, , $supA, $b, , $supB] = $this->twoAccounts();
        SupplierPayment::create(['supplier_id' => $supB->id, 'amount' => 7, 'method' => 'BOBMETHOD', 'paid_at' => now()]);

        $this->actingAs($a)->get(route('inventory.suppliers.show', $supB->id))->assertNotFound();
        $this->actingAs($a)->post(route('inventory.suppliers.payment', $supB->id), ['amount' => 5])->assertNotFound();
        $this->assertSame(1, DB::table('supplier_payments')->count(), 'no payment written against Bob\'s supplier');

        $this->actingAs($a)->post(route('inventory.suppliers.payment', $supA->id), ['amount' => 5, 'method' => 'cash'])->assertRedirect();
        $own = DB::table('supplier_payments')->where('supplier_id', $supA->id)->first();
        $this->assertSame((int) $a->account_id, (int) $own->account_id, 'filled in the owner request');
        $this->assertStringNotContainsString('BOBMETHOD', $this->actingAs($a)->get(route('inventory.suppliers.show', $supA->id))->assertOk()->getContent());
    }

    /**
     * Bug fix D-P1-21-15: a sale credits the SELLER's wallet from the BUYER's request. Under the buyer's tenant scope
     * the seller's rows were invisible — the balance snapshot started from 0 and the idempotent ref was missed (and on
     * PostgreSQL the RLS WITH CHECK refused the seller's row). The wallet now runs outside the tenant scope.
     */
    public function test_a_credit_to_another_accounts_wallet_from_a_tenant_request(): void
    {
        [$buyer, , , $seller] = $this->twoAccounts();
        WalletService::credit($seller, 10, 'earlier sale', 'sale-1');

        TenantContext::current()->actAs((int) $buyer->account_id);
        $balance = WalletService::credit($seller, 2.5, 'app sale', 'sale-2');
        $this->assertSame(12.5, $balance);
        $this->assertSame(12.5, WalletService::credit($seller, 2.5, 'app sale', 'sale-2'), 'same ref: no second row');
        TenantContext::current()->end();

        $row = WalletTransaction::where('ref', 'sale-2')->first();
        $this->assertSame((int) $seller->account_id, (int) $row->account_id);
        $this->assertSame(12.5, (float) $row->balance_after);
        $this->assertSame(2, WalletTransaction::where('user_id', $seller->id)->count());
    }
}
