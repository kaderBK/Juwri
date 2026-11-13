<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg\Feeds;

use Juwri\Core\Models\Branch;

/**
 * GOOGLE-PARTNER Lane C — Reserve-with-Google Merchants feed.
 *
 * One entry per eligible merchant (RwgMerchantSource) — each branch is its own Google
 * merchant (owner 2026-10-12). merchant.url is the store's EFFECTIVE public URL
 * (reseller / custom-domain aware via effective_public_url); geo comes from that
 * branch's coordinates and should match the branch's linked Google Business Profile
 * listing.
 *
 * A single-branch store keeps the historic `store-<id>` merchant: name = store title,
 * telephone = store phone, and the Google Maps place-id hint is the branch's own
 * `google_place_id` when set, else the store's. A store with two or more eligible
 * branches publishes one merchant per branch `store-<id>-b<branchId>`: name is
 * "<store title> - <branch name>", telephone is the branch phone (store phone as the
 * fallback), the branch's own opening hours are added when it carries any, and the
 * place-id hint is the branch's own only — a branch without one inherits NOTHING (the
 * store-level id would mis-match a different listing). Mirrors Storefront's per-branch
 * merchants (P5-20).
 */
class MerchantsFeedBuilder implements RwgFeedContributor
{
    public function __construct(private RwgMerchantSource $source)
    {
    }

    public function filename(): string
    {
        return 'merchant_feed.json';
    }

    public function build(): array
    {
        $merchants = [];
        foreach ($this->source->merchants() as $m) {
            $store = $m['store'];
            $branch = $m['branch'];
            $parsed = RwgMerchantSource::parseMerchantId((string) $m['merchant_id']);
            $perBranch = $parsed !== null && $parsed['branch'] !== null;

            $merchant = [
                'merchant_id' => $m['merchant_id'],
                'name'        => $perBranch
                    ? trim((string) $store->title) . ' - ' . trim((string) $branch->name)
                    : (string) $store->title,
                'telephone'   => $perBranch
                    ? (string) (($branch->phone ?? '') !== '' ? $branch->phone : ($store->phone ?? ''))
                    : (string) ($store->phone ?? ''),
                'url'         => effective_public_url($store),
                'geo'         => [
                    'latitude'  => (float) $branch->latitude,
                    'longitude' => (float) $branch->longitude,
                ],
                'category'    => 'restaurant',
            ];

            if ($perBranch) {
                // This branch is its own listing: its own opening hours (when it carries any).
                $hours = $this->weeklyHours($branch);
                if ($hours !== null) {
                    $merchant['hours'] = $hours;
                }
            }

            // The branch's own Google Maps place ID, when set. Only a one-branch store may fall
            // back to the store-level id; a multi-branch branch without one inherits nothing.
            $placeId = (string) ($branch->google_place_id ?? '');
            if ($placeId === '' && ! $perBranch) {
                $placeId = (string) ($store->google_place_id ?? '');
            }
            if ($placeId !== '') {
                $merchant['matching_hints'] = ['place_id' => $placeId];
            }

            $merchants[] = $merchant;
        }

        return [
            'metadata' => RwgFeedBuilder::metadata(),
            'merchant' => $merchants,
        ];
    }

    /**
     * The branch's opening hours as a Google-style weekly list (mon..sun, the branch model's own
     * day keys) carrying the branch timezone. Null when the branch has no `hours` array.
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
