<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * M3 — dashnotif_prefs filtering wired into the bell counts.
 *
 * notificationCounts() and markNotificationsSeen() now share ONE compute method
 * (no duplicated query block) and both narrow the per-event store scope by the
 * user's prefs; the orders count additionally drops muted branches. Disabling a
 * store/branch must drop its pending orders from the count AND from the read
 * watermark, so the badge can't relight on notifications the user muted.
 */
class DashNotifPrefsCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeStore(User $o): Store
    {
        $t = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $o->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pendingOrder(Store $store, ?int $branchId = null): void
    {
        (new Order)->forceFill([
            'store_id' => $store->id, 'branch_id' => $branchId, 'type' => 'takeaway',
            'status' => 'new', 'is_paid' => 0, 'source' => 'qr', 'held_at' => null,
            'created_at' => now(),
        ])->save();
    }

    private function setPrefs(User $owner, array $prefs): void
    {
        UserOption::updateUserOption($owner->id, 'dashnotif_prefs', json_encode($prefs));
    }

    public function test_disabling_a_store_excludes_its_pending_orders(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $b = $this->makeStore($owner);
        $this->pendingOrder($a);
        $this->pendingOrder($a);
        $this->pendingOrder($b);            // will be muted

        // The bell counts the CURRENT store; pin it to B explicitly (the unpinned
        // fallback is latest() — a created_at tie between A and B, which PostgreSQL
        // breaks either way).
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $b->id])
            ->getJson(route('dashboard.notifications'))
            ->assertOk()
            ->assertJsonPath('orders', 1);   // B's one before prefs

        $this->setPrefs($owner, ['stores' => [(string) $b->id => ['enabled' => false]]]);

        $this->actingAs($owner)->withSession(['dashboard_store_id' => $b->id])
            ->getJson(route('dashboard.notifications'))
            ->assertOk()
            ->assertJsonPath('orders', 0);   // B is muted
    }

    public function test_disabling_a_branch_excludes_only_that_branchs_orders(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $b1 = Branch::create(['store_id' => $a->id, 'name' => 'B1', 'active' => 1]);
        $b2 = Branch::create(['store_id' => $a->id, 'name' => 'B2', 'active' => 1]);
        $this->pendingOrder($a, $b1->id);
        $this->pendingOrder($a, $b1->id);
        $this->pendingOrder($a, $b2->id);   // muted branch
        $this->pendingOrder($a, null);      // no branch — always counted

        $this->setPrefs($owner, ['stores' => [
            (string) $a->id => ['enabled' => true, 'branches' => [(string) $b2->id => false]],
        ]]);

        $this->actingAs($owner)->getJson(route('dashboard.notifications'))
            ->assertOk()
            ->assertJsonPath('orders', 3);   // 2 (B1) + 1 (no branch); B2's one excluded (was 4)
    }

    public function test_watermark_matches_the_prefs_filtered_total(): void
    {
        // One store (no current-store ambiguity); its muted branch makes the
        // filtered total differ from the raw one.
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $on = Branch::create(['store_id' => $a->id, 'name' => 'On', 'active' => 1]);
        $muted = Branch::create(['store_id' => $a->id, 'name' => 'Muted', 'active' => 1]);
        $this->pendingOrder($a, $on->id);
        $this->pendingOrder($a, $on->id);
        $this->pendingOrder($a, $muted->id);

        $this->setPrefs($owner, ['stores' => [
            (string) $a->id => ['enabled' => true, 'branches' => [(string) $muted->id => false]],
        ]]);

        $this->actingAs($owner)->postJson(route('dashboard.notifications.seen'))->assertOk();

        // Seen watermark is the FILTERED total (2), not the raw 3 — otherwise the
        // badge would relight the moment counts recompute.
        $this->assertSame(2, (int) UserOption::getUserOption($owner->id, 'dashnotif_seen', 0));
    }
}
