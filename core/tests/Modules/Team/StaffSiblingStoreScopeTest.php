<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Reservation;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\EnsureEntitled;
use Juwri\Core\Http\Middleware\EnsureFeatureEnabled;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * BL-036 review (HIGH 4): the permission catalog opens owner pages to staff, so every one of them must stay
 * inside the staff member's own store, never another store of the same owner. The generated sweep
 * (CrossStoreIdorSweepTest::test_staff_never_reach_a_sibling_store_of_the_same_owner) covers every record
 * route and every query-string page; these are the reviewer's money cases, end to end.
 */
class StaffSiblingStoreScopeTest extends TestCase
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
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureFeatureEnabled::class, EnsureEntitled::class]);
        $this->owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->mine = $this->store('Mine');
        $this->sibling = $this->store('Sibling ZQSIB');
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

    private function asStaff(): self
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($this->staff, 'staff')->actingAs($this->owner, 'web')
            ->withSession(['staff_session_id' => $this->staff->id, 'dashboard_store_id' => $this->mine->id]);
    }

    private function reservation(Store $store, string $name): Reservation
    {
        return Reservation::forceCreate(['account_id' => $store->fresh()->account_id, 'store_id' => $store->id, 'name' => $name,
            'phone' => '1', 'party_size' => 2, 'reserved_at' => now()->addDay(), 'status' => 'confirmed']);
    }

    public function test_staff_cannot_cancel_a_sibling_store_reservation(): void
    {
        $theirs = $this->reservation($this->sibling, 'Their guest');
        $ours = $this->reservation($this->mine, 'Our guest');

        $status = $this->asStaff()->post(route('reservations.status', $theirs->id), ['status' => 'cancelled'])->getStatusCode();
        $this->assertContains($status, [403, 404]);
        $this->assertSame('confirmed', $theirs->fresh()->status);

        $this->asStaff()->post(route('reservations.status', $ours->id), ['status' => 'cancelled']);
        $this->assertSame('cancelled', $ours->fresh()->status, 'their own store still works');
    }

    public function test_staff_cannot_list_a_sibling_store_reservations(): void
    {
        $this->reservation($this->sibling, 'Their guest ZQSIB');

        $res = $this->asStaff()->get(route('reservations.index', ['store_id' => $this->sibling->id]));
        $this->assertStringNotContainsString('ZQSIB', (string) $res->getContent());
    }

    public function test_manual_deposit_refund_is_owner_only(): void
    {
        $theirs = $this->reservation($this->mine, 'Guest');

        $this->asStaff()->post(route('reservations.deposit.manual-refund', $theirs->id))->assertForbidden();
    }

    public function test_staff_cannot_open_the_theme_customizer_of_a_sibling_store(): void
    {
        foreach (['storeId', 'store', 'store_id'] as $q) {
            $res = $this->asStaff()->get(route('theme.customizer.page', [$q => $this->sibling->id]));
            $this->assertStringNotContainsString('ZQSIB', (string) $res->getContent(), "?$q= must not open the sibling store");
        }
    }
}
