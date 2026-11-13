<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\HungerStation;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;

/**
 * Resolves the HungerStation client. Returns the Fake (fixtures) client unless the
 * driver is 'real' AND the tenant has live BYOK credentials. A container binding
 * overrides everything (tests bind a scripted FakeHungerStationClient).
 */
class HungerStationClientFactory
{
    public static function make(?int $storeId = null): HungerStationClientInterface
    {
        if (app()->bound(HungerStationClientInterface::class)) {
            return app(HungerStationClientInterface::class);
        }

        // P1-13 13c-R: through the partner transport (Community: direct — the client below; a Cloud relay may bind another).
        return PartnerTransports::client('hungerstation', $storeId, HungerStationClientInterface::class, fn () => self::direct($storeId));
    }

    /** The client without a relay: real when configured, else the Fake (the behaviour before the transport). */
    private static function direct(?int $storeId): HungerStationClientInterface
    {
        if (config('services.hungerstation.driver') === 'real' && $storeId) {
            $creds = Credential::for('hungerstation', $storeId);
            if ($creds && ! empty($creds['client_id'])) {
                return new HungerStationClient($creds, $storeId);
            }
        }

        // Default: fixtures. Never makes a live call.
        return new FakeHungerStationClient;
    }
}
