<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Services\Maps\GoogleGeocodeProvider;
use Juwri\Core\Services\Maps\OsmGeocodeProvider;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * Guards the geocode providers' outbound HTTP timeout. These proxies run inside
 * a web request, so a stalled upstream pins an lsphp worker for the whole window;
 * with only a handful of workers on prod, an 8s timeout during per-keystroke
 * autocomplete traffic can starve them. Keep the ceiling low so a provider
 * slowdown can't hold a worker for long.
 */
class MapsProviderTimeoutTest extends TestCase
{
    public static function providerClasses(): array
    {
        return [
            'osm' => [OsmGeocodeProvider::class],
            'google' => [GoogleGeocodeProvider::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providerClasses')]
    public function test_http_timeout_is_bounded(string $class): void
    {
        $timeout = new ReflectionClassConstant($class, 'HTTP_TIMEOUT')->getValue();

        $this->assertIsInt($timeout, "$class::HTTP_TIMEOUT should be an int (seconds).");
        $this->assertLessThanOrEqual(
            5,
            $timeout,
            "$class::HTTP_TIMEOUT must stay <=5s so a stalled geocode upstream can't pin a worker."
        );
        $this->assertGreaterThanOrEqual(
            2,
            $timeout,
            "$class::HTTP_TIMEOUT must be >=2s so normal geocode latency isn't cut off."
        );
    }
}
