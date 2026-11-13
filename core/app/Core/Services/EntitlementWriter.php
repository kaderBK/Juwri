<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use Juwri\Core\Events\FeatureGranted;
use Juwri\Core\Events\FeatureRevoked;
use Juwri\Core\Models\EntitlementAudit;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P1 (feature-platform, SPEC §2): the ONE writer for out-of-plan grants.
 * Every grant/revoke is transactional, audited (entitlement_audit) and
 * evented (FeatureGranted/FeatureRevoked) so cache-busting, webhooks and
 * reconciliation can hang off it. Plan grants keep flowing through
 * SubscriptionService/mirrorPlanRows — this writer covers overrides only
 * (admin grants, promos, app installs, boolean add-ons).
 */
class EntitlementWriter
{
    /**
     * Grant a feature to a user. Idempotent per (user, key, source): an
     * existing row from the same source is refreshed, not duplicated.
     */
    public static function grant(
        User $user,
        string $featureKey,
        string $sourceType = 'admin',
        ?int $sourceId = null,
        ?string $value = '1',
        ?\DateTimeInterface $expiresAt = null,
        ?string $reason = null,
        ?int $actorId = null,
    ): EntitlementOverride {
        return DB::transaction(function () use ($user, $featureKey, $sourceType, $sourceId, $value, $expiresAt, $reason, $actorId) {
            $existing = EntitlementOverride::query()
                ->where('user_id', $user->id)
                ->where('feature_key', $featureKey)
                ->where('source_type', $sourceType)
                ->when($sourceId !== null, fn ($q) => $q->where('source_id', $sourceId))
                ->lockForUpdate()
                ->first();

            $before = $existing?->only(['value', 'expires_at', 'reason']);

            $override = EntitlementOverride::updateOrCreate(
                ['user_id' => $user->id, 'feature_key' => $featureKey, 'source_type' => $sourceType, 'source_id' => $sourceId],
                ['value' => $value ?? '1', 'expires_at' => $expiresAt, 'reason' => $reason, 'granted_by' => $actorId],
            );

            EntitlementAudit::create([
                'user_id' => $user->id, 'feature_key' => $featureKey, 'action' => 'grant',
                'source_type' => $sourceType, 'source_id' => $sourceId, 'actor_id' => $actorId,
                'before' => $before, 'after' => $override->only(['value', 'expires_at', 'reason']),
            ]);

            FeatureGranted::dispatch($user->id, $featureKey, $sourceType, $sourceId);

            return $override;
        });
    }

    /** Revoke every override for (user, key) from a source. Returns rows removed. */
    public static function revoke(
        User $user,
        string $featureKey,
        string $sourceType = 'admin',
        ?int $sourceId = null,
        ?string $reason = null,
        ?int $actorId = null,
    ): int {
        return DB::transaction(function () use ($user, $featureKey, $sourceType, $sourceId, $reason, $actorId) {
            $rows = EntitlementOverride::query()
                ->where('user_id', $user->id)
                ->where('feature_key', $featureKey)
                ->where('source_type', $sourceType)
                ->when($sourceId !== null, fn ($q) => $q->where('source_id', $sourceId))
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                EntitlementAudit::create([
                    'user_id' => $user->id, 'feature_key' => $featureKey, 'action' => 'revoke',
                    'source_type' => $sourceType, 'source_id' => $sourceId, 'actor_id' => $actorId,
                    'before' => $row->only(['value', 'expires_at', 'reason']), 'after' => ['reason' => $reason],
                ]);
                $row->delete();
            }

            if ($rows->isNotEmpty()) {
                FeatureRevoked::dispatch($user->id, $featureKey, $sourceType, $sourceId);
            }

            return $rows->count();
        });
    }
}
