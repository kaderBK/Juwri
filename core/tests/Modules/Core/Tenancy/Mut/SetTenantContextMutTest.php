<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy\Mut;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Http\Middleware\SetTenantContext;
use Juwri\Core\Services\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P6-27: which routes open a tenant context. Inside a tenant route an unscoped Order query returns only
 * owner A's stores; on any other route it returns every store; the context is closed after the request.
 */
class SetTenantContextMutTest extends TestCase
{
    use RefreshDatabase;
    use TenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        // BL-043: a module-registered non-tenant gate (stands in for the reseller portal's owner gate).
        SetTenantContext::addNonTenantMiddleware(MutNonTenantGate::class);
        app('router')->aliasMiddleware('mut.nontenant', MutNonTenantGate::class);
        $this->seedTenants();
        Auth::login($this->ownerA);
    }

    protected function tearDown(): void
    {
        SetTenantContext::removeNonTenantMiddleware(MutNonTenantGate::class);
        parent::tearDown();
    }

    /**
     * @param  list<string>|null  $middleware  null = no route at all
     * @return list<int> store ids visible inside the request
     */
    private function seenInside(?array $middleware): array
    {
        $request = Request::create('/x', 'GET');
        if ($middleware !== null) {
            $route = (new Route(['GET'], '/x', []))->middleware($middleware);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
        }
        $request->setUserResolver(fn () => Auth::user());
        $seen = [];
        new SetTenantContext()->handle($request, function () use (&$seen) {
            $seen = $this->visibleOrderStores();

            return response('ok');
        });

        // Always closed afterwards: no request pinned, every row visible again.
        $this->assertNull(TenantContext::current()->exportState()['request']);
        $this->assertFalse(TenantContext::current()->active());
        $this->assertCount(3, $this->visibleOrderStores());

        return $seen;
    }

    /** @return array<string, array{list<string>}> */
    public static function tenantRoutes(): array
    {
        return [
            'auth' => [['web', 'auth']],
            'auth:web' => [['web', 'auth:web']],
            'auth:api' => [['api', 'auth:api']],
            'auth:staff' => [['web', 'auth:staff']],
            'auth:staff,web' => [['web', 'auth:staff,web']],
            'staff.dashboard' => [['web', 'staff.dashboard']],
            'ApiTokenAuth class' => [['api', 'App\\Http\\Middleware\\ApiTokenAuth:any']],
            'StaffTokenAuth class' => [['api', 'App\\Http\\Middleware\\StaffTokenAuth']],
            'tenant mw first, later harmless' => [['auth', 'throttle:60,1']],
            // BL-043: every owner dashboard route carries the `reseller.suspension` pass-through; a name that merely
            // contains "reseller" never drops the scope.
            'dashboard stack' => [['web', 'auth', 'staff.dashboard', 'dashboard.sync', 'reseller.suspension', 'abuse.hold', 'csp.dashboard']],
            'reseller.suspension before auth' => [['reseller.suspension', 'auth']],
            'reseller-named throttle' => [['auth', 'throttle:30,1,reseller-reseller-announcement-dismiss']],
            'reseller-named feature' => [['auth', 'feature:reseller_blog']],
            'unregistered reseller-ish names' => [['auth', 'reseller.auth', 'reseller', 'App\\Http\\Middleware\\ResellerPortal']],
            'unregistered class' => [['auth', 'App\\Http\\Middleware\\NoSuchGate']],
        ];
    }

    #[DataProvider('tenantRoutes')]
    public function test_tenant_routes_see_only_their_account(array $middleware): void
    {
        $this->assertSame([$this->storeA1->id, $this->storeA2->id], $this->seenInside($middleware));
    }

    /** @return array<string, array{list<string>|null}> */
    public static function nonTenantRoutes(): array
    {
        return [
            'no route' => [null],
            'public' => [['web']],
            'admin after auth' => [['web', 'auth', 'admin']],
            'admin before auth' => [['admin', 'auth']],
            'registered gate class after auth' => [['auth', MutNonTenantGate::class]],
            'registered gate class with params' => [['auth', MutNonTenantGate::class.':x']],
            'registered gate subclass' => [['auth', MutNonTenantGateChild::class]],
            'registered gate alias' => [['auth', 'mut.nontenant']],
            'registered gate before api token' => [[MutNonTenantGate::class, 'App\\Http\\Middleware\\ApiTokenAuth:any']],
            'auth prefix only' => [['auth:sanctum']],
            'auth basic' => [['auth.basic']],
        ];
    }

    #[DataProvider('nonTenantRoutes')]
    public function test_non_tenant_routes_are_unscoped(?array $middleware): void
    {
        $all = [$this->storeA1->id, $this->storeA2->id, $this->storeB->id];
        $this->assertSame($all, $this->seenInside($middleware));
    }

    public function test_owner_b_sees_only_b_on_a_tenant_route(): void
    {
        Auth::login($this->ownerB);
        $this->assertSame([$this->storeB->id], $this->seenInside(['web', 'auth']));
    }

    public function test_context_is_closed_even_when_the_request_throws(): void
    {
        $request = Request::create('/x', 'GET');
        $route = (new Route(['GET'], '/x', []))->middleware(['auth']);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => Auth::user());
        try {
            new SetTenantContext()->handle($request, function () {
                $this->assertSame([$this->storeA1->id, $this->storeA2->id], $this->visibleOrderStores());
                throw new \RuntimeException('boom');
            });
            $this->fail('exception swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertFalse(TenantContext::current()->active());
        $this->assertCount(3, $this->visibleOrderStores());
    }
}

/** A non-tenant route-group gate, as an ee module registers one (BL-043). */
class MutNonTenantGate
{
    public function handle(Request $request, \Closure $next): mixed
    {
        return $next($request);
    }
}

class MutNonTenantGateChild extends MutNonTenantGate {}
