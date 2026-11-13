<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Account;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Billing\Subscriptions;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Models\AccountDeletion;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Account\AccountExports;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Services\Tenancy\StoreDataEraser;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Juwri\Core\Services\Tenancy\StoreDataScope;
use Juwri\Core\Services\Tenancy\StoreUploadFiles;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Settings\UserSettings;
use Juwri\Modules\AppStore\Mail\AppDunningMail;
use Juwri\Modules\Auth\Models\EmailChangeUndo;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Messaging\Models\PushSubscription;

/**
 * Owner self-service account deletion (Apple 5.1.1(v); owner-app server item S3).
 *
 * Policy (owner decision 2026-09-25):
 *  - REQUEST closes the account at once: login refused (status 0), storefronts
 *    offline (they require an active owner), platform + reseller subscriptions
 *    cancelled with no refund, app/API tokens, push devices and staff logins revoked.
 *  - RESTORE is possible for GRACE_DAYS via a signed link emailed to the owner.
 *  - PURGE after GRACE_DAYS: every row of the account in every registered tenant table is deleted EXCEPT the
 *    financial / tax records (RETAINED_TABLES, P4-07), whose customer personal data is anonymized;
 *    the user and store rows are ANONYMIZED, never deleted — posts.user_id,
 *    orders.store_id and transaction.user_id are ON DELETE CASCADE, so a row
 *    delete would destroy the invoices tax law requires us to keep.
 */
class AccountDeletionService
{
    /** BL-036 round 7: a failed staff step is retried once the request is this old (minutes). */
    public const STAFF_CLOSE_RETRY_AFTER_MINUTES = 5;

    public const GRACE_DAYS = 30;

    /**
     * P4-07: the tables in which rows of a deleted account are KEPT after the purge (everything else of the account is
     * deleted). Privacy §8 / DPA 10.4: invoices, payment and tax records (6 years) — stripped of the store's customers'
     * personal data —, the rows they point at (account, owner, stores: anonymised) and the deletion request itself
     * (records of rights requests). Pinned by AccountDeletionPurgeCompletenessTest.
     */
    public const RETAINED_TABLES = [
        // the account, its owner / users and stores — anonymised; the records below point at them
        'stores', 'users',
        // records of rights requests (Privacy §8: 6 years)
        'account_deletions',
        // the stores' sales and tax records: orders and their lines, refunds, e-invoices, the books
        'orders', 'order_items', 'order_item_extras', 'order_adjustments', 'order_refund_items', 'fiscal_documents',
        'accounting_accounts', 'accounting_journals', 'accounting_journal_lines', 'expenses', 'supplier_invoices',
        'supplier_payments', 'check_payments', 'disputes', 'external_invoices',
        // invoice lines point at these catalogue rows (ON DELETE CASCADE): kept soft-deleted, without images
        'items', 'modifiers', 'modifier_groups',
        // what juwri billed and paid the account (platform invoices, receipts, wallet money, payouts)
        'transaction', 'billing_receipts', 'wallet_transactions', 'seller_withdrawals',
    ];

    /**
     * Owner decision 2026-10-07: the account's security logs — its sign-ins and its security events — are KEPT by the
     * purge for SECURITY_LOG_DAYS (abuse and fraud investigations), then deleted by {@see pruneSecurityLogs()}
     * (accounts:prune-security-logs, daily). Sign-ins: every row of these tables. Security events: the activity-log
     * rows whose action starts with one of SECURITY_ACTIONS; every other activity-log row is deleted at the purge.
     */
    public const SECURITY_LOG_DAYS = 365;

    public const SECURITY_LOG_TABLES = ['login_audits'];

    public const SECURITY_ACTIONS = [
        'auth.', 'security.', '2fa.', 'two_factor.', 'password.', 'api_token.',
        'staff.passkey', 'staff.two_factor', 'admin.impersonate', 'admin.password_reset',
    ];

    /** Limit an activity_logs query to (true) or away from (false) the security events. Exact prefixes (no LIKE wildcards). */
    public static function securityEvents(Builder $query, bool $security): Builder
    {
        $match = function (Builder $q): void {
            foreach (self::SECURITY_ACTIONS as $prefix) {
                $q->orWhereRaw('SUBSTR(action, 1, '.strlen($prefix).') = ?', [$prefix]);
            }
        };

        return $security ? $query->where($match) : $query->whereNot($match);
    }

    /** Retained rows' file columns cleared (their files deleted) on purge: column => value written. */
    private const RETAINED_STRIP = [
        'items' => ['image' => '', 'gallery_images' => null],
        'modifiers' => ['image' => null],
        'orders' => ['pod_photo' => null],
    ];

