<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Tables\Services\TableSessionService;

/**
 * PARITY-RUN T3 — the guest side of verified table QR.
 *
 * Public by design: the caller is an anonymous diner holding a phone. There is
 * deliberately NO `feature:`/`entitled:` middleware on these routes (it would
 * 403 every customer); the entitlement question is asked store-scoped inside
 * TableSessionService::mode(), and a store whose mode resolves to `off` gets a
 * flat "nothing to verify" answer rather than a gate it cannot pass.
 *
 * `sms_otp` is thin wiring over the EXISTING SmsOtpService — the same store used
 * by customer phone login and the F7 checkout gate. No second OTP store.
 *
 * Every response works twice: JSON for the storefront modal, and a redirect
 * back for the no-JS <form> fallback in the shared partial.
 */
class TableVerifyController extends Controller
{
    public function __construct(
        protected SmsOtp $otp,
        protected Sms $sms,
    ) {}

    /**
     * POST {store}/table-verify/code — prove the 4-digit code printed on
     * the table tent card.
     */
    public function verifyCode(Request $request, Store $store)
    {
        $mode = TableSessionService::mode($store->id);
        if ($mode !== TableSessionService::MODE_TABLE_CODE) {
            return $this->answer($request, $store, false, ___('This table does not need a code right now.'));
        }

        $validator = Validator::make($request->all(), [
            'table' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:8'],
        ]);
        if ($validator->fails()) {
            return $this->answer($request, $store, false, $validator->errors()->first());
        }

        $ref = (string) $request->input('table');

        if (TableSessionService::tooManyAttempts($store->id, $ref, $request)) {
            $secs = TableSessionService::secondsUntilRetry($store->id, $ref, $request);

            return $this->answer(
                $request,
                $store,
                false,
                ___('Too many wrong codes. Please ask a member of staff.'),
                ['retry_after' => $secs],
                429
            );
        }

        $table = TableSessionService::resolveTable($store->id, $ref, $ref);
        if (! $table) {
            TableSessionService::recordFailure($store->id, $ref, $request, 'unknown_table');

            return $this->answer($request, $store, false, ___('We could not find that table.'));
        }

        if (! TableSessionService::codeMatches($table, (string) $request->input('code'))) {
            TableSessionService::recordFailure($store->id, $ref, $request, 'bad_table_code');

            return $this->answer($request, $store, false, ___('That code is not correct. Check the card on your table.'));
        }

        $session = TableSessionService::startSession($request, (int) $store->id, $table, TableSessionService::MODE_TABLE_CODE);
        TableSessionService::markVerified($session);
        TableSessionService::clearFailures($store->id, $ref, $request);

        return $this->answer(
            $request,
            $store,
            true,
            ___('Table verified — enjoy your meal.'),
            ['table' => (string) $table->table_no, 'table_key' => (string) $table->key],
            200,
            $session
        );
    }

    /** POST {store}/table-verify/otp/send — send the guest an SMS code. */
    public function sendOtp(Request $request, Store $store)
    {
        if (TableSessionService::mode($store->id) !== TableSessionService::MODE_SMS_OTP) {
            return $this->answer($request, $store, false, ___('This table does not need a code right now.'));
        }

        $validator = Validator::make($request->all(), [
            'table' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
        ]);
        if ($validator->fails()) {
            return $this->answer($request, $store, false, $validator->errors()->first());
        }

        // Resend cooldown, per-phone rate-limit and attempt cap all live inside
        // SmsOtpService — do not re-implement them here.
        // SmsOtpService returns its own already-worded message (it interpolates a
        // retry countdown), so it is passed through verbatim — exactly like
        // CheckoutOtpController does. Running it through ___() would auto-register
        // a garbage key per countdown value.
        [$ok, $message] = $this->otp->request((string) $request->input('phone'), OtpScope::store((int) $store->id));

        return $this->answer($request, $store, $ok, $message, [], $ok ? 200 : 429);
    }

    /** POST {store}/table-verify/otp/verify — confirm the SMS code. */
    public function verifyOtp(Request $request, Store $store)
    {
        if (TableSessionService::mode($store->id) !== TableSessionService::MODE_SMS_OTP) {
            return $this->answer($request, $store, false, ___('This table does not need a code right now.'));
        }

        $validator = Validator::make($request->all(), [
            'table' => ['required', 'string', 'max:64'],
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:12'],
        ]);
        if ($validator->fails()) {
            return $this->answer($request, $store, false, $validator->errors()->first());
        }

        $ref = (string) $request->input('table');

        if (TableSessionService::tooManyAttempts($store->id, $ref, $request)) {
            $secs = TableSessionService::secondsUntilRetry($store->id, $ref, $request);

            return $this->answer(
                $request,
                $store,
                false,
                ___('Too many wrong codes. Please ask a member of staff.'),
                ['retry_after' => $secs],
                429
            );
        }

        $table = TableSessionService::resolveTable($store->id, $ref, $ref);
        if (! $table) {
            TableSessionService::recordFailure($store->id, $ref, $request, 'unknown_table');

            return $this->answer($request, $store, false, ___('We could not find that table.'));
        }

        if (! $this->otp->verify((string) $request->input('phone'), (string) $request->input('code'), OtpScope::store((int) $store->id))) {
            TableSessionService::recordFailure($store->id, $ref, $request, 'bad_table_otp');

            return $this->answer($request, $store, false, ___('That code is invalid or has expired.'));
        }

        $phone = $this->sms->normalizePhone((string) $request->input('phone'));
        $session = TableSessionService::startSession($request, (int) $store->id, $table, TableSessionService::MODE_SMS_OTP);
        TableSessionService::markVerified($session, $phone);
        TableSessionService::clearFailures($store->id, $ref, $request);

        return $this->answer(
            $request,
            $store,
            true,
            ___('Table verified — enjoy your meal.'),
            ['table' => (string) $table->table_no, 'table_key' => (string) $table->key],
            200,
            $session
        );
    }

    /**
     * One answer shape for both transports. When a session was just verified the
     * response carries its cookie — that opaque token is the whole credential,
     * and it is httpOnly because nothing in the page ever needs to read it.
     */
    private function answer(
        Request $request,
        Store $store,
        bool $success,
        string $message,
        array $extra = [],
        int $status = 200,
        $session = null
    ) {
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            $response = response()->json(array_merge([
                'success' => $success,
                'message' => $message,
            ], $extra), $success ? 200 : $status);
        } else {
            $back = redirect()->to(
                route('publicView', $store->slug)
                .(($extra['table_key'] ?? '') !== '' ? '?table='.urlencode((string) $extra['table_key']) : '')
            );
            $response = $success
                ? $back->with('table_verify_success', $message)
                : $back->with('table_verify_error', $message);
        }

        if ($session) {
            $response = $response->withCookie(TableSessionService::cookie($session));
        }

        return $response;
    }
}
