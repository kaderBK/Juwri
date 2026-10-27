<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use App\Http\Middleware\ApiTokenAuth;
use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Scopes\AccountScope;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Policies\TenantPolicy;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Integrations\Models\ApiToken;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * P1-04 slice 5 (J29-2): cross-tenant isolation of the app-scoping layer. A deliberately UNSCOPED query
 * (`Order::pluck('id')`, no where clause) inside an owner-facing request returns only the subject's rows —
 * for a web session, a staff session (own store only), an owner API token and a Passport token — while
 * public storefront routes and platform-admin routes are not scoped. (The app has no Livewire components;
 * nothing to cover there.)
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private User $ownerB;

    private Store $storeA1;

    private Store $storeA2;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerA = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->ownerB = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->storeA1 = $this->store($this->ownerA);
        $this->storeA2 = $this->store($this->ownerA);
        $this->storeB = $this->store($this->ownerB);
        $this->ownerA->refresh(); // the account was created with the first store
        $this->ownerB->refresh();
        foreach ([$this->storeA1, $this->storeA2, $this->storeB] as $store) {
            Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        }

        $probe = fn () => response()->json(['orders' => Order::orderBy('id')->pluck('store_id')->map(fn ($v) => (int) $v)->all()]);
        Route::middleware(['web', 'auth'])->get('__tenancy/probe/web', $probe);
        Route::middleware(['web', 'auth:staff'])->get('__tenancy/probe/staff', $probe);
        Route::middleware(['api', ApiTokenAuth::class.':any'])->get('__tenancy/probe/token', $probe);
        Route::middleware(['api', 'auth:api'])->get('__tenancy/probe/passport', $probe);
        Route::middleware(['web'])->get('__tenancy/probe/public', $probe);
        Route::middleware(['web', 'auth', 'admin'])->get('__tenancy/probe/admin', $probe);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'title' => 'S', 'slug' => 'st-'.Str::lower(Str::random(8)),
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    private function ids(Store ...$stores): array
    {
        return array_map(fn (Store $p) => $p->id, $stores);
    }

    public function test_web_session_sees_only_its_account(): void
    {
        $this->actingAs($this->ownerA)->getJson('__tenancy/probe/web')
            ->assertOk()->assertExactJson(['orders' => $this->ids($this->storeA1, $this->storeA2)]);
        $this->actingAs($this->ownerB)->getJson('__tenancy/probe/web')
            ->assertOk()->assertExactJson(['orders' => $this->ids($this->storeB)]);
    }

    public function test_staff_session_sees_only_its_store(): void
    {
        $staff = StaffMember::create([
            'owner_id' => $this->ownerA->id, 'store_id' => $this->storeA2->id, 'name' => 'S',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);

        $this->actingAs($staff, 'staff')->getJson('__tenancy/probe/staff')
            ->assertOk()->assertExactJson(['orders' => $this->ids($this->storeA2)]);
    }

    public function test_owner_api_token_sees_only_its_account(): void
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $this->ownerB->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read']]);

        $this->getJson('__tenancy/probe/token', ['Authorization' => 'Bearer '.$raw])
            ->assertOk()->assertExactJson(['orders' => $this->ids($this->storeB)]);
    }

    public function test_passport_token_sees_only_its_account(): void
    {
        Passport::actingAs($this->ownerA);

        $this->getJson('__tenancy/probe/passport')
            ->assertOk()->assertExactJson(['orders' => $this->ids($this->storeA1, $this->storeA2)]);
    }

    public function test_public_and_admin_routes_are_not_scoped(): void
    {
        $all = $this->ids($this->storeA1, $this->storeA2, $this->storeB);
        $this->actingAs($this->ownerA)->getJson('__tenancy/probe/public')->assertOk()->assertExactJson(['orders' => $all]);

        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($admin)->getJson('__tenancy/probe/admin')->assertOk()->assertExactJson(['orders' => $all]);
    }

    public function test_context_scope_bypass_and_no_account(): void
    {
        $ctx = TenantContext::current();
        $ctx->actAs($this->ownerB->account_id);
        $this->assertSame($this->ids($this->storeB), Order::pluck('store_id')->map(fn ($v) => (int) $v)->all());
        $this->assertNull(Order::find(Order::withoutGlobalScope(AccountScope::class)->where('store_id', $this->storeA1->id)->value('id')));
        $this->assertSame(3, TenantContext::bypass(fn () => Order::count()), 'explicit platform opt-out');

        // An owner with no store (no account) sees no store rows at all.
        $ctx->actAs(null);
        $this->assertSame(0, Order::count());
        $ctx->end();
        $this->assertSame(3, Order::count(), 'no context outside owner-facing requests');
    }

    public function test_tenant_policy_checks_account_and_staff_store(): void
    {
        [$a1, $a2, $b] = TenantContext::bypass(fn () => [
            Order::where('store_id', $this->storeA1->id)->first(),
            Order::where('store_id', $this->storeA2->id)->first(),
            Order::where('store_id', $this->storeB->id)->first(),
        ]);
        $ctx = TenantContext::current();

        $this->assertTrue(TenantPolicy::allows($b), 'no context: defers to the other checks');

        $ctx->actAs($this->ownerA->account_id);
        $this->assertTrue(TenantPolicy::allows($a1));
        $this->assertFalse(TenantPolicy::allows($b));
        $this->assertTrue(Gate::forUser($this->ownerA)->allows('tenant.access', $a2));
        $this->assertFalse(Gate::forUser($this->ownerA)->allows('tenant.access', $b));

        $ctx->actAs($this->ownerA->account_id, [$this->storeA2->id]); // staff of store A2
        $this->assertFalse(TenantPolicy::allows($a1));
        $this->assertTrue(TenantPolicy::allows($a2));
        $this->assertFalse(TenantPolicy::allows($this->storeA1));
        $ctx->end();
    }
}
