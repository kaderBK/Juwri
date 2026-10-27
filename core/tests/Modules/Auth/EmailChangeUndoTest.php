<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Auth;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\ActivityLog;
use App\Models\StaffMember;
use App\Models\WebhookEndpoint;
use App\Services\Account\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\AddDashboardCspHeaders;
use Juwri\Core\Http\Middleware\IsDemoMode;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Services\OwnershipTransferService;
use Juwri\Modules\Auth\Contracts\EmailChangeUndos;
use Juwri\Modules\Auth\Models\EmailChangeUndo;
use Juwri\Modules\Auth\Models\Passkey;
use Juwri\Modules\Auth\Services\EmailChangeUndoService;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Team\Models\StaffToken;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-036 A1a: the notice to the OLD address after a sign-in email change carries a signed,
 * single-use, 7-day "This wasn't me — undo" link. GET shows a confirm page only; POST
 * restores the old email, locks the account (password unknowable, sessions die), removes
 * access granted since the change, voids newer links and mails a reset link to the old
 * address.
 */
class EmailChangeUndoTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret-pass-1';

    /** @var list<array{to: list<string>, subject: string, body: string}> */
    private array $mails = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class, SyncDashboardStore::class, ...EditionTestHooks::middleware(), AbuseHoldWall::class,
            AddDashboardCspHeaders::class, EnsureStaffDashboardPermission::class, IsDemoMode::class,
        ]);
        Event::listen(MessageSent::class, function (MessageSent $e) {
            $this->mails[] = [
                'to' => array_map(fn ($a) => $a->getAddress(), $e->message->getTo()),
                'subject' => (string) $e->message->getSubject(),
                'body' => (string) ($e->message->getTextBody() ?? '').(string) ($e->message->getHtmlBody() ?? ''),
            ];
        });
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'x', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 999], 'status' => 1,
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'own'.Str::lower(Str::random(6)),
            'email' => 'owner_'.Str::lower(Str::random(6)).'@old.test',
            'password' => Hash::make(self::PASSWORD), 'image' => 'default_user.png',
        ]);
    }

    /** @return array{0: User, 1: string, 2: string} owner (email changed), old email, undo url */
    private function changedOwner(): array
    {
        $owner = $this->owner();
        $old = $owner->email;
        $new = 'taker_'.Str::lower(Str::random(6)).'@new.test';
        $owner->forceFill(['email' => $new])->save();
        $url = app(EmailChangeUndos::class)->tryIssue($owner, $old, $new, EmailChangeUndo::SOURCE_SELF, null, $owner->id);
        $this->assertNotNull($url);

        return [$owner->fresh(), $old, (string) $url];
    }

    private function path(string $url): string
    {
        return (string) Str::after($url, rtrim((string) config('app.url'), '/'));
    }

    public function test_owner_settings_email_change_mails_the_old_address_an_undo_link(): void
    {
        $owner = $this->owner();
        $old = $owner->email;

        $this->actingAs($owner)->from('/settings')->post(route('settings.editProfile'), [
            'username' => $owner->username, 'email' => 'fresh@new.test', 'current_password' => self::PASSWORD,
        ])->assertRedirect();

        $notice = collect($this->mails)->first(fn ($m) => in_array($old, $m['to'], true));
        $this->assertNotNull($notice, 'the old address is told');
        $this->assertStringContainsString('/account/email-undo/', $notice['body']);
        $this->assertStringContainsString('expires in 7 days', $notice['body']);

        $undo = EmailChangeUndo::where('user_id', $owner->id)->sole();
        $this->assertSame($old, $undo->old_email);
        $this->assertSame('fresh@new.test', $undo->new_email);
        $this->assertSame(EmailChangeUndo::SOURCE_SELF, $undo->source);
        $this->assertSame(64, strlen($undo->token_hash));
        $this->assertStringNotContainsString($undo->token_hash, $notice['body'], 'only the raw token travels, never its hash');
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $undo->expires_at?->getTimestamp(), 5);
    }

    public function test_get_only_shows_a_confirm_page_and_changes_nothing(): void
    {
        [$owner, $old, $url] = $this->changedOwner();

        $this->get($this->path($url))->assertOk()
            ->assertSee('data-undo-state="confirm"', false)
            ->assertSee($owner->email)
            ->assertSee('method="POST"', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertSame($owner->email, $owner->fresh()->email);
        $this->assertNull(EmailChangeUndo::where('user_id', $owner->id)->value('used_at'));
        $this->assertNotSame($old, $owner->fresh()->email);
    }

    public function test_unsigned_or_wrong_token_links_do_nothing(): void
    {
        [$owner, , $url] = $this->changedOwner();
        $undo = EmailChangeUndo::where('user_id', $owner->id)->sole();

        $this->get('/account/email-undo/'.$undo->id.'?token=x')->assertForbidden();
        $this->post('/account/email-undo/'.$undo->id.'?token=x')->assertForbidden();

        // A correctly signed link with someone else's token: invalid.
        $forged = URL::temporarySignedRoute(EmailChangeUndoService::ROUTE, now()->addDay(), ['undo' => $undo->id, 'token' => Str::random(48)], false);
        $this->post($forged)->assertNotFound()->assertSee('data-undo-state="invalid"', false);
        $this->assertSame($owner->email, $owner->fresh()->email);
    }

    public function test_post_restores_the_email_locks_the_account_and_mails_a_reset_link(): void
    {
        Bus::fake([CreateNotificationJob::class]);
        [$owner, $old, $url] = $this->changedOwner();
        $owner->forceFill(['remember_token' => 'remember-me', 'oauth_provider' => 'google', 'oauth_uid' => 'g-123'])->save();
        DB::table('password_reset_tokens')->insert(['email' => $owner->email, 'token' => 'x', 'created_at' => now()]);
        $this->mails = [];

        $this->post($this->path($url))->assertOk()->assertSee('data-undo-state="done"', false);

        $fresh = $owner->fresh();
        $this->assertSame($old, $fresh->email);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertFalse(Hash::check(self::PASSWORD, (string) $fresh->password), 'the password stops working');
        $this->assertNotSame('remember-me', $fresh->remember_token);
        $this->assertNull($fresh->oauth_uid, 'a social login linked by the taker is removed');
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $owner->email)->count());

        $undo = EmailChangeUndo::where('user_id', $owner->id)->sole();
        $this->assertNotNull($undo->used_at);
        $this->assertNotNull($undo->used_ip);

        $this->assertTrue(collect($this->mails)->contains(fn ($m) => in_array($old, $m['to'], true) && str_contains($m['body'], 'password/reset/')),
            'a reset link goes to the restored address');
        $this->assertFalse(collect($this->mails)->contains(fn ($m) => in_array($owner->email, $m['to'], true)), 'nothing goes to the reverted address');

        $log = ActivityLog::where('action', 'account.email.undo')->sole();
        $this->assertSame($old, $log->meta['restored_email']);
        $this->assertContains('social_login', $log->meta['cleared']);
        Bus::assertDispatched(CreateNotificationJob::class, fn ($j) => $j->type === 'security');
    }

    public function test_all_api_access_is_removed_and_older_two_factor_is_kept(): void
    {
        $owner = $this->owner();
        $before = ApiToken::create(['user_id' => $owner->id, 'name' => 'Old sync', 'token' => hash('sha256', Str::random(40)), 'abilities' => ['read']]);
        $before->forceFill(['created_at' => now()->subDays(3)])->save();
        $owner->forceFill(['two_factor_secret' => 'old-secret', 'two_factor_confirmed_at' => now()->subDays(30)])->save();

        $this->travel(1)->minutes();
        $old = $owner->email;
        $owner->forceFill(['email' => 'taker@new.test'])->save();
        $url = (string) app(EmailChangeUndos::class)->tryIssue($owner, $old, 'taker@new.test', EmailChangeUndo::SOURCE_SELF);

        $this->travel(1)->minutes();
        $after = ApiToken::create(['user_id' => $owner->id, 'name' => 'Exfil', 'token' => hash('sha256', Str::random(40)), 'abilities' => ['read']]);
        $app = ApiToken::create(['user_id' => $owner->id, 'name' => 'Mobile app', 'token' => hash('sha256', Str::random(40)),
            'abilities' => ['read'], 'kind' => 'app', 'device_id' => 'd1', 'platform' => 'ios', 'expires_at' => now()->addDays(180)]);

        $this->post($this->path($url))->assertOk();

        $this->assertNull($before->fresh(), 'BL-036 review: every API token goes, older ones too (the owner re-issues them)');
        $this->assertNull($after->fresh(), 'an API token minted since the change is deleted');
        $this->assertNull($app->fresh(), 'owner-app devices are signed out');
        $this->assertSame('old-secret', $owner->fresh()->two_factor_secret, '2FA enrolled before the change stays');
        $this->assertContains('api_tokens', ActivityLog::where('action', 'account.email.undo')->sole()->meta['cleared']);
    }

    public function test_two_factor_enrolled_since_the_change_is_removed(): void
    {
        [$owner, , $url] = $this->changedOwner();
        $this->travel(1)->minutes();
        $owner->forceFill(['two_factor_secret' => 'taker-secret', 'two_factor_recovery_codes' => 'x', 'two_factor_confirmed_at' => now()])->save();

        $this->post($this->path($url))->assertOk();

        $fresh = $owner->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_confirmed_at);
    }

    public function test_the_link_works_once(): void
    {
        [$owner, $old, $url] = $this->changedOwner();
        $this->post($this->path($url))->assertOk();
        $owner->fresh()->forceFill(['email' => 'again@new.test'])->save();

        $this->post($this->path($url))->assertStatus(410)->assertSee('data-undo-state="used"', false);
        $this->get($this->path($url))->assertStatus(410);
        $this->assertSame('again@new.test', $owner->fresh()->email);
        $this->assertNotSame($old, $owner->fresh()->email);
    }

    public function test_expired_link_is_refused(): void
    {
        [$owner, , $url] = $this->changedOwner();
        EmailChangeUndo::where('user_id', $owner->id)->update(['expires_at' => now()->subMinute()]);

        // The signature itself is still valid for a moment: the row's own expiry refuses it.
        $this->post($this->path($url))->assertStatus(410)->assertSee('data-undo-state="expired"', false);
        $this->assertSame($owner->email, $owner->fresh()->email);
    }

    public function test_old_address_held_by_another_account_since_before_the_change_is_refused(): void
    {
        [$owner, $old, $url] = $this->changedOwner();
        $holder = $this->owner();
        DB::table('users')->where('id', $holder->id)
            ->update(['email' => $old, 'created_at' => now()->subYear(), 'updated_at' => now()->subYear()]);

        $this->get($this->path($url))->assertStatus(410)->assertSee('data-undo-state="taken"', false);
        $this->post($this->path($url))->assertStatus(409)->assertSee('data-undo-state="taken"', false);
        $this->assertSame($owner->email, $owner->fresh()->email);
        $this->assertNull(EmailChangeUndo::where('user_id', $owner->id)->value('used_at'));
        $this->assertSame(1, ActivityLog::where('action', 'account.email.undo_refused')->count());
    }

    public function test_an_undo_voids_newer_links_but_not_older_ones(): void
    {
        $owner = $this->owner();
        $first = $owner->email;
        $undos = app(EmailChangeUndos::class);
        $owner->forceFill(['email' => 'b@new.test'])->save();
        $urlA = (string) $undos->tryIssue($owner, $first, 'b@new.test', EmailChangeUndo::SOURCE_SELF);
        $owner->forceFill(['email' => 'c@new.test'])->save();
        $urlB = (string) $undos->tryIssue($owner, 'b@new.test', 'c@new.test', EmailChangeUndo::SOURCE_SELF);
        $owner->forceFill(['email' => 'd@new.test'])->save();
        $urlC = (string) $undos->tryIssue($owner, 'c@new.test', 'd@new.test', EmailChangeUndo::SOURCE_SELF);

        // The taker undoes its own middle change: the later link dies, the owner's first link survives.
        $this->post($this->path($urlB))->assertOk();
        $this->assertSame('b@new.test', $owner->fresh()->email);
        $this->post($this->path($urlC))->assertStatus(410)->assertSee('data-undo-state="void"', false);

        $this->post($this->path($urlA))->assertOk();
        $this->assertSame($first, $owner->fresh()->email);
    }

    public function test_reseller_made_changes_start_the_reseller_block(): void
    {
        $owner = $this->owner();
        $old = $owner->email;
        $owner->forceFill(['email' => 'reseller-set@new.test'])->save();
        $undos = app(EmailChangeUndos::class);
        $url = (string) $undos->tryIssue($owner, $old, 'reseller-set@new.test', EmailChangeUndo::SOURCE_RESELLER, null, null);
        $this->assertNull($undos->resellerBlockedUntil($owner));

        $this->post($this->path($url))->assertOk();

        $until = $undos->resellerBlockedUntil($owner->fresh());
        $this->assertNotNull($until);
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $until->getTimestamp(), 5);
        $this->travel(8)->days();
        $this->assertNull($undos->resellerBlockedUntil($owner->fresh()));
    }

    public function test_self_changes_do_not_start_the_reseller_block(): void
    {
        [$owner, , $url] = $this->changedOwner();
        $this->post($this->path($url))->assertOk();

        $this->assertNull(app(EmailChangeUndos::class)->resellerBlockedUntil($owner->fresh()));
        $this->assertEmpty(UserOption::getUserOption($owner->id, EmailChangeUndoService::RESELLER_BLOCK_OPTION));
    }

    public function test_no_link_is_minted_when_nothing_changed(): void
    {
        $owner = $this->owner();

        $this->assertNull(app(EmailChangeUndos::class)->tryIssue($owner, $owner->email, strtoupper($owner->email), EmailChangeUndo::SOURCE_SELF));
        $this->assertNull(app(EmailChangeUndos::class)->tryIssue($owner, '', 'x@new.test', EmailChangeUndo::SOURCE_SELF));
        $this->assertSame(0, EmailChangeUndo::count());
        $this->assertSame('', app(EmailChangeUndos::class)->undoParagraph(null));
    }

    public function test_webhooks_created_since_the_change_are_paused_not_deleted(): void
    {
        $owner = $this->owner();
        $older = WebhookEndpoint::create(['user_id' => $owner->id, 'url' => 'https://owner.example/hook', 'events' => ['*'], 'active' => true]);
        $older->forceFill(['created_at' => now()->subDays(5)])->save();
        $this->travel(1)->minutes();
        $old = $owner->email;
        $owner->forceFill(['email' => 'taker@new.test'])->save();
        $url = (string) app(EmailChangeUndos::class)->tryIssue($owner, $old, 'taker@new.test', EmailChangeUndo::SOURCE_SELF);
        $this->travel(1)->minutes();
        $exfil = WebhookEndpoint::create(['user_id' => $owner->id, 'url' => 'https://taker.example/hook', 'events' => ['order.created'], 'active' => true]);

        $this->post($this->path($url))->assertOk()->assertSee('data-undo-webhooks="1"', false);

        $this->assertFalse((bool) $exfil->fresh()->active);
        $this->assertNotEmpty($exfil->fresh()->disabled_reason);
        $this->assertTrue((bool) $older->fresh()->active, 'endpoints from before the change keep running');
        $undo = EmailChangeUndo::where('user_id', $owner->id)->sole();
        $this->assertSame([$exfil->id], $undo->deactivatedWebhookIds());
        $log = ActivityLog::where('action', 'account.email.undo')->sole();
        $this->assertSame([$exfil->id], $log->meta['deactivated_webhooks']);
        $this->assertContains('webhooks', $log->meta['cleared']);
    }

    public function test_done_page_without_paused_webhooks_has_no_notice(): void
    {
        [, , $url] = $this->changedOwner();

        $this->post($this->path($url))->assertOk()->assertDontSee('data-undo-webhooks', false);
    }

    private function passkey(User $owner, string $name): object
    {
        $id = DB::table('passkeys')->insertGetId([
            'user_id' => $owner->id, 'name' => $name, 'credential_id' => Str::random(32), 'rp_id' => 'localhost',
            'public_key' => 'pk', 'sign_count' => 0, 'credential' => json_encode(['id' => Str::random(8)]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('passkeys')->where('id', $id)->first();
    }

    public function test_passkeys_registered_since_the_change_are_removed(): void
    {
        $owner = $this->owner();
        $mine = $this->passkey($owner, 'Owner phone');
        DB::table('passkeys')->where('id', $mine->id)->update(['created_at' => now()->subDays(10)]);
        $this->travel(1)->minutes();
        $old = $owner->email;
        $owner->forceFill(['email' => 'taker@new.test'])->save();
        $url = (string) app(EmailChangeUndos::class)->tryIssue($owner, $old, 'taker@new.test', EmailChangeUndo::SOURCE_SELF);
        $this->travel(1)->minutes();
        $takers = $this->passkey($owner, 'Taker laptop');

        $this->post($this->path($url))->assertOk();

        // A deleted credential can never complete a passkey sign-in (the ceremony looks it up by credential_id).
        $this->assertNull(Passkey::where('credential_id', $takers->credential_id)->first(), 'the taker passkey is gone');
        $this->assertNotNull(Passkey::find($mine->id), 'a passkey from before the change stays');
        $this->assertContains('passkeys', ActivityLog::where('action', 'account.email.undo')->sole()->meta['cleared']);
    }

    public function test_staff_created_or_edited_since_the_change_are_switched_off(): void
    {
        $owner = $this->owner();
        $store = Store::create(['user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'S',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        $mk = fn (string $n) => StaffMember::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'name' => $n,
            'email' => Str::lower(Str::random(8)).'@t.test', 'password' => Hash::make('x'), 'role' => 'manager', 'active' => 1]);
        $loyal = $mk('Loyal');
        $edited = $mk('Edited');
        DB::table('staff_members')->whereIn('id', [$loyal->id, $edited->id])
            ->update(['created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)]);
        $this->travel(1)->minutes();
        $old = $owner->email;
        $owner->forceFill(['email' => 'taker@new.test'])->save();
        $url = (string) app(EmailChangeUndos::class)->tryIssue($owner, $old, 'taker@new.test', EmailChangeUndo::SOURCE_SELF);
        $this->travel(1)->minutes();
        $backdoor = $mk('Backdoor');
        DB::table('staff_members')->where('id', $backdoor->id)->update(['pin_hash' => Hash::make('1234')]);
        $edited->forceFill(['password' => Hash::make('taker')])->save();
        // A sign-in bumps updated_at (last_login_at) but changes nothing security-relevant.
        $loyal->forceFill(['last_login_at' => now()])->save();
        StaffToken::forceCreate(['staff_id' => $backdoor->id, 'name' => 'app', 'token' => hash('sha256', Str::random(40))]);

        $this->post($this->path($url))->assertOk()->assertSee('data-undo-staff="2"', false);

        $this->assertFalse((bool) $backdoor->fresh()->active);
        $this->assertNull($backdoor->fresh()->pin_hash, 'its PIN is revoked');
        $this->assertSame(0, StaffToken::where('staff_id', $backdoor->id)->count(), 'its staff-app tokens are gone');
        $this->assertFalse((bool) $edited->fresh()->active, 'a member edited since the change is switched off too');
        $this->assertTrue((bool) $loyal->fresh()->active, 'staff who only signed in since keep working');
        $ids = EmailChangeUndo::where('user_id', $owner->id)->sole()->disabledStaffIds();
        sort($ids);
        $this->assertSame([$edited->id, $backdoor->id], $ids);
    }

    public function test_the_old_address_stays_reserved_while_the_undo_is_live(): void
    {
        [$owner, $old, $url] = $this->changedOwner();
        $other = $this->owner();

        // Another account cannot move onto the old address…
        $this->actingAs($other)->from('/settings')->post(route('settings.editProfile'), [
            'username' => $other->username, 'email' => strtoupper($old), 'current_password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');
        // …and nobody can sign up with it.
        $this->app['auth']->forgetGuards();
        $this->assertTrue(app(EmailChangeUndos::class)->isReserved($old));
        $this->assertFalse(app(EmailChangeUndos::class)->isReserved($old, (int) $owner->id), 'the owner may go back to it');

        $this->post($this->path($url))->assertOk();
        $this->assertFalse(app(EmailChangeUndos::class)->isReserved($old), 'a used link reserves nothing');
    }

    public function test_registration_refuses_a_reserved_address(): void
    {
        [, $old] = $this->changedOwner();
        $this->app['auth']->forgetGuards();

        $this->post(route('register'), [
            'name' => 'Squatter', 'username' => 'squat'.Str::lower(Str::random(4)), 'email' => $old,
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'terms' => '1',
        ])->assertSessionHasErrors('email');
        $this->assertSame(0, User::whereRaw('lower(email) = ?', [mb_strtolower($old)])->count());
    }

    public function test_an_account_that_took_the_address_after_the_change_never_blocks_the_undo(): void
    {
        [$owner, $old, $url] = $this->changedOwner();
        $this->travel(1)->minutes();
        // A path that skipped the reservation (or data from before it existed).
        $squatter = $this->owner();
        $squatter->forceFill(['email' => $old])->save();

        Bus::fake([CreateNotificationJob::class]);

        $this->post($this->path($url))->assertOk()->assertSee('data-undo-state="done"', false);

        $this->assertSame($old, $owner->fresh()->email);
        $this->assertStringEndsWith('@invalid.invalid', (string) $squatter->fresh()->email, 'the squatter loses the address');
        $log = ActivityLog::where('action', 'account.email.squatter_released')->sole();
        $this->assertSame($old, $log->meta['released_email']);
        $this->assertSame((int) $owner->id, (int) $log->meta['undo_user_id']);
        Bus::assertDispatched(CreateNotificationJob::class, fn ($j) => $j->type === 'security'
            && str_contains($j->title, '#'.$squatter->id) && str_contains($j->title, $old)
            && $j->link === route('admin.users.edit', $squatter->id));
        $this->assertTrue(Lang::has('lang.account_id_username_lost_its_sign_in_email_email_it_was_created_after_an_email_change_that_was_then_undone'));
    }

    public function test_an_older_account_that_moved_onto_the_address_after_the_change_still_refuses(): void
    {
        $holder = $this->owner();
        DB::table('users')->where('id', $holder->id)->update(['created_at' => now()->subYear(), 'updated_at' => now()->subYear()]);
        [$owner, $old, $url] = $this->changedOwner();
        $this->travel(1)->minutes();
        DB::table('users')->where('id', $holder->id)->update(['email' => $old, 'updated_at' => now()]);

        $this->post($this->path($url))->assertStatus(409);

        $this->assertSame($old, $holder->fresh()->email, 'an account from before the change is never stripped of its email');
        $this->assertNotSame($old, $owner->fresh()->email);
        $this->assertSame(0, ActivityLog::where('action', 'account.email.squatter_released')->count());
    }

    public function test_an_account_that_held_the_address_before_the_change_still_refuses(): void
    {
        [$owner, $old, $url] = $this->changedOwner();
        $holder = $this->owner();
        DB::table('users')->where('id', $holder->id)
            ->update(['email' => $old, 'created_at' => now()->subYear(), 'updated_at' => now()->subYear()]);

        $this->post($this->path($url))->assertStatus(409);
        $this->assertSame($old, $holder->fresh()->email);
    }

    public function test_an_account_deletion_requested_since_the_change_is_cancelled(): void
    {
        [$owner, , $url] = $this->changedOwner();
        $this->travel(1)->minutes();
        $deletion = app(AccountDeletionService::class)->request($owner->fresh());
        $this->assertSame(0, (int) $owner->fresh()->status);

        $this->post($this->path($url))->assertOk();

        $this->assertSame(AccountDeletion::RESTORED, $deletion->fresh()->status, 'its restore link dies with the PENDING status');
        $this->assertSame(1, (int) $owner->fresh()->status);
        $this->assertContains('account_deletion', ActivityLog::where('action', 'account.email.undo')->sole()->meta['cleared']);
    }

    public function test_webhooks_edited_since_the_change_are_paused_too(): void
    {
        $owner = $this->owner();
        $hook = WebhookEndpoint::create(['user_id' => $owner->id, 'url' => 'https://owner.example/hook', 'events' => ['*'], 'active' => true]);
        $hook->forceFill(['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)])->save();
        $this->travel(1)->minutes();
        $old = $owner->email;
        $owner->forceFill(['email' => 'taker@new.test'])->save();
        $url = (string) app(EmailChangeUndos::class)->tryIssue($owner, $old, 'taker@new.test', EmailChangeUndo::SOURCE_SELF);
        $this->travel(1)->minutes();
        $hook->forceFill(['url' => 'https://taker.example/hook'])->save();

        $this->post($this->path($url))->assertOk();

        $this->assertFalse((bool) $hook->fresh()->active, 'an endpoint re-pointed since the change is paused');
    }

    /**
     * BL-036 re-review: there is NO way to cancel pending undo links from the account. Password + 2FA is exactly what
     * someone who changed the email holds, so such a switch would reopen the bypass. Accepted risk (ledger): a
     * compromised OLD inbox can undo a change the owner made, for up to 7 days.
     */
    public function test_undo_links_cannot_be_cancelled_from_the_account(): void
    {
        [$owner, , $url] = $this->changedOwner();
        $this->app['auth']->forgetGuards();

        $this->assertFalse(Route::has('settings.emailUndo.cancel'));
        $this->actingAs($owner)->get(route('settings'))->assertOk()->assertDontSee('data-email-undo-cancel', false);
        $this->assertContains($this->actingAs($owner)->post('/settings/email-undo/cancel', ['undo_password' => self::PASSWORD])->status(), [404, 405]);

        $this->app['auth']->forgetGuards();
        $this->post($this->path($url))->assertOk()->assertSee('data-undo-state="done"', false);
    }

    private function storeOf(User $owner): Store
    {
        return Store::create(['user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'S',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
    }

    private function staffOf(User $owner, Store $store, bool $active = true): StaffMember
    {
        return StaffMember::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'S '.Str::random(4),
            'email' => Str::lower(Str::random(8)).'@t.test', 'password' => Hash::make('x'), 'role' => 'manager', 'active' => $active]);
    }

    /** Round 4: cancelling the taker's deletion must not switch the taker's staff back on. */
    public function test_cancelling_a_deletion_since_the_change_does_not_revive_the_takers_staff(): void
    {
        $owner = $this->owner();
        $store = $this->storeOf($owner);
        $loyal = $this->staffOf($owner, $store);
        DB::table('staff_members')->where('id', $loyal->id)
            ->update(['created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20), 'security_changed_at' => now()->subDays(20)]);
        $this->travel(1)->minutes();
        $old = $owner->email;
        $owner->forceFill(['email' => 'taker@new.test'])->save();
        $url = (string) app(EmailChangeUndos::class)->tryIssue($owner, $old, 'taker@new.test', EmailChangeUndo::SOURCE_SELF);
        $this->travel(1)->minutes();
        $backdoor = $this->staffOf($owner, $store);
        $this->travel(1)->minutes();
        $deletion = app(AccountDeletionService::class)->request($owner->fresh());
        $this->assertSame([$loyal->id, $backdoor->id], $deletion->fresh()->deactivated_staff_ids);

        $this->post($this->path($url))->assertOk();

        $this->assertSame(AccountDeletion::RESTORED, $deletion->fresh()->status);
        $this->assertFalse((bool) $backdoor->fresh()->active, 'the taker staff stays off');
        $this->assertTrue((bool) $loyal->fresh()->active, 'staff from before the change come back with the account');
        $this->assertSame(0, StaffToken::where('staff_id', $backdoor->id)->count());
        $this->assertContains($backdoor->id, EmailChangeUndo::where('user_id', $owner->id)->sole()->disabledStaffIds());
    }

    /** Round 4: a store moved into the account after the change brings its staff; they are switched off too. */
    public function test_staff_of_a_store_transferred_in_since_the_change_are_switched_off(): void
    {
        [$owner, , $url] = $this->changedOwner();
        $this->travel(1)->minutes();
        $accomplice = $this->owner();
        $store = $this->storeOf($accomplice);
        $moved = $this->staffOf($accomplice, $store);
        DB::table('staff_members')->where('id', $moved->id)
            ->update(['created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30), 'security_changed_at' => now()->subDays(30)]);
        StaffToken::forceCreate(['staff_id' => $moved->id, 'name' => 'app', 'token' => hash('sha256', Str::random(40))]);
        $transfer = StoreTransfer::create([
            'kind' => 'store', 'token' => StoreTransfer::newToken(), 'from_user_id' => $accomplice->id,
            'to_user_id' => $owner->id, 'to_email' => $owner->email, 'store_id' => $store->id,
            'status' => 'pending', 'expires_at' => now()->addDay(), 'by_superadmin' => true,
        ]);
        app(OwnershipTransferService::class)->applyStore($transfer);
        $this->assertSame((int) $owner->id, (int) $moved->fresh()->owner_id);

        $this->post($this->path($url))->assertOk();

        $this->assertFalse((bool) $moved->fresh()->active, 'transferred staff are switched off');
        $this->assertSame(0, StaffToken::where('staff_id', $moved->id)->count());
    }

    /** @return array{0: User, 1: StaffMember, 2: StaffMember, 3: AccountDeletion, 4: string} owner, loyal, backdoor, deletion, undo url */
    private function takerDeletedAccount(): array
    {
        $owner = $this->owner();
        $store = $this->storeOf($owner);
        $loyal = $this->staffOf($owner, $store);
        DB::table('staff_members')->where('id', $loyal->id)
            ->update(['created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20), 'security_changed_at' => now()->subDays(20)]);
        $this->travel(1)->minutes();
        $old = $owner->email;
        $owner->forceFill(['email' => 'taker@new.test'])->save();
        $url = (string) app(EmailChangeUndos::class)->tryIssue($owner, $old, 'taker@new.test', EmailChangeUndo::SOURCE_SELF);
        $this->travel(1)->minutes();
        $backdoor = $this->staffOf($owner, $store);
        $this->travel(1)->minutes();
        $deletion = app(AccountDeletionService::class)->request($owner->fresh());

        return [$owner, $loyal, $backdoor, $deletion, $url];
    }

    /** Round 5: the signed restore link, used in the window after the undo, never switches the taker's staff back on. */
    public function test_a_restore_link_used_after_the_undo_keeps_the_takers_staff_off(): void
    {
        [, $loyal, $backdoor, $deletion, $url] = $this->takerDeletedAccount();
        $this->post($this->path($url))->assertOk();
        // The window between the undo's commit and its own restore: the deletion is still pending.
        DB::table('account_deletions')->where('id', $deletion->id)->update(['status' => AccountDeletion::PENDING, 'restored_at' => null]);
        DB::table('staff_members')->where('id', $loyal->id)->update(['active' => 0]);

        $this->post(app(AccountDeletionService::class)->restoreUrl($deletion->fresh()))->assertRedirect(route('login'));

        $this->assertSame(AccountDeletion::RESTORED, $deletion->fresh()->status);
        $this->assertFalse((bool) $backdoor->fresh()->active, 'the link path reads the undo and keeps its staff off');
        $this->assertTrue((bool) $loyal->fresh()->active);
        $this->assertFalse(app(AccountDeletionService::class)->restore($deletion->fresh()), 'a second restore finds nothing pending');
    }

    /** Round 5: the taker restores through the link first; the later undo still switches off only the taker's staff. */
    public function test_a_restore_link_used_before_the_undo_leaves_the_owners_team_on(): void
    {
        [$owner, $loyal, $backdoor, $deletion, $url] = $this->takerDeletedAccount();
        $this->post(app(AccountDeletionService::class)->restoreUrl($deletion))->assertRedirect(route('login'));
        $this->assertTrue((bool) $loyal->fresh()->active);
        $this->assertTrue((bool) $backdoor->fresh()->active);

        $this->post($this->path($url))->assertOk();

        $this->assertFalse((bool) $backdoor->fresh()->active, 'created since the change: switched off');
        $this->assertTrue((bool) $loyal->fresh()->active, 'the restore did not stamp the owner\'s team');
        $this->assertSame([$backdoor->id], EmailChangeUndo::where('user_id', $owner->id)->sole()->disabledStaffIds());
    }
}
