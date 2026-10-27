<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services;

use Juwri\Core\Models\User;
use Juwri\Core\Services\AbuseGuard;
use Juwri\Core\Services\AbuseHold;
use Juwri\Modules\Ai\Models\AiUsage;

/**
 * Abuse Guard P3 (SPEC §2): denial-of-wallet protection for platform-key AI calls.
 *
 * Consulted from AiService before each provider call. BYOK calls bypass every cap
 * (they burn the owner's wallet, not ours) but are still metered elsewhere. Each
 * would-be block routes through AbuseGuard::hit('ai_spend', …) so WARN logs+allows
 * and ENFORCE throws the existing AiLimitExceededException quota shape.
 *
 * "Credits" are counted as AiUsage rows (1 call = 1 credit), consistent with the
 * pre-existing monthly-call gate.
 */
class AiSpendGuard
{
    public const GUARD = 'ai_spend';

    /**
     * @throws AiLimitExceededException in enforce mode when a cap is crossed.
     */
    public static function assert(?int $userId, ?int $storeId, bool $isByok): void
    {
        // Abuse hold freezes AI regardless of guard mode (admin-initiated).
        if ($userId && AbuseHold::active($userId)) {
            throw new AiLimitExceededException('AI is paused while your account is under review.');
        }

        if (AbuseGuard::mode(self::GUARD) === AbuseGuard::OFF) {
            return;
        }

        // Platform-wide kill switch first — applies to platform-key calls only.
        if (! $isByok) {
            $platformCap = (int) AbuseGuard::value(self::GUARD, 'platform_daily_cap', 10000);
            if ($platformCap > 0 && self::platformUsedToday() >= $platformCap) {
                self::deny('platform_daily_cap', $userId, ['cap' => $platformCap]);
            }
        }

        if (! $userId || $isByok) {
            return; // guest/system or BYOK — no per-user/tenant caps
        }

        // Per-user daily cap.
        $userCap = (int) AbuseGuard::value(self::GUARD, 'user_daily_cap', 200);
        $userUsed = self::userUsedToday($userId);
        if ($userCap > 0 && $userUsed >= $userCap) {
            self::deny('user_daily_cap', $userId, ['cap' => $userCap, 'used' => $userUsed]);
        }

        // Per-tenant (reseller) daily cap.
        $resellerId = self::resellerIdForUser($userId);
        if ($resellerId) {
            $tenantCap = (int) AbuseGuard::value(self::GUARD, 'tenant_daily_cap', 1000);
            $tenantUsed = self::tenantUsedToday($resellerId);
            if ($tenantCap > 0 && $tenantUsed >= $tenantCap) {
                self::deny('tenant_daily_cap', $userId, ['reseller_id' => $resellerId, 'cap' => $tenantCap, 'used' => $tenantUsed]);
            }
        }

        // Anomaly cutoff: today >> the user's own trailing-7-day daily average.
        $mult = (float) AbuseGuard::value(self::GUARD, 'anomaly_multiplier', 10);
        if ($mult > 0) {
            $avg = self::userAvgDaily($userId, 7);
            if ($avg >= 1 && $userUsed > $avg * $mult) {
                self::deny('anomaly', $userId, ['used' => $userUsed, 'avg7d' => round($avg, 2), 'x' => $mult]);
            }
        }
    }

    /** Reject an over-length prompt before it ever reaches a provider. */
    public static function assertInputLength(array $messages, ?int $userId): void
    {
        if (AbuseGuard::mode(self::GUARD) === AbuseGuard::OFF) {
            return;
        }
        $max = (int) AbuseGuard::value(self::GUARD, 'max_input_chars', 40000);
        if ($max <= 0) {
            return;
        }
        $chars = 0;
        foreach ($messages as $m) {
            $c = $m['content'] ?? '';
            $chars += is_string($c) ? mb_strlen($c) : mb_strlen(json_encode($c));
        }
        if ($chars > $max) {
            self::deny('input_too_long', $userId, ['chars' => $chars, 'max' => $max]);
        }
    }

    /** Default max output tokens forced onto every provider call. 0 = unset. */
    public static function maxTokens(): int
    {
        return (int) AbuseGuard::value(self::GUARD, 'max_tokens', 2048);
    }

    protected static function deny(string $action, ?int $userId, array $meta): void
    {
        $allow = AbuseGuard::hit(self::GUARD, $action, ['user_id' => $userId, 'meta' => $meta]);
        if (! $allow) {
            throw new AiLimitExceededException;
        }
    }

    protected static function userUsedToday(int $userId): int
    {
        return AiUsage::where('user_id', $userId)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    protected static function platformUsedToday(): int
    {
        return AiUsage::where('created_at', '>=', now()->startOfDay())->count();
    }

    protected static function tenantUsedToday(int $resellerId): int
    {
        return AiUsage::whereIn('user_id', self::tenantUserIds($resellerId))
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    protected static function userAvgDaily(int $userId, int $days): float
    {
        $total = AiUsage::where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->where('created_at', '<', now()->startOfDay())
            ->count();

        return $total / max(1, $days);
    }

    protected static function resellerIdForUser(int $userId): ?int
    {
        $rid = User::whereKey($userId)->value('reseller_id');

        return $rid ? (int) $rid : null;
    }

    protected static function tenantUserIds(int $resellerId): array
    {
        return User::where('reseller_id', $resellerId)->pluck('id')->all();
    }
}
