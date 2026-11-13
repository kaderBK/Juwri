<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\KitchenStation;
use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\OrderApiService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Pos\Models\PrintJob;
use Juwri\Modules\Pos\Services\Printing\PrintDispatcher;
use Juwri\Modules\Pos\Services\Printing\PrintSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T1: profile routing (event × station × branch), the auto-print
 * triggers, job idempotency, the register's queue endpoints and the owner CRUD.
 */
class PrintRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        config(['settings.admin_allow_print_routing' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        // The '*' composer that shares the layout scaffolding is gated behind
        // env('APP_INSTALLED'), which is deliberately false in phpunit.xml.
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

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

    private function staff(Store $r, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    private function item(Store $r, float $price = 12, ?int $stationId = null): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish '.Str::random(4),
            'price' => $price, 'active' => 1, 'station_id' => $stationId,
        ]);
    }

    private function profile(Store $r, array $attrs = []): PrinterProfile
    {
        return PrinterProfile::create(array_merge([
            'store_id' => $r->id, 'name' => 'Front', 'transport' => 'webserial',
            'width_mm' => 80, 'events' => ['new_order'], 'station_ids' => [],
            'auto_print' => true, 'active' => true, 'copies' => 1,
        ], $attrs));
    }

    // ── routing matrix ───────────────────────────────────────────────────────

    public function test_a_profile_only_matches_events_it_subscribes_to(): void
    {
        $r = $this->storeFixture();
        $this->profile($r, ['name' => 'Receipt', 'events' => ['paid']]);
        $this->profile($r, ['name' => 'Kitchen', 'events' => ['kot']]);

        $this->assertSame(['Receipt'], PrintDispatcher::profilesFor($r->id, 'paid', null)->pluck('name')->all());
        $this->assertSame(['Kitchen'], PrintDispatcher::profilesFor($r->id, 'kot', null)->pluck('name')->all());
        $this->assertCount(0, PrintDispatcher::profilesFor($r->id, 'shift_close', null));
    }

    /** A profile with no branch is the store-wide default; a branch profile is not. */
    public function test_branch_scoping(): void
    {
        $r = $this->storeFixture();
        $b1 = Branch::create(['store_id' => $r->id, 'name' => 'North', 'active' => 1, 'position' => 1]);
        $b2 = Branch::create(['store_id' => $r->id, 'name' => 'South', 'active' => 1, 'position' => 2]);

        $this->profile($r, ['name' => 'All']);
        $this->profile($r, ['name' => 'North only', 'branch_id' => $b1->id]);

        $this->assertSame(['All', 'North only'], PrintDispatcher::profilesFor($r->id, 'new_order', $b1->id)->pluck('name')->all());
        $this->assertSame(['All'], PrintDispatcher::profilesFor($r->id, 'new_order', $b2->id)->pluck('name')->all());
        $this->assertSame(['All'], PrintDispatcher::profilesFor($r->id, 'new_order', null)->pluck('name')->all());
    }

    public function test_inactive_and_manual_profiles_are_skipped_by_auto_print(): void
    {
        $r = $this->storeFixture();
        $this->profile($r, ['name' => 'Off', 'active' => false]);
        $this->profile($r, ['name' => 'Manual', 'auto_print' => false]);

        $this->assertCount(0, PrintDispatcher::profilesFor($r->id, 'new_order', null));
        // …but a manual profile is still offered for the register's print menu.
        $this->assertSame(['Manual'], PrintDispatcher::profilesFor($r->id, 'new_order', null, autoOnly: false)->pluck('name')->all());
    }

    public function test_another_stores_profiles_are_never_matched(): void
    {
        $mine = $this->storeFixture();
        $theirs = $this->storeFixture();
        $this->profile($theirs, ['name' => 'Not mine']);

        $this->assertCount(0, PrintDispatcher::profilesFor($mine->id, 'new_order', null));
    }

    // ── station-filtered KOT ─────────────────────────────────────────────────

    public function test_each_station_printer_gets_only_its_own_lines(): void
    {
        $r = $this->storeFixture();
        $kitchen = KitchenStation::create(['store_id' => $r->id, 'name' => 'Kitchen']);
        $bar = KitchenStation::create(['store_id' => $r->id, 'name' => 'Bar']);

        $kProfile = $this->profile($r, ['name' => 'Kitchen printer', 'events' => ['kot'], 'station_ids' => [$kitchen->id]]);
        $bProfile = $this->profile($r, ['name' => 'Bar printer', 'events' => ['kot'], 'station_ids' => [$bar->id]]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [
                ['item_id' => $this->item($r, 20, $kitchen->id)->id, 'quantity' => 1],
                ['item_id' => $this->item($r, 15, $bar->id)->id, 'quantity' => 1],
            ],
        ]);

        $kJob = PrintJob::where('profile_id', $kProfile->id)->first();
        $bJob = PrintJob::where('profile_id', $bProfile->id)->first();

        $this->assertNotNull($kJob);
        $this->assertNotNull($bJob);

        $kBytes = base64_decode($kJob->payload);
        $bBytes = base64_decode($bJob->payload);
        $this->assertStringContainsString('Kitchen', $kBytes);
        $this->assertStringContainsString('Bar', $bBytes);
        // Each ticket carries only its own station's item — the whole point.
        $this->assertNotSame($kBytes, $bBytes);
    }

    /** A bar printer stays silent on a food-only order rather than spitting a blank. */
    public function test_a_station_printer_with_no_matching_lines_prints_nothing(): void
    {
        $r = $this->storeFixture();
        $kitchen = KitchenStation::create(['store_id' => $r->id, 'name' => 'Kitchen']);
        $bar = KitchenStation::create(['store_id' => $r->id, 'name' => 'Bar']);
        $barProfile = $this->profile($r, ['events' => ['kot'], 'station_ids' => [$bar->id]]);

        OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $this->item($r, 20, $kitchen->id)->id, 'quantity' => 1]],
        ]);

        $this->assertSame(0, PrintJob::where('profile_id', $barProfile->id)->count());
    }

    // ── triggers ─────────────────────────────────────────────────────────────

    public function test_creating_an_order_queues_new_order_and_kot_tickets(): void
    {
        $r = $this->storeFixture();
        $receipt = $this->profile($r, ['name' => 'Receipt', 'events' => ['new_order']]);
        $kitchen = $this->profile($r, ['name' => 'Kitchen', 'events' => ['kot']]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $this->item($r)->id, 'quantity' => 2]],
        ]);

        $this->assertSame(1, PrintJob::where('profile_id', $receipt->id)->where('event', 'new_order')->count());
        $this->assertSame(1, PrintJob::where('profile_id', $kitchen->id)->where('event', 'kot')->count());
        $this->assertSame($order->id, (int) PrintJob::first()->order_id);
    }

    /** A parked order has not happened yet — the kitchen ticket waits for the resume. */
    public function test_a_held_order_prints_nothing_until_it_is_resumed(): void
    {
        $r = $this->storeFixture();
        $this->profile($r, ['events' => ['kot']]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'held' => true,
            'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
        ]);
        $this->assertSame(0, PrintJob::count());

        PosService::resume($order->fresh());
        $this->assertSame(1, PrintJob::count());
    }

    public function test_settling_an_order_queues_the_paid_receipt(): void
    {
        $r = $this->storeFixture();
        $this->profile($r, ['name' => 'Receipt', 'events' => ['paid']]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $this->item($r, 25)->id, 'quantity' => 1]],
        ]);
        $this->assertSame(0, PrintJob::count());

        PosService::settle($order->fresh(), 'cash', 30.0);

        $job = PrintJob::where('event', 'paid')->first();
        $this->assertNotNull($job);
        $this->assertStringContainsString('25.00', base64_decode($job->payload));
    }

    public function test_closing_a_shift_queues_the_summary(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $this->profile($r, ['name' => 'Back office', 'events' => ['shift_close']]);

        $shift = PosService::openShift($r, $staff, null, 50);
        PosService::closeShift($shift->fresh(), 60.0);

        $job = PrintJob::where('event', 'shift_close')->first();
        $this->assertNotNull($job);
        $this->assertSame($shift->id, (int) $job->shift_id);
        $this->assertStringContainsString('Shift report', base64_decode($job->payload));
    }

    // ── idempotency ──────────────────────────────────────────────────────────

    /**
     * The one guarantee that matters most: a status replay, a retried listener or
     * a resumed order can never produce a second physical ticket. A duplicate
     * kitchen ticket is a real-world cost (two steaks).
     */
    public function test_replaying_a_trigger_never_double_prints(): void
    {
        $r = $this->storeFixture();
        $this->profile($r, ['events' => ['kot']]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
        ]);

        PrintDispatcher::onOrderCreated($order->fresh());
        PrintDispatcher::onOrderCreated($order->fresh());
        PrintDispatcher::onOrderReleased($order->fresh());

        $this->assertSame(1, PrintJob::count());
    }

    public function test_a_paid_order_resaved_does_not_reprint(): void
    {
        $r = $this->storeFixture();
        $this->profile($r, ['events' => ['paid']]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
        ]);
        PosService::settle($order->fresh(), 'cash', 50.0);

        $order->fresh()->update(['status' => 'delivered']); // a later status write
        PrintDispatcher::onOrderPaid($order->fresh());

        $this->assertSame(1, PrintJob::where('event', 'paid')->count());
    }

    public function test_job_keys_are_stable_and_within_the_bridge_limit(): void
    {
        $r = $this->storeFixture();
        $profile = $this->profile($r, ['name' => str_repeat('Very Long Printer Name ', 3)]);

        $key = PrintDispatcher::jobKey('ord', 91423, $profile, 'kot');

        $this->assertLessThanOrEqual(64, strlen($key));
        $this->assertStringStartsWith('ord-91423-', $key);
        $this->assertSame($key, PrintDispatcher::jobKey('ord', 91423, $profile, 'kot'));
    }

    public function test_a_test_print_is_deliberately_repeatable(): void
    {
        $r = $this->storeFixture();
        $profile = $this->profile($r);

        PrintDispatcher::testPrint($profile);
        PrintDispatcher::testPrint($profile);

        $this->assertGreaterThanOrEqual(1, PrintJob::where('event', 'test')->count());
    }

    // ── server-side bridge lane ──────────────────────────────────────────────

    public function test_bridge_profiles_are_delivered_server_side_on_a_single_host_install(): void
    {
        config(['printing.server_bridge' => true]);
        Http::fake(['*' => Http::response(['ok' => true, 'jobId' => 'x'])]);

        $r = $this->storeFixture();
        PrintSettings::rotatePairingToken((int) $r->id);
        $this->profile($r, ['transport' => 'bridge', 'target' => 'tcp://192.168.1.50:9100', 'bridge_url' => 'http://127.0.0.1:8543']);

        OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
        ]);

        $this->assertSame('done', PrintJob::first()->status);
    }

    public function test_a_dead_bridge_marks_the_job_failed_with_a_reason(): void
    {
        config(['printing.server_bridge' => true]);
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'econnrefused'])]);

        $r = $this->storeFixture();
        $this->profile($r, ['transport' => 'bridge', 'target' => 'tcp://10.0.0.9:9100']);

        OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
        ]);

        $job = PrintJob::first();
        $this->assertSame('failed', $job->status);
        $this->assertSame('econnrefused', $job->error);
    }

    /** Hosted installs must NOT try 127.0.0.1 — that is the web server, not the till. */
    public function test_bridge_jobs_stay_pending_when_server_side_delivery_is_off(): void
    {
        config(['printing.server_bridge' => false]);
        Http::fake();

        $r = $this->storeFixture();
        $this->profile($r, ['transport' => 'bridge', 'target' => 'tcp://10.0.0.9:9100']);

        OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
        ]);

        $this->assertSame('pending', PrintJob::first()->status);
        Http::assertNothingSent();
    }

    // ── register endpoints ───────────────────────────────────────────────────

    public function test_register_reads_its_printer_config(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        PrintSettings::rotatePairingToken((int) $r->id);
        $this->profile($r, ['name' => 'Front counter']);

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.print.config', ['store' => $r->id]))
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('profiles.0.name', 'Front counter')
            ->assertJsonPath('profiles.0.transport', 'webserial');
    }

    public function test_register_claims_jobs_and_acks_them(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $this->profile($r, ['events' => ['new_order']]);

        OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
        ]);

        $res = $this->actingAs($staff, 'staff')
            ->getJson(route('pos.print.jobs', ['store' => $r->id]))
            ->assertOk();

        $jobId = $res->json('jobs.0.id');
        $this->assertNotNull($jobId);
        $this->assertStringStartsWith("\x1B@", base64_decode($res->json('jobs.0.payload')));
        $this->assertSame('claimed', PrintJob::find($jobId)->status);

        // A claimed job is not handed out again straight away.
        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.print.jobs', ['store' => $r->id]))
            ->assertOk()->assertJsonCount(0, 'jobs');

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.print.ack', ['id' => $jobId, 'store' => $r->id]), ['ok' => true])
            ->assertOk();

        $this->assertSame('done', PrintJob::find($jobId)->status);
    }

    public function test_a_failed_ack_records_the_reason_from_the_closed_vocabulary(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $profile = $this->profile($r);
        $job = PrintDispatcher::testPrint($profile);
        $job->forceFill(['status' => 'claimed', 'claimed_at' => now()])->save();

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.print.ack', ['id' => $job->id, 'store' => $r->id]), ['ok' => false, 'error' => 'econnrefused'])
            ->assertOk();

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('econnrefused', $job->fresh()->error);

        // An invented reason is normalised rather than stored raw.
        $job2 = PrintDispatcher::testPrint($profile);
        $job2->forceFill(['status' => 'claimed', 'claimed_at' => now()])->save();
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.print.ack', ['id' => $job2->id, 'store' => $r->id]), ['ok' => false, 'error' => 'printer-on-fire'])
            ->assertOk();
        $this->assertSame('internal', $job2->fresh()->error);
    }

    public function test_a_register_can_never_claim_another_stores_jobs(): void
    {
        $mine = $this->storeFixture();
        $theirs = $this->storeFixture();
        $staff = $this->staff($mine);

        $job = PrintDispatcher::testPrint($this->profile($theirs));

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.print.jobs', ['store' => $mine->id]))
            ->assertOk()->assertJsonCount(0, 'jobs');

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.print.ack', ['id' => $job->id, 'store' => $mine->id]), ['ok' => true])
            ->assertNotFound();
    }

    public function test_stale_jobs_are_abandoned_rather_than_printed_late(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $job = PrintDispatcher::testPrint($this->profile($r));
        $job->forceFill(['created_at' => now()->subHours(3)])->save();

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.print.jobs', ['store' => $r->id]))
            ->assertOk()->assertJsonCount(0, 'jobs');

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertSame('etimedout', $job->fresh()->error);
    }

    public function test_register_can_queue_a_test_print_and_a_manual_ticket(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $profile = $this->profile($r, ['auto_print' => false]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.print.test', ['profile' => $profile->id, 'store' => $r->id]))
            ->assertOk()->assertJsonPath('ok', true);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
        ]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.print.order', ['id' => $order->id, 'store' => $r->id]), [
                'profile_id' => $profile->id, 'event' => 'kot',
            ])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, PrintJob::where('event', 'kot')->count());
    }

    public function test_register_print_endpoints_refuse_when_the_feature_is_killed(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);

        config(['settings.admin_allow_print_routing' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.print.jobs', ['store' => $r->id]))->assertForbidden();

        // The config endpoint stays 200 but reports the feature off, so the
        // register simply renders no printer chip instead of erroring at boot.
        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.print.config', ['store' => $r->id]))
            ->assertOk()->assertJsonPath('enabled', false);
    }

    // ── owner CRUD ───────────────────────────────────────────────────────────

    public function test_owner_can_create_edit_and_delete_a_printer(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);
        $station = KitchenStation::create(['store_id' => $r->id, 'name' => 'Grill']);

        $this->actingAs($owner)->get(route('stores.printing.index', ['store_id' => $r->id]))->assertOk()->assertSeeHtml('Printing');

        $this->actingAs($owner)->post(route('stores.printing.store', ['store_id' => $r->id]), [
            'name' => 'Grill printer', 'transport' => 'bridge', 'target' => 'tcp://192.168.1.50:9100',
            'width_mm' => 57, 'copies' => 2, 'events' => ['kot'], 'station_ids' => [$station->id],
            'auto_print' => '1', 'active' => '1',
        ])->assertRedirect();

        $profile = PrinterProfile::where('store_id', $r->id)->firstOrFail();
        $this->assertSame('bridge', $profile->transport);
        $this->assertSame(57, (int) $profile->width_mm);
        $this->assertSame([$station->id], $profile->stationFilter());
        // A bridge profile gets the frozen loopback default when none is given.
        $this->assertSame(PrinterProfile::DEFAULT_BRIDGE_URL, $profile->bridge_url);

        $this->actingAs($owner)->post(route('stores.printing.update', ['id' => $profile->id, 'store_id' => $r->id]), [
            'name' => 'Renamed', 'transport' => 'rawbt', 'width_mm' => 80, 'copies' => 1,
            'events' => ['new_order', 'kot'], 'active' => '1',
        ])->assertRedirect();

        $profile->refresh();
        $this->assertSame('Renamed', $profile->name);
        $this->assertFalse($profile->auto_print); // unchecked switch really turns it off
        $this->assertNull($profile->bridge_url);  // non-bridge transports carry none

        $this->actingAs($owner)->delete(route('stores.printing.destroy', ['id' => $profile->id, 'store_id' => $r->id]))
            ->assertRedirect();
        $this->assertSame(0, PrinterProfile::count());
    }

    public function test_an_owner_cannot_touch_another_stores_printer(): void
    {
        $mine = $this->storeFixture();
        $theirs = $this->storeFixture();
        $profile = $this->profile($theirs);

        $this->actingAs(User::find($mine->user_id))
            ->post(route('stores.printing.update', ['id' => $profile->id, 'store_id' => $mine->id]), [
                'name' => 'Hijacked', 'transport' => 'browser', 'width_mm' => 80, 'copies' => 1,
            ])->assertNotFound();

        $this->assertSame('Front', $profile->fresh()->name);
    }

    public function test_a_station_from_another_store_is_dropped_not_saved(): void
    {
        $mine = $this->storeFixture();
        $theirs = $this->storeFixture();
        $foreign = KitchenStation::create(['store_id' => $theirs->id, 'name' => 'Their grill']);

        $this->actingAs(User::find($mine->user_id))
            ->post(route('stores.printing.store', ['store_id' => $mine->id]), [
                'name' => 'Mine', 'transport' => 'browser', 'width_mm' => 80, 'copies' => 1,
                'events' => ['kot'], 'station_ids' => [$foreign->id],
            ])->assertRedirect();

        $this->assertSame([], PrinterProfile::where('store_id', $mine->id)->first()->stationFilter());
    }

    public function test_the_sunmi_transport_cannot_be_created_from_the_printing_page(): void
    {
        $r = $this->storeFixture();

        $this->actingAs(User::find($r->user_id))
            ->post(route('stores.printing.store', ['store_id' => $r->id]), [
                'name' => 'Fake sunmi', 'transport' => 'sunmi', 'width_mm' => 80, 'copies' => 1,
            ])->assertSessionHasErrors('transport');
    }

    public function test_pairing_token_is_generated_and_rotatable(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        $this->assertNull(PrintSettings::pairingToken((int) $r->id));

        $this->actingAs($owner)->post(route('stores.printing.token', ['store_id' => $r->id]))->assertRedirect();
        $first = PrintSettings::pairingToken((int) $r->id);
        $this->assertSame(40, strlen((string) $first)); // frozen by print-bridge.md

        $this->actingAs($owner)->post(route('stores.printing.token', ['store_id' => $r->id]))->assertRedirect();
        $this->assertNotSame($first, PrintSettings::pairingToken((int) $r->id));
    }

    public function test_owner_can_save_the_store_wide_receipt_options(): void
    {
        $r = $this->storeFixture();

        $this->actingAs(User::find($r->user_id))
            ->post(route('stores.printing.options', ['store_id' => $r->id]), [
                'print_rtl' => '1', 'print_logo' => '1', 'print_rtl_font' => '/fonts/arabic.ttf',
            ])->assertRedirect();

        $this->assertSame('1', (string) store_options($r->id, 'print_rtl'));
        $this->assertSame('0', (string) store_options($r->id, 'print_open_drawer')); // unchecked → off
        $this->assertSame('/fonts/arabic.ttf', (string) store_options($r->id, 'print_rtl_font'));
    }

    public function test_owner_can_queue_a_test_print_from_the_settings_page(): void
    {
        $r = $this->storeFixture();
        $profile = $this->profile($r);

        $this->actingAs(User::find($r->user_id))
            ->post(route('stores.printing.test', ['id' => $profile->id, 'store_id' => $r->id]))
            ->assertRedirect();

        $this->assertSame(1, PrintJob::where('event', 'test')->count());
    }
}
