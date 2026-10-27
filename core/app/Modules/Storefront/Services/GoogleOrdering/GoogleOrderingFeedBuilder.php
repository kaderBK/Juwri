<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\GoogleOrdering;

use Carbon\CarbonInterface;

/**
 * P5-20 — Google Ordering Redirect feed framework.
 *
 * Runs the three builders (Merchants / Services / Menu), and gives the command the shared
 * envelope (`metadata`), a stable sort and the merchant-aware sharding the Actions Center
 * generic-feed format expects. Shaped after Reservations' RwgFeedBuilder so the two feed
 * families stay recognisably one thing.
 */
final class GoogleOrderingFeedBuilder
{
    private MerchantFeedBuilder $merchants;

    private ServiceFeedBuilder $services;

    private MenuFeedBuilder $menu;

    public function __construct(
        ?MerchantFeedBuilder $merchants = null,
        ?ServiceFeedBuilder $services = null,
        ?MenuFeedBuilder $menu = null,
    ) {
        $this->merchants = $merchants ?? app(MerchantFeedBuilder::class);
        $this->services = $services ?? app(ServiceFeedBuilder::class);
        $this->menu = $menu ?? app(MenuFeedBuilder::class);
    }

    /**
     * The folder the feed artifacts live in. Configurable so tests can point each parallel
     * process at its own temp folder; unset means storage/app/google-ordering.
     */
    public static function feedDir(): string
    {
        return rtrim(config('filesystems.google_ordering_feeds_path') ?: storage_path('app/google-ordering'), '/');
    }

    /**
     * Build every feed.
     *
     * @return array<string, array<string, mixed>> filename => feed payload
     */
    public function buildAll(): array
    {
        $out = [];
        foreach ([$this->merchants, $this->services, $this->menu] as $builder) {
            $out[$builder->filename()] = $builder->build();
        }

        return $out;
    }

    /**
     * Stable order so two runs over the same data produce byte-identical entries (diff-able,
     * cache-friendly): merchants by id, services by merchant then service, menus by merchant.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sorted(string $filename, array $payload): array
    {
        if (isset($payload['merchant'])) {
            usort($payload['merchant'], static fn (array $a, array $b): int => strnatcmp((string) ($a['merchant_id'] ?? ''), (string) ($b['merchant_id'] ?? '')));
        }
        if (isset($payload['service'])) {
            usort($payload['service'], static fn (array $a, array $b): int => strnatcmp((string) ($a['merchant_id'] ?? ''), (string) ($b['merchant_id'] ?? '')) ?: strnatcmp((string) ($a['service_id'] ?? ''), (string) ($b['service_id'] ?? '')));
        }
        if (isset($payload['menu'])) {
            usort($payload['menu'], static fn (array $a, array $b): int => strnatcmp((string) ($a['merchant_id'] ?? ''), (string) ($b['merchant_id'] ?? '')));
        }

        return $payload;
    }

    /**
     * Split one feed into shards of at most $size entries. A merchant's entries always stay
     * together and always land in the same shard for a given shard count (crc32 of its id),
     * so a feed that grows by one store does not reshuffle everything. One shard (the usual
     * case) keeps the plain file name.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>> file name => shard payload
     */
    public static function shard(string $filename, array $payload, int $size): array
    {
        $key = self::entityKey($payload);
        if ($key === null) {
            return [$filename => $payload];
        }
        $list = array_values((array) $payload[$key]);
        $count = count($list);
        $total = max(1, (int) ceil($count / max(1, $size)));
        if ($total === 1) {
            $payload['metadata'] = ['shard_number' => 0, 'total_shards' => 1] + ($payload['metadata'] ?? []);

            return [$filename => $payload];
        }
        $buckets = array_fill(0, $total, []);
        foreach ($list as $entry) {
            $buckets[crc32((string) ($entry['merchant_id'] ?? '')) % $total][] = $entry;
        }
        $out = [];
        foreach ($buckets as $n => $entries) {
            $shard = $payload;
            $shard[$key] = $entries;
            $shard['metadata'] = ['shard_number' => $n, 'total_shards' => $total] + ($payload['metadata'] ?? []);
            $out[preg_replace('/\.json$/', '', $filename).'.shard-'.($n + 1).'-of-'.$total.'.json'] = $shard;
        }

        return $out;
    }

    /**
     * Actions Center generic-feed metadata block. Every feed carries one.
     * PROCESS_AS_COMPLETE = a full daily snapshot (not a delta).
     *
     * @return array<string, mixed>
     */
    public static function metadata(?CarbonInterface $now = null): array
    {
        $ts = $now ? $now->getTimestamp() : now()->getTimestamp();

        return [
            'processing_instruction' => 'PROCESS_AS_COMPLETE',
            'shard_number'           => 0,
            'total_shards'           => 1,
            'nonce'                  => $ts, // monotonic per run; feeds are full snapshots
            'generation_timestamp'   => $ts,
        ];
    }

    /**
     * The top-level entity array of a feed payload, or null when it carries none.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function entityKey(array $payload): ?string
    {
        foreach (['merchant', 'service', 'menu'] as $key) {
            if (array_key_exists($key, $payload)) {
                return $key;
            }
        }

        return null;
    }
}
