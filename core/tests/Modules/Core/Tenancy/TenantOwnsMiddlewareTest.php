<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use Juwri\Core\Http\Middleware\EnsureTenantOwnsResource;
use Juwri\Core\Models\Branch;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * S13 — the `tenant.owns` middleware backstop. Resolves the route-bound owned
 * model and aborts 403 unless it falls within the acting owner/staff scope.
 * Superadmins bypass. Unresolvable (scalar-id) routes pass through so the
 * middleware is always safe to attach.
 */
class TenantOwnsMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(6);
        $r = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        $order = Order::create([
            'store_id' => $r->id, 'customer_name' => 'J', 'status' => 'pending',
            'track_token' => Str::random(32), 'created_at' => now(),
        ]);
        $branch = Branch::create(['store_id' => $r->id, 'name' => 'Main', 'position' => 0]);

        return compact('user', 'r', 'order', 'branch');
    }

    /** Build a request whose bound route parameter is $model under param $name. */
    private function requestBinding(string $name, $model): Request
    {
        $request = Request::create('/x', 'POST');
        $route = new Route(['POST'], '/x', []);
        $route->bind($request);
        $route->setParameter($name, $model);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => Auth::user());

        return $request;
    }

    /** Run the middleware; return the HTTP status it produced (403 on abort). */
    private function statusFor(Request $request, ?string $param = null): int
    {
        try {
            new EnsureTenantOwnsResource()->handle($request, fn () => response('ok', 200), $param);

            return 200;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    public function test_it_denies_a_foreign_bound_order(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $this->actingAs($a['user']);

        $this->assertSame(403, $this->statusFor($this->requestBinding('order', $b['order'])));
    }

    public function test_it_allows_the_owners_own_bound_order(): void
    {
        $a = $this->tenant();
        $this->actingAs($a['user']);

        $this->assertSame(200, $this->statusFor($this->requestBinding('order', $a['order'])));
    }

    public function test_it_denies_a_foreign_bound_branch_via_post_id(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $this->actingAs($a['user']);

        $this->assertSame(403, $this->statusFor($this->requestBinding('branch', $b['branch'])));
    }

    public function test_superadmin_bypasses_ownership(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $b = $this->tenant();
        $this->actingAs($admin);

        $this->assertSame(200, $this->statusFor($this->requestBinding('order', $b['order'])));
    }

    public function test_a_route_with_no_bound_model_passes_through(): void
    {
        $a = $this->tenant();
        $this->actingAs($a['user']);

        // Scalar id (manual-find route): nothing to resolve, defer to controller.
        $request = Request::create('/x', 'POST');
        $route = new Route(['POST'], '/x', []);
        $route->bind($request);
        $route->setParameter('id', 999);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => Auth::user());

        $this->assertSame(200, $this->statusFor($request));
    }
}
