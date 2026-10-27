<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Square;

use Juwri\Core\Models\Credential;

/**
 * Binds → real-if-creds → fake. Tests bind SquareClientInterface (or set no creds)
 * to get the Fake; production resolves the real client when BYOK tokens exist and
 * the driver is switched to 'real'.
 */
class SquareClientFactory
{
    public static function make(?int $storeId = null): SquareClientInterface
    {
        if (app()->bound(SquareClientInterface::class)) {
            return app(SquareClientInterface::class);
        }
        $creds = $storeId ? Credential::for('square', $storeId) : null;
        if (config('services.square.driver') === 'real' && ! empty($creds['access_token']) && class_exists(SquareClient::class)) {
            return new SquareClient($creds, $storeId);
        }

        return new FakeSquareClient;
    }
}
