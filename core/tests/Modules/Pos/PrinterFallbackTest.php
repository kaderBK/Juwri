<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Pos\Models\PrintJob;
use Juwri\Modules\Pos\Services\Printing\PrintDispatcher;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D3 — printer fallback + fleet screen: a failed job re-routes to its profile's
 * fallback exactly once (no fallback-of-fallback chain), the register NACK path
 * wires the re-route, and the admin history screen renders.
 */
class PrinterFallbackTest extends TestCase
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
        $this->shareLayoutGlobals();
        View::share('errors', new ViewErrorBag);
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

    private function profile(Store $r, array $attrs = []): PrinterProfile
    {
        return PrinterProfile::create(array_merge([
            'store_id' => $r->id, 'name' => 'P'.Str::random(3), 'transport' => 'webserial',
            'width_mm' => 80, 'events' => ['new_order'], 'station_ids' => [],
            'auto_print' => true, 'active' => true, 'copies' => 1,
        ], $attrs));
    }

    private function job(Store $r, PrinterProfile $p, array $attrs = []): PrintJob
    {
        return PrintJob::create(array_merge([
            'store_id' => $r->id, 'profile_id' => $p->id, 'event' => 'new_order',
            'job_key' => 'k-'.Str::random(12), 'transport' => $p->transport, 'target' => $p->target,
            'width_mm' => $p->width_mm, 'copies' => 1, 'payload' => base64_encode('BYTES'), 'status' => 'pending',
        ], $attrs));
    }

    public function test_failed_job_reroutes_to_fallback_exactly_once(): void
    {
        $r = $this->storeFixture();
        $b = $this->profile($r, ['name' => 'Backup']);
        $a = $this->profile($r, ['name' => 'Front', 'fallback_profile_id' => $b->id]);

        $job = $this->job($r, $a);
        $job->markFailed('etimedout');

        $new = PrintDispatcher::requeueToFallback($job->fresh());
        $this->assertNotNull($new);
        $this->assertSame((int) $b->id, (int) $new->profile_id);
        $this->assertSame((int) $job->id, (int) $new->fallback_of_job_id);
        $this->assertSame('pending', $new->status);

        // A replay on the same original job re-resolves the SAME row — no second ticket.
        $again = PrintDispatcher::requeueToFallback($job->fresh());
        $this->assertSame((int) $new->id, (int) $again->id);
        $this->assertSame(1, PrintJob::where('profile_id', $b->id)->count());
    }

    public function test_a_fallback_job_never_chains_again(): void
    {
        $r = $this->storeFixture();
        $c = $this->profile($r, ['name' => 'Third']);
        $b = $this->profile($r, ['name' => 'Backup', 'fallback_profile_id' => $c->id]);
        $a = $this->profile($r, ['name' => 'Front', 'fallback_profile_id' => $b->id]);

        $job = $this->job($r, $a);
        $job->markFailed('etimedout');
        $fallback = PrintDispatcher::requeueToFallback($job->fresh());
        $this->assertNotNull($fallback);

        // The fallback itself fails — it must NOT fall back to C (no chain).
        $fallback->markFailed('etimedout');
        $this->assertNull(PrintDispatcher::requeueToFallback($fallback->fresh()));
        $this->assertSame(0, PrintJob::where('profile_id', $c->id)->count());
    }

    public function test_no_fallback_configured_is_a_noop(): void
    {
        $r = $this->storeFixture();
        $a = $this->profile($r, ['name' => 'Lonely']);
        $job = $this->job($r, $a);
        $job->markFailed('etimedout');

        $this->assertNull(PrintDispatcher::requeueToFallback($job->fresh()));
        $this->assertSame(1, PrintJob::where('store_id', $r->id)->count());
    }

    public function test_register_nack_triggers_the_fallback(): void
    {
        $r = $this->storeFixture();
        $b = $this->profile($r, ['name' => 'Backup']);
        $a = $this->profile($r, ['name' => 'Front', 'fallback_profile_id' => $b->id]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'M', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);
        $job = $this->job($r, $a, ['status' => 'claimed']);

        $res = $this->actingAs($staff, 'staff')
            ->postJson(route('pos.print.ack', ['store' => $r->id, 'id' => $job->id]), [
                'ok' => false, 'error' => 'etimedout',
            ])->assertStatus(200);

        $this->assertNotNull($res->json('fallback_job_id'));
        $this->assertSame(1, PrintJob::where('profile_id', $b->id)->where('status', 'pending')->count());
    }

    public function test_history_screen_renders(): void
    {
        $r = $this->storeFixture();
        $a = $this->profile($r, ['name' => 'Front']);
        $this->job($r, $a, ['status' => 'failed', 'error' => 'etimedout', 'attempts' => 1]);

        $this->actingAs(User::find($r->user_id))
            ->get(route('stores.printing.index', ['store_id' => $r->id]))
            ->assertOk()
            ->assertSee('Print-job history');
    }

    public function test_p6238_job_is_claimed_by_only_one_register_and_late_ack_cannot_flip_it(): void
    {
        $r = $this->storeFixture();
        $a = $this->profile($r, ['name' => 'Front']);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'M', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);
        $job = $this->job($r, $a);

        $first = $this->actingAs($staff, 'staff')->getJson(route('pos.print.jobs', ['store' => $r->id]))->assertOk();
        $second = $this->actingAs($staff, 'staff')->getJson(route('pos.print.jobs', ['store' => $r->id]))->assertOk();
        $this->assertCount(1, $first->json('jobs'));
        $this->assertCount(0, $second->json('jobs'));
        $this->assertSame(1, (int) $job->fresh()->attempts);

        $ack = route('pos.print.ack', ['store' => $r->id, 'id' => $job->id]);
        $this->postJson($ack, ['ok' => true])->assertOk();
        // late failure ack on a done job: refused, still done, no fallback ticket.
        $this->postJson($ack, ['ok' => false, 'error' => 'etimedout'])->assertStatus(409);
        $this->assertSame('done', $job->fresh()->status);
    }

    public function test_p6238_ack_is_refused_for_an_unclaimed_job(): void
    {
        $r = $this->storeFixture();
        $a = $this->profile($r, ['name' => 'Front']);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'M', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);
        $job = $this->job($r, $a);
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.print.ack', ['store' => $r->id, 'id' => $job->id]), ['ok' => true])
            ->assertStatus(409);
        $this->assertSame('pending', $job->fresh()->status);
    }

    public function test_p6238_timed_out_ticket_is_failed_routed_to_fallback_and_reported(): void
    {
        $r = $this->storeFixture();
        $b = $this->profile($r, ['name' => 'Backup']);
        $a = $this->profile($r, ['name' => 'Front', 'fallback_profile_id' => $b->id]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'M', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);
        $old = $this->job($r, $a);
        PrintJob::where('id', $old->id)->update(['created_at' => now()->subMinutes(((int) config('printing.job_ttl_minutes')) + 5)]);

        $res = $this->actingAs($staff, 'staff')->getJson(route('pos.print.jobs', ['store' => $r->id]))->assertOk();

        $this->assertSame('failed', $old->fresh()->status);
        $this->assertSame('etimedout', $old->fresh()->error);
        $this->assertSame((int) $old->id, (int) $res->json('expired.0.id'));
        $this->assertNotNull($res->json('expired.0.fallback_job_id'));
        $this->assertSame(1, PrintJob::where('profile_id', $b->id)->count());
        // the fresh fallback ticket is handed to this register in the same poll
        $this->assertCount(1, $res->json('jobs'));
    }
}
