<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services;

use Illuminate\Support\Facades\Cache;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\AbuseGuard;

/**
 * Abuse Guard (edge): per-tenant daily cap on BULK/marketing message sends
 * (WhatsApp + SMS campaigns, broadcasts, cart recovery). Transactional sends
 * (order/status) are never capped. Routes through AbuseGuard::hit('messaging', …):
 * WARN logs+allows, ENFORCE drops the message. Cache-counter — no per-send DB write.
 */
class MessagingGuard
{
    public const GUARD = 'messaging';

    /** True = send may proceed. Counts one marketing message toward today's cap. */
    public static function allow(?int $storeId, string $channel = 'wa'): bool
    {
        if (AbuseGuard::mode(self::GUARD) === AbuseGuard::OFF) {
            return true;
        }

        $ownerId = self::ownerId($storeId);
        if (! $ownerId) {
            return true;
        }

        $cap = (int) AbuseGuard::value(self::GUARD, 'tenant_daily_cap', 5000);
        if ($cap <= 0) {
            return true;
        }

        $key = 'abuse:messaging:'.now()->toDateString().':'.$ownerId;
        $sent = (int) Cache::get($key, 0);

        if ($sent >= $cap) {
            if (! AbuseGuard::hit(self::GUARD, 'daily_cap', [
                'user_id' => $ownerId,
                'meta' => ['channel' => $channel, 'sent' => $sent, 'cap' => $cap],
            ])) {
                return false;
            }
        }

        Cache::put($key, $sent + 1, now()->addHours(36));

        return true;
    }

    protected static function ownerId(?int $storeId): ?int
    {
        if (! $storeId) {
            return null;
        }

        return (int) (Store::where('id', $storeId)->value('user_id') ?: 0) ?: null;
    }
}
