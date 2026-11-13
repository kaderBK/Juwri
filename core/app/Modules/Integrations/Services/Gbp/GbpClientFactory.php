<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;

use Juwri\Core\Models\Credential;

/**
 * Binds → real-if-creds → fake. Mirrors the run-1/B1 driver factories.
 */
class GbpClientFactory
{
    public static function make(?int $storeId = null): GbpClientInterface
    {
        if (app()->bound(GbpClientInterface::class)) {
            return app(GbpClientInterface::class);
        }
        $creds = $storeId ? Credential::for('gbp', $storeId) : null;
        if (config('services.gbp.driver') === 'real' && ! empty($creds['access_token']) && class_exists(GbpClient::class)) {
            return new GbpClient($creds, $storeId);
        }

        return new FakeGbpClient;
    }
}
