<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Http\Controllers\Rwg;

use App\Models\WaitlistEntry;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Reservations\Services\Rwg\WaitEstimateService;
use Juwri\Modules\Reservations\Services\Rwg\WaitlistStateMap;

/**
 * google-partner Lane D (D3): Reserve-with-Google waitlist booking server
 * (Google → partner, REST). Methods: BatchGetWaitEstimates, CreateWaitlistEntry,
 * GetWaitlistEntry, DeleteWaitlistEntry.
 *
 * Auth is enforced IN-CONTROLLER (HTTP Basic + hash_equals against the C-owned
 * `services.reserve_with_google` creds + kill-switch) so the lane is runnable and
 * curl-testable standalone while Lane C's C2 RwgAuth middleware + `rwg/v3` prefix
 * are still pending. F0 folds these routes into C2's group and adds RwgAuth as
 * defense-in-depth (the in-controller check is idempotent with it).
 *
 * Idempotency: `waitlist_entries.rwg_entry_id` (unique) stores the value we return
 * as `waitlist_entry_id`, set equal to Google's `idempotency_token` — so a retry
 * with the same token resolves to the same row. NEVER reuses any bearer secret.
 */
class RwgWaitlistController extends Controller
{
    /** WaitlistBusinessLogicFailure causes we emit. */
    private const CAUSE_CLOSED = 'WAITLIST_CLOSED';

    private const CAUSE_BELOW_MIN = 'BELOW_MIN_PARTY_SIZE';

    private const CAUSE_ABOVE_MAX = 'ABOVE_MAX_PARTY_SIZE';

    private const DEFAULT_MAX_PARTY = 12;

    public function __construct(private WaitEstimateService $estimates) {}

    /** POST rwg/v3/BatchGetWaitEstimates */
    public function batchGetWaitEstimates(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeRwg($request)) {
            return $deny;
        }

        $storeId = $this->resolveRestaurantId((string) $request->input('merchant_id'));
        if (! $storeId) {
            return response()->json((object) [], 404);
        }

        // Consistency with createWaitlistEntry: a store that hasn't opted its RwG
        // waitlist in must not advertise live wait estimates.
        if (! store_options($storeId, 'rwg_waitlist_enabled')) {
            return response()->json((object) [], 404);
        }

        $partySizes = array_map('intval', (array) $request->input('party_size', []));

