<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Swiggy;

use Juwri\Core\Models\Credential;

/**
 * PARITY-RUN T7 — resolves the Swiggy client: container binding (tests) → the real
 * client when `services.swiggy.driver=real` AND this tenant's BYOK client id/secret
 * exist AND the class is present → else the Fake.
 *
 * `SwiggyClient` does not exist yet (approved-POS-partner gated, BD-ACTIONS #12);
 * the `class_exists` guard is what lets it be dropped in later without touching a
 * single caller.
 */
class SwiggyClientFactory
{
    public static function make(?int $storeId = null): SwiggyClientInterface
    {
        if (app()->bound(SwiggyClientInterface::class)) {
            return app(SwiggyClientInterface::class);
        }

        $creds = $storeId ? Credential::for('swiggy', $storeId) : null;

        if (config('services.swiggy.driver') === 'real'
            && ! empty($creds['client_id'])
            && ! empty($creds['client_secret'])
            && class_exists(__NAMESPACE__.'\\SwiggyClient')) {
            $class = __NAMESPACE__.'\\SwiggyClient';

            return new $class($creds, $storeId);
        }

        return new FakeSwiggyClient;
    }

    /** True when this tenant is running against the real partner API. */
    public static function isLive(?int $storeId = null): bool
    {
        return ! (self::make($storeId) instanceof FakeSwiggyClient);
    }
}
