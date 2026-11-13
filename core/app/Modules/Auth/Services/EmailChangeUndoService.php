<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Modules\Auth\Contracts\EmailChangeUndos;
use Juwri\Modules\Auth\Events\CredentialsChanged;
use Juwri\Modules\Auth\Events\EmailChangeUndone;
use Juwri\Modules\Auth\Models\EmailChangeUndo;
use Juwri\Modules\Auth\Models\Passkey;

/**
 * BL-036 A1a: "This wasn't me — undo" on the notice mailed to the OLD address after a
 * sign-in email change.
 *
 * Threat: someone holding the password (or a bad-faith reseller) points the sign-in
 * email at an inbox they control and then uses the public forgot-password flow. The old
 * address gets a signed, single-use link (7 days). Performing it:
 *   - restores the old email (refused when another account now uses it),
 *   - drops every reset token for the old, new and current address,
 *   - LOCKS the account: the password becomes an unknowable random hash, so only the
 *     reset link mailed to the restored address gets back in; with AuthenticateSession
 *     (web group) that signs every session out; the remember token is rotated,
 *   - removes access granted since the change: social-login link, 2FA enrolled since,
 *     OAuth/personal tokens minted since (here), API tokens (Integrations, via
 *     EmailChangeUndone), owner-app devices (CredentialsChanged); webhook endpoints
 *     created since are paused, never deleted (A1b, ids kept in `meta`),
 *   - voids NEWER pending undo links of the user (never older ones: the real owner's
 *     first link always wins over a later link the attacker mailed itself),
 *   - mails a reset link to the restored address,
 *   - a reseller-made change blocks further reseller email changes and reseller "login as"
 *     for RESELLER_BLOCK_SECONDS; always: activity log + admin bell.
 *
 * Links are built on the platform host (app.url), never a reseller's custom domain (a
 * reseller controls that DNS and could swallow the click); the signature is relative so
 * it does not depend on the host the change was made on.
 */
class EmailChangeUndoService implements EmailChangeUndos
{
    public const VALID_DAYS = 7;

    /** user_options key: unix time of the last performed undo of a reseller-made change. */
    public const RESELLER_BLOCK_OPTION = 'reseller_email_undo_at';

    public const ROUTE = 'account.email-undo';

    /** Record a pending undo and return the absolute link for the old address. */
    public function issue(User $user, string $oldEmail, string $newEmail, string $source, ?int $resellerId = null, ?int $actorId = null): string
    {
        $token = Str::random(48);
        $undo = EmailChangeUndo::create([
            'user_id' => $user->id,
            'old_email' => mb_substr(trim($oldEmail), 0, 190),
            'new_email' => mb_substr(trim($newEmail), 0, 190),
            'source' => $source === EmailChangeUndo::SOURCE_RESELLER ? EmailChangeUndo::SOURCE_RESELLER : EmailChangeUndo::SOURCE_SELF,
            'reseller_id' => $resellerId,
            'actor_user_id' => $actorId,
            'token_hash' => hash('sha256', $token),
            'changed_at' => now(),
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);

        return $this->url($undo, $token);
    }

    /**
     * issue() that never breaks the caller's notice: null when there is nothing to undo,
     * the table is not migrated yet or the insert fails (the notice then goes out without a link).
     */
    public function tryIssue(User $user, string $oldEmail, string $newEmail, string $source, ?int $resellerId = null, ?int $actorId = null): ?string
    {
        if (trim($oldEmail) === '' || strcasecmp(trim($oldEmail), trim($newEmail)) === 0) {
            return null;
        }
        try {
            if (! Schema::hasTable('email_change_undos')) {
                return null;
            }

            return $this->issue($user, $oldEmail, $newEmail, $source, $resellerId, $actorId);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function undoParagraph(?string $url): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        return ___('This wasn\'t me — undo this change').":\n".$url."\n\n"
            .___('The undo link works once and expires in 7 days. It restores this address, signs everyone out and sends you a link to choose a new password.');
    }

    public function selfChangeNotice(?string $url): string
    {
        return rtrim(___('The email address on your account was just changed. If you did not make this change, contact support immediately.')
            ."\n\n".$this->undoParagraph($url));
    }

    public function url(EmailChangeUndo $undo, string $token): string
    {
        $path = URL::temporarySignedRoute(self::ROUTE, $undo->expires_at ?? now()->addDays(self::VALID_DAYS), ['undo' => $undo->id, 'token' => $token], false);

        return rtrim((string) config('app.url'), '/').$path;
    }

    /** The undo row matching id + token (constant-time), or null. */
    public function resolve(int $id, string $token): ?EmailChangeUndo
    {
        $undo = EmailChangeUndo::find($id);
        if (! $undo || $token === '' || ! hash_equals((string) $undo->token_hash, hash('sha256', $token))) {
            return null;
        }

        return $undo;
    }

    /** ok | used | void | expired */
    public function state(EmailChangeUndo $undo): string
    {
        return match (true) {
            $undo->used_at !== null => 'used',
            $undo->voided_at !== null => 'void',
            $undo->expires_at === null || $undo->expires_at->isPast() => 'expired',
            default => 'ok',
        };
    }

    /**
     * Is the old address the sign-in email of ANOTHER account that existed before the change? Such an
     * account is legitimate (even if it moved onto the address later) and the undo is refused: support
     * sorts it out. Only an account CREATED after the change is a squatter: it never blocks the undo
     * (see releaseSquatters()).
     */
    public function oldEmailTaken(EmailChangeUndo $undo): bool
    {
        $since = $undo->changed_at ?? $undo->created_at ?? now();

        return $this->holdersOf($undo)
            ->where(fn ($q) => $q->where('created_at', '<', $since)->orWhereNull('created_at'))
            ->exists();
    }

    public function isReserved(string $email, ?int $exceptUserId = null): bool
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || ! Schema::hasTable('email_change_undos')) {
            return false;
        }

        return EmailChangeUndo::query()
            ->whereRaw('lower(old_email) = ?', [$email])
            ->whereNull('used_at')->whereNull('voided_at')->where('expires_at', '>', now())
            ->when($exceptUserId !== null, fn ($q) => $q->where('user_id', '!=', $exceptUserId))
            ->exists();
    }

