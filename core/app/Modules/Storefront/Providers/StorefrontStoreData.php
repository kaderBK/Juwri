<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Providers;

use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\StoreData;
use Juwri\Core\Contracts\Tenancy\StoreTable;
use Juwri\Core\Contracts\Tenancy\StoreTransferContext;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;

/**
 * P1-21 (J29-8): the Storefront module's store-owned tables for the store-data registry.
 *
 *  - store_domains: custom domains stay attached but the new owner proves them again — unverified, a fresh TXT
 *    token (the previous owner's record no longer matches), SSL back to pending (audit key `domains`). Platform
 *    subdomains are not the owner's DNS and stay as they are. Never copied.
 *  - storefront_tokens: the previous owner's storefront API tokens are switched off (kept for history). Never copied.
 *  - storefront_api_idempotency (P3-06b): stored answers of the headless API's writes for 24 h (a customer OTP
 *    answer holds a sign-in token) — deleted on a transfer, never exported or copied.
 *  - Everything else (theme, blocks, banners, stories, redirects, stats) follows the store; not copied by a
 *    duplicate in part 1 (today's set).
 */
final class StorefrontStoreData extends StoreData
{
    public function tables(): array
    {
        return [
            StoreTable::data('ai_theme_proposals'),
            StoreTable::data('folio_page_stats'),
            StoreTable::data('menu_banners')->media(['image' => ['public:storage/menu/']]), // P4-07: library images (export + erasure)
            StoreTable::data('slug_redirects'),
            StoreTable::data('store_custom_blocks'),
            StoreTable::connection('store_domains', self::unverifyDomains(...), 'domains'),
            StoreTable::connection('storefront_tokens', fn (ConnectionInterface $db, StoreTransferContext $ctx): int => $db->table('storefront_tokens')->where('store_id', $ctx->storeId)->update(['active' => false]))
                ->secret(['token' => fn (): string => hash('sha256', Str::random(40))]),
            StoreTable::connection('storefront_api_idempotency', fn (ConnectionInterface $db, StoreTransferContext $ctx): int => $db->table('storefront_api_idempotency')->where('store_id', $ctx->storeId)->delete())
                ->notExported(),
            StoreTable::data('stories')->media(['media_url' => ['public:storage/menu/']]),
            StoreTable::data('template_rollout_stores'),
            StoreTable::data('theme_setting_versions'),
            StoreTable::data('theme_settings'),
        ];
    }

    /** Cached storefront pages and menus carry the previous owner's tracking ids and payment options. */
    public function forgetStoreCaches(int $storeId): void
    {
        StorefrontMenuCache::bust($storeId);
    }

    /** @return list<string> the custom domains that must be verified again */
    public static function unverifyDomains(ConnectionInterface $db, StoreTransferContext $ctx): array
    {
        $domains = $db->table('store_domains')->where('store_id', $ctx->storeId)->where('type', 'custom')->orderBy('id')->get(['id', 'host']);
        foreach ($domains as $domain) {
            $db->table('store_domains')->where('id', $domain->id)->update([
                'verified_at' => null,
                'verification_token' => 'mb-verify-'.Str::lower(Str::random(24)),
                'ssl_status' => 'pending',
                // P6-241: no longer the primary address, and the pending grace starts NOW — with the old created_at the
                // row counted as long-expired, so any other store could purge it and take the host at once.
                'is_primary' => false,
                'created_at' => now(),
                'dns_failing_since' => null,
            ]);
        }
        if ($domains->isNotEmpty()) {
            // Query-builder writes skip the model events: drop the memoised host → store map and the TrustHosts
            // list so the unverified domains stop serving in this process too.
            if (app()->resolved(StoreAddressResolver::class)) {
                app(StoreAddressResolver::class)->flush();
            }
            Cache::forget('trusted_custom_domains');
        }

        return $domains->pluck('host')->map(fn ($h) => (string) $h)->values()->all();
    }
}
