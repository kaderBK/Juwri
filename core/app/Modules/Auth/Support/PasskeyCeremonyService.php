<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Support;

use Illuminate\Http\Request;
use Juwri\Modules\Auth\Contracts\PasskeyCeremonies;
use Juwri\Modules\Auth\Http\Controllers\PasskeyController;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * P1-19: {@see PasskeyCeremonies} over the Auth module's own helpers (PasskeyChallenge, PasskeyController's
 * credential parser), so every kind of passkey is challenged and parsed the same way.
 */
final class PasskeyCeremonyService implements PasskeyCeremonies
{
    public function putChallenge(Request $request, string $ceremony, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options, string $subject): void
    {
        PasskeyChallenge::put($request, $ceremony, $options, $subject);
    }

    public function pullChallenge(Request $request, string $ceremony, string $class, string $subject): object
    {
        return PasskeyChallenge::pull($request, $ceremony, $class, $subject);
    }

    public function parseCredential(Request $request, string $responseClass): PublicKeyCredential
    {
        return PasskeyController::parseCredential($request, $responseClass);
    }
}
