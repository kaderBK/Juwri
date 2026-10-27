<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy\Mut;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Juwri\Core\Http\Middleware\EnsureTenantOwnsResource;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** A plain model with no tenancy traits: ownership comes only from its columns. */
class MutOwnedThing extends Model
{
    protected $table = 'mut_owned_things';

    protected $guarded = [];
}

/**
 * P6-27: `tenant.owns` — every ownership column path, param resolution, and the admin bypass.
 * Owner A must never pass the guard with a resource of owner B, and vice versa.
 */
class EnsureTenantOwnsResourceMutTest extends TestCase
{
    use RefreshDatabase;
    use TenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenants();
        Auth::login($this->ownerA);
    }

    /** @param  array<string, mixed>  $params */
    private function guard(array $params, ?string $param = null): int
    {
        $request = Request::create('/x', 'POST');
        $route = new Route(['POST'], '/x', []);
        $route->bind($request);
        foreach ($params as $name => $value) {
            $route->setParameter($name, $value);
        }
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => Auth::user());

        try {
            $res = new EnsureTenantOwnsResource()->handle($request, fn () => response('ok', 200), $param);

            return $res->getStatusCode();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    /** @param  array<string, mixed>  $attrs */
    private function thing(array $attrs): MutOwnedThing
    {
        return (new MutOwnedThing)->forceFill($attrs);
    }

    public function test_store_column_decides_ownership_and_wins_over_owner_and_user(): void
    {
        $this->assertSame(200, $this->guard(['t' => $this->thing(['store_id' => (string) $this->storeA2->id])]));
        $this->assertSame(403, $this->guard(['t' => $this->thing(['store_id' => $this->storeB->id])]));
        // A foreign store is never rescued by an owner_id / user_id that points at the actor.
        $this->assertSame(403, $this->guard(['t' => $this->thing([
            'store_id' => $this->storeB->id, 'owner_id' => $this->ownerA->id, 'user_id' => $this->ownerA->id,
        ])]));
        // Own store wins even with a foreign owner_id.
        $this->assertSame(200, $this->guard(['t' => $this->thing([
            'store_id' => $this->storeA1->id, 'owner_id' => $this->ownerB->id,
        ])]));
    }

    public function test_owner_column_decides_when_no_store_and_wins_over_user(): void
    {
        $this->assertSame(200, $this->guard(['t' => $this->thing(['owner_id' => (string) $this->ownerA->id])]));
        $this->assertSame(403, $this->guard(['t' => $this->thing(['owner_id' => $this->ownerB->id])]));
        $this->assertSame(403, $this->guard(['t' => $this->thing(['owner_id' => $this->ownerB->id, 'user_id' => $this->ownerA->id])]));
        $this->assertSame(200, $this->guard(['t' => $this->thing(['owner_id' => $this->ownerA->id, 'user_id' => $this->ownerB->id])]));
    }

    public function test_user_column_decides_last_and_no_column_fails_closed(): void
    {
        $this->assertSame(200, $this->guard(['t' => $this->thing(['user_id' => (string) $this->ownerA->id])]));
        $this->assertSame(403, $this->guard(['t' => $this->thing(['user_id' => $this->ownerB->id])]));
        $this->assertSame(403, $this->guard(['t' => $this->thing(['name' => 'x'])]));
    }

    public function test_store_model_by_id_or_by_owner(): void
    {
        $this->assertSame(200, $this->guard(['s' => $this->storeA1]));
        $this->assertSame(403, $this->guard(['s' => $this->storeB]));
        // In the actor's store list though the owner column says otherwise → allowed (either test suffices).
        $byId = (new Store)->forceFill(['id' => (string) $this->storeA2->id, 'user_id' => $this->ownerB->id]);
        $this->assertSame(200, $this->guard(['s' => $byId]));
        // Not in the list but owned by the actor (string column) → allowed.
        $byOwner = (new Store)->forceFill(['id' => 999999, 'user_id' => (string) $this->ownerA->id]);
        $this->assertSame(200, $this->guard(['s' => $byOwner]));
        $neither = (new Store)->forceFill(['id' => 999998, 'user_id' => $this->ownerB->id]);
        $this->assertSame(403, $this->guard(['s' => $neither]));
    }

    public function test_trait_models_resolve_through_their_owner_scope(): void
    {
        $own = Order::withoutGlobalScopes()->where('store_id', $this->storeA1->id)->firstOrFail();
        $foreign = Order::withoutGlobalScopes()->where('store_id', $this->storeB->id)->firstOrFail();
        $this->assertSame(200, $this->guard(['order' => $own]));
        $this->assertSame(403, $this->guard(['order' => $foreign]));
    }

    public function test_auto_detect_skips_scalars_and_checks_the_first_model(): void
    {
        $own = $this->thing(['store_id' => $this->storeA1->id]);
        $foreign = $this->thing(['store_id' => $this->storeB->id]);
        $this->assertSame(403, $this->guard(['id' => '5', 'a' => $foreign, 'b' => $own]));
        $this->assertSame(200, $this->guard(['id' => '5', 'a' => $own, 'b' => $foreign]));
        $this->assertSame(200, $this->guard(['id' => '5']), 'nothing bound → passthrough');
        $this->assertSame(200, $this->guard([]));
    }

    public function test_explicit_param_checks_only_that_param(): void
    {
        $own = $this->thing(['store_id' => $this->storeA1->id]);
        $foreign = $this->thing(['store_id' => $this->storeB->id]);
        $this->assertSame(403, $this->guard(['a' => $own, 'b' => $foreign], 'b'));
        $this->assertSame(200, $this->guard(['a' => $foreign, 'b' => $own], 'b'));
        // A scalar under the pinned param passes through even when another param is a foreign model.
        $this->assertSame(200, $this->guard(['a' => $foreign, 'b' => '7'], 'b'));
        $this->assertSame(200, $this->guard(['a' => $foreign], 'missing'));
    }

    public function test_owner_b_is_the_mirror_image(): void
    {
        Auth::login($this->ownerB);
        $this->assertSame(200, $this->guard(['t' => $this->thing(['store_id' => $this->storeB->id])]));
        $this->assertSame(403, $this->guard(['t' => $this->thing(['store_id' => $this->storeA1->id])]));
        $this->assertSame(200, $this->guard(['t' => $this->thing(['user_id' => $this->ownerB->id])]));
        $this->assertSame(403, $this->guard(['t' => $this->thing(['user_id' => $this->ownerA->id])]));
    }

    public function test_only_a_real_admin_bypasses(): void
    {
        $foreign = $this->thing(['store_id' => $this->storeB->id]);
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        Auth::login($admin);
        $this->assertSame(200, $this->guard(['t' => $foreign]));
        $this->assertSame(200, $this->guard(['t' => $this->thing(['name' => 'x'])]), 'admin skips even fail-closed');

        Auth::login($this->ownerA);
        $this->assertFalse($this->ownerA->isAdmin());
        $this->assertSame(403, $this->guard(['t' => $foreign]));
    }

    public function test_guest_with_foreign_resource_is_denied(): void
    {
        Auth::logout();
        $this->assertSame(403, $this->guard(['t' => $this->thing(['store_id' => $this->storeB->id])]));
        $this->assertSame(403, $this->guard(['t' => $this->thing(['user_id' => $this->ownerB->id])]));
    }
}
