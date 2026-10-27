<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Models;

use Juwri\Modules\Auth\Services\DualSecretClientRepository;
use App\Services\AppSessionToken;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Carbon;
use Laravel\Passport\Client;

/**
 * P1-00a (Passport 13): the partner-app OAuth client.
 *
 * Passport 13 always bcrypt-hashes client secrets. juwri still needs the plain
 * secret server-side: embedded-app session tokens are HS256 JWTs signed with it
 * ({@see AppSessionToken}; the partner verifies with its copy) and
 * the 48h rotation grace compares the previous secret
 * ({@see DualSecretClientRepository}). So this model keeps storing the
 * secret as given — the same as Passport 12 without hashClientSecrets(), i.e. no
 * change from Phase 0. Hashing + a separate session-signing key is a follow-up
 * for the AppStore/Agents module move (logged in progress/lane-b.md, P1-00a).
 *
 * The repository accepts both plain and already-hashed stored secrets.
 *
 * Grants: juwri partner apps use authorization_code + refresh_token only. Passport
 * 13 would also infer client_credentials for every confidential first-party client,
 * and its TokenGuard then treats a token whose user id equals the client id as a
 * machine token — with bigint client ids (Passport::$clientUuids = false) that
 * silently logs out user #N on client #N. So client_credentials, implicit and
 * device_code are not inferred; an explicit grant_types column (P1-03 schema)
 * still wins.
 *
 * @property string|null $secret
 * @property string|null $previous_secret
 * @property Carbon|string|null $previous_secret_expires_at
 */
class OAuthClient extends Client
{
    protected $table = 'oauth_clients';

    /** @return Attribute<string|null, string|null> */
    protected function secret(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $this->plainSecret = $value;

                return $value;
            },
        );
    }

    /** @return Attribute<list<string>, never> */
    protected function grantTypes(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => isset($value) ? $this->fromJson($value) : array_keys(array_filter([
                'authorization_code' => ! empty($this->redirect_uris),
                'password' => (bool) $this->password_client,
                'personal_access' => $this->personal_access_client && $this->confidential(),
                'refresh_token' => true,
            ])),
        );
    }
}
