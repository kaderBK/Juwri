<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Admin;

use App\Models\Upgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Juwri\Core\Mail\SupportMessage;
use Juwri\Core\Mail\UserDetails;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\Notification;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Policies\Admin\PanelAbilities;
use Juwri\Core\Settings\UserSettings;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-08g characterisation: the superadmin accounts screen — the DataTables
 * answer, create/edit/update, bulk delete, avatar removal, password reset,
 * e-mail to the account, manual plan change and "sign in as". The export /
 * import / backup tools, filters and cascades have their own tests
 * (AdminUsersPageTest, AdminUserFiltersSubscriptionTest,
 * FeaturePlatformFix5DeleteCascadeTest, ImpersonationAuditTest, ...).
 */
class UsersAdminContractTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->bootAdminPanel();
        $this->registerSqliteMysqlShims();
    }

    /** usersTableQuery() uses MySQL functions; give sqlite equivalents (as AdminUsersPageTest does). */
    private function registerSqliteMysqlShims(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($v) => $v === null ? null : trim((string) $v, '"'), 1);
        $pdo->sqliteCreateFunction('DATE_FORMAT', function ($date, $format) {
            if ($date === null) {
                return null;
            }
            $ts = is_numeric($date) ? (int) $date : strtotime((string) $date);

            return $ts === false ? null : date(strtr((string) $format, ['%Y' => 'Y', '%m' => 'm', '%d' => 'd', '%H' => 'H', '%i' => 'i', '%s' => 's']), $ts);
        }, 2);
        $pdo->sqliteCreateFunction('UNIX_TIMESTAMP', fn ($d) => $d === null ? null : (is_numeric($d) ? (int) $d : (strtotime((string) $d) ?: null)), 1);
    }

    private function country(): Country
    {
        return Country::create(['code' => 'TT', 'name' => 'Testland', 'continent' => 'X', 'continent_code' => 'XX', 'phone' => '0', 'currency' => 'USD', 'symbol' => '$', 'alpha_3' => 'TTT']);
    }

    private function plan(): object
    {
        return TestPlans::create(['name' => 'Pro', 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => json_encode(['restaurant_limit' => 5]), 'status' => 1]);
    }

    public function test_users_admin_only(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $user = $this->plainUser();
        $this->assertAdminOnly('get', route('admin.users.index'));
        $this->assertAdminOnly('get', route('admin.users.create'));
        $this->assertAdminOnly('get', route('admin.users.edit', $user));
        $this->assertAdminOnly('post', route('admin.users.store'), ['name' => 'X']);
        $this->assertAdminOnly('put', route('admin.users.update', $user), ['name' => 'X']);
        $this->assertAdminOnly('post', route('admin.users.delete'), ['ids' => [$user->id]]);
        $this->assertAdminOnly('post', route('admin.users.password.update', $user), ['password' => 'x']);
        $this->assertAdminOnly('post', route('admin.users.sendmail', $user), ['subject' => 'x']);
        $this->assertAdminOnly('post', route('admin.users.plan', $user), ['plan' => 'free']);
        $this->assertAdminOnly('post', route('admin.users.login', $user));
        $this->assertNotNull($user->fresh());
    }

    public function test_users_data_table_row(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create([
            'name' => 'Olive Owner', 'username' => 'olive', 'email' => 'olive@example.test',
            'user_type' => 'user', 'status' => 1, 'email_verified_at' => now(),
        ]);
        Store::factory()->create(['user_id' => $owner->id, 'phone' => '+44 1234 5678']);

        $response = $this->actingAs($admin)->getJson(
            route('admin.users.index', ['draw' => 4, 'start' => 0, 'length' => 25, 'search' => ['value' => 'olive@example.test']]),
            ['X-Requested-With' => 'XMLHttpRequest']
        )->assertOk()->assertJson(['draw' => 4]);

        $this->assertSame(1, $response->json('recordsFiltered'));
        $row = $response->json('data.0');
        $this->assertSame($owner->id, $row['DT_RowId']);
        $this->assertStringContainsString((string) $owner->id, $row[0]);
        $this->assertStringContainsString('Olive Owner', $row[1]);
        $this->assertStringContainsString('@olive', $row[1]);
        $this->assertStringContainsString(route('admin.users.edit', $owner->id), $row[1]);
        $this->assertStringContainsString('olive@example.test', $row[2]);
        $this->assertStringContainsString('Verified', $row[3]);
        $this->assertStringContainsString('Active', $row[4]);
        $this->assertStringContainsString('+44 1234 5678', $row[10]);
        $this->assertStringContainsString('https://wa.me/4412345678', $row[11]);
        $this->assertStringContainsString(route('admin.users.login', $owner->id), $row[15]);
        $this->assertStringContainsString(route('admin.users.abuseHold', $owner->id), $row[15]);
        $this->assertStringContainsString('quick-check', $row[16]);
        $this->assertStringContainsString('value="'.$owner->id.'"', $row[16]);
    }

    public function test_banned_unverified_row_badges(): void
    {
        $admin = $this->admin();
        User::factory()->create(['username' => 'banned1', 'user_type' => 'user', 'status' => 0, 'email_verified_at' => null]);

        $row = $this->actingAs($admin)->getJson(
            route('admin.users.index', ['draw' => 1, 'start' => 0, 'length' => 25, 'search' => ['value' => 'banned1']]),
            ['X-Requested-With' => 'XMLHttpRequest']
        )->json('data.0');
        $this->assertStringContainsString('Unverified', $row[3]);
        $this->assertStringContainsString('Banned', $row[4]);
    }

    public function test_create_and_edit_forms(): void
    {
        $admin = $this->admin();
        $user = $this->plainUser();
        View::share('plans', collect()); // the userdetails composer only registers on an installed app
        $create = $this->actingAs($admin)->get(route('admin.users.create'))->assertOk()->assertViewIs('admin.users.create');
        // Country picker: required kit select, empty "Choose" first, country id → name.
        $country = countries()->first();
        $this->assertNotNull($country);
        $create->assertSeeHtml('<select id="ui-f-country" name="country"')->assertSeeHtml('<option value="" selected>Choose</option>')->assertSeeHtml('<option value="'.$country->id.'" >'.e($country->name).'</option>');
        $this->actingAs($admin)->get(route('admin.users.edit', $user))->assertOk()
            ->assertViewIs('admin.users.edit')->assertViewHas('user', fn ($u) => $u->is($user));
        $this->actingAs($admin)->get(route('admin.users.password', $user))->assertOk()
            ->assertViewIs('admin.users.password')->assertViewHas('user', fn ($u) => $u->is($user));
        $this->actingAs($admin)->get(route('admin.users.show', $user))->assertNotFound();
        $this->actingAs($admin)->delete(route('admin.users.destroy', $user))->assertNotFound();
        $this->assertNotNull($user->fresh());
    }

    public function test_store_validates_and_creates_account(): void
    {
        Mail::fake();
        config(['settings.default_user_plan' => 'free']);
        $admin = $this->admin();
        $country = $this->country();
        $taken = $this->plainUser();

        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.users.store'), []));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.users.store'), [
            'name' => 'Nia', 'username' => $taken->username, 'email' => 'nia@example.test', 'password' => 'secret12', 'country' => $country->id,
        ]));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.users.store'), [
            'name' => 'Nia', 'username' => 'nia', 'email' => 'nia@example.test', 'password' => '123', 'country' => $country->id,
        ]));
        $this->assertPanelFails($this->actingAs($admin)->panelPost(route('admin.users.store'), [
            'name' => 'Nia', 'username' => 'nia', 'email' => 'nia@example.test', 'password' => 'secret12', 'country' => 999999,
        ]));
        $this->assertNull(User::where('username', 'nia')->first());

        $this->assertPanelOk($this->actingAs($admin)->panelPost(route('admin.users.store'), [
            'name' => 'Nia', 'username' => 'nia', 'email' => 'nia@example.test', 'password' => 'secret12', 'country' => $country->id,
        ]));
        $nia = User::where('username', 'nia')->firstOrFail();
        $this->assertSame(['user', 'Nia', 'nia@example.test', 'default_user.png', 'TT', 'Testland'],
            [$nia->user_type, $nia->name, $nia->email, $nia->image, $nia->country_code, $nia->country]);
        $this->assertTrue(Hash::check('secret12', $nia->password));
        $this->assertNotNull($nia->email_verified_at);
        Mail::assertQueued(UserDetails::class, fn ($m) => $m->hasTo('nia@example.test'));
    }

    public function test_update_validates_and_saves(): void
    {
        $admin = $this->admin();
        $country = $this->country();
        $user = User::factory()->create(['user_type' => 'user', 'status' => 1, 'email_verified_at' => now(), 'username' => 'before']);
        $other = $this->plainUser();

        $this->actingAs($admin)->from(route('admin.users.edit', $user))->put(route('admin.users.update', $user), [
            'user_type' => 'user', 'name' => 'X', 'username' => $other->username, 'email' => 'x@example.test', 'country' => $country->id,
        ])->assertRedirect(route('admin.users.edit', $user))->assertSessionHas('app_alert_type', 'error');
        $this->assertSame('before', $user->fresh()->username);

        $this->actingAs($admin)->from(route('admin.users.edit', $user))->put(route('admin.users.update', $user), [
            'user_type' => 'user', 'name' => 'Renamed', 'username' => 'after', 'email' => 'after@example.test',
            'status' => '0', 'email_status' => '0', 'country' => $country->id,
        ])->assertRedirect(route('admin.users.edit', $user))->assertSessionHas('app_alert_type', 'success');
        $user->refresh();
        $this->assertSame(['Renamed', 'after', 'after@example.test', 0, 'TT', 'Testland'],
            [$user->name, $user->username, $user->email, (int) $user->status, $user->country_code, $user->country]);
        $this->assertNull($user->email_verified_at);

        // Saving an unchanged username/email is not a clash with itself.
        $this->actingAs($admin)->from(route('admin.users.edit', $user))->put(route('admin.users.update', $user), [
            'user_type' => 'user', 'name' => 'Renamed', 'username' => 'after', 'email' => 'after@example.test',
            'status' => '1', 'email_status' => '1', 'country' => $country->id,
        ])->assertSessionHas('app_alert_type', 'success');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(1, (int) $user->fresh()->status);
    }

    public function test_bulk_delete(): void
    {
        $admin = $this->admin();
        $a = $this->plainUser();
        $b = $this->plainUser();
        $c = $this->plainUser();
        $keep = $this->plainUser();
        $store = Store::factory()->create(['user_id' => $a->id]);
        Notification::create(['title' => 'New user', 'type' => 'user', 'link' => route('admin.users.edit', $a->id)]);

        $this->actingAs($admin)->post(route('admin.users.delete'), ['ids' => [$a->id, $b->id]])
            ->assertOk()->assertJson(['success' => true]);
        $this->actingAs($admin)->post(route('admin.users.delete'), ['ids' => (string) $c->id])
            ->assertOk()->assertJson(['success' => true]);
        $this->actingAs($admin)->postJson(route('admin.users.delete'), ['ids' => []])
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->assertNull($a->fresh());
        $this->assertNull($b->fresh());
        $this->assertNull($c->fresh());
        $this->assertNotNull($keep->fresh());
        $this->assertNull(Store::find($store->id));
        $this->assertSame(0, Notification::where('link', route('admin.users.edit', $a->id))->count());
    }

    public function test_delete_avatar(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['user_type' => 'user', 'image' => 'missing-avatar-test.png']);

        $this->actingAs($admin)->from(route('admin.users.edit', $user))->delete(route('admin.users.deleteAvatar', $user))
            ->assertRedirect(route('admin.users.edit', $user))->assertSessionHas('app_alert_type', 'success');
        $this->assertSame('default_user.png', $user->fresh()->image);
    }

    public function test_password_reset_is_validated_and_audited(): void
    {
        $admin = $this->admin();
        $user = $this->plainUser();
        $old = $user->password;

        $this->actingAs($admin)->from(route('admin.users.password', $user))->post(route('admin.users.password.update', $user), [
            'password' => 'newsecret1', 'password_confirmation' => 'different',
        ])->assertRedirect(route('admin.users.password', $user))->assertSessionHas('app_alert_type', 'error');
        $this->assertSame($old, $user->fresh()->password);

        $this->actingAs($admin)->from(route('admin.users.password', $user))->post(route('admin.users.password.update', $user), [
            'password' => 'newsecret1', 'password_confirmation' => 'newsecret1',
        ])->assertRedirect(route('admin.users.password', $user))->assertSessionHas('app_alert_type', 'success');
        $this->assertTrue(Hash::check('newsecret1', $user->fresh()->password));
        $this->assertDatabaseHas('activity_logs', ['action' => 'admin.password_reset', 'actor_user_id' => $admin->id]);
    }

    public function test_send_mail(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $user = User::factory()->create(['user_type' => 'user', 'email' => 'reader@example.test']);

        $this->actingAs($admin)->from(route('admin.users.edit', $user))->post(route('admin.users.sendmail', $user), [
            'subject' => 'Hello', 'message' => 'Body', 'reply_to' => 'not-an-email',
        ])->assertRedirect(route('admin.users.edit', $user))->assertSessionHas('app_alert_type', 'error');
        Mail::assertNothingQueued();

        $this->actingAs($admin)->from(route('admin.users.edit', $user))->post(route('admin.users.sendmail', $user), [
            'subject' => 'Hello', 'message' => '<p>Body</p>', 'reply_to' => 'support@example.test',
        ])->assertRedirect(route('admin.users.edit', $user))->assertSessionHas('app_alert_type', 'success');
        Mail::assertQueued(SupportMessage::class, fn ($m) => $m->hasTo('reader@example.test'));
    }

    public function test_manual_plan_change(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $admin = $this->admin();
        $plan = $this->plan();
        $user = $this->plainUser();

        $this->actingAs($admin)->from(route('admin.users.edit', $user))->post(route('admin.users.plan', $user), [])
            ->assertSessionHas('app_alert_type', 'error');
        $this->assertSame(0, Upgrade::where('user_id', $user->id)->count());

        $this->actingAs($admin)->from(route('admin.users.edit', $user))->post(route('admin.users.plan', $user), [
            'plan' => (string) $plan->id, 'plan_expiration_date' => '2031-05-06', 'package_trial_done' => '1',
        ])->assertRedirect(route('admin.users.edit', $user))->assertSessionHas('app_alert_type', 'success');
        $upgrade = Upgrade::where('user_id', $user->id)->sole();
        $this->assertSame((string) $plan->id, (string) $upgrade->sub_id);
        $this->assertSame(strtotime('2031-05-06'), (int) $upgrade->upgrade_expires);
        $this->assertSame(Upgrade::STATUS_ACTIVE, $upgrade->status);
        $this->assertSame((string) $plan->id, (string) $user->fresh()->group_id);
        $this->assertSame('1', (string) UserSettings::legacy((int) $user->id, 'package_trial_done'));

        $this->actingAs($admin)->from(route('admin.users.edit', $user))->post(route('admin.users.plan', $user), [
            'plan' => 'free', 'package_trial_done' => '0',
        ])->assertSessionHas('app_alert_type', 'success');
        $this->assertSame(0, Upgrade::where('user_id', $user->id)->count());
        $this->assertSame('free', $user->fresh()->group_id);
        $this->assertSame('0', (string) UserSettings::legacy((int) $user->id, 'package_trial_done'));
    }

    public function test_sign_in_as_account(): void
    {
        $admin = $this->admin();
        $user = $this->plainUser();

        $this->actingAs($admin)->from(route('admin.users.index'))->post(route('admin.users.login', $admin))
            ->assertRedirect(route('admin.users.index'))->assertSessionHas('app_alert_type', 'info');
        $this->assertSame($admin->id, auth()->id());

        // Upstream e757fc4fa: no reason, no impersonation.
        $this->actingAs($admin)->from(route('admin.users.index'))->post(route('admin.users.login', $user))
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame($admin->id, auth()->id());

        $this->actingAs($admin)->post(route('admin.users.login', $user), ['reason' => 'Support ticket 42'])
            ->assertRedirect(route('dashboard'))->assertSessionHas('quick_admin_user_id', $admin->id);
        $this->assertSame($user->id, auth()->id());
    }

    public function test_rewrite_escapes_cells_and_keeps_omitted_fields(): void
    {
        $admin = $this->admin();
        // Fixed identity: the search below is 'eve' and a random faker admin name/e-mail (e.g. "Everardo Koss") would match too.
        $admin->forceFill(['name' => 'Admin Person', 'username' => 'adminperson', 'email' => 'admin.person@example.test'])->save();
        $country = $this->country();
        User::factory()->create(['name' => '<b>Eve</b>', 'username' => 'eve<i>', 'user_type' => 'user']);

        $row = $this->actingAs($admin)->getJson(
            route('admin.users.index', ['draw' => 1, 'start' => 0, 'length' => 25, 'search' => ['value' => 'eve<i>']]), // not 'eve': a random faker admin name ("Cleve") would match too
            ['X-Requested-With' => 'XMLHttpRequest']
        )->json('data.0');
        $this->assertStringNotContainsString('<b>Eve</b>', $row[1]);
        $this->assertStringContainsString('&lt;b&gt;Eve&lt;/b&gt;', $row[1]);
        $this->assertStringNotContainsString('eve<i>', $row[1]);

        // A form without status / email_status keeps the stored values (the old code wrote null).
        $user = User::factory()->create(['user_type' => 'user', 'status' => 0, 'email_verified_at' => now()]);
        $this->actingAs($admin)->from(route('admin.users.edit', $user))->put(route('admin.users.update', $user), [
            'user_type' => 'user', 'name' => 'Kept', 'username' => 'kept1', 'email' => 'kept@example.test', 'country' => $country->id,
        ])->assertSessionHas('app_alert_type', 'success');
        $this->assertSame(0, (int) $user->fresh()->status);
        $this->assertNotNull($user->fresh()->email_verified_at);

        // user_type is limited to the two account kinds.
        $this->actingAs($admin)->from(route('admin.users.edit', $user))->put(route('admin.users.update', $user), [
            'user_type' => 'root', 'name' => 'Kept', 'username' => 'kept1', 'email' => 'kept@example.test', 'country' => $country->id,
        ])->assertSessionHas('app_alert_type', 'error');
        $this->assertSame('user', $user->fresh()->user_type);
    }

    public function test_rewrite_refuses_unknown_plans_and_missing_expiry(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $admin = $this->admin();
        $plan = $this->plan();
        $user = $this->plainUser();
        $group = $user->fresh()->group_id;

        $this->actingAs($admin)->from(route('admin.users.edit', $user))->post(route('admin.users.plan', $user), [
            'plan' => '999999', 'plan_expiration_date' => '2031-05-06',
        ])->assertSessionHas('app_alert_type', 'error');
        $this->actingAs($admin)->from(route('admin.users.edit', $user))->post(route('admin.users.plan', $user), [
            'plan' => (string) $plan->id,
        ])->assertSessionHas('app_alert_type', 'error');
        $this->assertSame(0, Upgrade::where('user_id', $user->id)->count());
        $this->assertSame($group, $user->fresh()->group_id);
    }

    public function test_accounts_ability_is_admin_only(): void
    {
        $this->assertTrue(Gate::forUser($this->admin())->allows(PanelAbilities::ACCOUNTS));
        $this->assertFalse(Gate::forUser($this->plainUser())->allows(PanelAbilities::ACCOUNTS));
    }
}
