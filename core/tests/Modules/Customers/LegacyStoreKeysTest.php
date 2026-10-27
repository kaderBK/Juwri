<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AcceptLegacyStoreKeys;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\LegacyStoreKeys;
use Tests\TestCase;

/**
 * P1-04 slice 3: `restaurant_id` / `post_id` became `store_id`. Old clients keep working:
 * old request keys are aliased (global middleware), `api/*` JSON and outbound webhooks
 * mirror `store_id` as `restaurant_id`.
 */
class LegacyStoreKeysTest extends TestCase
{
    use RefreshDatabase;

    /** Runs the global middleware on a synthetic request; returns [request seen by the app, response]. */
    private function through(Request $request, ?JsonResponse $reply = null): array
    {
        $seen = null;
        $response = (new AcceptLegacyStoreKeys)->handle($request, function (Request $r) use (&$seen, $reply) {
            $seen = $r;

            return $reply ?? new JsonResponse(['ok' => true]);
        });

        return [$seen, $response];
    }

    public function test_alias_and_mirror_units(): void
    {
        $used = [];
        $this->assertSame(['x' => 1, 'store_id' => 5], LegacyStoreKeys::alias(['x' => 1, 'restaurant_id' => 5], $used));
        $this->assertSame(['restaurant_id'], $used);
        // an explicit store_id wins; the old key is always dropped
        $this->assertSame(['store_id' => 9], LegacyStoreKeys::alias(['store_id' => 9, 'post_id' => 5]));
        // nested (bag lines, MCP params.arguments)
        $this->assertEquals(['lines' => [['store_id' => 3, 'item_id' => 1]]], LegacyStoreKeys::alias(['lines' => [['restaurant_id' => 3, 'item_id' => 1]]]));
        $this->assertSame(['a' => ['store_id' => 2, 'restaurant_id' => 2]], LegacyStoreKeys::mirror(['a' => ['store_id' => 2]]));
        // never overwrites an existing key; non-scalar store_id is left alone
        $this->assertSame(['store_id' => 2, 'restaurant_id' => 8], LegacyStoreKeys::mirror(['store_id' => 2, 'restaurant_id' => 8]));
        $this->assertSame(['store_id' => ['x' => 1]], LegacyStoreKeys::mirror(['store_id' => ['x' => 1]]));
    }

    public function test_middleware_is_global(): void
    {
        $this->assertContains(AcceptLegacyStoreKeys::class, app(HttpKernel::class)->getGlobalMiddleware());
    }

    public function test_old_request_keys_reach_the_app_as_store_id(): void
    {
        Log::spy();

        $json = Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['restaurant_id' => 11, 'lines' => [['post_id' => 12]]]));
        [$seen] = $this->through($json);
        $this->assertSame(11, $seen->input('store_id'));
        $this->assertSame(12, $seen->input('lines.0.store_id'));
        $this->assertArrayNotHasKey('restaurant_id', $seen->all());

        [$seen] = $this->through(Request::create('/x', 'POST', ['post_id' => '13']));
        $this->assertSame('13', $seen->input('store_id'));

        [$seen] = $this->through(Request::create('/x?restaurant_id=14', 'GET'));
        $this->assertSame('14', $seen->query('store_id'));
        $this->assertNull($seen->query('restaurant_id'));

        Log::shouldHaveReceived('info')->with('legacy store key', \Mockery::on(fn ($ctx) => in_array('restaurant_id', $ctx['keys'], true)));
    }

    public function test_inbound_webhooks_keep_the_partner_key(): void
    {
        $req = Request::create('/webhook/swiggy/orders', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['restaurant_id' => 'SWG-1']));
        [$seen] = $this->through($req);
        $this->assertSame('SWG-1', $seen->input('restaurant_id'));
        $this->assertNull($seen->input('store_id'));
    }

    public function test_api_json_mirrors_store_id_as_restaurant_id(): void
    {
        $payload = ['data' => [['id' => 1, 'store_id' => 7]], 'store_id' => 7];
        [, $res] = $this->through(Request::create('/api/v1/x', 'GET'), new JsonResponse($payload));
        $this->assertSame(7, $res->getData(true)['restaurant_id']);
        $this->assertSame(7, $res->getData(true)['data'][0]['restaurant_id']);

        // non-API JSON is not touched
        [, $res] = $this->through(Request::create('/dashboard/x', 'GET'), new JsonResponse($payload));
        $this->assertArrayNotHasKey('restaurant_id', $res->getData(true));
    }

    public function test_api_v1_response_carries_both_keys(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        Customer::create(['store_id' => $store->id, 'name' => 'C', 'email' => 'c@example.test', 'password' => Hash::make('secret-pass')]);

        $res = $this->postJson('/api/v1/stores/'.$store->id.'/customer/login', ['email' => 'c@example.test', 'password' => 'secret-pass'])
            ->assertOk();
        $this->assertSame($store->id, (int) $res->json('customer.store_id'));
        $this->assertSame($store->id, (int) $res->json('customer.restaurant_id'));
    }
}
