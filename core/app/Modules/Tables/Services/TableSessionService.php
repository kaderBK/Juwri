<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Services;

use App\Models\Order;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\FeatureAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Juwri\Core\Services\AbuseGuard;
use Juwri\Modules\Tables\Models\TableSession;

/**
 * PARITY-RUN T3 — verified table QR ("anti-fake-order").
 *
 * A per-store DINE-IN verification mode. Everything a caller needs to know:
 *
 *   off            today's behaviour, byte for byte. The default, and what an
 *                  unentitled / kill-switched store always sees.
 *   table_code     each table carries a rotating 4-digit code (printed on the
 *                  tent card). The first order of a scan session must enter it;
 *                  the session is then trusted for the rest of the service day.
 *   sms_otp        the guest proves a phone number through the EXISTING
 *                  SmsOtpService plumbing (BYOK provider per store) before the
 *                  first dine-in order.
 *   staff_approve  no guest-side proof at all: the order lands `pending_approval`
 *                  and a human approves or rejects it before it enters the KOT
 *                  flow. See Order::STATUS_PENDING_APPROVAL.
 *
 * GATE RULE (this codebase has been bitten three times): the storefront
 * order-submit route is reached by ANONYMOUS customers, so it may never carry
 * `feature:`/`entitled:` middleware. The entitlement question is asked here,
 * store-scoped, via FeatureAccess::storeAllows() — and a refusal degrades to
 * `off`, i.e. the pre-T3 behaviour, never to a locked-out diner.
 */
class TableSessionService
{
    public const MODE_OFF = 'off';

    public const MODE_TABLE_CODE = 'table_code';

    public const MODE_SMS_OTP = 'sms_otp';

    public const MODE_STAFF_APPROVE = 'staff_approve';

    /** The post_option that stores the owner's choice. */
    public const OPTION_MODE = 'table_verify_mode';

    /** Feature key (kill-switch default OFF — opt-in per store). */
    public const FEATURE = 'table_otp';

    /** Cookie holding the opaque session token. Encrypted by EncryptCookies. */
    public const COOKIE = 'mb_table_session';

    /** Wrong-code budget before the guest is told to ask a staff member. */
    public const MAX_ATTEMPTS = 5;

    public const ATTEMPT_DECAY = 900; // 15 minutes

    /** How long a verified session stays good (a long service day). */
    public const SESSION_HOURS = 12;

    /** @return array<int, string> */
    public static function modes(): array
    {
        return [self::MODE_OFF, self::MODE_TABLE_CODE, self::MODE_SMS_OTP, self::MODE_STAFF_APPROVE];
    }

    /**
     * What the OWNER picked, ignoring entitlement. Only the settings screen
     * should use this — every enforcement path wants {@see mode()}.
     */
    public static function rawMode($storeId): string
    {
        $rid = self::rid($storeId);
        if (! $rid) {
            return self::MODE_OFF;
        }

        $mode = (string) store_options($rid, self::OPTION_MODE);

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_OFF;
    }

    /**
     * The EFFECTIVE mode. Falls back to `off` whenever the platform kill-switch
     * is down or the store's plan does not include the key — a lapsed store
     * keeps taking orders, it just stops verifying.
     */
    public static function mode($storeId): string
    {
        $rid = self::rid($storeId);
        if (! $rid) {
            return self::MODE_OFF;
        }

        $mode = self::rawMode($rid);
        if ($mode === self::MODE_OFF) {
            return self::MODE_OFF;
        }

        return FeatureAccess::storeAllows($rid, self::FEATURE) ? $mode : self::MODE_OFF;
    }

    /** Is this store gating dine-in at all? */
    public static function enabled($storeId): bool
    {
        return self::mode($storeId) !== self::MODE_OFF;
    }

    /** Modes where the GUEST must prove something before the order is accepted. */
    public static function requiresGuestProof(string $mode): bool
    {
        return in_array($mode, [self::MODE_TABLE_CODE, self::MODE_SMS_OTP], true);
    }

    /** Persist the owner's choice. Returns the stored (validated) mode. */
    public static function setMode(int $storeId, string $mode): string
    {
        $mode = in_array($mode, self::modes(), true) ? $mode : self::MODE_OFF;
        StoreOption::updateStoreOption($storeId, self::OPTION_MODE, $mode);

        return $mode;
    }

    /* ===================================================================== */
    /* Table codes */
    /* ===================================================================== */

