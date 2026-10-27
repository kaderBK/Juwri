<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Services\Account\AccountDeletionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\OwnerApp;
use Juwri\Modules\AppStore\Mail\AppDunningMail;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Team\Models\StaffToken;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner self-service account deletion (Apple 5.1.1(v); owner-app server item S3):
 * close now → restore within the grace period → purge that KEEPS anonymized
 * financial records and wipes everything else.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-123';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
        Mail::fake();
    }

    private function owner(array $attrs = []): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create(array_merge([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
            'password' => Hash::make(self::PASSWORD),
        ], $attrs));
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Real Store', 'sub_title' => 't', 'description' => 'desc', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 Real St', 'phone' => '555-0100',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: User, 1: Store, 2: Order, 3: Customer} */
    private function ownerWithData(): array
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Cust Omer', 'phone' => '5'.Str::random(8)]);
        DB::table('customer_addresses')->insert(['customer_id' => $customer->id, 'store_id' => $store->id, 'label' => 'Home', 'line' => '9 Private Rd', 'created_at' => now(), 'updated_at' => now()]);
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'delivery', 'status' => 'completed', 'track_token' => Str::random(32),
            'customer_name' => 'Cust Omer', 'phone_number' => '0500000000', 'address' => '9 Private Rd',
            'customer_id' => $customer->id, 'created_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => Menu::create(['store_id' => $store->id, 'name' => 'Tea', 'price' => 5, 'active' => '1'])->id,
            'quantity' => 1,
        ]);
        StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Staff', 'email' => Str::random(6).'@s.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        ApiToken::create(['user_id' => $owner->id, 'name' => 't', 'token' => hash('sha256', Str::random(40)), 'abilities' => ['*']]);
        PushSubscription::storeWeb(['endpoint' => 'https://push.example.com/o', 'keys' => ['p256dh' => 'k', 'auth' => 'a']], $store->id, $owner->id);

        return [$owner, $store, $order, $customer];
    }

    // ── request ───────────────────────────────────────────────────────────────

    public function test_owner_sees_the_confirm_page_with_consequences(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->get(route('user.account.delete.show'))
            ->assertOk()
            ->assertSee('Delete my account')
            ->assertSee('30 days');
        $this->actingAs($owner)->get(route('settings'))->assertOk()->assertSee(route('user.account.delete.show'));
    }

    public function test_staff_and_support_sessions_can_never_delete_the_owner(): void
    {
        $owner = $this->owner();

        // A staff session is bounced (403 here, or the staff middleware's redirect first).
        $status = $this->actingAs($owner)->withSession(['staff_session_id' => 5])->get(route('user.account.delete.show'))->status();
        $this->assertContains($status, [302, 403]);
        $status = $this->actingAs($owner)->withSession(['impersonating' => ['reseller_id' => 1]])
            ->post(route('user.account.delete'), ['password' => self::PASSWORD, 'understand' => '1'])->status();
        $this->assertContains($status, [302, 403]); // ImpersonationGuard may redirect first
        $this->assertSame(1, (int) $owner->fresh()->status);
        $this->assertSame(0, AccountDeletion::count());
    }

    public function test_wrong_password_or_missing_confirmation_deletes_nothing(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('user.account.delete'), ['password' => 'nope', 'understand' => '1'])->assertSessionHasErrors('password');
        $this->actingAs($owner)->post(route('user.account.delete'), ['password' => self::PASSWORD])->assertSessionHasErrors('understand');
        $this->assertSame(0, AccountDeletion::count());
        $this->assertSame(1, (int) $owner->fresh()->status);
    }

    public function test_deleting_closes_the_account_now_and_revokes_access(): void
    {
        [$owner, $store] = $this->ownerWithData();

        $this->actingAs($owner)->post(route('user.account.delete'), [
            'password' => self::PASSWORD, 'understand' => '1', 'reason' => 'closing the business',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        $del = AccountDeletion::sole();
        $this->assertSame(AccountDeletion::PENDING, $del->status);
        $this->assertTrue($del->purge_after->isSameDay(now()->addDays(30)));
        $this->assertSame(0, (int) $owner->fresh()->status);
        $this->assertSame(0, ApiToken::where('user_id', $owner->id)->count());
        $this->assertSame(0, PushSubscription::where('store_id', $store->id)->count());
        $this->assertSame(0, (int) StaffMember::where('store_id', $store->id)->value('active'));
        // Nothing is deleted yet.
        $this->assertSame(1, Customer::where('store_id', $store->id)->count());
        Mail::assertSent(AppDunningMail::class, fn ($m) => $m->hasTo($owner->email)
            && str_contains(implode(' ', $m->lines), '/account/restore/'.$del->id)
            // P1-23: the mail tells the owner how to export during the 30-day window (Terms 7.6).
            && str_contains(implode(' ', $m->lines), 'Backup & export'));
    }

    public function test_login_explains_a_pending_deletion_and_the_app_gets_a_specific_code(): void
    {
        $owner = $this->owner();
        app(AccountDeletionService::class)->request($owner);

        $this->postJson('/api/v1/app/auth/login', [
            'username' => $owner->email, 'password' => self::PASSWORD, 'device_id' => 'd1', 'platform' => 'ios',
        ])->assertStatus(403)->assertJsonPath('code', 'account_deletion_pending');
    }

    // ── restore ───────────────────────────────────────────────────────────────

    public function test_signed_restore_link_reopens_the_account(): void
    {
        [$owner, $store] = $this->ownerWithData();
        $del = app(AccountDeletionService::class)->request($owner);

        $this->get('/account/restore/'.$del->id)->assertForbidden(); // unsigned
        // BL-036 A1b: GET only shows the confirm page (mail scanners); POST restores.
        $this->get(app(AccountDeletionService::class)->restoreUrl($del))->assertOk()
            ->assertSee('data-restore-state="confirm"', false)->assertSee('method="POST"', false);
        $this->assertSame(AccountDeletion::PENDING, $del->fresh()->status);
        $this->post(app(AccountDeletionService::class)->restoreUrl($del))->assertRedirect(route('login'));

        $this->assertSame(AccountDeletion::RESTORED, $del->fresh()->status);
        $this->assertSame(1, (int) $owner->fresh()->status);
        $this->assertSame(1, (int) StaffMember::where('store_id', $store->id)->value('active'));
    }

    public function test_restore_is_refused_after_the_grace_period(): void
    {
        $owner = $this->owner();
        $del = app(AccountDeletionService::class)->request($owner);
        $url = URL::temporarySignedRoute('account.deletion.restore', now()->addDays(60), ['deletion' => $del->id]);

        $this->travel(31)->days();
        $this->get($url)->assertStatus(410)->assertSee('data-restore-state="invalid"', false);
        $this->post($url);

        $this->assertSame(AccountDeletion::PENDING, $del->fresh()->status);
        $this->assertSame(0, (int) $owner->fresh()->status);
    }

    // ── purge ─────────────────────────────────────────────────────────────────

    public function test_purge_waits_for_the_grace_period(): void
    {
        [$owner, $store] = $this->ownerWithData();
        app(AccountDeletionService::class)->request($owner);

        $this->artisan('accounts:purge-deleted')->assertSuccessful();

        $this->assertSame(AccountDeletion::PENDING, AccountDeletion::sole()->status);
        $this->assertSame('Real Store', $store->fresh()->title);
    }

    public function test_purge_keeps_anonymized_financial_records_and_wipes_the_rest(): void
    {
        [$owner, $store, $order, $customer] = $this->ownerWithData();
        $trialClaims = Schema::hasTable('trial_claims');
        if ($trialClaims) { // P6-276 (billing edition): the claim outlives the account, unlinked
            DB::table('trial_claims')->insert(['kind' => 'email', 'fingerprint' => str_repeat('a', 64), 'user_id' => $owner->id, 'created_at' => now()]);
        }
        app(AccountDeletionService::class)->request($owner);

        $this->travel(31)->days();
        app(AccountDeletionService::class)->purge(AccountDeletion::sole()); // surfaces errors (the command logs them)
        $this->artisan('accounts:purge-deleted')->assertSuccessful();

        $this->assertSame(AccountDeletion::PURGED, AccountDeletion::sole()->status);
        if ($trialClaims) {
            $this->assertSame(0, DB::table('trial_claims')->where('user_id', $owner->id)->count());
            $this->assertSame(1, DB::table('trial_claims')->where('fingerprint', str_repeat('a', 64))->whereNull('user_id')->count());
        }

        // Financial records kept, customers' personal data gone.
        $kept = Order::find($order->id);
        $this->assertNotNull($kept, 'orders are kept for tax law');
        $this->assertSame('', (string) $kept->customer_name);
        $this->assertSame('', (string) $kept->phone_number);
        $this->assertSame('', (string) $kept->address);
        $this->assertNull($kept->customer_id);
        $this->assertSame(1, OrderItem::where('order_id', $order->id)->count());

        // Everything else wiped (including orphan-prone customer children).
        $this->assertSame(0, Customer::where('store_id', $store->id)->count());
        $this->assertSame(0, DB::table('customer_addresses')->where('customer_id', $customer->id)->count());
        $this->assertSame(0, StaffMember::where('store_id', $store->id)->count());
        $this->assertSame(0, Menu::where('store_id', $store->id)->count(), 'menu rows are soft-deleted');

        // Store + user rows anonymized, not deleted (their FKs would cascade into the invoices).
        $s = $store->fresh();
        $this->assertSame('Deleted store', $s->title);
        $this->assertStringStartsWith('deleted-', $s->slug);
        $this->assertSame('', (string) $s->phone);
        $this->assertSame('', (string) $s->address);
        $u = $owner->fresh();
        $this->assertSame('Deleted user', $u->name);
        $this->assertStringEndsWith('@deleted.invalid', $u->email);
        $this->assertSame(0, (int) $u->status);
    }

    // ── owner-app server items S1 / S4 / S6 ─────────────────────────────────

    public function test_path_configuration_is_served_per_platform(): void
    {
        $ios = $this->getJson('/api/v1/app/path-configuration/ios.json')->assertOk()->json();
        $android = $this->getJson('/api/v1/app/path-configuration/android.json')->assertOk()->json();
        $this->get('/api/v1/app/path-configuration/web.json')->assertNotFound();

        $login = fn (array $cfg) => collect($cfg['rules'])->first(fn ($r) => in_array('^/login', $r['patterns'], true))['properties'];
        $this->assertSame('login', $login($ios)['view_controller']);
        $this->assertSame('hotwire://fragment/login', $login($android)['uri']);
        // Hotwire Native Android throws NavigatorException on `modal` + `replace_root`.
        foreach ([$ios, $android] as $cfg) {
            foreach ($cfg['rules'] as $rule) {
                $p = $rule['properties'];
                $this->assertFalse(($p['context'] ?? null) === 'modal' && ($p['presentation'] ?? null) === 'replace_root', json_encode($rule));
            }
        }
        $this->assertSame('hotwire://fragment/web', $android['rules'][0]['properties']['uri']);
        $this->assertNotEmpty($ios['settings']['account_deletion_path']);
    }

    public function test_purchases_are_blocked_inside_the_native_app_only(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $owner = $this->owner();
        $app = ['User-Agent' => 'JuwriApp/1.0 (ios) Hotwire Native iOS; Mozilla/5.0'];

        $this->actingAs($owner)->get('/subscription')->assertOk(); // browser unaffected (headers persist, so first)
        $this->actingAs($owner)->withHeaders($app)->get('/subscription')->assertStatus(403)->assertSee('Not available in the app');
        $this->actingAs($owner)->withHeaders($app)->postJson('/addons/checkout', [])->assertStatus(403)->assertJsonPath('code', 'purchase_unavailable_in_app');

        $html = (string) $this->actingAs($owner)->withHeaders($app)->get(route('dashboard'))->getContent();
        $this->assertStringContainsString('[data-purchase-cta]', $html);
        $this->assertStringContainsString('a[href$="/subscription"]', $html);
    }

    public function test_historical_location_routes_answer_a_blank_page(): void
    {
        // Blank but NOT empty: Turbo only proposes the visit (what the app's path
        // configuration acts on) when a followed form redirect returns HTML.
        foreach (['recede', 'resume', 'refresh'] as $kind) {
            $body = (string) $this->get("/{$kind}_historical_location")->assertOk()->getContent();
            $this->assertNotSame('', $body);
            $this->assertSame('', trim(strip_tags($body)));
        }
    }

    public function test_the_app_more_sheet_reaches_account_settings(): void
    {
        $store = $this->store($this->owner());

        $this->assertContains('account', array_column(OwnerApp::moreFor($store), 'key'));
    }

    /** BL-036 round 4: a restore switches back on only the staff the closure switched off. */
    public function test_restore_leaves_staff_the_owner_had_disabled_switched_off(): void
    {
        [$owner, $store] = $this->ownerWithData();
        $working = StaffMember::where('store_id', $store->id)->sole();
        $benched = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Benched', 'email' => Str::random(6).'@s.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 0,
        ]);
        $stampBefore = $working->fresh()->security_changed_at;
        $this->travel(5)->minutes();
        $del = app(AccountDeletionService::class)->request($owner);
        $this->assertSame([$working->id], $del->fresh()->deactivated_staff_ids);

        $this->post(app(AccountDeletionService::class)->restoreUrl($del))->assertRedirect(route('login'));

        $this->assertTrue((bool) $working->fresh()->active);
        $this->assertFalse((bool) $benched->fresh()->active, 'disabled by the owner before the closure: stays off');
        $this->assertEquals($stampBefore, $working->fresh()->security_changed_at, 'a restore puts back the old state: no security stamp');
    }

    public function test_a_legacy_deletion_without_a_staff_list_switches_nobody_on(): void
    {
        [$owner, $store] = $this->ownerWithData();
        $del = app(AccountDeletionService::class)->request($owner);
        DB::table('account_deletions')->where('id', $del->id)->update(['deactivated_staff_ids' => null]);

        $this->assertTrue(app(AccountDeletionService::class)->restore($del->fresh()));

        $this->assertSame(1, (int) $owner->fresh()->status);
        $this->assertFalse((bool) StaffMember::where('store_id', $store->id)->value('active'));
        $this->assertSame(1, ActivityLog::where('action', 'account.deletion.staff_not_restored')->count());
    }

    private function deletionRow(User $owner, string $status): AccountDeletion
    {
        return AccountDeletion::create([
            'user_id' => $owner->id, 'status' => $status, 'requested_at' => now(), 'purge_after' => now()->addDays(30),
            'source' => 'web', 'previous_status' => 1,
        ]);
    }

    private function closeStaff(Store $store, AccountDeletion $deletion): bool
    {
        $m = new \ReflectionMethod(AccountDeletionService::class, 'closeStaff');

        return (bool) $m->invoke(app(AccountDeletionService::class), [(int) $store->id], $deletion);
    }

    /** BL-036 round 6: an undo restored the deletion before the closure's staff step: nobody is switched off. */
    public function test_the_staff_step_does_nothing_on_a_deletion_that_is_no_longer_pending(): void
    {
        [$owner, $store] = $this->ownerWithData();
        $staff = StaffMember::where('store_id', $store->id)->sole();
        StaffToken::forceCreate(['staff_id' => $staff->id, 'name' => 'app', 'token' => hash('sha256', Str::random(40))]);
        $deletion = $this->deletionRow($owner, AccountDeletion::RESTORED);

        $this->assertFalse($this->closeStaff($store, $deletion));

        $this->assertTrue((bool) $staff->fresh()->active);
        $this->assertSame(1, StaffToken::where('staff_id', $staff->id)->count());
        $this->assertNull($deletion->fresh()->deactivated_staff_ids, 'no list on a restored row');
    }

    /** BL-036 round 6: the staff step takes the deletion row lock before any staff row (the undo/restore order). */
    public function test_the_staff_step_locks_the_deletion_before_the_staff_rows(): void
    {
        [$owner, $store] = $this->ownerWithData();
        $staff = StaffMember::where('store_id', $store->id)->sole();
        StaffToken::forceCreate(['staff_id' => $staff->id, 'name' => 'app', 'token' => hash('sha256', Str::random(40))]);
        $deletion = $this->deletionRow($owner, AccountDeletion::PENDING);
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower($q->sql);
        });

        $this->assertTrue($this->closeStaff($store, $deletion));

        // Row queries only (schema checks such as hasTable() name the tables too).
        $first = fn (string $table) => collect($sql)->search(fn (string $s) => (bool) preg_match('/\\b(from|update)\\s+["`]?'.$table.'["`]?(\\s|$)/', $s));
        $this->assertNotFalse($first('account_deletions'));
        $this->assertNotFalse($first('staff_members'));
        $this->assertLessThan($first('staff_members'), $first('account_deletions'), implode("\n", $sql));
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->assertStringContainsString('for update', $sql[$first('account_deletions')]);
        }
        $this->assertFalse((bool) $staff->fresh()->active);
        $this->assertSame(0, StaffToken::where('staff_id', $staff->id)->count());
        $this->assertSame([$staff->id], $deletion->fresh()->deactivated_staff_ids);
    }

    /** BL-036 round 7: a staff step that failed once is finished by the sweep a few minutes later. */
    public function test_a_failed_staff_step_is_retried_by_the_sweep(): void
    {
        [$owner, $store] = $this->ownerWithData();
        $staff = StaffMember::where('store_id', $store->id)->sole();
        StaffToken::forceCreate(['staff_id' => $staff->id, 'name' => 'app', 'token' => hash('sha256', Str::random(40))]);
        $armed = true;
        $event = 'eloquent.saving: '.AccountDeletion::class;
        Event::listen($event, function (AccountDeletion $d) use (&$armed) {
            if ($armed && $d->isDirty('deactivated_staff_ids')) {
                $armed = false;
                throw new \RuntimeException('Lock wait timeout exceeded');
            }
        });

        $del = app(AccountDeletionService::class)->request($owner);
        Event::forget($event);

        $this->assertFalse($armed, 'the staff step failed once');
        $this->assertTrue((bool) $staff->fresh()->active, 'fixture: the failed step left the staff on');
        $this->assertFalse($del->fresh()->purge_report['staff_closed']);
        $this->assertSame(['retried' => 0, 'closed' => 0], app(AccountDeletionService::class)->retryStaffClosures(), 'not yet due');

        $this->travel(AccountDeletionService::STAFF_CLOSE_RETRY_AFTER_MINUTES + 1)->minutes();
        $this->artisan('accounts:retry-staff-close')->assertSuccessful();

        $this->assertFalse((bool) $staff->fresh()->active);
        $this->assertSame(0, StaffToken::where('staff_id', $staff->id)->count());
        $this->assertSame([$staff->id], $del->fresh()->deactivated_staff_ids);
        $this->assertTrue($del->fresh()->purge_report['staff_closed']);
        $this->assertSame(['retried' => 0, 'closed' => 0], app(AccountDeletionService::class)->retryStaffClosures(), 'done rows are not retried');
    }

    public function test_the_sweep_skips_restored_legacy_and_finished_deletions(): void
    {
        [$owner, $store] = $this->ownerWithData();
        $staff = StaffMember::where('store_id', $store->id)->sole();
        $restored = $this->deletionRow($owner, AccountDeletion::RESTORED);
        $restored->forceFill(['purge_report' => ['staff_closed' => false]])->save();
        $legacy = $this->deletionRow($this->owner(), AccountDeletion::PENDING);
        $finished = $this->deletionRow($this->owner(), AccountDeletion::PENDING);
        $finished->forceFill(['purge_report' => ['staff_closed' => true], 'deactivated_staff_ids' => []])->save();
        $this->travel(30)->minutes();

        $this->assertSame(['retried' => 0, 'closed' => 0], app(AccountDeletionService::class)->retryStaffClosures());

        $this->assertTrue((bool) $staff->fresh()->active, 'a restored deletion never switches staff off');
        $this->assertNull($restored->fresh()->deactivated_staff_ids);
        $this->assertNull($legacy->fresh()->deactivated_staff_ids, 'rows from before the marker are left alone');
    }

    public function test_the_retry_is_scheduled_every_ten_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'accounts:retry-staff-close'));
        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
    }
}
