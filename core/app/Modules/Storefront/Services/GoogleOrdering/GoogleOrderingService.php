<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\GoogleOrdering;

use Juwri\Core\Settings\StoreSettings;

/**
 * P5-20 (ROADMAP 24 part 2) — Google Ordering Redirect config gate.
 *
 * Single source of truth for the `services.google_ordering` block. Every external effect
 * (the feed SFTP drop) sits behind these checks so a creds-less / dark environment produces
 * zero HTTP, zero sockets and leaves the artifacts for inspection only.
 *
 * Two switches, both default OFF:
 *  - the platform kill-switch (`services.google_ordering.enabled`), and
 *  - the per-store owner switch (`google_ordering_enabled`, read the legacy '1'/'0' form
 *    exactly like Reservations' RwgMerchantSource reads `rwg_reservations_enabled`).
 */
final class GoogleOrderingService
{
    /** Master switch AND kill-switch. Off ⇒ feeds are skipped (dark no-op). */
    public static function enabled(): bool
    {
        return (bool) config('services.google_ordering.enabled', false);
    }

    /** fake|real. `real` lets the feeds command attempt SFTP; `fake` never opens a socket. */
    public static function driver(): string
    {
        return (string) config('services.google_ordering.driver', 'fake');
    }

    public static function isReal(): bool
    {
        return self::driver() === 'real';
    }

    /**
     * Per-store owner switch (`google_ordering_enabled`, legacy string). Default off: an
     * absent or non-'1' value is never opted in.
     */
    public static function storeEnabled(int $storeId): bool
    {
        return StoreSettings::legacy($storeId, 'google_ordering_enabled') === '1';
    }

    /**
     * Ids of every store whose owner opted in (the canonical eligible set, one read).
     *
     * @return list<int>
     */
    public static function storeIds(): array
    {
        $ids = array_map('intval', StoreSettings::storeIdsWhere('google_ordering_enabled', fn ($v) => $v === '1'));

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    /**
     * SFTP config for the daily feed drop. Empty host ⇒ feeds are written to
     * storage/app/google-ordering/ for inspection but never uploaded.
     *
     * @return array{host:string,port:int,user:string,key:string,path:string}
     */
    public static function sftp(): array
    {
        return [
            'host' => (string) config('services.google_ordering.sftp_host', ''),
            'port' => (int) config('services.google_ordering.sftp_port', 22),
            'user' => (string) config('services.google_ordering.sftp_user', ''),
            'key'  => (string) config('services.google_ordering.sftp_key', ''),
            'path' => (string) config('services.google_ordering.sftp_path', '/'),
        ];
    }

    public static function sftpConfigured(): bool
    {
        $s = self::sftp();

        return $s['host'] !== '' && $s['user'] !== '';
    }

    public static function sftpAttempts(): int
    {
        return max(1, (int) config('services.google_ordering.sftp_attempts', 3));
    }

    /** Max entries per feed shard; a merchant's entries always stay together. */
    public static function feedShardSize(): int
    {
        return max(1, (int) config('services.google_ordering.feed_shard_size', 20000));
    }

    /**
     * The UTM pair stamped on every action link (so an order through Google attributes).
     *
     * @return array{source:string,medium:string}
     */
    public static function utm(): array
    {
        return [
            'source' => (string) config('services.google_ordering.utm_source', 'google'),
            'medium' => (string) config('services.google_ordering.utm_medium', 'ordering_redirect'),
        ];
    }
}
