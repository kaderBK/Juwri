<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\MobilePush;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Messaging\Models\PushSubscription;

/**
 * PARITY-RUN T9 — BYOK resolution for native push (FCM).
 *
 * Resolution order is the vault's own (Credential::for): merchant override →
 * platform default → config/env. Two shapes are understood:
 *
 *   v1     — a Firebase SERVICE ACCOUNT (project_id + client_email + private_key),
 *            the only shape Google still issues. Sends via FCM HTTP v1.
 *   legacy — the retired `server_key`. Kept because config/services.php already
 *            documents it and an install may still carry one in .env.
 *
 * NOTHING here ever returns a secret to a caller that renders it: {@see status()}
 * is what the settings screen reads, and it exposes only booleans plus the
 * project id. A credential must never reach a response body or a log line.
 */
class PushCredentials
{
    /** Vault feature name — one row per restaurant (plus a platform-wide default). */
    public const FEATURE = 'mobile_push';

    public const MODE_V1 = 'v1';

    public const MODE_LEGACY = 'legacy';

    public const MODE_NONE = 'none';

    /**
     * Effective credentials for a store.
     *
     * @return array{mode:string, project_id:?string, client_email:?string, private_key:?string, server_key:?string}
     */
    public static function forStore(?int $storeId): array
    {
        return self::resolve(Credential::for(self::FEATURE, $storeId) ?: []);
    }

    /**
     * The PLATFORM credential only (vault row with store_id NULL → config/env),
     * never a store's BYOK row. The owner app's device tokens belong to the
     * platform's Firebase project, so a store's own service account (meant for the
     * staff / driver apps it builds) can never deliver to them.
     *
     * @return array{mode:string, project_id:?string, client_email:?string, private_key:?string, server_key:?string}
     */
    public static function platform(): array
    {
        return self::resolve(Credential::for(self::FEATURE, null) ?: []);
    }

    /**
     * Credentials for one native device: an OWNER app device (registered by a
     * dashboard user, no staff member) always uses the platform credential; staff
     * and driver devices keep the store's BYOK resolution.
     *
     * @return array{mode:string, project_id:?string, client_email:?string, private_key:?string, server_key:?string}
     */
    public static function forDevice(PushSubscription $sub): array
    {
        if (self::isOwnerDevice($sub)) {
            return self::platform();
        }

        return self::forStore($sub->store_id ? (int) $sub->store_id : null);
    }

    /** An owner-app device: registered for a dashboard user, not for a staff member. */
    public static function isOwnerDevice(PushSubscription $sub): bool
    {
        return $sub->user_id !== null && $sub->staff_id === null;
    }

    /**
     * Safe projection of the platform credential for the admin screen: mode,
     * project id and the service account's client email — never the key.
     *
     * @return array{mode:string, configured:bool, project_id:?string, client_email:?string, stored:bool}
     */
    public static function platformStatus(): array
    {
        $creds = self::platform();

        return [
            'mode' => $creds['mode'],
            'configured' => $creds['mode'] !== self::MODE_NONE,
            'project_id' => $creds['project_id'],
            'client_email' => $creds['client_email'],
            'stored' => Credential::whereNull('store_id')->where('feature', self::FEATURE)->exists(),
        ];
    }

    /**
     * @param  array<string, mixed>  $secrets
     * @return array{mode:string, project_id:?string, client_email:?string, private_key:?string, server_key:?string}
     */
    private static function resolve(array $secrets): array
    {
        $projectId = trim((string) ($secrets['project_id'] ?? config('services.fcm.project_id', '')));
        $clientEmail = trim((string) ($secrets['client_email'] ?? ''));
        $privateKey = (string) ($secrets['private_key'] ?? '');
        $serverKey = trim((string) ($secrets['server_key'] ?? config('services.fcm.server_key', '')));

        if ($projectId !== '' && $clientEmail !== '' && trim($privateKey) !== '') {
            return [
                'mode' => self::MODE_V1,
                'project_id' => $projectId,
                'client_email' => $clientEmail,
                'private_key' => $privateKey,
                'server_key' => null,
            ];
        }

        if ($serverKey !== '') {
            return [
                'mode' => self::MODE_LEGACY, 'project_id' => $projectId ?: null,
                'client_email' => null, 'private_key' => null, 'server_key' => $serverKey,
            ];
        }

        return [
            'mode' => self::MODE_NONE, 'project_id' => null,
            'client_email' => null, 'private_key' => null, 'server_key' => null,
        ];
    }

    /** True when this store can actually deliver a native push right now. */
    public static function configured(?int $storeId): bool
    {
        return self::forStore($storeId)['mode'] !== self::MODE_NONE;
    }

    /**
     * The safe projection for the settings UI: no secret material, ever.
     *
     * @return array{mode:string, configured:bool, project_id:?string, own_credentials:bool}
     */
    public static function status(?int $storeId): array
    {
        $creds = self::forStore($storeId);
        $own = $storeId
            ? Credential::where('store_id', $storeId)
                ->where('feature', self::FEATURE)->where('active', true)->exists()
            : false;

        return [
            'mode' => $creds['mode'],
            'configured' => $creds['mode'] !== self::MODE_NONE,
            'project_id' => $creds['project_id'],
            'own_credentials' => $own,
        ];
    }

    /**
     * Parse an uploaded Firebase service-account JSON into vault secrets.
     * Returns null when the blob is not a usable service account — the caller
     * turns that into a validation error WITHOUT echoing the input back.
     *
     * @return array{project_id:string, client_email:string, private_key:string}|null
     */
    public static function parseServiceAccount(string $json): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        $projectId = trim((string) ($data['project_id'] ?? ''));
        $clientEmail = trim((string) ($data['client_email'] ?? ''));
        $privateKey = (string) ($data['private_key'] ?? '');

        if ($projectId === '' || $clientEmail === '' || trim($privateKey) === '') {
            return null;
        }

        // Some paste paths escape the newlines out of the PEM; put them back or
        // openssl_sign silently refuses the key.
        $privateKey = str_replace('\\n', "\n", $privateKey);

        if (! str_contains($privateKey, 'PRIVATE KEY')) {
            return null;
        }

        return ['project_id' => $projectId, 'client_email' => $clientEmail, 'private_key' => $privateKey];
    }
}
