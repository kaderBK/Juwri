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
        // AUDIT-authz #5: the fake provider's verifyWebhook() is always true, so it
        // only exists in local/testing — never a live, unsigned webhook in production.
        if ($key === 'fake' && ! app()->environment('local', 'testing')) {
            return null;
        }
        self::boot();

        return self::$providers[$key] ?? null;
    }

    private static function boot(): void
    {
        foreach ([
            'twilio' => TwilioTelephonyProvider::class,
            'telnyx' => TelnyxTelephonyProvider::class,
            'fake' => FakeTelephonyProvider::class,
        ] as $key => $class) {
            if (! isset(self::$providers[$key]) && class_exists($class)) {
                self::$providers[$key] = new $class;
            }
        }
    }
}
