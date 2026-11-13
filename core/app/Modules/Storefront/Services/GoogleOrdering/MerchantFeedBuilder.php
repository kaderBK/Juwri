<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\GoogleOrdering;

use Juwri\Core\Models\Branch;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;

/**
 * P5-20 — Google Ordering Redirect Merchants feed.
 *
 * One entry per eligible merchant (GoogleOrderingMerchantSource). `url` is the store's PRIMARY
 * public address (reseller / custom-domain / platform-host aware via StoreAddressResolver);
 * `geo` comes from the merchant's branch and should match the store's linked Google Business
 * Profile listing.
 *
 * A single-branch store keeps the historic `store-<id>` merchant, byte-identical to before (name
 * = store title, telephone = store phone, place-id hint). A store with two or more eligible
 * branches is its own Google merchant per branch (owner 2026-10-12): `store-<id>-b<branchId>`, so
 * the name is "<store title> - <branch name>", geo/phone come from THAT branch (store phone as
 * fallback), the branch's opening hours are added when it carries any, and the branch's own Google
 * Maps place-id is emitted as a matching hint when the branch carries one (no store-level hint: a
 * multi-branch branch without its own id gets none).
 */
final class MerchantFeedBuilder
{
    public function __construct(private readonly GoogleOrderingMerchantSource $source) {}

    public function filename(): string
    {
        return 'merchant_feed.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $resolver = app(StoreAddressResolver::class);
        $merchants = [];
        foreach ($this->source->merchants() as $m) {
            $store = $m['store'];
            $branch = $m['branch'];
            $parsed = GoogleOrderingMerchantSource::parseMerchantId((string) $m['merchant_id']);
            $perBranch = $parsed !== null && $parsed['branch'] !== null;

            $merchant = [
                'merchant_id' => $m['merchant_id'],
                'name'        => $perBranch
                    ? trim((string) $store->title).' - '.trim((string) $branch->name)
                    : (string) $store->title,
                'telephone'   => $perBranch
                    ? (string) (($branch->phone ?? '') !== '' ? $branch->phone : ($store->phone ?? ''))
                    : (string) ($store->phone ?? ''),
                'url'         => $resolver->baseUrl($store),
                'geo'         => [
                    'latitude'  => (float) $branch->latitude,
                    'longitude' => (float) $branch->longitude,
                ],
                'category'    => 'restaurant',
            ];

            if ($perBranch) {
                // This branch is its own listing: its own opening hours (when it carries any),
                // and its own Google Maps place-id hint when the branch carries one.
                $hours = $this->weeklyHours($branch);
                if ($hours !== null) {
                    $merchant['hours'] = $hours;
                }
                $placeId = (string) ($branch->google_place_id ?? '');
                if ($placeId !== '') {
                    $merchant['matching_hints'] = ['place_id' => $placeId];
                }
            } elseif (($placeId = (string) ($store->google_place_id ?? '')) !== '') {
                // A single-branch store keeps the owner's Google Maps place ID hint.
                $merchant['matching_hints'] = ['place_id' => $placeId];
            }

            $merchants[] = $merchant;
        }

        return [
            'metadata' => GoogleOrderingFeedBuilder::metadata(),
            'merchant' => $merchants,
        ];
    }

    /**
     * The branch's opening hours as a Google-style weekly list (mon..sun, the branch model's own
     * day keys) carrying the branch timezone. Null when the branch has no `hours` array, so a
     * branch without hours adds nothing.
     *
     * @return array{timezone: string, weekly: list<array{day: string, open: string, close: string, closed: bool}>}|null
     */
    private function weeklyHours(Branch $branch): ?array
    {
        $hours = $branch->hours;
        if (! is_array($hours) || $hours === []) {
            return null;
        }

        $weekly = [];
        foreach (Branch::DAYS as $day) {
            $h = $branch->dayHours($day);
            $weekly[] = [
                'day'    => $day,
                'open'   => (string) $h['open'],
                'close'  => (string) $h['close'],
                'closed' => (bool) $h['closed'],
            ];
        }

        return [
            'timezone' => (string) ($branch->timezone ?: config('app.timezone', 'UTC')),
            'weekly'   => $weekly,
        ];
    }
}
