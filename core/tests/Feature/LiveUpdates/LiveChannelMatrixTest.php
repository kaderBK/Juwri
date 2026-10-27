<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\LiveUpdates;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-57: allowed/denied matrix for every Broadcast::channel callback in routes/channels.php, including channel names
 * crafted to reach another tenant (id manipulation) and the owner's App.Models.User.{id} channel.
 */
class LiveChannelMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'reverb.public.host' => 'ws.example.test',
        ]);
        require base_path('routes/channels.php');
    }

    /** @return array{0: User, 1: Store} */
    private function owned(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function staff(User $owner, Store $store, string $role, ?array $permissions = null): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => $role, 'active' => 1, 'permissions' => $permissions,
        ]);
    }

    private function auth(string $channel)
    {
        return $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel]);
    }

    /** @return array<string, array{0: string}> */
    public static function crafted(): array
    {
        return [
            'trailing letters' => ['store.{a}x.orders'],
            'exponent' => ['store.{a}e0.orders'],
            'negative' => ['store.-{a}.orders'],
            'plus sign' => ['store.+{a}.orders'],
            'space' => ['store. {a}.orders'],
            'branch id with letters' => ['store.{a}.branch.{ba}x.orders'],
            'store b' => ['store.{b}.orders'],
            'store b, branch of b' => ['store.{b}.branch.{bb}.orders'],
            'store a, branch of b' => ['store.{a}.branch.{bb}.orders'],
            'store b, branch of a' => ['store.{b}.branch.{ba}.orders'],
            'unknown screen' => ['store.{a}.admin'],
            'empty id' => ['store..orders'],
        ];
    }

    #[DataProvider('crafted')]
    public function test_crafted_channel_names_never_reach_another_tenant(string $pattern): void
    {
        [$ownerA, $storeA] = $this->owned();
        [, $storeB] = $this->owned();
        $branchA = Branch::create(['store_id' => $storeA->id, 'name' => 'A1', 'active' => true]);
        $branchB = Branch::create(['store_id' => $storeB->id, 'name' => 'B1', 'active' => true]);
        $channel = strtr($pattern, ['{a}' => $storeA->id, '{b}' => $storeB->id, '{ba}' => $branchA->id, '{bb}' => $branchB->id]);

        $this->actingAs($ownerA);
        $this->assertContains($this->auth($channel)->status(), [403, 404], $channel);
        // control: the honest names still work
        $this->auth('store.'.$storeA->id.'.orders')->assertOk();
        $this->auth('store.'.$storeA->id.'.branch.'.$branchA->id.'.orders')->assertOk();
    }

    public function test_staff_without_permission_are_refused_every_screen_they_lack(): void
    {
        [$owner, $store] = $this->owned();
        $none = $this->staff($owner, $store, 'manager', ['orders' => false, 'kds' => false, 'pos' => false]);

        foreach (['orders', 'kds', 'pos'] as $suffix) {
            $this->actingAs($none, 'staff')->auth('store.'.$store->id.'.'.$suffix)->assertForbidden();
        }
    }

    public function test_guests_are_refused_every_channel(): void
    {
        [$owner, $store] = $this->owned();

        foreach (['store.'.$store->id.'.orders', 'App.Models.User.'.$owner->id] as $channel) {
            $this->auth($channel)->assertUnauthorized();
        }
    }

    public function test_owner_gets_only_their_own_user_channel(): void
    {
        [$ownerA] = $this->owned();
        [$ownerB] = $this->owned();

        $this->actingAs($ownerA)->auth('App.Models.User.'.$ownerA->id)->assertOk();
        $this->actingAs($ownerA)->auth('App.Models.User.'.$ownerB->id)->assertForbidden();
        $this->actingAs($ownerA)->auth('App.Models.User.'.$ownerA->id.'x')->assertForbidden();
    }

    public function test_a_staff_login_never_gets_an_owners_user_channel_by_matching_ids(): void
    {
        [$owner, $store] = $this->owned();
        $staff = $this->staff($owner, $store, 'manager');
        // the staff id may equal some owner's id: make sure one exists
        $victim = User::query()->find($staff->id) ?? User::factory()->create(['id' => $staff->id]);

        $this->actingAs($staff, 'staff')->auth('App.Models.User.'.$victim->id)->assertForbidden();
        // a staff session on the owner's web login does not get the owner's channel either
        $this->actingAs($owner, 'web')->actingAs($staff, 'staff')->withSession(['staff_session_id' => $staff->id])
            ->auth('App.Models.User.'.$owner->id)->assertForbidden();
    }
}
