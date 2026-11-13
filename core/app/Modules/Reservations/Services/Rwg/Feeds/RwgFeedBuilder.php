<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg\Feeds;

use Carbon\CarbonInterface;

/**
 * GOOGLE-PARTNER Lane C — Reserve-with-Google feed framework.
 *
 * Discovers RwgFeedContributor classes from the
 * `services.reserve_with_google.feed_builders` config array and builds each feed.
 * The `rwg:feeds` command (C4) gzips the results to storage/app/rwg/ and, when SFTP
 * is configured, uploads them; otherwise it leaves the artifacts for inspection
 * (dark). Lane D contributes its waitlist feed by appending its class to that config
 * array — no edit to any Lane C file.
 */
class RwgFeedBuilder
{
    /**
     * The folder the feed artifacts live in. Configurable so the tests can point each
     * parallel process at its own temp folder; unset means the historical storage/app/rwg.
     */
    public static function feedDir(): string
    {
        return rtrim(config('filesystems.rwg_feeds_path') ?: storage_path('app/rwg'), '/');
    }

    /**
     * Build every registered feed.
     *
     * @return array<string,array<string,mixed>>  filename => feed payload
     */
    public function buildAll(): array
    {
        $out = [];
        foreach ($this->contributors() as $contributor) {
            $out[$contributor->filename()] = $contributor->build();
        }

        return $out;
    }

    /**
     * Stable order, so two runs over the same data produce byte-identical entries (diff-able, cache-friendly):
     * merchants by id, services by merchant then service, availability by merchant, service, start time.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sorted(string $filename, array $payload): array
    {
        $cmp = static fn (array $a, array $b, array $keys): int => array_reduce($keys, static fn (int $carry, string $k): int => $carry !== 0 ? $carry : (is_int($a[$k] ?? null) && is_int($b[$k] ?? null) ? $a[$k] <=> $b[$k] : strnatcmp((string) ($a[$k] ?? ''), (string) ($b[$k] ?? ''))), 0);
        if (isset($payload['merchant'])) {
            usort($payload['merchant'], fn ($a, $b) => $cmp($a, $b, ['merchant_id']));
        }
        if (isset($payload['service'])) {
            usort($payload['service'], fn ($a, $b) => $cmp($a, $b, ['merchant_id', 'service_id']));
        }
        foreach ($payload['service_availability'] ?? [] as $i => $block) {
            $rows = $block['availability'] ?? [];
            usort($rows, fn ($a, $b) => $cmp($a, $b, ['merchant_id', 'service_id', 'start_sec']));
            $payload['service_availability'][$i]['availability'] = $rows;
        }

        return $payload;
    }

    /**
     * Split one feed into shards of at most $size entries. A merchant's entries always stay together and a merchant
     * always lands in the same shard for a given shard count (crc32 of its id), so a feed that grows by one store
     * does not reshuffle everything. One shard (the usual case) keeps the plain file name.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>> file name => shard payload
     */
    public static function shard(string $filename, array $payload, int $size): array
    {
        [$list, $merchantOf] = self::entries($payload);
        $count = count($list);
        $total = max(1, (int) ceil($count / max(1, $size)));
        if ($total === 1) {
            $payload['metadata'] = ['shard_number' => 0, 'total_shards' => 1] + ($payload['metadata'] ?? []);

            return [$filename => $payload];
        }
        $buckets = array_fill(0, $total, []);
        foreach ($list as $entry) {
            $buckets[crc32($merchantOf($entry)) % $total][] = $entry;
        }
        $out = [];
        foreach ($buckets as $n => $entries) {
            $shard = self::withEntries($payload, $entries);
            $shard['metadata'] = ['shard_number' => $n, 'total_shards' => $total] + ($payload['metadata'] ?? []);
            $out[preg_replace('/\.json$/', '', $filename).'.shard-'.($n + 1).'-of-'.$total.'.json'] = $shard;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: list<array<string,mixed>>, 1: callable(array<string,mixed>): string}
     */
    private static function entries(array $payload): array
    {
        $merchantOf = static fn (array $e): string => (string) ($e['merchant_id'] ?? '');
        if (isset($payload['merchant'])) {
            return [$payload['merchant'], $merchantOf];
        }
        if (isset($payload['service'])) {
            return [$payload['service'], $merchantOf];
        }

        return [$payload['service_availability'][0]['availability'] ?? [], $merchantOf];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string,mixed>>  $entries
     * @return array<string, mixed>
     */
    private static function withEntries(array $payload, array $entries): array
    {
        if (isset($payload['merchant'])) {
            $payload['merchant'] = $entries;
        } elseif (isset($payload['service'])) {
            $payload['service'] = $entries;
        } else {
            $payload['service_availability'] = [['availability' => $entries]];
        }

        return $payload;
    }

    /**
     * Instantiate the configured contributors, skipping anything that is missing or
     * not a RwgFeedContributor (a stale config entry must never fatal the run).
     *
     * @return array<int,RwgFeedContributor>
     */
    public function contributors(): array
    {
        $classes = config('services.reserve_with_google.feed_builders', []);
        $classes = is_array($classes) ? $classes : [];

        $list = [];
        foreach ($classes as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $instance = app($class);
            if ($instance instanceof RwgFeedContributor) {
                $list[] = $instance;
            }
        }

        return $list;
    }

    /**
     * Actions Center generic-feed metadata block. Every feed carries one.
     * PROCESS_AS_COMPLETE = a full daily snapshot (not a delta).
     *
     * @return array<string,mixed>
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
}