    /**
     * The table's current code, generating one on first read so an owner who
     * flips the mode on never faces a grid of blanks.
     */
    public static function codeFor(StoreTable $table, bool $generate = true): ?string
    {
        $code = trim((string) ($table->verify_code ?? ''));
        if ($code !== '') {
            return $code;
        }

        return $generate ? self::rotateCode($table) : null;
    }

    /** Issue a fresh 4-digit code for one table. */
    public static function rotateCode(StoreTable $table): string
    {
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        $table->verify_code = $code;
        $table->verify_code_rotated_at = now();
        $table->save();

        // Any session proven with the OLD code dies with it.
        self::invalidateSessionsForTable($table);

        return $code;
    }

    /** Rotate every table of a store (optionally one branch). Returns the count. */
    public static function rotateAll(int $storeId, ?int $branchId = null): int
    {
        $query = StoreTable::where('store_id', $storeId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $n = 0;
        foreach ($query->get() as $table) {
            self::rotateCode($table);
            $n++;
        }

        return $n;
    }

    /**
     * CROSS-TRACK SEAM for T10 (QR designer). Rotating a table's QR should also
     * rotate the code printed beside it, otherwise a tent card reprinted with a
     * new QR still carries the old number. T10 is being built in parallel, so
     * this is written null-safe and inert: an unknown key, a store with the
     * feature off, or a missing column all return null and change nothing.
     *
     * T10 call site (when it lands):
     *   \Juwri\Modules\Tables\Services\TableSessionService::onTableQrRotated($storeId, $tableKey);
     */
    public static function onTableQrRotated($storeId, ?string $tableKey): ?string
    {
        try {
            $rid = self::rid($storeId);
            if (! $rid || $tableKey === null || $tableKey === '') {
                return null;
            }

            $table = StoreTable::where('store_id', $rid)->where('key', $tableKey)->first();
            if (! $table) {
                return null;
            }

            return self::rotateCode($table);
        } catch (\Throwable $e) {
            // A QR rotation must never fail because of the verification layer.
            return null;
        }
    }

    /* ===================================================================== */
    /* Table resolution */
    /* ===================================================================== */

    /**
     * Resolve a table from either identifier. The QR carries `key`; the order
     * payload (and a guest typing it in) carries `table_no`. Rooms
     * (area_type='room', the hotel module) are deliberately excluded — room
     * service has its own gate and is not "dine-in" for this feature.
     */
    public static function resolveTable(int $storeId, ?string $tableKey, ?string $tableNo = null): ?StoreTable
    {
        $query = StoreTable::where('store_id', $storeId)
            ->where(function ($q) {
                $q->whereNull('area_type')->orWhere('area_type', '!=', 'room');
            });

        if ($tableKey !== null && $tableKey !== '') {
            $row = (clone $query)->where('key', $tableKey)->first();
            if ($row) {
                return $row;
            }
        }

        if ($tableNo !== null && $tableNo !== '' && is_numeric($tableNo)) { // integer column
            return (clone $query)->where('table_no', $tableNo)->first();
        }

        return null;
    }

    /* ===================================================================== */
    /* Sessions */
    /* ===================================================================== */

    /** The session this request carries, if it belongs to this store and is alive. */
    public static function current(Request $request, int $storeId): ?TableSession
    {
        $token = (string) $request->cookie(self::COOKIE, '');
        if ($token === '') {
            return null;
        }

        $session = TableSession::where('token', $token)
            ->where('store_id', $storeId)
            ->first();

        if (! $session) {
            return null;
        }

        // A session belongs to a service DAY: yesterday's cookie is dead.
        if ($session->expires_at !== null && $session->expires_at->isPast()) {
            return null;
        }

        return $session;
    }

    /**
     * Is this request allowed to place a dine-in order for this table?
     * `staff_approve` never grants a guest pass — it lets the order through and
     * parks it for a human instead (see the middleware).
     */
    public static function isVerifiedFor(Request $request, int $storeId, ?string $tableKey, ?string $tableNo = null): bool
    {
        $session = self::current($request, $storeId);

        return $session !== null
            && $session->isUsable()
            && $session->coversTable($tableKey, $tableNo);
    }

    /**
     * Find or create the session row for this table + day. Never verified on
     * creation — verification is an explicit act.
     */
    public static function startSession(Request $request, int $storeId, StoreTable $table, string $method): TableSession
    {
        $existing = self::current($request, $storeId);
        if ($existing && $existing->coversTable((string) $table->key, (string) $table->table_no)) {
            return $existing;
        }

        return TableSession::create([
            'store_id' => $storeId,
            'table_key' => (string) $table->key,
            'table_no' => (string) $table->table_no,
            'table_id' => (int) $table->id,
            'branch_id' => $table->branch_id ? (int) $table->branch_id : null,
            'token' => (string) Str::random(48),
            'method' => $method,
            'day' => now()->toDateString(),
            'expires_at' => now()->addHours(self::SESSION_HOURS),
            'ip' => $request->ip(),
        ]);
    }

    /** Stamp a session verified and hand back the cookie the caller must queue. */
    public static function markVerified(TableSession $session, ?string $phone = null): TableSession
    {
        $session->verified_at = now();
        if ($phone !== null && $phone !== '') {
            $session->phone = mb_substr($phone, 0, 32);
        }
        if ($session->expires_at === null) {
            $session->expires_at = now()->addHours(self::SESSION_HOURS);
        }
        $session->save();

        return $session;
    }

    /** The cookie carrying a session token. httpOnly — no script ever needs it. */
    public static function cookie(TableSession $session): \Symfony\Component\HttpFoundation\Cookie
    {
        $minutes = max(1, (int) Carbon::now()->diffInMinutes($session->expires_at ?: now()->addHours(self::SESSION_HOURS)));

        return Cookie::make(self::COOKIE, (string) $session->token, $minutes, null, null, null, true, false, 'lax');
    }

    /** Drop every session proven against a code that has just been rotated. */
    public static function invalidateSessionsForTable(StoreTable $table): void
    {
        try {
            TableSession::where('store_id', $table->store_id)
                ->where('table_key', (string) $table->key)
                ->where('method', self::MODE_TABLE_CODE)
                ->delete();
        } catch (\Throwable $e) {
            // best-effort — never break a rotation
        }
    }

    /* ===================================================================== */
    /* Verification + throttling */
    /* ===================================================================== */

    /*
     * P6-270: three wrong-code budgets, all checked on every attempt.
     *   ip     per table + client IP (the original budget);
     *   device per table + device fingerprint (UA + Accept-Language), so one
     *          phone behind a rotating mobile IP keeps one budget;
     *   table  per table across EVERY IP/device. When it runs out the table is
     *          locked for ATTEMPT_DECAY and its code is rotated, so a guesser
     *          spreading 4-digit tries over many IPs from home gets nowhere.
     * The table part of every key is the table's id when it resolves (so
     * alternating key vs number cannot buy a second budget), else the raw ref.
     */
    public const MAX_TABLE_ATTEMPTS = 15;

    public static function throttleKey(int $storeId, ?string $tableRef, ?string $ip): string
    {
        return 'table-verify:'.$storeId.':'.sha1(self::budgetRef($storeId, $tableRef).'|'.(string) $ip);
    }

    public static function deviceThrottleKey(int $storeId, ?string $tableRef, Request $request): string
    {
        $device = (string) $request->userAgent().'|'.(string) $request->header('Accept-Language');

        return 'table-verify-dev:'.$storeId.':'.sha1(self::budgetRef($storeId, $tableRef).'|'.$device);
    }

    public static function tableThrottleKey(int $storeId, ?string $tableRef): string
    {
        return 'table-verify-all:'.$storeId.':'.sha1(self::budgetRef($storeId, $tableRef));
    }

    /** @return array<string, int> key => max attempts */
    private static function budgets(int $storeId, ?string $tableRef, Request $request): array
    {
        return [
            self::throttleKey($storeId, $tableRef, $request->ip()) => self::MAX_ATTEMPTS,
            self::deviceThrottleKey($storeId, $tableRef, $request) => self::MAX_ATTEMPTS,
            self::tableThrottleKey($storeId, $tableRef) => self::MAX_TABLE_ATTEMPTS,
        ];
    }

    public static function tooManyAttempts(int $storeId, ?string $tableRef, Request $request): bool
    {
        foreach (self::budgets($storeId, $tableRef, $request) as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return true;
            }
        }

        return false;
    }

