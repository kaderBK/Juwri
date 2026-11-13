<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Zomato;

use Juwri\Core\Models\Credential;

/**
 * PARITY-RUN T7 — resolves the Zomato client, mirroring JahezClientFactory:
 * container binding (tests) → the real client when `services.zomato.driver=real`
 * AND this tenant's BYOK key/secret exist AND the class is present → else the Fake.
 *
 * `ZomatoClient` does not exist yet (partner-program gated, BD-ACTIONS #11); the
 * `class_exists` guard is what lets it be dropped in later without touching a
 * single caller.
 */
class ZomatoClientFactory
{
    public static function make(?int $storeId = null): ZomatoClientInterface
    {
        if (app()->bound(ZomatoClientInterface::class)) {
            return app(ZomatoClientInterface::class);
        }

        $creds = $storeId ? Credential::for('zomato', $storeId) : null;

        if (config('services.zomato.driver') === 'real'
            && ! empty($creds['api_key'])
            && ! empty($creds['api_secret'])
            && class_exists(__NAMESPACE__.'\\ZomatoClient')) {
            $class = __NAMESPACE__.'\\ZomatoClient';

            return new $class($creds, $storeId);
        }

        return new FakeZomatoClient;
    }

    /** True when this tenant is running against the real partner API. */
    public static function isLive(?int $storeId = null): bool
    {
        return ! (self::make($storeId) instanceof FakeZomatoClient);
    }
}
