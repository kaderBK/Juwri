<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;

use Juwri\Core\Models\Credential;

/**
 * GOOGLE-PARTNER Lane B factory — mirrors A's {@see GbpClientFactory} shape but is
 * a SEPARATE file so B never edits A's factory (SPEC §9 fence). Real-if-creds →
 * Fake; a container binding (test `app()->instance(GbpEngagementClientInterface…)`)
 * always wins so suites inject the Fake explicitly.
 *
 * Shares A's ONE GBP connection: reads config('services.gbp.driver') (A owns that
 * block; null-safe when absent) and Credential feature='gbp'. Dark by default —
 * with no driver/creds this returns the Fake and makes zero HTTP calls.
 */
class GbpEngagementClientFactory
{
    public static function make(?int $storeId = null): GbpEngagementClientInterface
    {
        if (app()->bound(GbpEngagementClientInterface::class)) {
            return app(GbpEngagementClientInterface::class);
        }

        $creds = $storeId ? Credential::for('gbp', $storeId) : null;
        if (config('services.gbp.driver') === 'real'
            && ! empty($creds['access_token'])
            && class_exists(GbpEngagementClient::class)) {
            return new GbpEngagementClient($creds, $storeId);
        }

        return new FakeGbpEngagementClient;
    }
}
