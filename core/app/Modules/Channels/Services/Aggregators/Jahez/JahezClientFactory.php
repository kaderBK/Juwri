<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Jahez;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Services\Aggregators\Concerns\ResolvesDriverStatus;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;

/**
 * Resolves the Jahez client: container binding (tests) → real client when driver=real
 * AND BYOK api key/secret exist AND the real class is available → else the Fake. Real
 * client + real endpoint contracts are DEFERRED until Jahez portal access (BD-ACTIONS #7).
 *
 * A6: when driver=real + creds are present but `JahezClient` is missing, we log loudly
 * and expose an `unavailable` status so the owner UI can refuse the push instead of
 * reporting a phantom success against the Fake.
 */
class JahezClientFactory
{
    use ResolvesDriverStatus;

    private const REAL_CLIENT = __NAMESPACE__.'\\JahezClient';

    public static function make(?int $storeId = null): JahezClientInterface
    {
        if (app()->bound(JahezClientInterface::class)) {
            return app(JahezClientInterface::class);
        }

        // P1-13 13c-R: through the partner transport (Community: direct — the client below; a Cloud relay may bind another).
        return PartnerTransports::client('jahez', $storeId, JahezClientInterface::class, fn () => self::direct($storeId));
    }

    /** The client without a relay: real when configured, else the Fake (the behaviour before the transport). */
    private static function direct(?int $storeId): JahezClientInterface
    {
        $status = self::driverStatus($storeId);

        if ($status === 'real' && is_a(self::REAL_CLIENT, JahezClientInterface::class, true)) {
            $client = new \ReflectionClass(self::REAL_CLIENT)
                ->newInstance(Credential::for('jahez', $storeId), $storeId);

            if ($client instanceof JahezClientInterface) {
                return $client;
            }
        }

        if ($status === 'unavailable') {
            self::logDriverUnavailable('jahez', $storeId);
        }

        return new FakeJahezClient;
    }

    /**
     * A6: `real` | `unavailable` | `fake` — see ResolvesDriverStatus.
     */
    public static function driverStatus(?int $storeId = null): string
    {
        $creds = $storeId ? Credential::for('jahez', $storeId) : null;
        $hasCreds = ! empty($creds['api_key']) && ! empty($creds['secret']);

        return self::computeDriverStatus(
            config('services.jahez.driver') === 'real',
            $hasCreds,
            self::REAL_CLIENT
        );
    }
}
