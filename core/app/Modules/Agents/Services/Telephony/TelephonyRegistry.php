<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;


/** T6: telephony provider registry. */
class TelephonyRegistry
{
    /** @var array<string, TelephonyProvider> */
    private static array $providers = [];

    public static function register(TelephonyProvider $provider): void
    {
        self::$providers[$provider->key()] = $provider;
    }

    public static function get(string $key): ?TelephonyProvider
    {
        self::boot();
        return self::$providers[$key] ?? null;
    }

    private static function boot(): void
    {
        foreach ([
            'twilio' => TwilioTelephonyProvider::class,
            'telnyx' => TelnyxTelephonyProvider::class,
            'fake'   => FakeTelephonyProvider::class,
        ] as $key => $class) {
            if (!isset(self::$providers[$key]) && class_exists($class)) {
                self::$providers[$key] = new $class();
            }
        }
    }
}
