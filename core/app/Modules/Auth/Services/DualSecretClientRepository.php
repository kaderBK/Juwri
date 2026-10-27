<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Services;

use Juwri\Modules\Auth\Models\OAuthClient;
use Laravel\Passport\Bridge\ClientRepository as PassportBridgeClientRepository;
use Laravel\Passport\Client;

/**
 * Polish (closes the P5-3 deferral): dual-secret grace window for partner-app
 * credential rotation. After a rotate, the OLD secret keeps validating until
 * oauth_clients.previous_secret_expires_at (48h) so a partner can deploy the
 * new secret without an outage. Bound over Passport's bridge repository in
 * AuthServiceProvider.
 *
 * P1-00a (Passport 13): Passport 13 only checks hashed secrets; juwri stores
 * them plain ({@see OAuthClient}), so both forms are accepted here.
 * The grant check keeps Passport 12's handlesGrant() rule via grant_types.
 */
class DualSecretClientRepository extends PassportBridgeClientRepository
{
    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        if ($clientSecret === null || $clientSecret === '') {
            return false;
        }

        try {
            $record = $this->clients->findActive($clientIdentifier);
            if (! $record instanceof Client || ($grantType !== null && ! $record->hasGrantType($grantType))) {
                return false;
            }

            if ($this->secretMatches($record->getAttributes()['secret'] ?? null, $clientSecret)) {
                return true;
            }

            // Grace path: the previous secret, while unexpired.
            if (! $record->confidential()) {
                return false;
            }

            $previous = $record->getAttribute('previous_secret');
            $expires = $record->getAttribute('previous_secret_expires_at');
            if (! $previous || ! $expires || now()->greaterThan($expires)) {
                return false;
            }

            return $this->secretMatches((string) $previous, $clientSecret);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function secretMatches(?string $stored, string $given): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }

        if (password_get_info($stored)['algo'] !== null) {
            return $this->hasher->check($given, $stored);
        }

        return hash_equals($stored, $given);
    }
}
