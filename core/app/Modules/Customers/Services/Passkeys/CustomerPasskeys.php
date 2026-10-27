<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Customers\Services\Passkeys;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Scopes\AccountScope;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Models\CustomerPasskey;
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
 * P1-19 (customer passkeys): WebAuthn ceremonies for storefront customers. laravel/passkeys is bound to ONE user
 * model (`users`), so customer passkeys live in their own table and use the package's building blocks directly
 * (web-auth/webauthn-lib serializer and validators: origin, RP ID hash, challenge, user verification, signature,
 * sign counter; the package's authenticator selection and algorithms) — owner, staff and customer passkeys are
 * made and checked the same way. The relying party is the one CustomerRelyingParty configured for the request.
 *
 * Store scope: a customer row belongs to one store, its user handle names the store, and a sign-in only accepts
 * a credential registered for the store whose page it is made on.
 */
final class CustomerPasskeys
{
    /** Opaque WebAuthn user handle of a customer (never an owner's or staff member's: the table is part of it). */
    public static function userHandle(Customer $customer): string
    {
        return hash_hmac('sha256', 'customers|'.$customer->store_id.'|'.$customer->getKey(), (string) config('passkeys.user_handle_secret'), true);
    }

    public static function registrationOptions(Customer $customer, Store $store, string $rpName): PublicKeyCredentialCreationOptions
    {
        $base = app(GenerateRegistrationOptions::class);
        $label = trim((string) ($customer->email ?: $customer->phone ?: $customer->name));
        $title = trim((string) ($store->title ?? ''));
        $name = trim((string) $customer->name) ?: $label;

        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(name: $rpName, id: Passkeys::relyingPartyId()),
            // Stores on the shared storefront host share one RP ID: the picker shows WHICH store each passkey is for.
            user: PublicKeyCredentialUserEntity::create(
                name: $label !== '' ? $label : 'customer',
                id: self::userHandle($customer),
                displayName: $title !== '' ? $name.' · '.$title : $name,
            ),
            challenge: random_bytes(32),
            pubKeyCredParams: $base->supportedAlgorithms(),
            authenticatorSelection: $base->authenticatorSelection(),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: self::descriptors($customer),
            timeout: Passkeys::timeout(),
        );
    }

    /** Discoverable sign-in: the browser offers the passkeys saved for this RP; the server checks the store. */
    public static function requestOptions(): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: Passkeys::relyingPartyId(),
            allowCredentials: [],
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: Passkeys::timeout(),
        );
    }

    /**
     * Check the attestation and store the passkey for the customer.
     *
     * @throws ValidationException when the credential id is already registered
     */
    public static function store(Customer $customer, string $name, PublicKeyCredential $credential, PublicKeyCredentialCreationOptions $options): CustomerPasskey
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

        $passkey = new CustomerPasskey([
            'account_id' => $customer->account_id,
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'name' => $name,
            'credential_id' => $credentialId,
            'rp_id' => Passkeys::relyingPartyId(),
            'credential' => json_decode(WebAuthn::toJson($record), true, flags: JSON_THROW_ON_ERROR),
        ]);
        $passkey->save();

        return $passkey;
    }

    /**
     * Check an assertion against a passkey of $store and store the new counter. Any refusal throws
     * (webauthn-lib exceptions, CounterException for a counter that did not increase); a credential of another
     * store is unknown here.
     */
    public static function verify(PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, Store $store): CustomerPasskey
    {
        $response = $credential->response;
        if (! $response instanceof AuthenticatorAssertionResponse) {
            throw new \InvalidArgumentException('Not an assertion response.');
        }
        $credentialId = Base64UrlSafe::encodeUnpadded($credential->rawId);

        return DB::transaction(function () use ($credentialId, $response, $options, $store): CustomerPasskey {
            // Sign-in runs before any tenant context exists: the store of the page is the scope.
            $passkey = CustomerPasskey::withoutGlobalScope(AccountScope::class)
                ->where('credential_id', $credentialId)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first();
            if (! $passkey instanceof CustomerPasskey || $passkey->rp_id !== Passkeys::relyingPartyId()) {
                throw new \InvalidArgumentException('Unknown customer passkey for this store.');
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

    public static function credentialTaken(string $credentialId): bool
    {
        return CustomerPasskey::withoutGlobalScope(AccountScope::class)->where('credential_id', $credentialId)->exists();
    }

    /** @return Builder<CustomerPasskey> */
    public static function of(Customer $customer): Builder
    {
        return CustomerPasskey::withoutGlobalScope(AccountScope::class)
            ->where('store_id', $customer->store_id)
            ->where('customer_id', $customer->id);
    }

    /** @return list<PublicKeyCredentialDescriptor> */
    private static function descriptors(Customer $customer): array
    {
        return self::of($customer)->pluck('credential_id')
            ->map(fn (string $id): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                Base64UrlSafe::decodeNoPadding($id),
            ))->values()->all();
    }
}