        return response()->json($this->estimates->batchEstimate($storeId, $partySizes));
    }

    /** POST rwg/v3/CreateWaitlistEntry (idempotent on idempotency_token). */
    public function createWaitlistEntry(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeRwg($request)) {
            return $deny;
        }

        $token = trim((string) $request->input('idempotency_token'));
        if ($token === '') {
            return response()->json(['error' => 'idempotency_token required'], 400);
        }
        // rwg_entry_id is VARCHAR(64): reject an over-length token instead of letting
        // the insert raise an uncaught data-too-long QueryException (500) / truncate.
        if (mb_strlen($token) > 64) {
            return response()->json(['error' => 'idempotency_token too long'], 400);
        }

        $storeId = $this->resolveRestaurantId((string) $request->input('merchant_id'));
        if (! $storeId) {
            return response()->json((object) [], 404);
        }

        // Idempotent retry: same token → same entry, no second row.
        $existing = WaitlistEntry::where('rwg_entry_id', $token)->first();
        if ($existing) {
            return response()->json(['waitlist_entry_id' => $existing->rwg_entry_id]);
        }

        if (! store_options($storeId, 'rwg_waitlist_enabled')) {
            return $this->businessFailure(self::CAUSE_CLOSED);
        }

        $partySize = (int) $request->input('party_size', 0);
        $maxParty = (int) store_options($storeId, 'waitlist_max_party', self::DEFAULT_MAX_PARTY);
        if ($maxParty < 1) {
            $maxParty = self::DEFAULT_MAX_PARTY;
        }
        if ($partySize < 1) {
            return $this->businessFailure(self::CAUSE_BELOW_MIN);
        }
        if ($partySize > $maxParty) {
            return $this->businessFailure(self::CAUSE_ABOVE_MAX);
        }

        $info = (array) $request->input('user_information', []);
        $name = trim(($info['given_name'] ?? '').' '.($info['family_name'] ?? ''));
        $name = $name !== '' ? mb_substr($name, 0, 120) : 'Guest';
        $phone = isset($info['telephone']) ? mb_substr((string) $info['telephone'], 0, 30) : null;

        // Quote BEFORE insert so the new party doesn't count toward its own wait
        // (mirrors WaitlistController::add()).
        $quoted = $this->estimates->quotedWaitMinutesFor($storeId);

        try {
            // Savepoint: on PostgreSQL a failed insert would abort an enclosing transaction.
            $entry = DB::transaction(fn () => WaitlistEntry::create([
                'store_id' => $storeId,
                'rwg_entry_id' => $token,
                'name' => $name,
                'phone' => $phone,
                'party_size' => $partySize,
                'status' => 'waiting',
                'source' => 'google',
                'quoted_wait' => $quoted,
            ]));
        } catch (QueryException $e) {
            // Lost a race on the unique rwg_entry_id — the winner's row is authoritative.
            $winner = WaitlistEntry::where('rwg_entry_id', $token)->first();
            if ($winner) {
                return response()->json(['waitlist_entry_id' => $winner->rwg_entry_id]);
            }
            throw $e;
        }

        // Best-effort join confirmation SMS (simulated without creds; opt-out
        // respected via marketing=true). State is authoritative regardless.
        if ($phone) {
            $placeTitle = (string) (Store::whereKey($storeId)->value('title') ?? '');
            $message = ___(":name, you're on the waitlist at :place — party of :n. Estimated wait: ~:mins min.", [
                'name' => $name,
                'place' => $placeTitle,
                'n' => $partySize,
                'mins' => $quoted,
            ]);
            try {
                app(Sms::class)->send($phone, $message, $storeId, 'waitlist', true);
            } catch (\Throwable $e) {
                // best-effort only
            }
        }

        log_activity('waitlist.rwg_create', $entry, ['source' => 'google'], $storeId);

        return response()->json(['waitlist_entry_id' => $entry->rwg_entry_id]);
    }

    /** POST rwg/v3/GetWaitlistEntry (retrievable ≥30 days — we never delete). */
    public function getWaitlistEntry(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeRwg($request)) {
            return $deny;
        }

        $entry = $this->findByEntryId($request);
        if (! $entry) {
            return response()->json((object) [], 404);
        }

        return response()->json(['waitlist_entry' => [
            'waitlist_entry_state' => WaitlistStateMap::toGoogle((string) $entry->status),
            'waitlist_entry_state_times' => [
                'created_time_seconds' => (int) $entry->created_at?->timestamp,
            ],
            'wait_estimate' => [
                'party_size' => (int) $entry->party_size,
                'wait_length' => [
                    'wait_length_seconds' => (int) $entry->estimatedWait(
                        (int) store_options((int) $entry->store_id, 'waitlist_avg_turn', 20)
                    ) * 60,
                ],
            ],
        ]]);
    }

    /** POST rwg/v3/DeleteWaitlistEntry (user canceled → our `left` → CANCELED). */
    public function deleteWaitlistEntry(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeRwg($request)) {
            return $deny;
        }

        $entry = $this->findByEntryId($request);
        // Idempotent: unknown or already-terminal entries still return empty 200.
        if ($entry && in_array((string) $entry->status, ['waiting', 'notified'], true)) {
            $entry->update(['status' => 'left']); // observer maps left → CANCELED RTU
            log_activity('waitlist.rwg_delete', $entry, [], (int) $entry->store_id);
        }

        return response()->json((object) []);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function findByEntryId(Request $request): ?WaitlistEntry
    {
        $id = trim((string) $request->input('waitlist_entry_id'));
        if ($id === '') {
            return null;
        }

        return WaitlistEntry::where('rwg_entry_id', $id)->first();
    }

    /** @return JsonResponse business-logic failure union member (HTTP 200). */
    private function businessFailure(string $cause): JsonResponse
    {
        return response()->json(['waitlist_business_logic_failure' => ['cause' => $cause]]);
    }

    /**
     * Resolve the merchant_id from our feed to a restaurant (Post) id. Supports
     * the `store-{id}` convention this lane's feed builder emits, a bare numeric
     * id, and a slug fallback. F0: align with Lane C's actual merchant_id shape.
     */
    private function resolveRestaurantId(string $merchantId): ?int
    {
        $merchantId = trim($merchantId);
        if ($merchantId === '') {
            return null;
        }

        if (str_starts_with($merchantId, 'store-')) {
            $merchantId = substr($merchantId, 6);
        }

        if (ctype_digit($merchantId)) {
            return Store::whereKey((int) $merchantId)->value('id');
        }

        return Store::where('slug', $merchantId)->value('id');
    }

    /**
     * HTTP Basic auth for the booking server. Reads the C-owned
     * `services.reserve_with_google` creds; dark (kill-switch off / no creds) ⇒
     * 503 so a missing-creds env exposes nothing. Bad creds ⇒ 401. No tenant data
     * in either error body.
     */
    private function authorizeRwg(Request $request): ?JsonResponse
    {
        if (! config('services.reserve_with_google.enabled')) {
            return response()->json(['error' => 'service_unavailable'], 503);
        }

        $wantUser = (string) config('services.reserve_with_google.basic_user');
        $wantPass = (string) config('services.reserve_with_google.basic_pass');
        [$gotUser, $gotPass] = $this->basicCredentials($request);

        if ($wantUser === '' || $wantPass === ''
            || ! hash_equals($wantUser, $gotUser)
            || ! hash_equals($wantPass, $gotPass)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return null;
    }

    /** @return array{0:string,1:string} [user, pass] from PHP_AUTH_* or Authorization header. */
    private function basicCredentials(Request $request): array
    {
        $user = $request->getUser();
        $pass = $request->getPassword();

        if ($user === null || $user === '') {
            $header = (string) $request->header('Authorization');
            if (stripos($header, 'basic ') === 0) {
                $decoded = base64_decode(substr($header, 6), true);
                if ($decoded !== false && str_contains($decoded, ':')) {
                    [$user, $pass] = explode(':', $decoded, 2);
                }
            }
        }

        return [(string) $user, (string) $pass];
    }
}
