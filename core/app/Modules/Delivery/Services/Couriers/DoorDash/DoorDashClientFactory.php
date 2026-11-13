<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers\DoorDash;

use Juwri\Core\Models\Credential;

class DoorDashClientFactory
{
    public static function make(?int $storeId = null): DoorDashClientInterface
    {
        if (app()->bound(DoorDashClientInterface::class)) {
            return app(DoorDashClientInterface::class);
        }
        $creds = $storeId ? Credential::for('doordash', $storeId) : null;
        // NOTE: production access is closed; a real client stays sandbox-only.
        if (config('services.doordash.driver') === 'real' && ! empty($creds['developer_id']) && class_exists(DoorDashClient::class)) {
            return new DoorDashClient($creds, $storeId);
        }

        return new FakeDoorDashClient;
    }
}
