<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Cviebrock\EloquentSluggable\Services\SlugService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Rules\GloballyUnique;
use Juwri\Core\Services\Tenancy\GlobalSlugService;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Auth\Support\StaffPasskeys;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\StoreDomains;
use Tests\TestCase;

/**
 * BL-043 round 2: with the owner tenant scope on dashboard routes, values that must be unique across EVERY account
 * (store slugs, staff sign-in e-mails, custom hosts) are still checked across every account — by the app scope on
 * all databases and, on PostgreSQL, with row-level security FORCEd like the Cloud app role.
 */
class GlobalUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerA;

    private User $ownerB;

    private Store $storeA;

    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerA = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->ownerB = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->storeA = $this->store($this->ownerA, 'Pizza Palace');
        $this->storeB = $this->store($this->ownerB, 'Other Place');
        $this->ownerA->refresh();
        $this->ownerB->refresh();
        $this->assertSame('pizza-palace', $this->storeA->slug);
    }

    private function store(User $owner, string $title, ?string $slug = null): Store
    {
        return Store::create(array_filter([
            'user_id' => $owner->id, 'title' => $title, 'slug' => $slug,
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ], fn ($v) => $v !== null));
    }

    /** Act as $owner's account (or an owner without an account), with RLS FORCEd on PostgreSQL. */
    private function asTenant(?User $owner): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_keys(RowLevelSecurity::status(DB::connection())) as $table) {
                DB::statement('ALTER TABLE "'.$table.'" FORCE ROW LEVEL SECURITY');
            }
        }
        TenantContext::current()->actAs($owner?->account_id !== null ? (int) $owner->account_id : null);
        $this->assertTrue(TenantContext::current()->active());
    }

    private function assertFreshSlug(string $slug): void
    {
        $this->assertNotSame('pizza-palace', $slug);
        $this->assertStringStartsWith('pizza-palace-', $slug);
        $this->assertFalse(TenantContext::bypass(fn () => Store::where('slug', $slug)->exists()), 'no store in any account has it');
    }

    protected function tearDown(): void
    {
        TenantContext::current()->end();
        parent::tearDown();
    }

    public function test_the_tenant_scope_hides_other_accounts_stores_from_a_plain_lookup(): void
    {
        $this->asTenant($this->ownerB);
        $this->assertFalse(Store::where('slug', 'pizza-palace')->exists(), 'precondition: the scope is on');
        if (DB::getDriverName() === 'pgsql') {
            // RLS (FORCEd like the Cloud app role) filters even a plain query-builder lookup without the bypass —
            // the reason `unique:` and withoutGlobalScopes() alone are not enough there.
            $this->assertFalse(DB::table('stores')->where('slug', 'pizza-palace')->exists(), 'RLS hides the other account');
            $this->assertTrue(TenantContext::bypass(fn () => DB::table('stores')->where('slug', 'pizza-palace')->exists()));
        }
        $this->assertSame('pizza-palace', SlugService::createSlug(Store::class, 'slug', 'Pizza Palace'), 'the package service alone would reuse the slug');
    }

    public function test_create_slug_is_unique_across_accounts(): void
    {
        $this->asTenant($this->ownerB);
        $this->assertFreshSlug(GlobalSlugService::createSlug(Store::class, 'slug', 'Pizza Palace'));
    }

    public function test_a_first_store_owner_without_an_account_gets_a_unique_slug(): void
    {
        $newcomer = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->asTenant($newcomer);
        $this->assertFreshSlug(GlobalSlugService::createSlug(Store::class, 'slug', 'Pizza Palace'));
    }

    public function test_an_auto_generated_slug_is_unique_across_accounts(): void
    {
        $this->asTenant($this->ownerB);
        $store = $this->store($this->ownerB, 'Pizza Palace');
        TenantContext::current()->end();

        $this->assertStringStartsWith('pizza-palace-', $store->slug);
        $this->assertSame(1, Store::where('slug', 'pizza-palace')->count());
        $this->assertSame(1, Store::where('slug', $store->slug)->count());
    }

    public function test_the_reserved_slug_guard_is_unique_across_accounts(): void
    {
        $this->store($this->ownerA, 'Arabic', 'ar-menu');
        $this->asTenant($this->ownerB);
        $store = $this->store($this->ownerB, 'Arabic', 'ar');
        TenantContext::current()->end();

        $this->assertSame('ar-menu-2', $store->slug);
    }

    public function test_slug_validation_sees_every_account(): void
    {
        $this->asTenant($this->ownerB);
        $fails = fn (array $rule, string $value) => Validator::make(['slug' => $value], ['slug' => $rule])->fails();

        $this->assertTrue($fails([new GloballyUnique('stores', 'slug')], 'pizza-palace'));
        $this->assertTrue($fails([new GloballyUnique('stores', 'slug', $this->storeB->id)], 'pizza-palace'));
        $this->assertFalse($fails([new GloballyUnique('stores', 'slug', $this->storeA->id)], 'pizza-palace'), 'its own row is ignored');
        $this->assertFalse($fails([new GloballyUnique('stores', 'slug')], 'free-slug'));
        $message = Validator::make(['slug' => 'pizza-palace'], ['slug' => [new GloballyUnique('stores', 'slug')]])->errors()->first('slug');
        $this->assertSame(__('validation.unique', ['attribute' => 'slug']), $message, 'the standard message, nothing about the other account');
    }

    public function test_a_staff_email_used_in_another_account_is_refused(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        StaffMember::create(['owner_id' => $this->ownerA->id, 'store_id' => $this->storeA->id, 'name' => 'A',
            'email' => 'taken@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1]);
        if (DB::getDriverName() === 'pgsql') {
            $this->asTenant($this->ownerB); // FORCE RLS for the request's queries
            TenantContext::current()->end();
        }

        $this->actingAs($this->ownerB)->post(route('staff.store'), [
            'name' => 'B', 'email' => 'taken@t.test', 'password' => 'Str0ng!Passw0rd#2026', 'role' => 'cashier', 'store_id' => $this->storeB->id,
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, TenantContext::bypass(fn () => StaffMember::where('email', 'taken@t.test')->count()));
    }

    public function test_a_custom_host_verified_by_another_account_is_taken(): void
    {
        TenantContext::bypass(fn () => StoreDomain::create([
            'store_id' => $this->storeA->id, 'type' => StoreDomain::TYPE_CUSTOM, 'host' => 'pizza.example.com',
            'is_primary' => false, 'verified_at' => now(), 'verification_token' => 't', 'ssl_status' => 'active',
        ]));
        $this->asTenant($this->ownerB);

        $this->assertSame('taken', StoreDomains::customHostProblem('pizza.example.com'));
        $this->assertSame('taken', StoreDomains::customHostProblem('www.pizza.example.com'));
        $this->assertNull(StoreDomains::customHostProblem('free.example.com'));
    }

    public function test_a_mixed_case_staff_email_used_in_another_account_is_a_clean_validation_error(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $staffA = StaffMember::create(['owner_id' => $this->ownerA->id, 'store_id' => $this->storeA->id, 'name' => 'A',
            'email' => 'taken@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1]);
        if (DB::getDriverName() === 'pgsql') {
            $this->asTenant($this->ownerB);
            TenantContext::current()->end();
        }

        $this->actingAs($this->ownerB)->post(route('staff.store'), [
            'name' => 'B', 'email' => 'Taken@T.TEST', 'password' => 'Str0ng!Passw0rd#2026', 'role' => 'cashier', 'store_id' => $this->storeB->id,
        ])->assertRedirect()->assertSessionHasErrors('email');
        $this->assertSame(1, TenantContext::bypass(fn () => StaffMember::count()));

        // The same value on one's own record (an update ignoring its id) still passes, in any case.
        $this->asTenant($this->ownerA);
        $own = Validator::make(['email' => 'TAKEN@t.test'], ['email' => [new GloballyUnique('staff_members', 'email', $staffA->id)]]);
        $this->assertFalse($own->fails());
        $other = Validator::make(['email' => 'TAKEN@t.test'], ['email' => [new GloballyUnique('staff_members', 'email')]]);
        $this->assertTrue($other->fails());
    }

    public function test_request_custom_purges_only_expired_unverified_rows_of_any_account(): void
    {
        $make = fn (string $host, bool $verified, bool $expired) => TenantContext::bypass(function () use ($host, $verified, $expired) {
            $row = StoreDomain::create([
                'store_id' => $this->storeA->id, 'type' => StoreDomain::TYPE_CUSTOM, 'host' => $host, 'is_primary' => false,
                'verified_at' => $verified ? now() : null, 'verification_token' => 't-'.Str::random(6), 'ssl_status' => 'pending',
            ]);
            if ($expired) {
                DB::table('store_domains')->where('id', $row->id)->update(['created_at' => now()->subHours(200)]);
            }

            return $row;
        });
        $verifiedTwin = $make('www.twin.example.com', true, true);
        $expiredPending = $make('old.example.com', false, true);
        $freshPending = $make('fresh.example.com', false, false);

        $this->asTenant($this->ownerB);
        $this->assertNull(StoreDomains::customHostProblem('old.example.com'), 'an expired request does not hold the host');
        $this->assertSame('taken', StoreDomains::customHostProblem('fresh.example.com'), 'a fresh request of another account does');
        $mine = StoreDomains::requestCustom($this->storeB, 'old.example.com');
        $twin = StoreDomains::requestCustom($this->storeB, 'twin.example.com');
        TenantContext::current()->end();

        $this->assertNull(StoreDomain::withoutGlobalScopes()->find($expiredPending->id), 'the expired, unverified row of account A is purged');
        $this->assertNotNull(StoreDomain::withoutGlobalScopes()->find($verifiedTwin->id), 'a verified row of account A survives');
        $this->assertNotNull(StoreDomain::withoutGlobalScopes()->find($freshPending->id), 'a fresh pending row of account A survives');
        $this->assertSame([$this->storeB->id, $this->storeB->id], [(int) $mine->store_id, (int) $twin->store_id]);
    }

    public function test_a_platform_subdomain_of_another_account_is_taken(): void
    {
        $host = 'pizza.'.HostMap::storefrontHost();
        TenantContext::bypass(fn () => StoreDomain::create([
            'store_id' => $this->storeA->id, 'type' => StoreDomain::TYPE_SUBDOMAIN, 'host' => $host,
            'is_primary' => false, 'verified_at' => now(), 'verification_token' => 't', 'ssl_status' => 'active',
        ]));
        $this->asTenant($this->ownerB);

        $this->assertSame('taken', StoreDomains::subdomainProblem('pizza'));
        $this->assertNull(StoreDomains::subdomainProblem('pasta'));
    }

    public function test_a_staff_passkey_credential_of_another_account_is_taken(): void
    {
        $staffA = StaffMember::create(['owner_id' => $this->ownerA->id, 'store_id' => $this->storeA->id, 'name' => 'A',
            'email' => 'pk@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1]);
        TenantContext::bypass(fn () => DB::table('staff_passkeys')->insert([
            'account_id' => $this->ownerA->account_id, 'store_id' => $this->storeA->id, 'staff_id' => $staffA->id,
            'name' => 'Key', 'credential_id' => 'cred-a-1', 'rp_id' => 'localhost', 'public_key' => 'pk',
            'credential' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->asTenant($this->ownerB);

        $this->assertTrue(StaffPasskeys::credentialTaken('cred-a-1'));
        $this->assertFalse(StaffPasskeys::credentialTaken('cred-b-1'));
    }
}
