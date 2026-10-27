<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Services\SandboxStores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-315 owner activity log filters / CSV; P6-317 admin store search and sandbox-free stats. */
class ActivityLogFiltersAndStoreSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(?User $owner = null, array $attrs = []): Store
    {
        if (! $owner) {
            $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999], 'status' => 1]);
            $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        }
        $t = 'S'.Str::random(6);

        return Store::create(array_merge(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::random(5), 'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()], $attrs));
    }

    private function log(User $actor, Store $s, string $action, $at): ActivityLog
    {
        return ActivityLog::create(['actor_user_id' => $actor->id, 'store_id' => $s->id, 'action' => $action, 'created_at' => $at]);
    }

    public function test_staff_action_is_filterable_and_percent_is_literal(): void
    {
        $s = $this->store();
        $owner = User::find($s->user_id);
        $this->log($owner, $s, 'staff.updated', now());
        $this->log($owner, $s, 'menu.created', now());

        $html = $this->actingAs($owner)->get(route('activity-log.index', ['action' => 'staff']))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="staff"', $html);
        $logs = $this->actingAs($owner)->get(route('activity-log.index', ['action' => 'staff']))->viewData('logs');
        $this->assertSame(['staff.updated'], $logs->pluck('action')->all());

        $logs = $this->actingAs($owner)->get(route('activity-log.index', ['action' => '%']))->viewData('logs');
        $this->assertCount(0, $logs);
    }

    public function test_date_range_excludes_yesterday_and_actor_filter_works(): void
    {
        $s = $this->store();
        $owner = User::find($s->user_id);
        $other = User::factory()->create();
        $this->log($owner, $s, 'menu.today', now());
        $this->log($owner, $s, 'menu.yesterday', now()->subDays(2));
        $this->log($other, $s, 'menu.other', now());

        $today = now()->toDateString();
        $logs = $this->actingAs($owner)->get(route('activity-log.index', ['from' => $today, 'to' => $today, 'actor' => $owner->id]))->viewData('logs');
        $this->assertSame(['menu.today'], $logs->pluck('action')->all());
    }

    public function test_csv_export_respects_filters(): void
    {
        $s = $this->store();
        $owner = User::find($s->user_id);
        $this->log($owner, $s, 'staff.updated', now());
        $this->log($owner, $s, 'menu.created', now());

        $res = $this->actingAs($owner)->get(route('activity-log.index', ['action' => 'staff', 'format' => 'csv']))->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('staff.updated', $csv);
        $this->assertStringNotContainsString('menu.created', $csv);
    }

    public function test_admin_search_by_id_domain_and_owner_phone_and_sandbox_stats(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $a = $this->store();
        $b = $this->store(null, ['phone' => '+447700900123']);
        $c = $this->store();
        DB::table('store_domains')->insert(['account_id' => $c->account_id ?? 0, 'store_id' => $c->id, 'type' => 'custom', 'host' => 'menu.findme-example.com', 'is_primary' => false, 'ssl_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        $ids = fn (array $q) => $this->actingAs($admin)->get(route('admin.stores.index', $q))->assertOk()->viewData('stores')->pluck('id')->all();

        $this->assertContains($a->id, $ids(['q' => (string) $a->id]));
        $this->assertSame([$c->id], $ids(['q' => 'findme-example.com']));
        $this->assertSame([$b->id], $ids(['q' => '447700900']));

        SandboxStores::flag($c->id);
        $stats = $this->actingAs($admin)->get(route('admin.stores.index'))->viewData('stats');
        $this->assertSame(2, $stats['total']);
    }
}
