<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Models;

use Laravel\Passkeys\Passkey as BasePasskey;
use Laravel\Passkeys\Passkeys;

/**
 * P1-19 (J25-13): a WebAuthn passkey of a `users` account (owner, superadmin, reseller owner).
 *
 * `credential` is laravel/passkeys' serialized credential record — the package verifies against it and
 * writes the new sign counter into it after every sign-in. The plain columns public_key, sign_count,
 * transports and aaguid mirror that record on every save, and rp_id records the host the passkey was
 * registered on (set from the request's relying party when the package creates the row).
 *
 * @property string $rp_id
 * @property string $public_key
 * @property int $sign_count
 * @property list<string>|null $transports
 * @property string|null $aaguid
 */
class Passkey extends BasePasskey
{
    protected $table = 'passkeys';

    /** The serialized credential and public key never leave the server in JSON. */
    protected $hidden = ['credential', 'public_key', 'credential_id'];

    protected function casts(): array
    {
        return parent::casts() + [
            'transports' => 'array',
            'sign_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $passkey): void {
            $raw = $passkey->getAttribute('credential');
            $record = is_array($raw) ? $raw : [];

            $passkey->public_key = (string) ($record['credentialPublicKey'] ?? $passkey->public_key ?? '');
            $passkey->sign_count = (int) ($record['counter'] ?? $passkey->sign_count ?? 0);
            $transports = $record['transports'] ?? null;
            $passkey->transports = is_array($transports) ? array_values(array_map('strval', $transports)) : null;
            $aaguid = $record['aaguid'] ?? null;
            $passkey->aaguid = is_string($aaguid) && $aaguid !== '' ? substr($aaguid, 0, 36) : null;

            if (blank($passkey->rp_id)) {
                $passkey->rp_id = Passkeys::relyingPartyId();
            }
        });
    }
}
