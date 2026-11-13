<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy\Mut;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantContext;
use Tests\TestCase;

/**
 * P6-27: the tenant context's own state machine — subject resolution (owner, staff, API user, admin),
 * pinning, export/import, bypass nesting, settled-ness, the session tenant-key reset and log context.
 */
class TenantContextMutTest extends TestCase
{
    use RefreshDatabase;
    use TenancyFixtures;

    private TenantContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenants();
        $this->ctx = TenantContext::current();
    }

    protected function tearDown(): void
    {
        TenantContext::current()->end();
        parent::tearDown();
    }

    private function requestFor(?User $user): Request
    {
        $request = Request::create('/x', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    public function test_fresh_context_is_inactive_and_has_no_subject(): void
    {
        $fresh = new TenantContext;
        $this->assertFalse($fresh->active());
        $this->assertFalse($fresh->activeWhenSettled());
        $this->assertNull($fresh->subject());
        $this->assertNull($fresh->accountId());
        $this->assertNull($fresh->storeIds());
        $this->assertSame(
            ['request' => null, 'resolved' => false, 'scoped' => false, 'accountId' => null, 'userId' => null, 'storeIds' => null],
            $fresh->exportState(),
        );
    }

    public function test_owner_request_resolves_to_its_own_account_only(): void
    {
        $this->ctx->begin($this->requestFor($this->ownerB));
        $this->assertTrue($this->ctx->active());
        $this->assertSame((int) $this->ownerB->account_id, $this->ctx->accountId());
        $this->assertNull($this->ctx->storeIds());
        $this->assertSame(['account_id' => (int) $this->ownerB->account_id, 'store_ids' => null], $this->ctx->subject());
        $this->assertSame([$this->storeB->id], $this->visibleOrderStores());
        $this->assertSame((int) $this->ownerB->id, $this->ctx->exportState()['userId']);
        $this->assertSame((int) $this->ownerB->account_id, Context::get('account_id'));

        $this->ctx->end();
        $this->assertFalse($this->ctx->active());
        $this->assertNull(Context::get('account_id'));
        $this->assertCount(3, $this->visibleOrderStores());
    }

    public function test_account_is_read_from_the_database_not_the_stale_user(): void
    {
        $stale = clone $this->ownerA;
        $stale->setAttribute('account_id', $this->ownerB->account_id);
        $this->ctx->begin($this->requestFor($stale));
        $this->assertSame((int) $this->ownerA->account_id, $this->ctx->accountId());
    }

    public function test_admin_is_never_scoped_and_no_subject_retries(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $this->ctx->begin($this->requestFor($admin));
        $this->assertFalse($this->ctx->active());
        $this->assertCount(3, $this->visibleOrderStores());

        // No user yet: not resolved, so the next query tries again and picks up the late subject.
        $user = null;
        $request = Request::create('/x', 'GET');
        $request->setUserResolver(function () use (&$user) {
            return $user;
        });
        $this->ctx->begin($request);
        $this->assertFalse($this->ctx->active());
        $this->assertFalse($this->ctx->exportState()['resolved']);
        $user = $this->ownerA;
        $this->assertTrue($this->ctx->active());
        $this->assertSame([$this->storeA1->id, $this->storeA2->id], $this->visibleOrderStores());
    }

    public function test_staff_is_pinned_to_its_one_store_and_account(): void
    {
        $staff = StaffMember::create([
            'owner_id' => $this->ownerA->id, 'store_id' => $this->storeA2->id, 'name' => 'S',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $request = Request::create('/x', 'GET');
        $request->setUserResolver(fn () => $staff);
        $this->ctx->begin($request);
        $this->assertSame((int) $this->ownerA->account_id, $this->ctx->accountId());
        $this->assertSame([$this->storeA2->id], $this->ctx->storeIds());
        $this->assertSame([$this->storeA2->id], $this->visibleOrderStores());
        $this->assertSame($this->storeA2->id, Context::get('store_id'));
    }

    public function test_staff_without_account_takes_its_stores_account(): void
    {
        $staff = new StaffMember;
        $staff->forceFill(['id' => 77, 'store_id' => (string) $this->storeB->id, 'account_id' => null]);
        app()->instance('api.staff', $staff);
        try {
            $this->ctx->begin($this->requestFor($this->ownerA));
            $this->assertTrue($this->ctx->activeWhenSettled());
            $this->assertSame((int) $this->storeB->account_id, $this->ctx->accountId());
            $this->assertSame([$this->storeB->id], $this->ctx->storeIds());
            $this->assertSame([$this->storeB->id], $this->visibleOrderStores());

            // A store-less staff member sees no store at all.
            $staff->forceFill(['store_id' => null]);
            $this->ctx->begin($this->requestFor(null));
            $this->assertSame([], $this->ctx->storeIds());
            $this->assertNull($this->ctx->accountId());
            $this->assertSame([], $this->visibleOrderStores());
        } finally {
            app()->forgetInstance('api.staff');
        }
    }

    public function test_api_user_attribute_wins_over_the_request_user(): void
    {
        $request = $this->requestFor($this->ownerA);
        $request->attributes->set('_api_user_id', (string) $this->ownerB->id);
        $this->ctx->begin($request);
        $this->assertTrue($this->ctx->activeWhenSettled());
        $this->assertSame((int) $this->ownerB->account_id, $this->ctx->accountId());
        $this->assertSame([$this->storeB->id], $this->visibleOrderStores());
    }

    public function test_active_when_settled_waits_for_a_subject(): void
    {
        Auth::logout();
        $this->ctx->begin($this->requestFor(null));
        $this->assertFalse($this->ctx->activeWhenSettled());
        $this->assertFalse($this->ctx->exportState()['resolved'], 'not resolved before the subject is known');

        Auth::login($this->ownerA);
        $this->ctx->begin($this->requestFor($this->ownerA));
        $this->assertTrue($this->ctx->activeWhenSettled());
        $this->assertSame((int) $this->ownerA->account_id, $this->ctx->accountId());

        // A started session carrying a staff session id counts as settled.
        Auth::logout();
        $request = $this->requestFor(null);
        $session = app('session.store');
        $session->start();
        $staff = StaffMember::create([
            'owner_id' => $this->ownerA->id, 'store_id' => $this->storeA2->id, 'name' => 'S',
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $session->put('staff_session_id', $staff->id);
        $request->setLaravelSession($session);
        $this->ctx->begin($request);
        $this->assertTrue($this->ctx->activeWhenSettled());
        $this->assertSame([$this->storeA2->id], $this->ctx->storeIds());
        $this->assertSame([$this->storeA2->id], $this->visibleOrderStores());
    }

    public function test_unstarted_session_without_user_is_not_settled(): void
    {
        Auth::logout();
        $request = $this->requestFor(null);
        $session = app('session.store');
        $session->put('staff_session_id', 1);
        $request->setLaravelSession($session);
        $this->assertFalse($session->isStarted());
        $this->ctx->begin($request);
        $this->assertFalse($this->ctx->activeWhenSettled());
    }

    public function test_act_as_pins_and_clear_pins_nothing(): void
    {
        $this->ctx->actAs((int) $this->ownerA->account_id, [''.$this->storeA1->id]);
        $this->assertSame([$this->storeA1->id], $this->ctx->storeIds());
        $this->assertSame([$this->storeA1->id], $this->visibleOrderStores());
        $this->assertSame($this->storeA1->id, Context::get('store_id'));

        $this->ctx->actAs((int) $this->ownerA->account_id, [$this->storeA1->id, $this->storeA2->id]);
        $this->assertNull(Context::get('store_id'), 'several stores → no single store in the log context');
        $this->assertSame((int) $this->ownerA->account_id, Context::get('account_id'));

        $this->ctx->clear();
        $this->assertFalse($this->ctx->active());
        $this->assertTrue($this->ctx->exportState()['resolved'], 'cleared = resolved to nobody');
        $this->assertNull(Context::get('account_id'));
        // A cleared context ignores a later request user (it is pinned, not pending).
        $this->assertCount(3, $this->visibleOrderStores());
    }

    public function test_export_import_round_trip_restores_isolation(): void
    {
        $this->ctx->actAs((int) $this->ownerB->account_id, [$this->storeB->id]);
        $saved = $this->ctx->exportState();
        $this->ctx->actAs((int) $this->ownerA->account_id);
        $this->assertSame([$this->storeA1->id, $this->storeA2->id], $this->visibleOrderStores());

        $this->ctx->importState($saved);
        $this->assertSame([$this->storeB->id], $this->visibleOrderStores());
        $this->assertSame((int) $this->ownerB->account_id, Context::get('account_id'));

        $this->ctx->importState([]);
        $this->assertSame(
            ['request' => null, 'resolved' => false, 'scoped' => false, 'accountId' => null, 'userId' => null, 'storeIds' => null],
            $this->ctx->exportState(),
        );
        $this->assertCount(3, $this->visibleOrderStores());
        $this->assertNull(Context::get('account_id'));
    }

    public function test_bypass_nests_and_restores(): void
    {
        $this->ctx->actAs((int) $this->ownerA->account_id);
        $inner = TenantContext::bypass(fn () => [
            $this->ctx->active(),
            TenantContext::bypass(fn () => count($this->visibleOrderStores())),
            count($this->visibleOrderStores()),
        ]);
        $this->assertSame([false, 3, 3], $inner);
        $this->assertTrue($this->ctx->active());
        $this->assertFalse(TenantContext::bypass(fn () => $this->ctx->activeWhenSettled()));
        $this->assertSame(2, count($this->visibleOrderStores()));

        try {
            TenantContext::bypass(fn () => throw new \RuntimeException('x'));
        } catch (\RuntimeException) {
        }
        $this->assertTrue($this->ctx->active(), 'bypass is undone after an exception');
    }

    public function test_account_provisioned_only_updates_the_matching_unscoped_owner(): void
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->assertNull($user->fresh()->account_id);
        $this->ctx->begin($this->requestFor($user));
        $this->assertTrue($this->ctx->active());
        $this->assertNull($this->ctx->accountId());

        $this->ctx->accountProvisioned((int) $this->ownerB->id, 4242);
        $this->assertNull($this->ctx->accountId(), 'another user → untouched');

        $this->ctx->accountProvisioned((int) $user->id, (int) $this->ownerA->account_id);
        $this->assertSame((int) $this->ownerA->account_id, $this->ctx->accountId());
        $this->assertSame((int) $this->ownerA->account_id, Context::get('account_id'));

        // A pinned (staff / job) subject is never re-pointed.
        $this->ctx->actAs((int) $this->ownerB->account_id, [$this->storeB->id]);
        $this->ctx->accountProvisioned((int) $user->id, 999);
        $this->assertSame((int) $this->ownerB->account_id, $this->ctx->accountId());
        $this->ctx->actAs((int) $this->ownerB->account_id);
        $this->ctx->accountProvisioned((int) $user->id, 999);
        $this->assertSame((int) $this->ownerB->account_id, $this->ctx->accountId(), 'actAs has no userId');

        // Not yet resolved → nothing to update.
        $this->ctx->end();
        $this->ctx->accountProvisioned((int) $user->id, 999);
        $this->assertSame(['request' => null, 'resolved' => false, 'scoped' => false, 'accountId' => null, 'userId' => null, 'storeIds' => null], $this->ctx->exportState());
    }

    public function test_switching_account_in_one_session_drops_tenant_keys(): void
    {
        config(['tenancy.session.tenant_keys' => ['dashboard_store_id']]);
        $session = app('session.store');
        $session->start();

        $session->put('dashboard_store_id', $this->storeA1->id);
        $request = $this->requestFor($this->ownerA);
        $request->setLaravelSession($session);
        $this->ctx->begin($request);
        $this->assertTrue($this->ctx->active());
        $this->assertSame((int) $this->ownerA->account_id, $session->get('juwri.tenant_account'));
        $this->assertSame($this->storeA1->id, $session->get('dashboard_store_id'), 'first sight keeps keys');

        // Same account again: keys stay.
        $this->ctx->begin($request);
        $this->ctx->active();
        $this->assertSame($this->storeA1->id, $session->get('dashboard_store_id'));

        // Another account in the same session: A's store pointer must not leak to B.
        $requestB = $this->requestFor($this->ownerB);
        $requestB->setLaravelSession($session);
        $this->ctx->begin($requestB);
        $this->ctx->active();
        $this->assertNull($session->get('dashboard_store_id'));
        $this->assertSame((int) $this->ownerB->account_id, $session->get('juwri.tenant_account'));

        // An owner with no account is recorded as 0 and also drops the keys.
        $session->put('dashboard_store_id', 5);
        $none = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $requestN = $this->requestFor($none);
        $requestN->setLaravelSession($session);
        $this->ctx->begin($requestN);
        $this->ctx->active();
        $this->assertSame(0, $session->get('juwri.tenant_account'));
        $this->assertNull($session->get('dashboard_store_id'));
    }

    public function test_unstarted_session_is_left_alone(): void
    {
        $session = app('session.store');
        $session->put('dashboard_store_id', 9);
        $request = $this->requestFor($this->ownerA);
        $request->setLaravelSession($session);
        $this->ctx->begin($request);
        $this->assertTrue($this->ctx->active());
        $this->assertNull($session->get('juwri.tenant_account'));
        $this->assertSame(9, $session->get('dashboard_store_id'));
    }
}