    /** Order columns holding the store's customers' personal data. */
    public const ORDER_PII = [
        'customer_name' => '', 'phone_number' => '', 'address' => '', 'message' => '',
        'vehicle_details' => null, 'delivery_lat' => null, 'delivery_lng' => null,
        'driver_note' => null, 'pod_otp' => null, 'customer_id' => null, // pod_photo: RETAINED_STRIP (file deleted too)
    ];

    // ── request (close now) ──────────────────────────────────────────────────

    /**
     * `$source`: web | app (the owner) or reseller (the owner's reseller closed it —
     * no self-restore link is mailed and the reseller is not notified of its own act).
     */
    public function request(User $user, ?string $reason = null, ?string $ip = null, string $source = 'web'): AccountDeletion
    {
        if ($existing = AccountDeletion::pendingFor((int) $user->id)) {
            return $existing;
        }

        $deletion = AccountDeletion::create([
            'user_id' => $user->id,
            'status' => AccountDeletion::PENDING,
            'requested_at' => now(),
            'purge_after' => now()->addDays(self::GRACE_DAYS),
            'email_hash' => hash('sha256', mb_strtolower((string) $user->email)),
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'requested_ip' => $ip,
            'source' => in_array($source, ['web', 'app', 'reseller'], true) ? $source : 'web',
            'previous_status' => (int) ($user->status ?? 1),
            // BL-036 round 7: set true by closeStaff(); a row still false is retried by retryStaffClosures().
            'purge_report' => ['staff_closed' => false],
        ]);

        $this->close($user, $deletion);
        if ($source === 'reseller') {
            $this->mailOwnerClosedByReseller($user, $deletion);
        } else {
            $this->notifyReseller($user, $deletion);
            $this->mailOwner($user, $deletion);
        }

        return $deletion;
    }

