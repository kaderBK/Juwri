<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Middleware\StoreDailyCap;
use Juwri\Core\Services\AbuseGuard;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;

/**
 * theme-pack-blade · F7 — OTP checkout gate.
 *
 * Thin wiring over the EXISTING SmsOtpService (no new OTP store). Verifies a
 * customer's phone right before order placement when a restaurant has the
 * `require_phone_verification` post_option toggled on. On success we mark the
 * authenticated customer (customers.phone_verified_at) and always drop a
 * session marker for the verified phone (covers guests + phone mismatches).
 *
 * Rate-limit, resend cooldown and attempt cap all live inside SmsOtpService.
 *
 * Per-store scoping (security): the route carries no store, so the store a code
 * was SENT for (body `store_id`, accepted only when that store has the gate on;
 * none = the platform account) is remembered in the session, and verify checks
 * the code against that scope only — never a store named at verify time. The
 * verified marker is per store too: a phone proved for store A does not pass
 * store B's gate or order lookup. A platform-sent code (no store; the platform
 * SMS account, which no store can read) counts for every store.
 */
class CheckoutOtpController extends Controller
{
    /** store_options flag key that turns the checkout gate on. */
    public const FLAG = 'require_phone_verification';

    /** Session bucket of normalized phones this session has verified. */
    public const SESSION_KEY = 'otp_verified_phones';

    /** Session bucket: normalized phone => [store id (0 = platform) => true] the codes were sent for. */
    public const PENDING_KEY = 'otp_pending_scopes';

    /** Store id recorded for a code sent without a store (platform SMS account). */
    public const PLATFORM = 0;

    public function __construct(
        protected SmsOtp $otp,
        protected Sms $sms,
    ) {}

    /**
     * POST /checkout/otp/send — send an OTP to the given phone.
     * Rate-limit + resend cooldown are enforced inside SmsOtpService::request().
     */
    public function send(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'max:32'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        /* The store id arrives in the body of an ANONYMOUS request, and the SMS
         * it triggers is billed to that store. Taking it on trust let anyone
         * drain any store's SMS credit by posting its id in a loop — the store
         * did not have to be involved in the checkout at all, or even to use
         * phone verification.
         *
         * Only a store that has actually switched the checkout OTP gate on can
         * be charged for one. Those stores are already sending these messages
         * for real checkouts, and SmsOtpService::request() applies the
         * per-phone rate limit and resend cooldown on top. An unknown or
         * non-participating id is refused rather than silently falling back to
         * the platform account. */
        $storeId = (int) $request->get('store_id') ?: null;
        if ($storeId !== null && ! self::gateEnabled($storeId)) {
            return response()->json([
                'success' => false,
                'message' => ___('Phone verification is not enabled for this store.'),
            ], 422);
        }

        $scopeId = $storeId ?? self::PLATFORM;

        /* P6-261: the daily SMS cap is counted against THIS store (validated above), not a shared route bucket —
         * exhausting store A never stops store B. No-store sends use their own small platform bucket; one phone gets
         * a daily ceiling across all stores (anti SMS-bombing); a global ceiling backs the whole platform. */
        $phoneKey = $this->sms->normalizePhone((string) $request->get('phone'));
        $phoneKey = $phoneKey !== '' ? $phoneKey : 'raw:'.mb_substr((string) $request->get('phone'), 0, 32);
        $denied = app(StoreDailyCap::class)->check(
            $request,
            $storeId !== null ? 'sms' : 'sms_platform',
            $scopeId,
            ['phone' => [hash('sha256', $phoneKey), (int) AbuseGuard::value(StoreDailyCap::GUARD, 'checkout_otp_phone_daily', (int) config('abuse.checkout_otp.phone_daily', 0))]],
        );
        if ($denied !== null) {
            return $denied;
        }
        [$ok, $message] = $this->otp->request((string) $request->get('phone'), self::scope($scopeId));

        $phone = $this->sms->normalizePhone((string) $request->get('phone'));
        if ($ok && $phone !== '') {
            $pending = (array) $request->session()->get(self::PENDING_KEY, []);
            $scopes = is_array($pending[$phone] ?? null) ? $pending[$phone] : [];
            $scopes[$scopeId] = true;
            $pending[$phone] = array_slice($scopes, -5, null, true);
            $request->session()->put(self::PENDING_KEY, $pending);
        }

        return response()->json([
            'success' => $ok,
            'message' => $message,
        ], $ok ? 200 : 429);
    }

