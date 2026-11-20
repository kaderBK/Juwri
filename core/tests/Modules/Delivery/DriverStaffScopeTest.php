<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Driver;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\EnsureEntitled;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * AUDIT-tenancy M1: a staff session bound to store A must never list, rotate, toggle or delete a driver of the
 * owner's store B (or an owner-wide driver), and must never read a one-time driver token the owner made.
 */
class DriverStaffScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $mine;

    private Store $sibling;

    private StaffMember $staff;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_driver_app' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureFeatureEnabled::class, EnsureEntitled::class]);
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $this->owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->mine = $this->store('Mine');
        $this->sibling = $this->store('Sibling');
        $this->staff = StaffMember::create([
            'owner_id' => $this->owner->id, 'store_id' => $this->mine->id, 'name' => 'M', 'email' => Str::lower(Str::random(8)).'@t.test',
            'password' => Hash::make('x'), 'role' => 'manager', 'active' => 1,
            'permissions' => array_fill_keys(StaffMember::PERMISSION_KEYS, true),
        ]);
    }

    private function store(string $title): Store
    {
        return Store::create(['user_id' => $this->owner->id, 'slug' => 's-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => $title,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
    }

    private function driver(?Store $store, string $name): Driver
    {
        return Driver::create(['owner_id' => $this->owner->id, 'store_id' => $store?->id, 'name' => $name, 'token' => hash('sha256', Str::random(48)), 'active' => true]);
    }

    private function asStaff(): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->staff, 'staff')->actingAs($this->owner, 'web')
            ->withSession(['staff_session_id' => $this->staff->id, 'dashboard_store_id' => $this->mine->id]);
    }

    public function test_staff_list_shows_only_their_store_drivers(): void
    {
        $this->driver($this->mine, 'Our driver ZQOURS');
        $this->driver($this->sibling, 'Their driver ZQSIB');
        $this->driver(null, 'Owner wide ZQWIDE');

        $res = $this->asStaff()->get(route('drivers.index'))->assertOk();
        $res->assertSee('ZQOURS');
        $res->assertDontSee('ZQSIB');
        $res->assertDontSee('ZQWIDE');
    }

    public function test_staff_cannot_rotate_toggle_or_delete_a_sibling_or_owner_wide_driver(): void
    {
        foreach ([$this->driver($this->sibling, 'Theirs'), $this->driver(null, 'Wide')] as $driver) {
            $token = $driver->token;
            $this->asStaff()->post(route('drivers.rotate', $driver->id))->assertNotFound();
            $this->asStaff()->post(route('drivers.toggle', $driver->id))->assertNotFound();
            $this->asStaff()->delete(route('drivers.destroy', $driver->id))->assertNotFound();
            $fresh = $driver->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame($token, $fresh->token);
            $this->assertTrue((bool) $fresh->active);
        }
    }

    public function test_staff_still_manage_their_own_store_driver(): void
    {
        $ours = $this->driver($this->mine, 'Ours');

        $res = $this->asStaff()->post(route('drivers.rotate', $ours->id))->assertRedirectContains('new_driver_token='.$ours->id);
        preg_match('#<code class="ui-drivers__token ui-ltr">([^<]+)</code>#', (string) $this->asStaff()->get((string) $res->headers->get('Location'))->getContent(), $m);
        $this->assertSame(hash('sha256', $m[1] ?? ''), $ours->fresh()->token);

        $this->asStaff()->post(route('drivers.toggle', $ours->id));
        $this->assertFalse((bool) $ours->fresh()->active);
        $this->asStaff()->delete(route('drivers.destroy', $ours->id));
        $this->assertNull($ours->fresh());
    }

    public function test_staff_cannot_read_a_token_the_owner_just_made(): void
    {
        $theirs = $this->driver($this->sibling, 'Theirs');
        $this->actingAs($this->owner)->post(route('drivers.rotate', $theirs->id))->assertRedirect();

        $this->asStaff()->get(route('drivers.index', ['new_driver_token' => $theirs->id]))
            ->assertOk()->assertDontSee('ui-drivers__token', false);
    }

    public function test_a_driver_added_by_staff_is_bound_to_their_store(): void
    {
        $this->asStaff()->post(route('drivers.store'), ['name' => 'New'])->assertRedirect();
        $this->assertDatabaseHas('drivers', ['owner_id' => $this->owner->id, 'name' => 'New', 'store_id' => $this->mine->id]);

        $this->asStaff()->post(route('drivers.store'), ['name' => 'Other', 'store_id' => $this->sibling->id])->assertNotFound();
        $this->assertDatabaseMissing('drivers', ['name' => 'Other']);
    }
}