    /** Close the account: every step is independent and never blocks the others. */
    private function close(User $user, AccountDeletion $deletion): void
    {
        $user->forceFill(['status' => 0])->save();
        $storeIds = Store::where('user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->attempt('platform subscription', fn () => $this->cancelAtGateway($user));

        $this->attempt('reseller subscription', function () use ($user) {
            // Hosted (Paddle/Lemon) subscriptions are cancelled by the customer at the gateway; locally the
            // customer is gone either way (Reseller module, ee — P1-13 extension point).
            app(ResellerContext::class)->cancelCustomerSubscriptions($user);
        });

        $this->attempt('tokens', fn () => ApiToken::where('user_id', $user->id)->delete());
        $this->attempt('push', function () use ($user, $storeIds) {
            PushSubscription::where('user_id', $user->id)->delete();
            if ($storeIds !== []) {
                PushSubscription::whereIn('store_id', $storeIds)->delete();
            }
        });
        // Round 7: a failure (lock-wait timeout, a deadlock past the retries) reaches error reporting and the row stays
        // marked staff_closed=false for retryStaffClosures().
        $this->attempt('staff', fn () => $this->closeStaff($storeIds, $deletion), report: true);
        $this->attempt('remember token', fn () => $user->forceFill(['remember_token' => Str::random(60)])->save());
    }

    /**
     * BL-036 rounds 4-6: switch the owner's staff off and remember exactly who (a restore switches on only them). One
     * transaction, and the same lock order as an email-change undo and AccountDeletionService::restore(): the deletion
     * row first, then the staff rows in id order, so those three never deadlock. A deletion that is no longer pending
     * (an undo restored it between request() and this step) switches nobody off and stores nothing.
     *
     * @param  list<int>  $storeIds
     * @return bool whether staff were switched off
     */
    private function closeStaff(array $storeIds, AccountDeletion $deletion): bool
    {
        $hasStaff = $storeIds !== [] && Schema::hasTable('staff_members');

        // Round 7: a deadlock or serialization failure is retried (3 attempts in all).
        return DB::transaction(function () use ($storeIds, $deletion, $hasStaff): bool {
            $locked = AccountDeletion::whereKey($deletion->id)->lockForUpdate()->first();
            if (! $locked instanceof AccountDeletion || $locked->status !== AccountDeletion::PENDING) {
                return false;
            }
            $ids = $hasStaff
                ? DB::table('staff_members')->whereIn('store_id', $storeIds)->where('active', true)->orderBy('id')->lockForUpdate()
                    ->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                : [];
            $changes = ['purge_report' => ['staff_closed' => true] + (is_array($locked->purge_report) ? $locked->purge_report : [])];
            if (Schema::hasColumn('account_deletions', 'deactivated_staff_ids')) {
                $changes['deactivated_staff_ids'] = $ids;
            }
            $locked->forceFill($changes)->save();
            $deletion->setRawAttributes($locked->getAttributes(), true);
            if (! $hasStaff) {
                return false;
            }
            // Not stamped (security_changed_at): switching staff off only removes access. Stamping it would make an
            // email-change undo treat every staff member a taker's closure switched off as changed.
            DB::table('staff_members')->whereIn('store_id', $storeIds)->update(['active' => 0]);
            if (Schema::hasTable('staff_tokens')) {
                DB::table('staff_tokens')->whereIn('staff_id', DB::table('staff_members')->whereIn('store_id', $storeIds)->select('id'))->delete();
            }

            return true;
        }, 3);
    }

    /**
     * BL-036 round 7: re-run the closure's staff step for pending deletions whose step failed (staff_closed=false,
     * requested more than a few minutes ago). Rows from before the marker existed (no staff_closed key) are skipped.
     * Scheduled every ten minutes (accounts:retry-staff-close).
     *
     * @return array{retried: int, closed: int}
     */
    public function retryStaffClosures(): array
    {
        $out = ['retried' => 0, 'closed' => 0];
        $due = AccountDeletion::where('status', AccountDeletion::PENDING)
            ->where('requested_at', '<=', now()->subMinutes(self::STAFF_CLOSE_RETRY_AFTER_MINUTES))
            ->orderBy('id')->get()
            ->filter(fn (AccountDeletion $d) => is_array($d->purge_report) && ($d->purge_report['staff_closed'] ?? null) === false);
        foreach ($due as $deletion) {
            $out['retried']++;
            try {
                $storeIds = Store::where('user_id', $deletion->user_id)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $this->closeStaff($storeIds, $deletion);
                if (($deletion->purge_report['staff_closed'] ?? false) === true) {
                    $out['closed']++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $out;
    }

    /**
     * P6-244: cancel the recurring subscription at the gateway FIRST; only a successful cancel ends it locally. On a
     * failure the local subscription stays live (the gateway is still billing it, the upgrade row keeps its gateway
     * id = the "cancel pending" mark), the admin is alerted, and {@see retryGatewayCancels()} (scheduled) tries again.
     *
     * @return bool true when nothing is billing at the gateway any more
     */
    public function cancelAtGateway(User $user): bool
    {
        try {
            $user->cancelRecurringSubscription(); // gateway-side (Stripe / PayPal / MoR); clears the gateway id on success
        } catch (\Throwable $e) {
            Log::warning('[account-deletion] gateway cancel failed', ['user' => $user->id, 'error' => $e->getMessage()]);
            try {
                log_activity('account.gateway_cancel_failed', $user, ['error' => mb_substr($e->getMessage(), 0, 300)], null);
            } catch (\Throwable) {
            }
            AlertDispatcher::send('payment', "closed account #{$user->id}: the gateway subscription could not be cancelled (".mb_substr($e->getMessage(), 0, 200).') — still billing; retried daily, purge blocked until it succeeds');

            return false;
        }

        app(Subscriptions::class)->expireLive($user, 'user');

        return true;
    }

    /** P6-244: is a gateway subscription still live for this user (a cancel that has not succeeded yet)? */
    public function gatewaySubscriptionLive(User $user): bool
    {
        $upgrade = $user->upgrade()->first();

        return $upgrade !== null && (string) ($upgrade->unique_id ?? '') !== '' && str_contains((string) $upgrade->unique_id, '###');
    }

    /**
     * P6-244: retry the gateway cancel of every closed (pending deletion) account whose cancel failed.
     *
     * @return array{retried: int, cancelled: int}
     */
    public function retryGatewayCancels(): array
    {
        $out = ['retried' => 0, 'cancelled' => 0];
        $userIds = AccountDeletion::where('status', AccountDeletion::PENDING)->pluck('user_id')->all();
        foreach (User::whereIn('id', $userIds)->get() as $user) {
            if (! $this->gatewaySubscriptionLive($user)) {
                continue;
            }
            $out['retried']++;
            if ($this->cancelAtGateway($user)) {
                $out['cancelled']++;
            }
        }

        return $out;
    }

    // ── restore ──────────────────────────────────────────────────────────────

    public function restoreUrl(AccountDeletion $deletion): string
    {
        return URL::temporarySignedRoute('account.deletion.restore', $deletion->purge_after, ['deletion' => $deletion->id]);
    }

    public function restore(AccountDeletion $deletion): bool
    {
        // BL-036 round 5: re-read under a row lock so two restores (the signed link and an email-change undo) never
        // both pass the PENDING check; an undo holds the same lock while its listeners switch staff off.
        return DB::transaction(function () use ($deletion): bool {
            $locked = AccountDeletion::whereKey($deletion->id)->lockForUpdate()->first();
            if (! $locked instanceof AccountDeletion || $locked->status !== AccountDeletion::PENDING || $locked->purge_after->isPast()) {
                return false;
            }
            $user = User::find($locked->user_id);
            if (! $user) {
                return false;
            }

            $user->forceFill(['status' => max(1, (int) $locked->previous_status)])->save();
            $locked->forceFill(['status' => AccountDeletion::RESTORED, 'restored_at' => now()])->save();
            $deletion->setRawAttributes($locked->getAttributes(), true);
            if (Schema::hasTable('staff_members')) {
                $this->restoreStaff($user, $locked);
            }

            return true;
        });
    }

    /**
     * BL-036 round 4/5: switch back on only the staff this closure switched off, and only while they still belong to
     * one of the owner's stores. Staff the owner had disabled stay off; staff added after the closure are untouched;
     * staff an email-change undo switched off since the deletion stay off, whichever restore path runs (worked out
     * here, never from the caller). A legacy row (no list stored) switches on nobody: the owner does it from the Staff
     * page. Not stamped (security_changed_at): this puts back the state from before the closure.
     */
    private function restoreStaff(User $user, AccountDeletion $deletion): void
    {
        $ids = Schema::hasColumn('account_deletions', 'deactivated_staff_ids') ? $deletion->deactivated_staff_ids : null;
        if (! is_array($ids)) {
            try {
                log_activity('account.deletion.staff_not_restored', $user, ['deletion_id' => $deletion->id, 'reason' => 'no stored staff list'], null);
            } catch (\Throwable $e) {
                report($e);
            }

            return;
        }
        $ids = array_values(array_diff(array_filter(array_map('intval', $ids)), $this->staffKeptOffByUndo($user, $deletion)));
        if ($ids === []) {
            return;
        }
        DB::table('staff_members')->whereIn('id', $ids)
            ->whereIn('store_id', Store::where('user_id', $user->id)->select('id'))
            ->update(['active' => 1]);
    }

    /** @return list<int> staff switched off by an email-change undo used since this deletion was requested */
    private function staffKeptOffByUndo(User $user, AccountDeletion $deletion): array
    {
        if (! Schema::hasTable('email_change_undos')) {
            return [];
        }
        $out = [];
        $since = $deletion->created_at ?? $deletion->requested_at;
        foreach (EmailChangeUndo::where('user_id', $user->id)->whereNotNull('used_at')->where('used_at', '>=', $since)->get() as $undo) {
            array_push($out, ...$undo->disabledStaffIds());
        }

        return array_values(array_unique($out));
    }

    // ── purge (after the grace period) ──────────────────────────────────────

    /** @return array<string, mixed> report */
    public function purge(AccountDeletion $deletion): array
    {
        if ($deletion->status !== AccountDeletion::PENDING || $deletion->purge_after->isFuture()) {
            return ['skipped' => true];
        }

        $user = User::find($deletion->user_id);
        $report = ['stores' => [], 'tables' => []];

        // P6-244: never erase an account the gateway is still billing — the cancel is retried first.
        if ($user && $this->gatewaySubscriptionLive($user) && ! $this->cancelAtGateway($user)) {
            return ['skipped' => true, 'reason' => 'gateway subscription still live'];
        }

        if ($user) {
            $report = TenantContext::bypass(fn (): array => $this->purgeAccount($user));
        }

        if (($report['failed_stores'] ?? []) !== []) {
            $deletion->forceFill(['purge_report' => $report])->save();

            return $report;
        }

        $deletion->forceFill([
            'status' => AccountDeletion::PURGED,
            'purged_at' => now(),
            'purge_report' => $report,
        ])->save();

        return $report;
    }

    /**
     * P4-07: every row of the account in every registered table (the store-data registry: Community + the installed
     * edition — the list export and hard erasure use) is deleted, children first, except {@see RETAINED_TABLES}, which
     * are anonymised. Before, only the per-store backup registry plus a hand list was purged: most tables (API and
     * OAuth tokens, passkeys, credentials, bookings, staff, team chat, webhooks, AI and studio data, …) kept the
     * account's rows for ever. One transaction; refused and rolled back if a delete would reach a retained record
     * (a database cascade) or a row outside the account.
     *
     * @return array<string, mixed>
     */
    private function purgeAccount(User $user): array
    {
        $db = DB::connection();
        $accountId = (int) ($user->account_id ?: 0);
        $stores = Store::where('user_id', $user->id)->get();
        $report = ['stores' => $stores->pluck('id')->all(), 'tables' => []];

        $members = $accountId === 0 ? collect([$user]) : User::where('account_id', $accountId)->orWhere('id', $user->id)->get();
        $profileImages = $members->pluck('image')->filter(fn ($f) => is_string($f) && $f !== '' && $f !== 'default.png')->all();
        $eraser = app(StoreDataEraser::class);
        $files = [];
        $media = [];

        $scope = $accountId !== 0 ? StoreDataScope::account($accountId) : null; // a user without an account has no scoped rows

        $failedStores = [];

        // Isolate each store's anonymisation so one failing statement cannot roll back the owner's erasure.
        foreach ($stores as $store) {
            try {
                $db->transaction(function () use ($store): void {
                    $this->anonymizeStoreRecords($store);
                    $this->anonymizeStore($store);
                });
            } catch (\Throwable $e) {
                report($e);
                $failedStores[$store->id] = $e->getMessage();
            }
        }

        // Always commit the owner/member erasure before account-wide cleanup so a failing table never leaves PII live.
        $db->transaction(function () use ($members): void {
            foreach ($members as $member) {
                $this->anonymizeUser($member);
            }
        });

        $db->transaction(function () use ($db, $scope, $accountId, $eraser, &$files, &$media, &$report): void {
            if ($scope === null) {
                return;
            }
            $db->table('accounts')->where('id', $accountId)->update(['name' => 'Deleted account', 'billing_profile' => null, 'status' => 'closed', 'updated_at' => now()]);

            $this->detachRetained($db, $scope);
            $this->stripRetainedFiles($db, $scope, $eraser, $files, $media);
            $this->deleteAccountRows($db, $scope, $eraser, $files, $media, $report);
        });

        if ($failedStores !== []) {
            $report['failed_stores'] = $failedStores;
        }

        // Files only after the commit: a refused purge keeps them.
        $report['media_removed'] = $eraser->removeFiles($db, $files, $media);
        foreach ($stores as $store) {
            $report['media_removed'] += StoreUploadFiles::delete((int) $store->id); // P6-320
        }
        $report['files'] = count($files);
        ksort($report['tables']);
        foreach ($stores as $store) {
            TenantFill::forgetStore((int) $store->id);
            $this->removeStoreFiles($store); // the models still carry the logo / cover names read before the purge
        }
        foreach ($profileImages as $image) {
            try {
                remove_file('storage/profile/', $image);
            } catch (\Throwable) {
            }
        }
        if ($accountId !== 0) {
            app(AccountExports::class)->clear($accountId); // the owner's export archives
        }
        // Removing files writes the store's activity log and storage usage: sweep the account once more.
        if ($accountId !== 0) {
            $db->transaction(function () use ($db, $accountId, $eraser, &$report): void {
                $none = [];
                $noMedia = [];
                $sweep = ['tables' => []];
                $this->deleteAccountRows($db, StoreDataScope::account($accountId), $eraser, $none, $noMedia, $sweep);
                foreach ($sweep['tables'] as $name => $n) {
                    $report['tables'][$name] = ($report['tables'][$name] ?? 0) + $n;
                }
            });
        }

        return $report;
    }

    /**
     * Delete every row of the account outside {@see RETAINED_TABLES}, children first; refused (exception → rollback)
     * when a delete would reach a retained record (a cascade) or another account's row.
     *
     * @param  list<array{0: string, 1: string}>  $files
     * @param  array<string, array{0: string, 1: string, 2: string}>  $media
     * @param  array<string, mixed>  $report
     */
    private function deleteAccountRows(Connection $db, StoreDataScope $scope, StoreDataEraser $eraser, array &$files, array &$media, array &$report): void
    {
        $tables = array_values(array_filter(
            array_reverse($scope->orderedTables($db)),
            fn ($t) => ! in_array($t->table, self::RETAINED_TABLES, true) && ! in_array($t->table, self::SECURITY_LOG_TABLES, true)
                && $t->table !== 'accounts',
        ));
        $retainedBefore = $this->scopeCounts($db, $scope, self::RETAINED_TABLES);
        $outsideBefore = [];
        foreach ($tables as $table) {
            $q = $scope->query($db, $table);
            $outsideBefore[$table->table] = $db->table($table->table)->count() - ($q === null ? 0 : $q->count());
        }
        foreach ($tables as $table) {
            $query = $scope->query($db, $table);
            if ($query === null) {
                continue;
            }
            if ($table->table === 'activity_logs') {
                $query = self::securityEvents($query, false); // security events stay for SECURITY_LOG_DAYS
            }
            if (StoreDataEraser::ownsFiles($table)) {
                foreach ((clone $query)->get() as $row) {
                    $eraser->collect($table, (array) $row, $files, $media);
                }
            }
            if (($n = $query->delete()) > 0) {
                $report['tables'][$table->table] = $n;
            }
        }
        $retainedAfter = $this->scopeCounts($db, $scope, self::RETAINED_TABLES);
        if ($retainedAfter !== $retainedBefore) {
            throw new \RuntimeException('account purge would delete retained records (a cascade): '.json_encode(array_diff_assoc($retainedBefore, $retainedAfter)));
        }
        foreach ($outsideBefore as $name => $before) {
            $q = ($t = StoreDataRegistry::get($name)) ? $scope->query($db, $t) : null;
            if ($db->table($name)->count() - ($q === null ? 0 : $q->count()) !== $before) {
                throw new \RuntimeException("account purge would delete rows of another account in {$name}");
            }
        }
    }

    /**
     * A retained record must not go with a row the purge deletes: its nullable references to deleted tables are
     * cleared first (e.g. items.category_id → categories ON DELETE CASCADE would take the invoice lines with it).
     * A NOT NULL reference stays — the cascade guard then refuses the purge rather than lose a record.
     */
    private function detachRetained(Connection $db, StoreDataScope $scope): void
    {
        $schema = $db->getSchemaBuilder();
        foreach (self::RETAINED_TABLES as $name) {
            $table = StoreDataRegistry::get($name);
            if ($table === null || in_array($name, ['stores', 'users'], true) || ! $schema->hasTable($name)) {
                continue;
            }
            $nullable = collect($schema->getColumns($name))->filter(fn ($c) => $c['nullable'])->pluck('name')->all();
            foreach ($schema->getForeignKeys($name) as $fk) {
                $column = $fk['columns'][0] ?? null;
                $foreign = (string) $fk['foreign_table'];
                if (count($fk['columns']) !== 1 || in_array($foreign, [...self::RETAINED_TABLES, 'accounts'], true) || ! in_array($column, $nullable, true)) {
                    continue;
                }
                $scope->query($db, $table)?->whereNotNull($column)->update([$column => null]);
            }
        }
    }

    /**
     * Files of retained rows that are not part of the record: the catalogue pictures of the kept invoice lines and the
     * delivery photo of a kept order (a customer's door). Their files are removed with the rest; the columns cleared.
     * Receipts of kept expenses and supplier invoices stay (tax records).
     *
     * @param  list<array{0: string, 1: string}>  $files
     * @param  array<string, array{0: string, 1: string, 2: string}>  $media
     */
    private function stripRetainedFiles(Connection $db, StoreDataScope $scope, StoreDataEraser $eraser, array &$files, array &$media): void
    {
        foreach (self::RETAINED_STRIP as $name => $columns) {
            $table = StoreDataRegistry::get($name);
            $query = $table ? $scope->query($db, $table) : null;
            if ($query === null) {
                continue;
            }
            $strip = clone $table;
            $strip->files = array_intersect_key($table->files, $columns);
            $strip->media = array_intersect_key($table->media, $columns + array_combine(array_map(fn ($c) => $c.'[]', array_keys($columns)), $columns));
            foreach ((clone $query)->get() as $row) {
                $eraser->collect($strip, (array) $row, $files, $media);
            }
            $present = array_filter($columns, fn ($v, $c) => Schema::hasColumn($name, $c), ARRAY_FILTER_USE_BOTH);
            if ($present !== []) {
                $query->update($present);
            }
        }
    }

    /**
     * @param  list<string>  $names
     * @return array<string, int>
     */
    private function scopeCounts(Connection $db, StoreDataScope $scope, array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $table = StoreDataRegistry::get($name);
            $q = $table ? $scope->query($db, $table) : null;
            $out[$name] = $q === null ? 0 : $q->count();
        }

        return $out;
    }

    /** The retained sales records: the store's customers' personal data stripped, catalogue rows soft-deleted. */
    private function anonymizeStoreRecords(Store $store): void
    {
        $sid = (int) $store->id;
        $orderPii = array_filter(self::ORDER_PII, fn ($v, $col) => Schema::hasColumn('orders', $col), ARRAY_FILTER_USE_BOTH);
        if ($orderPii) {
            DB::table('orders')->where('store_id', $sid)->update($orderPii);
        }
        if (Schema::hasTable('check_payments') && Schema::hasColumn('check_payments', 'payer_name')) {
            DB::table('check_payments')->where('store_id', $sid)->update(['payer_name' => null]);
        }
        if (Schema::hasColumn('items', 'deleted_at')) {
            DB::table('items')->where('store_id', $sid)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        }
    }

    /** The store row stays (orders reference it) — anonymised and unreachable. */
    private function anonymizeStore(Store $store): void
    {
        $sid = (int) $store->id;
        $blank = [];
        foreach (['sub_title', 'description', 'timing', 'address', 'phone', 'head_code', 'facebook_pixel', 'google_tag', 'css_code',
            'js_code', 'footer_code', 'website', 'bio_title', 'bio_description', 'bio_html', 'bio_published_html', 'bio_design_json',
            'main_image', 'cover_image', 'bio_main_image', 'bio_cover_image', 'mk_lat', 'mk_lng'] as $col) {
            if (Schema::hasColumn('stores', $col)) {
                // Several of these are NOT NULL: blank strings; coordinates and the json design are null.
                $blank[$col] = in_array($col, ['mk_lat', 'mk_lng', 'bio_design_json'], true) ? null : '';
            }
        }
        foreach (range(1, 10) as $i) {
            foreach (["link_label{$i}", "link{$i}"] as $col) {
                if (Schema::hasColumn('stores', $col)) {
                    $blank[$col] = '';
                }
            }
        }
        DB::table('stores')->where('id', $sid)->update($blank + [
            'title' => 'Deleted store',
            'slug' => 'deleted-'.$sid.'-'.Str::lower(Str::random(8)),
            'mk_enabled' => 0,
            'catalog_visible' => 0,
            'updated_at' => now(),
        ]);
        StoreSettings::forgetAll($sid);
    }

    private function removeStoreFiles(Store $store): void
    {
        $sid = (int) $store->id;
        $unlink = function (string $dir, ?string $file) use ($sid) {
            $file = trim((string) $file);
            if ($file === '' || $file === 'default.png') {
                return;
            }
            try {
                remove_file($dir, $file, $sid);
            } catch (\Throwable $e) {
                Log::warning('[account-deletion] file remove failed', ['store' => $sid, 'file' => $file]);
            }
        };

        $unlink('storage/store/logo/', $store->main_image);
        $unlink('storage/store/cover/', $store->cover_image);
        foreach (['bio_main_image', 'bio_cover_image'] as $col) {
            $unlink('storage/bio/', $store->getAttribute($col));
        }
    }

    /** Also used by the admin user delete (P6-246) for an account that has paid records. */
    public function anonymizeUser(User $user): void
    {
        $uid = (int) $user->id;
        $user->forceFill([
            'name' => 'Deleted user',
            'username' => 'deleted_'.$uid.'_'.Str::lower(Str::random(6)),
            'email' => 'deleted+'.$uid.'.'.Str::lower(Str::random(8)).'@deleted.invalid',
            'password' => bcrypt(Str::random(64)),
            'image' => 'default.png', // NOT NULL column
            'country_code' => null,
            'country' => '',
            'oauth_provider' => null,
            'oauth_uid' => null,
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'status' => 0,
        ])->save();

        UserSettings::forgetAll((int) $uid);
        self::detachTrialClaims($uid);
        if (Schema::hasTable('transaction') && Schema::hasColumn('transaction', 'transaction_ip')) {
            DB::table('transaction')->where('user_id', $uid)->update(['transaction_ip' => null]);
        }
        self::stampFormerUserRef($uid);
    }

    /** P6-246: anonymised reference to the account on its payments, kept if the user row ever goes (FK sets NULL). */
    public static function stampFormerUserRef(int $userId): void
    {
        if (Schema::hasColumn('transaction', 'former_user_ref')) {
            DB::table('transaction')->where('user_id', $userId)->whereNull('former_user_ref')
                ->update(['former_user_ref' => hash_hmac('sha256', 'user:'.$userId, (string) config('app.key'))]);
        }
    }

    /**
     * P6-276: trial-abuse claims (billing edition) keep their keyed fingerprint (legitimate interest: trial abuse
     * prevention, RETENTION-MATRIX.md) but lose the link to the erased person.
     */
    public static function detachTrialClaims(int $userId): void
    {
        if (Schema::hasTable('trial_claims')) {
            DB::table('trial_claims')->where('user_id', $userId)->update(['user_id' => null]);
        }
    }

    // ── notices ──────────────────────────────────────────────────────────────

    private function notifyReseller(User $user, AccountDeletion $deletion): void
    {
        $this->attempt('reseller notice', function () use ($user, $deletion) {
            app(ResellerContext::class)->notifyCustomerDeleted($user, [
                'customer_id' => $user->id,
                'purge_after' => $deletion->purge_after->toIso8601String(),
            ]);
        });
    }

    private function mailOwner(User $user, AccountDeletion $deletion): void
    {
        $this->attempt('owner mail', function () use ($user, $deletion) {
            if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $date = $deletion->purge_after->translatedFormat('j F Y');
            Mail::to($user->email)->send(new AppDunningMail(___('Your account has been closed'), [
                ___('Your account and stores have been closed and your subscription has been cancelled.'),
                ___('Your data will be permanently deleted on :date. Invoices and order records required by tax law are kept without your customers’ personal details.', ['date' => $date]),
                ___('Changed your mind? Restore your account before :date with this link: :link', ['date' => $date, 'link' => $this->restoreUrl($deletion)]),
                // P1-23 (Terms 7.6, DPA 10.1): the 30-day export window — the tools after a restore, or on request.
                ___('You can still get a copy of your data until :date: restore your account and use Backup & export, or ask our support team to send it to you.', ['date' => $date]),
            ]));
        });
    }

    private function mailOwnerClosedByReseller(User $user, AccountDeletion $deletion): void
    {
        $this->attempt('owner mail (reseller)', function () use ($user, $deletion) {
            if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $brand = $user->reseller_id ? (string) (app(ResellerContext::class)->find((int) $user->reseller_id)?->getAttribute('brand_name') ?: '') : '';
            $date = $deletion->purge_after->translatedFormat('j F Y');
            Mail::to($user->email)->send(new AppDunningMail(___('Your account has been closed'), [
                ___('Your account and stores were closed by your provider :brand.', ['brand' => $brand]),
                ___('Your data will be permanently deleted on :date. Invoices and order records required by tax law are kept without your customers’ personal details.', ['date' => $date]),
                ___('If this is a mistake, contact your provider before :date.', ['date' => $date]),
            ]));
        });
    }

    private function attempt(string $step, callable $fn, bool $report = false): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::error('[account-deletion] step failed: '.$step, ['error' => $e->getMessage()]);
            if ($report) {
                report($e);
            }
        }
    }

    /**
     * Owner decision 2026-10-07: delete the security logs the purge kept (sign-ins + security events) of every account
     * purged more than SECURITY_LOG_DAYS ago, once (`security_logs_pruned_at` in the purge report). Only purged
     * accounts: a live account's logs are never touched.
     *
     * @return array<int, int> deletion id => rows deleted
     */
    public function pruneSecurityLogs(int $limit = 50): array
    {
        $done = [];
        $due = AccountDeletion::where('status', AccountDeletion::PURGED)
            ->where('purged_at', '<=', now()->subDays(self::SECURITY_LOG_DAYS))
            ->orderBy('purged_at')
            ->get()
            ->filter(fn (AccountDeletion $d) => empty($d->purge_report['security_logs_pruned_at']))
            ->take(max(1, $limit));

        foreach ($due as $deletion) {
            $done[$deletion->id] = TenantContext::bypass(function () use ($deletion): int {
                $db = DB::connection();
                $user = User::find($deletion->user_id);
                $accountId = (int) ($user?->account_id ?: 0);
                $n = 0;
                $db->transaction(function () use ($db, $deletion, $accountId, &$n): void {
                    if ($accountId !== 0) {
                        $scope = StoreDataScope::account($accountId);
                        foreach ([...self::SECURITY_LOG_TABLES, 'activity_logs'] as $name) {
                            $table = StoreDataRegistry::get($name);
                            $query = $table !== null && Schema::hasTable($name) ? $scope->query($db, $table) : null;
                            if ($query !== null) {
                                $n += ($name === 'activity_logs' ? self::securityEvents($query, true) : $query)->delete();
                            }
                        }
                    } else {
                        foreach (self::SECURITY_LOG_TABLES as $name) {
                            $n += Schema::hasTable($name) ? $db->table($name)->where('user_id', $deletion->user_id)->delete() : 0;
                        }
                        $n += Schema::hasTable('activity_logs')
                            ? self::securityEvents($db->table('activity_logs')->where('actor_user_id', $deletion->user_id), true)->delete() : 0;
                    }
                    $deletion->forceFill(['purge_report' => ['security_logs_pruned_at' => now()->toIso8601String(), 'security_logs_deleted' => $n]
                        + (array) ($deletion->purge_report ?? [])])->save();
                });

                return $n;
            });
        }

        return $done;
    }
}
