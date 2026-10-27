<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * P1-19: server-side WebAuthn challenges. The options (with their random 32-byte challenge) live in the
 * server-side session under one key per ceremony, stamped with the time and the relying party they were
 * made for. Taking them out:
 *
 *  - removes them from the session (one ceremony per challenge);
 *  - refuses them after passkeys.challenge_ttl seconds or under another relying party;
 *  - burns the challenge in the cache with an atomic add, so the same challenge can never be accepted
 *    twice — not even from a copied session cookie or two requests racing each other.
 *
 * Each ceremony is stamped with its subject: by default the signed-in web user (owner flows); other sign-in
 * kinds pass their own (customer passkeys: the store for a sign-in, the customer for an enrolment), so a
 * challenge made for one subject is refused for any other.
 */
final class PasskeyChallenge
{
    public const LOGIN = 'login';

    public const REGISTER = 'register';

    public const CONFIRM = 'confirm';

    public static function put(Request $request, string $ceremony, PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options, ?string $subject = null): void
    {
        $request->session()->put(self::key($ceremony), [
            'options' => WebAuthn::toJson($options),
            'at' => now()->getTimestamp(),
            'rp' => (string) config('passkeys.relying_party_id'),
            'user' => $subject ?? $request->user()?->getAuthIdentifier(),
        ]);
    }

    /**
     * @template T of PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions
     *
     * @param  class-string<T>  $class
     * @return T
     *
     * @throws ValidationException
     */
    public static function pull(Request $request, string $ceremony, string $class, ?string $subject = null): object
    {
        $stored = $request->session()->pull(self::key($ceremony));
        $ttl = max(1, (int) config('passkeys.challenge_ttl', 120));

        if (! is_array($stored) || ! is_string($stored['options'] ?? null)
            || (now()->getTimestamp() - (int) ($stored['at'] ?? 0)) > $ttl
            || ($stored['rp'] ?? null) !== (string) config('passkeys.relying_party_id')
            || ($stored['user'] ?? null) !== ($subject ?? $request->user()?->getAuthIdentifier())) {
            throw self::expired();
        }

        $options = WebAuthn::fromJson($stored['options'], $class);

        if (! Cache::add('passkey-challenge:'.hash('sha256', $options->challenge), 1, $ttl * 2)) {
            throw self::expired();
        }

        return $options;
    }

    private static function key(string $ceremony): string
    {
        return 'passkey.challenge.'.$ceremony;
    }

    private static function expired(): ValidationException
    {
        return ValidationException::withMessages([
            'credential' => ___('This passkey request has expired. Please try again.'),
        ]);
    }
}
