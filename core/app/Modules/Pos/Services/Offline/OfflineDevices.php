<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Offline;

use App\Models\StaffMember;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Models\PosOfflineDevice;

/**
 * Offline POS tills (OFFLINE-POS-SPEC §6, OP-2): registration, the till's own session (a device token sent as
 * X-Pos-Device + X-Pos-Device-Token, stored only as a SHA-256 hash), the per-device pepper of the offline PIN check, and
 * revocation with remote wipe:
 *  - a revoked till that reconnects may still upload what it holds (replay), is told `wipe: true`, wipes, and reports it;
 *  - every contact renews the till's lease (3 days); a till that does not reach the server before its lease ends wipes its
 *    catalogue snapshot and PIN data on its next start and stops selling, but KEEPS its encrypted unsynced queue until it
 *    reaches the server again (owner Q5: records are never deleted silently) — then it uploads and, if revoked, wipes the
 *    rest; so a revoked till that never reconnects can no longer sign anyone in or sell after 3 days;
 *  - the owner can also ask a till that stays in service to wipe (requestWipe): same upload-then-wipe, then it registers
 *    again.
 * The PIN check offline: the snapshot carries, per staff member, HMAC-SHA256(pepper, PBKDF2(PIN, salt)) and the salt —
 * useless without this till's pepper, different on every till, and the server's bcrypt hash never leaves the server.
 */
final class OfflineDevices
{
    public const HEADER_ID = 'X-Pos-Device';

    public const HEADER_TOKEN = 'X-Pos-Device-Token';

    /** @return array{0: PosOfflineDevice, 1: string} the device and its new token (shown once) */
    public function register(Store $store, ?StaffMember $staff, int $branchId, string $deviceId, ?string $name, ?string $platform): array
    {
        if (preg_match(OfflineReplay::DEVICE, $deviceId) !== 1) {
            throw new InvalidArgumentException('device_id must be 8-64 letters, digits, . _ -');
        }
        $device = PosOfflineDevice::query()->firstOrNew(['store_id' => $store->id, 'device_id' => $deviceId]);
        if ($device->exists && $device->revoked_at !== null) {
            throw new DeviceRevoked;
        }
        // a till that wiped itself since it last registered starts its record numbers at 1 again: so does the server's
        // view of it (else every new number looks like a late fill of an old gap)
        $wipedSince = $device->exists && $device->wiped_at !== null && ($device->registered_at === null || $device->wiped_at >= $device->registered_at);
        if ($wipedSince) {
            $device->last_seq = 0;
            $device->missing_seqs = [];
        }
        $token = bin2hex(random_bytes(32));
        $device->fill([
            'account_id' => $store->account_id, 'branch_id' => $branchId ?: null,
            'name' => $name !== null ? mb_substr(trim($name), 0, 80) : $device->name, 'platform' => in_array($platform, ['ios', 'android', 'web', 'desktop'], true) ? $platform : 'web',
            'token_hash' => hash('sha256', $token), 'pepper' => bin2hex(random_bytes(32)), // a new pepper: older PIN verifiers stop matching
            'escrow' => $device->escrow ?: base64_encode(random_bytes(32)), // kept across re-registration: the till's data stays readable
            'registered_by_staff_id' => $staff?->id, 'registered_at' => now(), 'last_seen_at' => now(),
        ]);
        $device->last_seq ??= 0;
        $device->missing_seqs ??= [];
        $device->save();

        return [$device, $token];
    }

    /** The calling till of this store (revoked ones too when $allowRevoked), or null. Renews the lease on contact. */
    public function authenticate(Store $store, Request $request, bool $allowRevoked = false): ?PosOfflineDevice
    {
        $id = (string) $request->header(self::HEADER_ID, '');
        $token = (string) $request->header(self::HEADER_TOKEN, '');
        if ($id === '' || strlen($token) !== 64) {
            return null;
        }
        $device = PosOfflineDevice::query()->where('store_id', $store->id)->where('device_id', $id)->first();
        if ($device === null || $device->token_hash === null || ! hash_equals($device->token_hash, hash('sha256', $token))) {
            return null;
        }
        if ($device->revoked_at !== null && ! $allowRevoked) {
            return null;
        }
        $device->forceFill(['last_seen_at' => now()])->save();

        return $device;
    }

    public function revoke(Store $store, int $id, User $by): PosOfflineDevice
    {
        $device = PosOfflineDevice::query()->where('store_id', $store->id)->findOrFail($id);
        if ($device->revoked_at === null) {
            $device->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $by->id])->save();
        }

        return $device;
    }

    /** Owner: a till that stays in service wipes itself on next contact (after uploading); it registers again afterwards. */
    public function requestWipe(Store $store, int $id, User $by): PosOfflineDevice
    {
        $device = PosOfflineDevice::query()->where('store_id', $store->id)->findOrFail($id);
        $device->forceFill(['wipe_requested_at' => now(), 'wipe_requested_by_user_id' => $by->id])->save();

        return $device;
    }

    /** Days a till may go without reaching the server before it wipes itself on start (its lease). */
    public static function leaseDays(): int
    {
        return max(1, (int) config('pos.offline.wipe_after_days', 3));
    }

    /** @return array<string, mixed> what a till learns about itself on every contact */
    public static function status(PosOfflineDevice $device): array
    {
        $revoked = $device->revoked_at !== null;
        $wipe = $revoked || ($device->wipe_requested_at !== null && ($device->wiped_at === null || $device->wiped_at < $device->wipe_requested_at));

        return ['device_id' => $device->device_id, 'revoked' => $revoked, 'wipe' => $wipe,
            'lease_until' => $wipe ? null : CarbonImmutable::now()->addDays(self::leaseDays())->toIso8601ZuluString(),
            'retention_days' => (int) config('pos.offline.retention_days', 7)];
    }

    /**
     * Offline PIN verifiers for this till: staff of the snapshot with an offline PIN source.
     *
     * @param  list<int>  $staffIds
     * @return list<array{staff_id: int, salt: string, rounds: int, verifier: string}>
     */
    public static function pinVerifiers(PosOfflineDevice $device, Store $store, array $staffIds): array
    {
        $pepper = (string) $device->pepper;
        if ($pepper === '' || $staffIds === []) {
            return [];
        }

        return StaffMember::query()->where('store_id', $store->id)->whereIn('id', $staffIds)->whereNotNull('pin_offline_kdf')->orderBy('id')
            ->get(['id', 'pin_offline_salt', 'pin_offline_kdf'])
            ->map(fn (StaffMember $s) => ['staff_id' => (int) $s->id, 'salt' => (string) $s->pin_offline_salt, 'rounds' => StaffMember::OFFLINE_PIN_ROUNDS,
                'verifier' => hash_hmac('sha256', (string) $s->pin_offline_kdf, $pepper)])
            ->values()->all();
    }
}