    public static function secondsUntilRetry(int $storeId, ?string $tableRef, Request $request): int
    {
        $secs = 0;
        foreach (self::budgets($storeId, $tableRef, $request) as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $secs = max($secs, (int) RateLimiter::availableIn($key));
            }
        }

        return $secs;
    }

    /**
     * Record a failed attempt against every budget + an AbuseGuard evidence row
     * (the guard ships warn-only; the budgets are what stop a guesser). The
     * attempt that exhausts the per-table budget rotates the table's code.
     */
    public static function recordFailure(int $storeId, ?string $tableRef, Request $request, string $reason): void
    {
        foreach (array_keys(self::budgets($storeId, $tableRef, $request)) as $key) {
            RateLimiter::hit($key, self::ATTEMPT_DECAY);
        }

        $tableKey = self::tableThrottleKey($storeId, $tableRef);
        if (RateLimiter::attempts($tableKey) === self::MAX_TABLE_ATTEMPTS) {
            try {
                $table = self::resolveTable($storeId, $tableRef, $tableRef);
                if ($table && $table->verify_code) {
                    self::rotateCode($table);
                }
            } catch (\Throwable $e) {
                // the lock itself already holds; rotation is the bonus
            }
        }

        try {
            AbuseGuard::hit('table_verify', $reason, [
                'ip' => $request->ip(),
                'meta' => [
                    'store_id' => $storeId,
                    'table' => $tableRef,
                    'reason' => $reason,
                ],
            ]);
        } catch (\Throwable $e) {
            // evidence logging must never break a diner's request
        }
    }

    /** A success clears this guest's own budgets, never the table-wide one. */
    public static function clearFailures(int $storeId, ?string $tableRef, Request $request): void
    {
        RateLimiter::clear(self::throttleKey($storeId, $tableRef, $request->ip()));
        RateLimiter::clear(self::deviceThrottleKey($storeId, $tableRef, $request));
    }

    private static function budgetRef(int $storeId, ?string $tableRef): string
    {
        try {
            $table = self::resolveTable($storeId, $tableRef, $tableRef);
        } catch (\Throwable $e) {
            $table = null;
        }

        return $table ? 'id:'.$table->id : 'ref:'.(string) $tableRef;
    }

    /* ===================================================================== */
    /* Ending sessions (P6-270) */
    /* ===================================================================== */

    /** Table statuses that mean the party has gone. */
    public const CLEARED_STATUSES = ['free', 'dirty', 'cleaning'];

    /**
     * End every session (any method) proven at this table: the party has paid
     * or left, so their cookie must stop ordering onto the next party's bill.
     */
    public static function endSessionsForTable(StoreTable $table): int
    {
        try {
            return TableSession::where('store_id', $table->store_id)
                ->where(function ($q) use ($table) {
                    $q->where('table_id', $table->id)->orWhere('table_key', (string) $table->key);
                })
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now()->subSecond());
                })
                ->update(['expires_at' => now()->subSecond()]);
        } catch (\Throwable $e) {
            return 0; // never break a table/order transition
        }
    }

    /**
     * Order hook: a dine-in bill on a table was paid or closed. When nothing on
     * that table is still open, the table's sessions end.
     */
    public static function onTableOrderClosed(Order $order): void
    {
        if (! $order->store_id || $order->table_number === null) {
            return;
        }

        $table = self::resolveTable((int) $order->store_id, null, (string) $order->table_number);
        if (! $table) {
            return;
        }

        $open = Order::where('store_id', $order->store_id)
            ->where('table_number', $order->table_number)
            ->where('id', '!=', $order->id)
            ->where('created_at', '>=', now()->subHours(self::SESSION_HOURS))
            ->where(function ($q) {
                $q->whereNull('is_paid')->orWhere('is_paid', false);
            })
            ->whereNotIn('status', ['completed', 'cancelled', 'delivered'])
            ->exists();

        if (! $open) {
            self::endSessionsForTable($table);
        }
    }

    /**
     * Constant-time compare of a submitted code against the table's current one.
     * A table with no code yet can never be satisfied by an empty submission.
     */
    public static function codeMatches(StoreTable $table, ?string $submitted): bool
    {
        $expected = trim((string) ($table->verify_code ?? ''));
        $given = trim((string) $submitted);

        if ($expected === '' || $given === '') {
            return false;
        }

        return hash_equals($expected, $given);
    }

    private static function rid($storeId): ?int
    {
        if (is_object($storeId)) {
            $storeId = $storeId->id ?? null;
        }

        return $storeId ? (int) $storeId : null;
    }
}
