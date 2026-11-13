<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Support;

use App\Models\StaffMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Scopes\AccountScope;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Auth\Models\Passkey;
use Juwri\Modules\Auth\Models\StaffPasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * P1-19b: WebAuthn ceremonies for staff members. laravel/passkeys is bound to ONE user model (`users`), so
 * staff passkeys live in their own table and use the same building blocks directly: the package's
 * serializer/validators (web-auth/webauthn-lib — origin, RP ID hash, challenge, user verification, signature,
 * sign counter) and its authenticator-selection / algorithm choices, so both kinds of passkey are made and
 * checked the same way. The relying party is the one PasskeyRelyingParty configured for the request.
 */
final class StaffPasskeys
{
    /** Opaque WebAuthn user handle of a staff member (never the owner's: the table name is part of it). */
    public static function userHandle(StaffMember $staff): string
    {
        return hash_hmac('sha256', 'staff_members|'.$staff->getKey(), (string) config('passkeys.user_handle_secret'), true);
    }

    public static function registrationOptions(StaffMember $staff): PublicKeyCredentialCreationOptions
    {
        $base = app(GenerateRegistrationOptions::class);
        $store = trim((string) ($staff->store->title ?? ''));

        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(name: Passkeys::relyingPartyName(), id: Passkeys::relyingPartyId()),
            // The account picker on a shared device shows WHICH staff member (and store) each passkey is.
            user: PublicKeyCredentialUserEntity::create(
                name: (string) $staff->email,
                id: self::userHandle($staff),
                displayName: $store !== '' ? $staff->name.' · '.$store : (string) $staff->name,
            ),
            challenge: random_bytes(32),
            pubKeyCredParams: $base->supportedAlgorithms(),
            authenticatorSelection: $base->authenticatorSelection(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: self::descriptors($staff),
            timeout: Passkeys::timeout(),
        );
    }

    /** Discoverable sign-in (no staff given) or a staff-bound confirmation. */
    public static function requestOptions(?StaffMember $staff = null): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: Passkeys::relyingPartyId(),
            allowCredentials: $staff ? self::descriptors($staff) : [],
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: Passkeys::timeout(),
        );
    }

    /**
     * Check the attestation and store the passkey for the staff member.
     *
     * @throws ValidationException when the credential id is already registered (staff or user passkey)
     */
    public static function store(StaffMember $staff, string $name, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): StaffPasskey
    {
        $response = $credential->response;
        if (! $response instanceof AuthenticatorAttestationResponse) {
            throw new \InvalidArgumentException('Not an attestation response.');
        }

        $record = WebAuthn::attestationValidator()->check(
            authenticatorAttestationResponse: $response,
            publicKeyCredentialCreationOptions: $options,
            host: Passkeys::relyingPartyId(),
        );

        $credentialId = Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId);
        if (self::credentialTaken($credentialId)) {
            throw ValidationException::withMessages(['credential' => ___('This passkey could not be added. Please try again.')]);
        }

        $passkey = new StaffPasskey([
            'account_id' => $staff->account_id,
            'store_id' => $staff->store_id,
            'staff_id' => $staff->id,
            'name' => $name,
            'credential_id' => $credentialId,
            'rp_id' => Passkeys::relyingPartyId(),
            'credential' => json_decode(WebAuthn::toJson($record), true, flags: JSON_THROW_ON_ERROR),
        ]);
        $passkey->save();

        return $passkey;
    }

    /**
     * Check an assertion against a stored staff passkey (optionally one of $staff's) and store the new counter.
     * Any refusal throws (webauthn-lib exceptions, CounterException for a counter that did not increase).
     */
    public static function verify(PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, ?StaffMember $staff = null): StaffPasskey
    {
        $response = $credential->response;
        if (! $response instanceof AuthenticatorAssertionResponse) {
            throw new \InvalidArgumentException('Not an assertion response.');
        }
        $credentialId = Base64UrlSafe::encodeUnpadded($credential->rawId);

        return DB::transaction(function () use ($credentialId, $response, $options, $staff): StaffPasskey {
            // Sign-in happens before any tenant context exists; a staff-bound check is narrowed by staff_id.
            $query = StaffPasskey::withoutGlobalScope(AccountScope::class)->where('credential_id', $credentialId)->lockForUpdate();
            if ($staff) {
                $query->where('staff_id', $staff->id);
            }
            $passkey = $query->first();
            if (! $passkey instanceof StaffPasskey) {
                throw new \InvalidArgumentException('Unknown staff passkey.');
            }

            $record = WebAuthn::fromJson(json_encode($passkey->credential, JSON_THROW_ON_ERROR), CredentialRecord::class);
            $record = WebAuthn::assertionValidator()->check(
                credentialRecord: $record,
                authenticatorAssertionResponse: $response,
                publicKeyCredentialRequestOptions: $options,
                host: Passkeys::relyingPartyId(),
                userHandle: $record->userHandle,
            );

            $passkey->forceFill([
                'credential' => json_decode(WebAuthn::toJson($record), true, flags: JSON_THROW_ON_ERROR),
                'last_used_at' => now(),
            ])->save();

            return $passkey;
        });
    }

    /** A credential id is unique across BOTH passkey tables, so a sign-in can never be ambiguous. */
    public static function credentialTaken(string $credentialId): bool
    {
        // BL-043: across every account (bypass also lifts PostgreSQL RLS, which withoutGlobalScope does not).
        return TenantContext::bypass(fn (): bool => StaffPasskey::withoutGlobalScope(AccountScope::class)->where('credential_id', $credentialId)->exists()
            || Passkey::where('credential_id', $credentialId)->exists());
    }

    /** @return list<PublicKeyCredentialDescriptor> */
    private static function descriptors(StaffMember $staff): array
    {
        return StaffPasskey::withoutGlobalScope(AccountScope::class)->where('staff_id', $staff->id)->pluck('credential_id')
            ->map(fn (string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                Base64UrlSafe::decodeNoPadding($id),
            ))->values()->all();
    }
}
