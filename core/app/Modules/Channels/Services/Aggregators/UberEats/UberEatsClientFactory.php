<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\UberEats;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;

/**
 * Resolves the Uber Eats client for a restaurant: a container binding (tests) →
 * the real OAuth client when driver=real AND BYOK creds exist → else the Fake.
 * (Real client is added with live partner credentials — deferred.)
 */
class UberEatsClientFactory
{
    public static function make(?int $storeId = null): UberEatsClientInterface
    {
        if (app()->bound(UberEatsClientInterface::class)) {
            return app(UberEatsClientInterface::class);
        }

        // P1-13 13c-R: through the partner transport (Community: direct — the client below; a Cloud relay may bind another).
        return PartnerTransports::client('uber_eats', $storeId, UberEatsClientInterface::class, fn () => self::direct($storeId));
    }

    /** The client without a relay: real when configured, else the Fake (the behaviour before the transport). */
    private static function direct(?int $storeId): UberEatsClientInterface
    {
        $creds = $storeId ? Credential::for('uber_eats', $storeId) : null;
        if (config('services.uber_eats.driver') === 'real' && ! empty($creds['client_id']) && class_exists(UberEatsClient::class)) {
            return new UberEatsClient($creds, $storeId);
        }

        return new FakeUberEatsClient;
    }
}
