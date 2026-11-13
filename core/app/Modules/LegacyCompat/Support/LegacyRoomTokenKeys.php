<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * J52b: printed hotel-room tokens (`rt=`) minted by the old install under ITS app key (LEGACYCOMPAT-SPEC §4,
 * plan §5.3). The migrated install verifies a room token under the current key (Juwri's own HotelRoomToken, via the
 * RoomCharge contract) OR under one of these old keys; minting always uses the current key, never an old one.
 *
 * Key storage: config `legacy_compat.room_token_legacy_keys` holds the old key(s) ENCRYPTED with the current app key
 * (Crypt::encryptString output, set from env LEGACY_COMPAT_ROOM_TOKEN_KEYS at cut-over, never in git). They are
 * decrypted only when a token is checked; an entry that does not decrypt is ignored (never "accept anything").
 *
 * Grace period (owner decision J56a): old-key tokens are accepted only before config
 * `legacy_compat.room_token_legacy_until` (env LEGACY_COMPAT_ROOM_KEY_UNTIL, set at cut-over to cut-over + 6 months).
 * Only an absolute date is accepted; empty, relative ("+6 months") or unparsable = refused (fail closed). After it, old room QRs still open the menu but cannot charge the room.
 *
 * Token format (plan §5.3): HMAC-SHA256 over a room payload, hex, truncated to 40 chars. The payload is the documented
 * Juwri form `room|<store id>|<room id>`, so a token is bound to one store AND one room.
 * OPEN (plan Q10, LEGACYCOMPAT-SPEC §6): the old platform's payload fields and whether its key was used as the raw
 * `base64:…` env string (as Juwri does) must be confirmed against the production version; the documented format has
 * no expiry of its own; the J56 end date above is the only time limit. Nothing beyond the documented format is guessed.
 */
final class LegacyRoomTokenKeys
{
    public const TOKEN_LENGTH = 40;

    /** @var list<string>|null */
    private ?array $decrypted = null;

    /** @param list<string>|null $encryptedKeys null = read config legacy_compat.room_token_legacy_keys */
    public function __construct(private readonly ?array $encryptedKeys = null) {}

    /** True when $token is a valid room token for this store + room under one of the old keys. */
    public function verifyLegacy(int $storeId, int $roomId, ?string $token): bool
    {
        if (! self::wellFormed($token) || $storeId <= 0 || $roomId <= 0 || ! self::graceOpen()) {
            return false;
        }

        foreach ($this->legacyKeys() as $key) {
            if (hash_equals(self::sign($storeId, $roomId, $key), (string) $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The end of the J56 grace period, or null (= old-key tokens refused) when unset or not an ABSOLUTE date: only
     * `Y-m-d` (00:00 in the app timezone) or an ISO-8601 date-time (`Y-m-d[T ]H:i[:s[.u]][Z|±hh[:]mm]`). A relative
     * value such as "+6 months" would be re-evaluated on every check and never expire, so it is refused.
     */
    public static function graceEndsAt(): ?CarbonImmutable
    {
        $until = trim((string) config('legacy_compat.room_token_legacy_until', ''));
        $tz = (string) config('app.timezone', 'UTC');

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $until) === 1) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $until, $tz);

                return $date !== null && $date->format('Y-m-d') === $until ? $date : null;
            }
            if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)(Z|[+-]\d{2}:?\d{2})?$/D', $until, $m) === 1) {
                $date = CarbonImmutable::parse($until, $tz);

                // Round-trip: an overflowing value (2027-02-30, 25:00) parses to another instant; refuse it.
                return $date->format('Y-m-d') === $m[1] && $date->format('H:i') === substr($m[2], 0, 5) ? $date : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /** True while old-key room tokens are still accepted (now is before the J56 end date). */
    public static function graceOpen(): bool
    {
        $ends = self::graceEndsAt();

        return $ends !== null && now()->lessThan($ends);
    }

    /** Only a full-length lowercase hex HMAC is ever compared; anything else (unsigned, XOR, empty) is refused. */
    public static function wellFormed(?string $token): bool
    {
        return is_string($token) && preg_match('/^[0-9a-f]{'.self::TOKEN_LENGTH.'}$/D', $token) === 1;
    }

    /** The documented token for (store, room) under $key. Public for the contract tests (fake old keys only). */
    public static function sign(int $storeId, int $roomId, string $key): string
    {
        return substr(hash_hmac('sha256', 'room|'.$storeId.'|'.$roomId, $key), 0, self::TOKEN_LENGTH);
    }

    /** How many old keys are usable (decrypt cleanly and are non-empty). */
    public function count(): int
    {
        return count($this->legacyKeys());
    }

    /** @return list<string> */
    private function legacyKeys(): array
    {
        if ($this->decrypted !== null) {
            return $this->decrypted;
        }

        $raw = $this->encryptedKeys ?? config('legacy_compat.room_token_legacy_keys', []);
        $keys = [];
        foreach (is_array($raw) ? $raw : [] as $index => $encrypted) {
            if (! is_string($encrypted) || $encrypted === '') {
                continue;
            }
            try {
                $key = Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                // Never log the value; the index is enough to find the bad entry.
                Log::warning('legacy_compat: room-token legacy key #'.$index.' does not decrypt with the current app key; ignored.');

                continue;
            }
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $this->decrypted = $keys;
    }
}
