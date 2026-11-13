<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;

class PushSubscription extends Model
{
    use BelongsToAccount;

    protected $table = 'push_subscriptions';

    protected $guarded = [];

    /* T9: this model had no casts at all, so `failed_at` and the new
       `last_seen_at` came back as raw strings and any ->format() on them fataled. */
    protected $casts = [
        'failed_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'notify_new_order' => 'boolean',
    ];

    /**
     * Store (or refresh) a subscription, deduped by endpoint.
     */
    public static function store(array $subscription, int $storeId, ?int $staffId = null): self
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');

        return static::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'staff_id' => $staffId,
                // A staff (re)subscription is never on the owner lane, even when the
                // endpoint was an owner's before (PWA-SPEC §3.3).
                'user_id' => null,
                'store_id' => $storeId,
                'platform' => 'web',
                'endpoint' => $endpoint,
                'public_key' => $subscription['keys']['p256dh'] ?? null,
                'auth_token' => $subscription['keys']['auth'] ?? null,
                'content_encoding' => $subscription['contentEncoding'] ?? 'aesgcm',
            ]
        );
    }

    /**
     * Owner dashboard PWA: a browser web-push subscription that belongs to an
     * OWNER (`user_id`), not a staff member. Deduped by endpoint; re-subscribing
     * moves it to the dashboard's current store and revives a pruned row.
     *
     * @param  array<string, mixed>  $subscription
     * @param  array<string, mixed>  $meta
     */
    public static function storeWeb(array $subscription, int $storeId, int $userId, array $meta = []): self
    {
        $endpoint = (string) ($subscription['endpoint'] ?? '');

        return static::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'staff_id' => null,
                'user_id' => $userId,
                'store_id' => $storeId,
                'platform' => 'web',
                'endpoint' => $endpoint,
                'public_key' => $subscription['keys']['p256dh'] ?? null,
                'auth_token' => $subscription['keys']['auth'] ?? null,
                'content_encoding' => $subscription['contentEncoding'] ?? 'aes128gcm',
                'notify_new_order' => true,
                'device_name' => isset($meta['device_name']) ? mb_substr((string) $meta['device_name'], 0, 80) : null,
                'last_seen_at' => now(),
                'failed_at' => null,
            ]
        );
    }

    /**
     * T8-P0: register a native app device token (FCM/APNs). Stored in the same table so
     * the StaffPushService fan-out reaches it; the token lives in `endpoint`.
     *
     * PARITY-RUN T9 additions:
     *  - `$userId` — the owner-app lane. A staff install carries `staff_id`, an
     *    owner install carries `user_id`; the device list shows both.
     *  - `$meta` — device_name / app_version / os_version, so an owner can tell
     *    "Ahmed's iPhone" from "the kitchen tablet" before revoking one.
     *  - `last_seen_at` is refreshed on every registration; the wrapper
     *    re-registers on each cold start, so that is the app's heartbeat.
     *  - `failed_at` is CLEARED: a token that registers again is alive again, and
     *    without this one transient prune would mute a device forever.
     *
     * `platform` records the DEVICE OS, not the token type. The wrapper uses
     *
     * `@capacitor-firebase/messaging`, so an `apns` row still holds an FCM
     * registration token — FCM rejects raw APNs tokens.
     */
    public static function storeNative(
        string $token,
        string $platform,
        int $storeId,
        ?int $staffId = null,
        ?int $userId = null,
        array $meta = []
    ): self {
        return static::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $token)],
            [
                'staff_id' => $staffId,
                'user_id' => $userId,
                'store_id' => $storeId,
                'platform' => in_array($platform, ['fcm', 'apns'], true) ? $platform : 'fcm',
                'endpoint' => $token,
                'device_name' => isset($meta['device_name']) ? mb_substr((string) $meta['device_name'], 0, 80) : null,
                'app_version' => isset($meta['app_version']) ? mb_substr((string) $meta['app_version'], 0, 24) : null,
                'os_version' => isset($meta['os_version']) ? mb_substr((string) $meta['os_version'], 0, 24) : null,
                'last_seen_at' => now(),
                'failed_at' => null,
                // P6-269: the app sign-in this device registered under; re-registration
                // always reflects the current registrant.
                'api_token_id' => isset($meta['api_token_id']) ? (int) $meta['api_token_id'] : null,
                'staff_token_id' => isset($meta['staff_token_id']) ? (int) $meta['staff_token_id'] : null,
                'driver_id' => isset($meta['driver_id']) ? (int) $meta['driver_id'] : null,
            ]
        );
    }

    /**
     * P6-269 revocation helpers. Keyed by globally unique ids, so they run without
     * the account scope (API/listener contexts may have no tenant bound).
     */
    public static function revokeForApiToken(int $apiTokenId): int
    {
        return static::withoutGlobalScopes()->where('api_token_id', $apiTokenId)->delete();
    }

    /** Every owner-app (native) device of a user, except the one tied to $keepApiTokenId. */
    public static function revokeOwnerApps(int $userId, ?int $keepApiTokenId = null): int
    {
        return static::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereIn('platform', ['fcm', 'apns'])
            ->when($keepApiTokenId, fn ($q, $keep) => $q->where(fn ($w) => $w->whereNull('api_token_id')->orWhere('api_token_id', '!=', $keep)))
            ->delete();
    }

    public static function revokeForStaffToken(int $staffTokenId): int
    {
        return static::withoutGlobalScopes()->where('staff_token_id', $staffTokenId)->delete();
    }

    /** Every device (web + native) of a staff member. */
    public static function revokeForStaff(int $staffId): int
    {
        return static::withoutGlobalScopes()->where('staff_id', $staffId)->delete();
    }

    public static function revokeForDriver(int $driverId): int
    {
        return static::withoutGlobalScopes()->where('driver_id', $driverId)->delete();
    }

    /** T9: a native app device, as opposed to a browser web-push subscription. */
    public function isNative(): bool
    {
        return in_array((string) ($this->platform ?? 'web'), ['fcm', 'apns'], true);
    }

    /** T9: what the device list shows when the app sent no device name. */
    public function displayName(): string
    {
        $name = trim((string) $this->device_name);
        if ($name !== '') {
            return $name;
        }

        return $this->platform === 'apns' ? 'iOS' : ($this->platform === 'fcm' ? 'Android' : 'Web');
    }
}
