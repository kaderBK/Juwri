<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Foodics;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;

/**
 * Resolves the Foodics client: a container binding (tests) → the real OAuth client when
 * driver=real AND a BYOK access token exists → else the Fake. The real client (Guzzle,
 * sandbox/prod base, 429 backoff) lands with sandbox creds — deferred until Foodics
 * emails them (BD-ACTIONS #1).
 */
class FoodicsClientFactory
{
    public static function make(?int $storeId = null): FoodicsClientInterface
    {
        if (app()->bound(FoodicsClientInterface::class)) {
            return app(FoodicsClientInterface::class);
        }

        // P1-13 13c-R: through the partner transport (Community: direct — the client below; a Cloud relay may bind another).
        return PartnerTransports::client('foodics', $storeId, FoodicsClientInterface::class, fn () => self::direct($storeId));
    }

    /** The client without a relay: real when configured, else the Fake (the behaviour before the transport). */
    private static function direct(?int $storeId): FoodicsClientInterface
    {
        $creds = $storeId ? Credential::for('foodics', $storeId) : null;
        if (config('services.foodics.driver') === 'real'
            && ! empty($creds['access_token'])
            && class_exists(FoodicsClient::class)) {
            return new FoodicsClient($creds, $storeId);
        }

        return new FakeFoodicsClient;
    }
}