    public function reservedEmailRule(?int $exceptUserId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($exceptUserId): void {
            if (is_string($value) && $this->isReserved($value, $exceptUserId)) {
                $fail(___('This email address is not available right now. Please use another one.'));
            }
        };
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> other accounts whose sign-in email is the undo's old address */
    private function holdersOf(EmailChangeUndo $undo): Builder
    {
        return User::query()->whereKeyNot($undo->user_id)
            ->whereRaw('lower(email) = ?', [mb_strtolower($undo->old_email)]);
    }

    /**
     * Accounts CREATED after the change that hold the old address (only possible before reservation existed, or
     * via a path that skips it) must not keep it: their sign-in email moves to an unusable placeholder. Each one
     * is named in the activity log and an admin bell, so support can reach its holder.
     *
     * @return list<int> released account ids
     */
    private function releaseSquatters(EmailChangeUndo $undo): array
    {
        $since = $undo->changed_at ?? $undo->created_at ?? now();
        $ids = [];
        foreach ($this->holdersOf($undo)->where('created_at', '>=', $since)->get() as $squatter) {
            $address = (string) $squatter->email;
            $squatter->forceFill(['email' => 'released-'.$squatter->id.'-'.Str::lower(Str::random(8)).'@invalid.invalid', 'email_verified_at' => null])->save();
            $ids[] = (int) $squatter->id;

            try {
                log_activity('account.email.squatter_released', $squatter, [
                    'released_email' => $address, 'undo_id' => $undo->id, 'undo_user_id' => (int) $undo->user_id,
                ]);
                CreateNotificationJob::dispatch(
                    ___('Account #:id (:username) lost its sign-in email :email: it was created after an email change that was then undone', [
                        'id' => $squatter->id, 'username' => (string) $squatter->username, 'email' => $address,
                    ]),
                    'security',
                    route('admin.users.edit', $squatter->id),
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $ids;
    }

    /**
     * Perform the undo. Returns done | used | void | expired | taken | missing.
     * Single-use: the row is claimed with an atomic `used_at IS NULL` update.
     */
    public function perform(EmailChangeUndo $undo, ?string $ip = null): string
    {
        if (($state = $this->state($undo)) !== 'ok') {
            return $state;
        }
        $user = User::find($undo->user_id);
        if (! $user instanceof User) {
            return 'missing';
        }
        if ($this->oldEmailTaken($undo)) {
            log_activity('account.email.undo_refused', $user, ['undo_id' => $undo->id, 'reason' => 'old_email_taken']);

            return 'taken';
        }

        $since = $undo->changed_at ?? $undo->created_at ?? now();
        $currentEmail = (string) $user->email;
        $restored = $undo->old_email;
        $event = null;

        $claimed = DB::transaction(function () use ($undo, $user, $ip, $since, $currentEmail, $restored, &$event) {
            $won = EmailChangeUndo::whereKey($undo->id)->whereNull('used_at')->whereNull('voided_at')
                ->where('expires_at', '>', now())
                ->update(['used_at' => now(), 'used_ip' => $ip !== null ? mb_substr($ip, 0, 45) : null, 'updated_at' => now()]);
            if ($won !== 1) {
                return false;
            }
            // Round 5: hold the user's pending deletion rows (first, before the user row: the same order as
            // AccountDeletionService::restore) while the listeners switch staff off, so a restore through the signed
            // link waits for this commit and then sees disabled_staff.
            AccountDeletion::where('user_id', $user->id)->where('status', AccountDeletion::PENDING)->lockForUpdate()->get(['id']);

            $cleared = [];
            $released = $this->releaseSquatters($undo);
            if ($released !== []) {
                $cleared[] = 'squatter_released';
            }
            $changes = [
                'email' => $restored,
                // Clicking a link mailed to the old address proves control of it.
                'email_verified_at' => now(),
                // The lock: nobody knows this password, so the only way back in is the reset
                // link mailed to the restored address. The new hash also ends every web session.
                'password' => Hash::make(Str::random(64)),
                'remember_token' => Str::random(60),
            ];
            if (! empty($user->oauth_uid)) {
                // A social account linked while the attacker held the new inbox would still sign in.
                $changes['oauth_provider'] = null;
                $changes['oauth_uid'] = null;
                $cleared[] = 'social_login';
            }
            if ($user->two_factor_confirmed_at !== null && $user->two_factor_confirmed_at->gte($since)) {
                // 2FA enrolled after the change is the attacker's device.
                $changes['two_factor_secret'] = null;
                $changes['two_factor_recovery_codes'] = null;
                $changes['two_factor_confirmed_at'] = null;
                $cleared[] = 'two_factor';
            }
            $user->forceFill($changes)->save();

            User::purgePasswordResetTokens([$restored, $undo->new_email, $currentEmail]);
            $cleared = array_merge($cleared, $this->revokeFrameworkTokens($user));
            // Passkeys registered since the change are the taker's: they would sign in past the password lock.
            if (Passkey::where('user_id', $user->id)->where('created_at', '>=', $since)->delete() > 0) {
                $cleared[] = 'passkeys';
            }

            $event = new EmailChangeUndone($user, $undo, $since);
            $event->cleared = $cleared;
            if ($released !== []) {
                $event->meta['released_accounts'] = $released;
            }
            event($event);
            if ($event->meta !== [] && Schema::hasColumn('email_change_undos', 'meta')) {
                // A1b: what listeners paused (e.g. webhook endpoints) stays on the row for the done page and support.
                $meta = array_merge(is_array($undo->meta) ? $undo->meta : [], $event->meta);
                EmailChangeUndo::whereKey($undo->id)->update(['meta' => json_encode($meta)]);
                $undo->setAttribute('meta', $meta);
            }

            // Newer pending links of this user are void; older ones stay valid.
            EmailChangeUndo::where('user_id', $user->id)->where('id', '>', $undo->id)
                ->whereNull('used_at')->whereNull('voided_at')
                ->update(['voided_at' => now(), 'updated_at' => now()]);

            if ($undo->source === EmailChangeUndo::SOURCE_RESELLER) {
                UserOption::updateUserOption($user->id, self::RESELLER_BLOCK_OPTION, (string) now()->getTimestamp());
            }

            return true;
        });

        if (! $claimed || ! $event instanceof EmailChangeUndone) {
            return 'used';
        }

        $user->refresh();
        // An account deletion requested since the change is the taker's: cancel it. The restore itself keeps off the
        // staff the Team listener switched off (it reads this undo's disabled_staff; round 5).
        if ($this->restoreDeletionSince($user, $since)) {
            $event->cleared[] = 'account_deletion';
            $user->refresh();
        }
        try {
            // Owner-app devices sign out (Integrations) and remaining API tokens are flagged for review.
            event(new CredentialsChanged($user, CredentialsChanged::EMAIL_CHANGE_UNDONE));
        } catch (\Throwable $e) {
            report($e);
        }
        $this->sendReset($user);
        $this->record($undo, $user, $currentEmail, $event->cleared);

        return 'done';
    }

    public function resellerBlockedUntil(User $user): ?Carbon
    {
        $at = (int) UserOption::getUserOption($user->id, self::RESELLER_BLOCK_OPTION, 0);
        if ($at <= 0) {
            return null;
        }
        $until = Carbon::createFromTimestamp($at + self::RESELLER_BLOCK_SECONDS);

        return now()->lt($until) ? $until : null;
    }

    /** Brand shown on the undo page: the reseller's brand for a reseller-made change, else the platform's. */
    public static function brand(?EmailChangeUndo $undo): string
    {
        $reseller = $undo && $undo->reseller_id ? self::reseller((int) $undo->reseller_id) : null;

        return trim((string) ($reseller?->getAttribute('brand_name') ?? '')) ?: (string) config('settings.site_title', config('app.name'));
    }

    /** Support contact shown when the undo cannot be performed automatically. */
    public static function supportContact(EmailChangeUndo $undo): string
    {
        $reseller = $undo->reseller_id ? self::reseller((int) $undo->reseller_id) : null;

        return implode(' / ', array_values(array_unique(array_filter([
            trim((string) ($reseller?->getAttribute('support_email') ?? '')),
            trim((string) config('settings.contact_email')),
        ]))));
    }

    // ── internals ────────────────────────────────────────────────────────────

    private static function reseller(int $id): ?Model
    {
        try {
            return app(ResellerContext::class)->find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Every Passport access token and personal access token of the user: whoever held the new inbox had
     * the account, so no token survives (the owner re-issues them after the reset).
     *
     * @return list<string> what was revoked
     */
    private function revokeFrameworkTokens(User $user): array
    {
        $done = [];
        if (Schema::hasTable('oauth_access_tokens')) {
            $ids = DB::table('oauth_access_tokens')->where('user_id', $user->id)->where('revoked', false)->pluck('id')->all();
            if ($ids !== []) {
                DB::table('oauth_access_tokens')->whereIn('id', $ids)->update(['revoked' => true]);
                if (Schema::hasTable('oauth_refresh_tokens')) {
                    DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->update(['revoked' => true]);
                }
                $done[] = 'oauth_tokens';
            }
        }
        if (Schema::hasTable('personal_access_tokens')) {
            $n = DB::table('personal_access_tokens')
                ->whereIn('tokenable_type', array_values(array_unique([$user->getMorphClass(), User::class])))
                ->where('tokenable_id', $user->id)->delete();
            if ($n > 0) {
                $done[] = 'personal_access_tokens';
            }
        }

        return $done;
    }

    /**
     * An account deletion requested since the change is the taker's: it is cancelled (the account is
     * restored, its restore link dies with the PENDING status). True when one was cancelled.
     */
    private function restoreDeletionSince(User $user, CarbonInterface $since): bool
    {
        try {
            $deletion = AccountDeletion::where('user_id', $user->id)->where('status', AccountDeletion::PENDING)
                ->where('created_at', '>=', $since)->latest('id')->first();
            if (! $deletion instanceof AccountDeletion) {
                return false;
            }
            $service = 'App\\Services\\Account\\AccountDeletionService';
            if (class_exists($service) && app($service)->restore($deletion)) {
                return true;
            }
            // Fallback (restore refused, e.g. past its window): never leave a taker's deletion pending.
            $deletion->forceFill(['status' => AccountDeletion::RESTORED, 'restored_at' => now()])->save();
            $user->forceFill(['status' => max(1, (int) $deletion->previous_status)])->save();

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function sendReset(User $user): void
    {
        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
            if ($status !== Password::RESET_LINK_SENT) {
                Log::warning('[email-undo] reset link not sent', ['user' => $user->id, 'status' => $status]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param  list<string>  $cleared */
    private function record(EmailChangeUndo $undo, User $user, string $emailBeforeUndo, array $cleared): void
    {
        $meta = [
            'undo_id' => $undo->id, 'source' => $undo->source,
            'restored_email' => $undo->old_email, 'reverted_email' => $undo->new_email,
            'email_before_undo' => $emailBeforeUndo, 'cleared' => array_values(array_unique($cleared)),
            'deactivated_webhooks' => $undo->deactivatedWebhookIds(),
            'disabled_staff' => $undo->disabledStaffIds(),
            'released_accounts' => array_values(array_map('intval', (array) (($undo->meta ?? [])['released_accounts'] ?? []))),
        ];

        try {
            log_activity('account.email.undo', $user, $meta);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $title = $undo->source === EmailChangeUndo::SOURCE_RESELLER
                ? ___('Customer reverted an email change made by reseller :brand (possible abuse)', ['brand' => self::brand($undo)])
                : ___('A user reverted an email change on their account (possible takeover)');
            CreateNotificationJob::dispatch($title, 'security', route('admin.users.edit', $user->id));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
