<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Contracts;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * P1-19 public surface: the WebAuthn ceremony plumbing the Auth module uses for owner and staff passkeys, for
 * other modules that offer passkeys of their own (Customers: storefront customer passkeys).
 *
 *  - challenges live server-side in the session, one per ceremony, single use, passkeys.challenge_ttl seconds,
 *    bound to the relying party configured for the request and to the given subject; a challenge is burned in
 *    the cache when taken, so it is never accepted twice;
 *  - credentials are parsed from the browser's JSON form with the same limits as the owner passkey endpoints.
 */
interface PasskeyCeremonies
{
    /** Longest credential id (base64url) a passkey table stores. */
    public const MAX_CREDENTIAL_ID = 255;

    public function putChallenge(Request $request, string $ceremony, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options, string $subject): void;

    /**
     * Take the ceremony's challenge out of the session (refused when missing, expired, made for another relying
     * party or subject, or already used).
     *
     * @template T of PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
     *
     * @param  class-string<T>  $class
     * @return T
     *
     * @throws ValidationException
     */
    public function pullChallenge(Request $request, string $ceremony, string $class, string $subject): object;

    /**
     * The `credential` field of the request as a webauthn-lib credential with a response of $responseClass.
     *
     * @param  class-string  $responseClass  AuthenticatorAttestationResponse or AuthenticatorAssertionResponse
     *
     * @throws ValidationException
     */
    public function parseCredential(Request $request, string $responseClass): PublicKeyCredential;
}
