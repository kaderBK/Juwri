<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Laravel\Passkeys\Support\Aaguids;

/**
 * P1-19 (customer passkeys): a WebAuthn passkey of ONE storefront customer (`customers`) of ONE store. It signs
 * in as that customer on that store only: a sign-in looks the credential up for the store whose page it is made
 * on, and the composite FK (store_id, customer_id) keeps the row on its customer's store. Tenant row (J29).
 *
 * `credential` is the webauthn-lib credential record the assertion is checked against (and that receives the
 * new sign counter); public_key, sign_count, transports and aaguid mirror it on every save.
 *
 * @property int $id
 * @property int $account_id
 * @property int $store_id
 * @property int $customer_id
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
 * @property-read Customer|null $customer
 * @property-read string|null $authenticator
 */
class CustomerPasskey extends Model
{
    use BelongsToAccount;

    protected $table = 'customer_passkeys';

    protected $guarded = ['id'];

    protected $hidden = ['credential', 'public_key', 'credential_id'];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'customer_id' => 'integer',
            'credential' => 'json',
            'transports' => 'array',
            'sign_count' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
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
