<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators\Keeta;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Services\Aggregators\Concerns\ResolvesDriverStatus;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;

/**
 * Resolves the Keeta client for a restaurant: a container binding (tests) → the real
 * signed client when driver=real AND BYOK creds exist AND the real class is available →
 * else the Fake. The real client (token lifecycle + KeetaSigner on every request) lands
 * with live partner creds — deferred until the Keeta test store arrives (BD-ACTIONS #5).
 *
 * A6: when driver=real + creds are present but `KeetaClient` is missing, we log loudly
 * and expose an `unavailable` status so the owner UI can refuse the push instead of
 * reporting a phantom success against the Fake.
 */
class KeetaClientFactory
{
    use ResolvesDriverStatus;

    private const REAL_CLIENT = __NAMESPACE__.'\\KeetaClient';

    public static function make(?int $storeId = null): KeetaClientInterface
    {
        if (app()->bound(KeetaClientInterface::class)) {
            return app(KeetaClientInterface::class);
        }

        // P1-13 13c-R: through the partner transport (Community: direct — the client below; a Cloud relay may bind another).
        return PartnerTransports::client('keeta', $storeId, KeetaClientInterface::class, fn () => self::direct($storeId));
    }

    /** The client without a relay: real when configured, else the Fake (the behaviour before the transport). */
    private static function direct(?int $storeId): KeetaClientInterface
    {
        $status = self::driverStatus($storeId);

        if ($status === 'real' && is_a(self::REAL_CLIENT, KeetaClientInterface::class, true)) {
            $client = new \ReflectionClass(self::REAL_CLIENT)
                ->newInstance(Credential::for('keeta', $storeId), $storeId);

            if ($client instanceof KeetaClientInterface) {
                return $client;
            }
        }

        if ($status === 'unavailable') {
            self::logDriverUnavailable('keeta', $storeId);
        }

        return new FakeKeetaClient;
    }

    /**
     * A6: `real` | `unavailable` | `fake` — see ResolvesDriverStatus.
     */
    public static function driverStatus(?int $storeId = null): string
    {
        $creds = $storeId ? Credential::for('keeta', $storeId) : null;
        $hasCreds = ! empty($creds['app_id']) && ! empty($creds['app_secret']);

        return self::computeDriverStatus(
            config('services.keeta.driver') === 'real',
            $hasCreds,
            self::REAL_CLIENT
        );
    }
}
