<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat\Import;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\User;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacyProdShapeFixture as Shape;
use Juwri\Modules\LegacyCompat\Database\Factories\LegacySourceFixture as Source;
use Juwri\Modules\LegacyCompat\Http\Middleware\LegacyGatewayNotice;
use Juwri\Modules\LegacyCompat\Import\PlanMemberships;
use Juwri\Modules\LegacyCompat\Import\StoreSettingsImporter;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * J59c plans and J59e gateways (SYNTHETIC rows, J19): owners keep their plan (through the configured plan map) and
 * paid-until date with no gateway subscription carried over; an unmapped or unknown plan is the free plan (reported,
 * never guessed); live add-ons move; a resumed run writes nothing twice. Gateways this install does not offer at
 * launch come with their keys but switched off, recorded per store, and the owner is told once on the dashboard.
 */
class LegacyPlanImportTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'legacy_plan_source';

    private Source $src;

    private Shape $shape;

    /** Id of the paid plan this install sells (null: an edition without plans). */
    private ?int $planId = null;

    private function requirePlans(): int
    {
        if ($this->planId === null) {
            $this->markTestSkipped('this edition sells no plans (the free-plan path is covered below)');
        }

        return $this->planId;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.'.self::SOURCE => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'legacy_compat.enabled' => true,
            'legacy_compat.import.enabled' => true,
            'legacy_compat.cutover.owner_signoff' => true,
            'legacy_compat.import.target_host' => 'localhost',
            'legacy_compat.import.source_timezone' => 'UTC',
            'legacy_compat.import.source_app_key' => '',
        ]);
        // The edition's plan when one that sells plans is installed; the Community stand-in otherwise (no plan row).
        $this->planId = TestPlans::productId(TestPlans::create([
            'name' => 'Pro (synthetic)', 'status' => 1, 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 300,
            'settings' => (object) ['menu_items' => 100],
        ]));
        config(['legacy_compat.import.plan_map' => ['3' => $this->planId === null ? 'free' : (string) $this->planId, '4' => 'free', '5' => '99']]);
        DB::purge(self::SOURCE);
        $this->src = (new Source(DB::connection(self::SOURCE)))->createSchema();
        $this->shape = (new Shape(DB::connection(self::SOURCE)))->laterColumns()->laterTenancy()->laterScope();
        $this->src->platformOption('currency_code', 'USD');
    }

    protected function tearDown(): void
    {
        DB::purge(self::SOURCE);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function import(): array
    {
        $exit = Artisan::call('legacy:import', ['--source' => self::SOURCE]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $report = json_decode($output, true);
        $this->assertIsArray($report, $output);

        return $report;
    }

    private function at(int $days): string
    {
        return now()->utc()->addDays($days)->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $attrs */
    private function subscription(int $id, int $user, ?int $plan, string $status, ?string $endsAt, array $attrs = []): void
    {
        $this->shape->row('subscriptions', $attrs + ['id' => $id, 'user_id' => $user, 'plan_id' => $plan, 'status' => $status,
            'interval_' => 'monthly', 'starts_at' => '2025-01-01 00:00:00', 'ends_at' => $endsAt]);
    }

    public function test_owners_keep_their_mapped_plan_and_paid_until_date_without_any_gateway_subscription(): void
    {
        $plan = $this->requirePlans();
        $s = $this->src;
        foreach ([1 => '3', 2 => '6', 3 => '3', 4 => '4', 5 => '5'] as $id => $group) {
            $s->user($id, ['group_id' => $group]);
            $s->store($id * 10, $id);
            $s->storeOption($id * 10, 'currency_code', 'USD');
        }
        $paidUntil = $this->at(20);
        $this->subscription(1, 1, 3, 'active', $paidUntil, ['anchor_day' => 1, 'meta' => '{"gateway":"stripe","gateway_subscription_id":"sub_synthetic"}']);
        $this->shape->row('upgrades', ['upgrade_id' => 1, 'sub_id' => '3', 'user_id' => 1, 'pay_mode' => 'recurring', 'interval' => 'MONTHLY',
            'upgrade_expires' => now()->addDays(20)->getTimestamp(), 'unique_id' => 'stripe###sub_synthetic', 'status' => 'Active']);
        $this->subscription(2, 2, 6, 'active', $this->at(20)); // plan not in the map
        $this->subscription(3, 3, 3, 'canceled', $this->at(-2)); // cancelled and over
        $this->subscription(4, 4, 4, 'active', $this->at(20)); // mapped to free
        $this->subscription(5, 5, 5, 'active', $this->at(20)); // mapped to a plan this install does not have
        $this->shape->row('user_addons', ['id' => 1, 'user_id' => 1, 'type' => 'extra_branch', 'quantity' => 2, 'quoted_price' => 15,
            'pay_mode' => 'recurring', 'unique_id' => 'stripe###si_synthetic', 'auto_renew' => true, 'expires_at' => $this->at(20), 'status' => 'active']);
        $this->shape->row('user_addons', ['id' => 2, 'user_id' => 1, 'type' => 'sms_pack', 'expires_at' => $this->at(-1)]);
        $this->shape->row('user_addons', ['id' => 3, 'user_id' => 1, 'type' => 'wallet_pack', 'pay_mode' => 'wallet', 'auto_renew' => true, 'quoted_price' => 5]);

        $report = $this->import();

        $groups = DB::table('users')->orderBy('id')->pluck('group_id', 'id')->all();
        $this->assertSame([1 => (string) $plan, 2 => 'free', 3 => 'free', 4 => 'free', 5 => 'free'], $groups);
        $sub = DB::table('subscriptions')->where('user_id', 1)->first();
        $this->assertNotNull($sub);
        $this->assertSame([$plan, 'active', 'monthly', 1], [(int) $sub->plan_id, $sub->status, $sub->interval_, (int) $sub->anchor_day]);
        $this->assertStringStartsWith($paidUntil, (string) $sub->ends_at);
        $meta = json_decode((string) $sub->meta, true);
        ksort($meta); // jsonb keeps its own key order
        $this->assertSame(['entitlements' => ['menu_items' => 100], 'legacy_plan_id' => '3', 'source' => 'legacy_import'], $meta);
        $this->assertSame((int) DB::table('accounts')->where('owner_user_id', 1)->value('id'), (int) $sub->account_id);
        $this->assertSame(1, DB::table('subscriptions')->count());
        $this->assertSame(1, DB::table('subscription_events')->where('subscription_id', $sub->id)->where('event', 'created')->count());

        // The legacy membership row: a one-time paid period, the gateway subscription reference dropped.
        $upgrade = DB::table('upgrades')->where('user_id', 1)->first();
        $this->assertSame([(string) $plan, 'one_time', 'MONTHLY', null], [$upgrade->sub_id, $upgrade->pay_mode, $upgrade->interval, $upgrade->unique_id]);
        $this->assertEqualsWithDelta(now()->utc()->addDays(20)->getTimestamp(), (int) $upgrade->upgrade_expires, 5);
        $this->assertSame(1, DB::table('upgrades')->count());
        $this->assertSame(1, DB::table('legacy_import_marks')->where('target_table', 'users')->where('target_id', '1')->where('mark', PlanMemberships::GATEWAY_DROPPED)->count());
        $this->assertSame(1, $report['counters']['plans_gateway_subscription_dropped']);

        $kind = 'plans: source plan not in the plan map (LEGACY_IMPORT_PLAN_MAP), free plan (J59c)';
        $this->assertSame(['user#2 plan#6'], $report['conflicts'][$kind]['samples']);
        $this->assertSame(['user#5 plan#5 > 99'], $report['conflicts']['plans: the plan map names a plan this install does not have, free plan (J59c)']['samples']);
        $this->assertSame(1, $report['counters']['plans_mapped_to_free']);

        // Live add-ons only; a gateway-recurring one becomes one-time, a wallet auto-renew stays.
        $addons = DB::table('account_addons')->orderBy('type')->get()->keyBy('type');
        $this->assertSame(['extra_branch', 'wallet_pack'], $addons->keys()->all());
        $this->assertSame(['one_time', null, false, 1500, 'USD', 2], [$addons['extra_branch']->pay_mode, $addons['extra_branch']->unique_id,
            (bool) $addons['extra_branch']->auto_renew, (int) $addons['extra_branch']->quoted_price_minor, $addons['extra_branch']->currency_code, (int) $addons['extra_branch']->quantity]);
        $this->assertSame(['wallet', true], [$addons['wallet_pack']->pay_mode, (bool) $addons['wallet_pack']->auto_renew]);

        $this->assertSame(1, $report['decisions']['J59c_plans']['plans_kept']);
        $this->assertSame(2, $report['decisions']['J59c_plans']['unmapped_to_free']);

        // Resume: nothing written twice.
        $this->import();
        $this->assertSame(1, DB::table('subscriptions')->count());
        $this->assertSame(1, DB::table('upgrades')->count());
        $this->assertSame(2, DB::table('account_addons')->count());
    }

    public function test_a_source_with_only_legacy_membership_rows_keeps_paid_trial_and_granted_plans(): void
    {
        $plan = $this->requirePlans();
        DB::connection(self::SOURCE)->getSchemaBuilder()->drop('subscriptions');
        $s = $this->src;
        foreach ([5 => '3', 6 => 'trial', 7 => '3', 8 => '3'] as $id => $group) {
            $s->user($id, ['group_id' => $group]);
        }
        $up = ['pay_mode' => 'one_time', 'upgrade_lasttime' => now()->subDays(10)->getTimestamp(), 'status' => 'Active'];
        $this->shape->row('upgrades', ['upgrade_id' => 1, 'sub_id' => '3', 'user_id' => 5, 'interval' => 'YEARLY', 'upgrade_expires' => now()->addDays(100)->getTimestamp()] + $up);
        $this->shape->row('upgrades', ['upgrade_id' => 2, 'sub_id' => 'trial', 'user_id' => 6, 'upgrade_expires' => now()->addDays(3)->getTimestamp()] + $up);
        $this->shape->row('upgrades', ['upgrade_id' => 3, 'sub_id' => '3', 'user_id' => 7, 'interval' => 'MONTHLY', 'upgrade_expires' => now()->subDay()->getTimestamp()] + $up);

        $report = $this->import();

        $this->assertSame([5 => (string) $plan, 6 => 'trial', 7 => 'free', 8 => (string) $plan], DB::table('users')->orderBy('id')->pluck('group_id', 'id')->all());
        $subs = DB::table('subscriptions')->orderBy('user_id')->get()->keyBy('user_id');
        $this->assertSame(['active', 'yearly', $plan], [$subs[5]->status, $subs[5]->interval_, (int) $subs[5]->plan_id]);
        $this->assertSame(['trialing', 'trial', null], [$subs[6]->status, $subs[6]->interval_, $subs[6]->plan_id]);
        $this->assertFalse($subs->has(7)); // expired: free
        $this->assertSame(['active', 'lifetime', null], [$subs[8]->status, $subs[8]->interval_, $subs[8]->ends_at]); // an admin grant
        $this->assertSame('trial', DB::table('upgrades')->where('user_id', 6)->value('sub_id'));
        $this->assertSame('LIFETIME', DB::table('upgrades')->where('user_id', 8)->value('interval'));
        $this->assertSame(2, $report['counters']['plans_kept']);
        $this->assertSame(1, $report['counters']['plans_trial_kept']);
    }

    public function test_a_plan_this_install_does_not_sell_lands_on_the_free_plan_reported_in_every_edition(): void
    {
        config(['legacy_compat.import.plan_map' => ['3' => '777777']]); // no such plan here (nor any plans table in Community)
        DB::connection(self::SOURCE)->getSchemaBuilder()->drop('subscriptions');
        $this->src->user(1, ['group_id' => '3']);
        $this->src->store(10, 1);
        $this->src->storeOption(10, 'currency_code', 'USD');
        $this->shape->row('upgrades', ['upgrade_id' => 1, 'sub_id' => '3', 'user_id' => 1, 'interval' => 'MONTHLY', 'upgrade_expires' => now()->addDays(20)->getTimestamp()]);

        $report = $this->import();

        $this->assertSame('free', DB::table('users')->where('id', 1)->value('group_id'));
        $this->assertSame(0, DB::table('upgrades')->count());
        $this->assertSame(['user#1 plan#3 > 777777'], $report['conflicts']['plans: the plan map names a plan this install does not have, free plan (J59c)']['samples']);
    }

    public function test_gateways_not_offered_at_launch_come_switched_off_and_the_owner_is_told_once(): void
    {
        $s = $this->src;
        $s->user(1);
        $s->store(10, 1);
        $s->storeOption(10, 'currency_code', 'USD');
        $s->storeOption(10, Source::gatewayKey('stripe', 'install'), '1');
        $s->storeOption(10, Source::gatewayKey('tap', 'install'), '1');
        $s->storeOption(10, Source::gatewayKey('moyasar', 'install'), '1');
        $s->storeOption(10, Source::gatewayKey('razorpay', 'install'), '0');

        $report = $this->import();

        $gateways = DB::table('store_payment_gateways')->where('store_id', 10)->pluck('enabled', 'gateway')->map(fn ($v) => (bool) $v)->all();
        $this->assertFalse($gateways['tap']);
        $this->assertFalse($gateways['moyasar']);
        $mark = DB::table('legacy_import_marks')->where('target_table', 'stores')->where('target_id', '10')->where('mark', StoreSettingsImporter::GATEWAYS_OFF)->first();
        $this->assertNotNull($mark);
        $this->assertSame(['moyasar', 'tap'], json_decode((string) $mark->detail, true)['gateways']);
        $this->assertSame(['10' => ['moyasar', 'tap']], $report['decisions']['J59e_gateways']['stores_with_gateways_switched_off']);

        config(['app.installed' => true]);
        LegacyCompatServiceProvider::activate($this->app);
        $this->assertContains(LegacyGatewayNotice::class, $this->app->make(Router::class)->getMiddlewareGroups()['web']);
        $user = User::query()->withoutGlobalScopes()->findOrFail(1);

        // Not in an admin's "log in as" session: the notice waits for the owner.
        $this->actingAs($user)->withSession(['quick_admin_user_id' => 99])->get('/dashboard');
        $this->assertTrue(DB::table('legacy_import_marks')->where('mark', StoreSettingsImporter::GATEWAYS_OFF)->exists());

        $this->flushSession();
        $this->actingAs($user)->get('/dashboard');
        $this->assertStringContainsString('Moyasar, Tap', (string) session('warning'));
        $this->assertSame(___(LegacyGatewayNotice::MESSAGE, ['gateways' => 'Moyasar, Tap']), session('warning')); // translated
        $this->assertFalse(DB::table('legacy_import_marks')->where('mark', StoreSettingsImporter::GATEWAYS_OFF)->exists());

        $this->flushSession();
        $this->actingAs($user)->get('/dashboard');
        $this->assertNull(session('warning')); // told once
    }
}
