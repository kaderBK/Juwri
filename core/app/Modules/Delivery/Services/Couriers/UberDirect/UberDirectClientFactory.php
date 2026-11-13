<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Delivery\Services\Couriers\UberDirect;

use Juwri\Core\Models\Credential;

class UberDirectClientFactory
{
    public static function make(?int $storeId = null): UberDirectClientInterface
    {
        if (app()->bound(UberDirectClientInterface::class)) {
            return app(UberDirectClientInterface::class);
        }
        $creds = $storeId ? Credential::for('uber_direct', $storeId) : null;
        if (config('services.uber_direct.driver') === 'real' && ! empty($creds['client_id']) && class_exists(UberDirectClient::class)) {
            return new UberDirectClient($creds, $storeId);
        }

        return new FakeUberDirectClient;
    }
}
