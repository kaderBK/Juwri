<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SetTenantContext;
use Juwri\Core\Services\Tenancy\TenantContext;
use Tests\Modules\Core\Tenancy\Mut\TenancyFixtures;
use Tests\TestCase;

/**
 * BL-043: owner dashboard routes run WITH the tenant scope. Every dashboard route carries the `reseller.suspension`
 * pass-through, and SetTenantContext used to skip any route whose middleware name contained "reseller" — so the
 * dashboard ran unscoped and only per-controller checks kept accounts apart.
 */
class DashboardTenantScopeTest extends TestCase
{
    use RefreshDatabase;
    use TenancyFixtures;

    /** The owner dashboard route stack (routes/web.php User group, module dashboard groups). */
    private const DASHBOARD = ['web', 'auth', 'staff.dashboard', 'dashboard.sync', 'reseller.suspension', 'abuse.hold', 'csp.dashboard'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenants();
        // `dashboard` is a name the staff permission gate lets every staff member open.
        Route::middleware(self::DASHBOARD)->get('/_bl043/probe', function () {
            $ctx = TenantContext::current();

            return response()->json([
                'active' => $ctx->active(),
                'account_id' => $ctx->accountId(),
                'store_ids' => $ctx->storeIds(),
                'order_stores' => $this->visibleOrderStores(),
            ]);
        })->name('dashboard');
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_an_owner_dashboard_request_is_scoped_to_the_owners_account(): void
    {
        $this->actingAs($this->ownerA, 'web')->getJson('/_bl043/probe')
            ->assertOk()
            ->assertJson([
                'active' => true,
                'account_id' => (int) $this->ownerA->account_id,
                'store_ids' => null,
                'order_stores' => [$this->storeA1->id, $this->storeA2->id],
            ]);
        $this->assertNotNull($this->ownerA->account_id);
        $this->assertFalse(TenantContext::current()->active(), 'the context is closed after the request');
    }

    public function test_another_owner_sees_only_their_own_account(): void
    {
        $this->actingAs($this->ownerB, 'web')->getJson('/_bl043/probe')
            ->assertOk()
            ->assertJson(['active' => true, 'account_id' => (int) $this->ownerB->account_id, 'order_stores' => [$this->storeB->id]]);
    }

    public function test_a_staff_dashboard_session_is_scoped_to_its_store(): void
    {
        $staff = StaffMember::create(['owner_id' => $this->ownerA->id, 'store_id' => $this->storeA2->id, 'name' => 'Cashier',
            'email' => Str::random(8).'@t.test', 'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1]);

        $this->actingAs($this->ownerA, 'web')->actingAs($staff, 'staff')
            ->withSession(['staff_session_id' => $staff->id, 'dashboard_store_id' => $this->storeA2->id])
            ->getJson('/_bl043/probe')
            ->assertOk()
            ->assertJson([
                'active' => true,
                'account_id' => (int) $this->ownerA->account_id,
                'store_ids' => [$this->storeA2->id],
                'order_stores' => [$this->storeA2->id],
            ]);
    }

    /** Every registered owner route carrying `reseller.suspension` behind an owner/staff auth gate opens the context. */
    public function test_every_registered_dashboard_route_opens_the_tenant_context(): void
    {
        $checked = 0;
        $skipped = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $mw = $route->middleware();
            if (! in_array('reseller.suspension', $mw, true) || array_intersect($mw, ['auth', 'auth:web', 'staff.dashboard']) === []) {
                continue;
            }
            $checked++;
            if (! $this->opensContext($route)) {
                $skipped[] = ($route->getName() ?? $route->uri()).' ['.implode(',', $mw).']';
            }
        }
        $this->assertGreaterThan(50, $checked, 'the dashboard route groups were found');
        $this->assertSame([], $skipped, 'dashboard routes without the tenant scope');
    }

    private function opensContext(RoutingRoute $route): bool
    {
        $request = Request::create('/'.ltrim($route->uri(), '/'), 'GET');
        $request->setRouteResolver(fn () => $route);
        $opened = false;
        new SetTenantContext()->handle($request, function () use (&$opened) {
            $opened = TenantContext::current()->exportState()['request'] !== null;

            return response('ok');
        });

        return $opened;
    }
}
