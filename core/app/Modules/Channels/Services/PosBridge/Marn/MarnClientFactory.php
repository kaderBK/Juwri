<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Marn;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;

/**
 * Resolves the Marn client: container binding (tests) → real client when driver=real
 * AND BYOK ClientID/Secret exist → else the Fake. Real client (client-credentials token
 * lifecycle, UAT/prod base) lands with Marn-issued creds — deferred (BD-ACTIONS #3).
 */
class MarnClientFactory
{
    public static function make(?int $storeId = null): MarnClientInterface
    {
        if (app()->bound(MarnClientInterface::class)) {
            return app(MarnClientInterface::class);
        }

        // P1-13 13c-R: through the partner transport (Community: direct — the client below; a Cloud relay may bind another).
        return PartnerTransports::client('marn', $storeId, MarnClientInterface::class, fn () => self::direct($storeId));
    }

    /** The client without a relay: real when configured, else the Fake (the behaviour before the transport). */
    private static function direct(?int $storeId): MarnClientInterface
    {
        $creds = $storeId ? Credential::for('marn', $storeId) : null;
        if (config('services.marn.driver') === 'real'
            && ! empty($creds['client_id']) && ! empty($creds['client_secret'])
            && class_exists(MarnClient::class)) {
            return new MarnClient($creds, $storeId);
        }

        return new FakeMarnClient;
    }
}
