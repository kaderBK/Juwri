<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Messaging\Contracts\MessageCosts;
use Juwri\Modules\Messaging\Models\MessageCost;

/**
 * P6-09 V-12: one `message_costs` row per finished call (channel `voice`, feature `phone_agent`), written from the
 * provider's final call duration. Units = billed minutes (every started minute counts); cost = units × the configured
 * all-in serving cost per minute. Idempotent per call SID (a replayed status callback writes nothing).
 *
 * {@see monthUsage()} compares the store's minutes this calendar month with the add-on plan (spec §11: price incl.
 * N minutes, then a per-minute overage) — what the owner pays juwri, next to what the minutes cost us.
 */
final class VoiceCostLedger
{
    public const FEATURE = 'phone_agent';

    public static function minutes(int $seconds): int
    {
        return $seconds <= 0 ? 0 : (int) ceil($seconds / 60);
    }

    public static function recordCall(CallLog $log): ?MessageCost
    {
        $minutes = self::minutes((int) $log->duration_seconds);
        if ($minutes === 0 || (string) $log->call_sid === '') {
            return null;
        }
        $existing = MessageCost::query()
            ->where('channel', 'voice')
            ->where('store_id', $log->store_id)
            ->where('provider_ref', $log->call_sid)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return app(MessageCosts::class)->record(
            channel: 'voice',
            category: 'service',
            units: $minutes,
            storeId: (int) $log->store_id,
            accountId: (int) (Store::query()->whereKey($log->store_id)->value('account_id') ?? 0) ?: null,
            provider: (string) $log->provider,
            feature: self::FEATURE,
            providerRef: (string) $log->call_sid,
            unitCostMajor: (float) config('voice.cost_per_minute', 0.12),
            currency: (string) config('voice.cost_currency', 'USD'),
        );
    }

    /**
     * The store's phone minutes this calendar month against the plan.
     *
     * @return array{minutes: int, included: int, overage_minutes: int, plan_price: float, overage_charge: float, cost: float, currency: string}
     */
    public static function monthUsage(int $storeId): array
    {
        $rows = MessageCost::query()
            ->where('channel', 'voice')
            ->where('store_id', $storeId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->get(['units', 'cost_minor', 'currency']);
        $minutes = (int) $rows->sum('units');
        $included = max(0, (int) config('voice.plan_included_minutes', 600));
        $overage = max(0, $minutes - $included);
        $currency = strtoupper((string) config('voice.cost_currency', 'USD'));
        $costMinor = (float) $rows->where('currency', $currency)->sum(fn ($r) => (float) $r->cost_minor);

        return [
            'minutes' => $minutes,
            'included' => $included,
            'overage_minutes' => $overage,
            'plan_price' => (float) config('voice.plan_price', 149),
            'overage_charge' => round($overage * (float) config('voice.overage_per_minute', 0.20), 2),
            'cost' => round($costMinor / (10 ** Currencies::exponent($currency)), 2),
            'currency' => $currency,
        ];
    }
}
