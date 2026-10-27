<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D5 — rush / priority flag: the toggle endpoint, rush tickets jumping the KDS
 * queue, and the red chip rendering on a dashboard order row.
 */
class RushFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        View::share('errors', new ViewErrorBag);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'M '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
    }

    private function order(Store $r, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'store_id' => $r->id, 'status' => 'new', 'type' => 'on-table',
            'customer_name' => 'C', 'table_number' => 1, 'created_at' => now(),
        ], $attrs));
    }

    public function test_toggle_endpoint_flips_the_flag(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $order = $this->order($r);

        $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.rush', ['store' => $r->id, 'id' => $order->id]), [])
            ->assertStatus(200)->assertJsonPath('is_rush', true);
        $this->assertTrue((bool) $order->fresh()->is_rush);

        // A second bare toggle clears it.
        $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.rush', ['store' => $r->id, 'id' => $order->id]), [])
            ->assertStatus(200)->assertJsonPath('is_rush', false);
        $this->assertFalse((bool) $order->fresh()->is_rush);
    }

    public function test_explicit_value_sets_rush(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $order = $this->order($r);

        $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.rush', ['store' => $r->id, 'id' => $order->id]), ['is_rush' => true])
            ->assertStatus(200)->assertJsonPath('is_rush', true);
    }

    public function test_rush_tickets_sort_first_on_kds(): void
    {
        $r = $this->storeFixture();
        // An OLDER normal ticket and a NEWER rush ticket — rush jumps the queue.
        $normal = $this->order($r, ['created_at' => now()->subMinutes(10)]);
        $rush = $this->order($r, ['created_at' => now(), 'is_rush' => true]);

        $res = $this->actingAs(User::find($r->user_id))
            ->getJson(route('kds.poll', ['store_id' => $r->id]))
            ->assertOk();

        $ids = collect($res->json())->pluck('id')->all();
        $this->assertSame([$rush->id, $normal->id], $ids); // rush first despite being newer
        $this->assertTrue(collect($res->json())->firstWhere('id', $rush->id)['is_rush']);
    }

    public function test_rush_chip_renders_on_order_row(): void
    {
        $r = $this->storeFixture();
        $order = $this->order($r, ['is_rush' => true]);
        $order->setRelation('items', collect());

        $html = view('owner.orders.order-row', ['order' => $order])->render();
        $this->assertStringContainsString('RUSH', $html);
    }
}
