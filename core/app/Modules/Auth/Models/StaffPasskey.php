<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Models;

use App\Models\StaffMember;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Laravel\Passkeys\Support\Aaguids;

/**
 * P1-19b: a WebAuthn passkey of ONE staff member (`staff_members`). A staff passkey signs in as that staff
 * member — the same staff session the staff password sign-in gives — never as the owner. Tenant row (J29):
 * account_id / store_id are the staff member's.
 *
 * `credential` is the webauthn-lib credential record the assertion is checked against (and that receives
 * the new sign counter); public_key, sign_count, transports and aaguid mirror it on every save.
 *
 * @property int $id
 * @property int $account_id
 * @property int $store_id
 * @property int $staff_id
 * @property string $name
 * @property string $credential_id
 * @property string $rp_id
 * @property string $public_key
 * @property int $sign_count
 * @property list<string>|null $transports
 * @property string|null $aaguid
 * @property array<string, mixed> $credential
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property-read StaffMember|null $staff
 * @property-read string|null $authenticator
 */
class StaffPasskey extends Model
{
    use BelongsToAccount;

    protected $table = 'staff_passkeys';

    protected $guarded = ['id'];

    protected $hidden = ['credential', 'public_key', 'credential_id'];

    protected $appends = ['authenticator'];

    protected function casts(): array
    {
        return [
            'credential' => 'json',
            'transports' => 'array',
            'sign_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StaffMember, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }

    public function getAuthenticatorAttribute(): ?string
    {
        $aaguid = $this->aaguid;

        return is_string($aaguid) && $aaguid !== Aaguids::unknown() ? Aaguids::labelFor($aaguid) : null;
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
        });
    }
}
