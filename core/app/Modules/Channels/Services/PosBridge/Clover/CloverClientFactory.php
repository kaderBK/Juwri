<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\PosBridge\Clover;

use Juwri\Core\Models\Credential;

/**
 * Binds → real-if-creds → fake. Mirrors SquareClientFactory.
 */
class CloverClientFactory
{
    public static function make(?int $storeId = null): CloverClientInterface
    {
        if (app()->bound(CloverClientInterface::class)) {
            return app(CloverClientInterface::class);
        }
        $creds = $storeId ? Credential::for('clover', $storeId) : null;
        if (config('services.clover.driver') === 'real' && ! empty($creds['access_token']) && class_exists(CloverClient::class)) {
            return new CloverClient($creds, $storeId);
        }

        return new FakeCloverClient;
    }
}