    /**
     * POST /checkout/otp/verify — verify the submitted code and record the
     * verified phone so the place-order gate lets the order through.
     */
    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:12'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $phoneRaw = (string) $request->get('phone');
        $phone = $this->sms->normalizePhone($phoneRaw);
        $code = (string) $request->get('code');

        // Only the scopes this session actually sent a code for (server side).
        $pending = (array) $request->session()->get(self::PENDING_KEY, []);
        $scopes = $phone !== '' && is_array($pending[$phone] ?? null) ? array_keys($pending[$phone]) : [];
        $provedFor = null;
        foreach ($scopes as $scopeId) {
            if ($this->otp->verify($phoneRaw, $code, self::scope((int) $scopeId))) {
                $provedFor = (int) $scopeId;
                break;
            }
        }

        if ($provedFor === null) {
            return response()->json([
                'success' => false,
                'message' => ___('That code is invalid or has expired.'),
            ], 422);
        }

        unset($pending[$phone][$provedFor]);
        if ($pending[$phone] === []) {
            unset($pending[$phone]);
        }
        $request->session()->put(self::PENDING_KEY, $pending);

        // Known customer of THAT store (or a platform-sent code): persist the
        // verification on the record. The customer guard is shared by every
        // store on a host, so a code for store A never stamps a store-B account.
        $customer = auth('customer')->user();
        if ($customer && ($provedFor === self::PLATFORM || (int) $customer->store_id === $provedFor)) {
            $customer->phone_verified_at = now();
            $customer->save();
        }

        // Always drop a session marker for this phone AND this store (guests +
        // verified-phone that differs from the account phone).
        $verified = (array) $request->session()->get(self::SESSION_KEY, []);
        $stores = is_array($verified[$phone] ?? null) ? $verified[$phone] : [];
        $stores[$provedFor] = now()->getTimestamp();
        $verified[$phone] = $stores;
        $request->session()->put(self::SESSION_KEY, $verified);

        return response()->json([
            'success' => true,
            'message' => ___('Your phone number has been verified.'),
        ]);
    }

    /**
     * Is this restaurant requiring phone verification for orders?
     */
    public static function gateEnabled($storeId): bool
    {
        return filter_var(
            store_options($storeId, self::FLAG),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /** The OTP scope of a checkout code: the store it was sent for, or the platform. */
    public static function scope(int $storeId): OtpScope
    {
        return $storeId > 0 ? OtpScope::store($storeId) : OtpScope::platform('checkout');
    }

    /**
     * Has the given (normalized) phone been verified for a checkout at this store?
     * True when the authenticated customer OF THIS STORE has that phone verified,
     * or the session holds a marker for that exact number at this store (or from
     * a platform-sent code).
     */
    public static function phoneIsVerified(string $normalizedPhone, int $storeId): bool
    {
        if ($normalizedPhone === '') {
            return false;
        }

        $customer = auth('customer')->user();
        if ($customer && $customer->phone_verified_at && (int) $customer->store_id === $storeId) {
            $customerPhone = app(Sms::class)->normalizePhone((string) $customer->phone);
            if ($customerPhone !== '' && $customerPhone === $normalizedPhone) {
                return true;
            }
        }

        // Per store: [phone => [store id => time]]. A marker from before the
        // scoping (phone => time) is not trusted — the diner verifies again.
        $verified = (array) session(self::SESSION_KEY, []);
        $stores = $verified[$normalizedPhone] ?? null;
        if (! is_array($stores)) {
            return false;
        }

        return ! empty($stores[$storeId]) || ! empty($stores[self::PLATFORM]);
    }
}
