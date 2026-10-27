<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FAQRCode\Google2FA as Google2FAQRCode;

/**
 * H8 — TOTP two-factor auth. Wraps pragmarx/google2fa for secret generation, code
 * verification (with a ±1 window for clock drift) and recovery codes. Secrets and
 * recovery codes are held encrypted on the User model; this service only ever sees
 * plaintext transiently during enroll/verify.
 */
class TwoFactorService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA();
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey();
    }

    /** Ten single-use recovery codes, format XXXXXXXX-XXXXXXXX. */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, 10))
            ->map(fn () => Str::upper(Str::random(8) . '-' . Str::random(8)))
            ->all();
    }

    /** The otpauth:// URI an authenticator app scans. */
    public function otpauthUrl(string $issuer, string $account, string $secret): string
    {
        return new Google2FAQRCode()->getQRCodeUrl($issuer, $account, $secret);
    }

    /** Inline SVG QR for the otpauth URI (no external request; CSP-safe). */
    public function qrCodeSvg(string $issuer, string $account, string $secret): string
    {
        return new Google2FAQRCode()->getQRCodeInline($issuer, $account, $secret);
    }

    /** Verify a 6-digit TOTP against the user's secret (±1 step for drift). */
    public function verifyCode(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;
        if (!$secret) {
            return false;
        }

        return $this->verifyOnce('user:'.$user->getKey(), $secret, $code);
    }

    /**
     * P4-06 (RFC 6238 §5.2): a code is accepted ONCE per subject. Before, the same 6 digits signed in again for the
     * whole ±1-step window (~90 s), so a code seen over a shoulder or relayed by a phishing page could be replayed. The
     * matched time step must be newer than the subject's last used one; the atomic add stops two requests racing one
     * code.
     */
    public function verifyOnce(string $subject, string $secret, string $code): bool
    {
        $lastKey = '2fa:last-step:'.$subject;
        $step = $this->engine->verifyKeyNewer($secret, preg_replace('/\s+/', '', $code), (int) Cache::get($lastKey, 0), 1);
        if ($step === false || ! is_int($step)) {
            return false;
        }
        if (! Cache::add('2fa:step:'.$subject.':'.$step, 1, 300)) {
            return false;
        }
        Cache::put($lastKey, $step, 300);

        return true;
    }

    /**
     * HPMS E12(b): verify a 6-digit TOTP against a RAW secret (±1 step for drift).
     * Lets non-User subjects (staff) reuse the same engine.
     */
    public function verifyRaw(?string $secret, string $code): bool
    {
        if (! $secret) {
            return false;
        }

        return (bool) $this->engine->verifyKey($secret, preg_replace('/\s+/', '', $code), 1);
    }

    /**
     * Consume a recovery code if it matches: returns true and removes it from the
     * user's set (single-use), false otherwise.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        return self::consumeRecoveryCode($user, $code);
    }

    /**
     * P6-268: atomic single-use consume for any model holding an encrypted
     * `two_factor_recovery_codes` array (owners, staff). The stored set is re-read
     * under a row lock and written back with a compare-and-set on the stored
     * ciphertext, so two parallel challenges with one leaked code (or a caller
     * holding a stale model) succeed once. Input is matched without case, spaces
     * or dashes ("abcd efgh-..." == "ABCDEFGH-...").
     */
    public static function consumeRecoveryCode(Model $model, string $code): bool
    {
        $needle = self::normaliseRecoveryCode($code);
        if ($needle === '' || ! $model->exists) {
            return false;
        }

        $column = 'two_factor_recovery_codes';

        $remaining = DB::transaction(function () use ($model, $column, $needle) {
            $fresh = $model->newQuery()->withoutGlobalScopes()->whereKey($model->getKey())->lockForUpdate()->first();
            if (! $fresh) {
                return null;
            }

            $codes = $fresh->getAttribute($column) ?: [];
            $matchIdx = null;
            // Constant-time per code; no early exit.
            foreach ($codes as $idx => $stored) {
                if (hash_equals(self::normaliseRecoveryCode((string) $stored), $needle)) {
                    $matchIdx = $idx;
                }
            }
            if ($matchIdx === null) {
                return null;
            }
            unset($codes[$matchIdx]);
            $codes = array_values($codes);

            $rawBefore = $fresh->getRawOriginal($column);
            $fresh->forceFill([$column => $codes]);
            $rawAfter = $fresh->getAttributes()[$column];

            $updated = $model->newQuery()->withoutGlobalScopes()
                ->whereKey($model->getKey())
                ->where($column, $rawBefore)
                ->update([$column => $rawAfter]);

            return $updated === 1 ? $codes : null;
        });

        if ($remaining === null) {
            return false;
        }

        // Keep the caller's in-memory model in step without another write.
        $model->forceFill([$column => $remaining]);
        $model->syncOriginalAttribute($column);

        return true;
    }

    private static function normaliseRecoveryCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/u', '', $code));
    }
}
