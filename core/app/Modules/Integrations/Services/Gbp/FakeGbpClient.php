<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;


/**
 * Fake GBP client: in-memory place action links per location. Never hits Google.
 */
class FakeGbpClient implements GbpClientInterface
{
    /** @var array<string, array<int, array>> links by locationId */
    public static array $links = [];
    private static int $seq = 0;

    /** @var array<int, array<string,mixed>> accounts the fake "administers" */
    public static array $accounts = [
        ['name' => 'accounts/111', 'accountName' => 'Demo Account', 'type' => 'LOCATION_GROUP', 'role' => 'OWNER'],
    ];

    /** @var array<string, array<int, array<string,mixed>>> locations keyed by account name */
    public static array $locations = [
        'accounts/111' => [
            ['name' => 'accounts/111/locations/222', 'title' => 'Demo Location', 'storefrontAddress' => ['locality' => 'Demo City']],
        ],
    ];

    /** @var array<string, array<string,mixed>> foodMenus keyed by locationId */
    public static array $foodMenus = [];

    public static function reset(): void
    {
        self::$links = [];
        self::$seq = 0;
        self::$foodMenus = [];
        self::$accounts = [
            ['name' => 'accounts/111', 'accountName' => 'Demo Account', 'type' => 'LOCATION_GROUP', 'role' => 'OWNER'],
        ];
        self::$locations = [
            'accounts/111' => [
                ['name' => 'accounts/111/locations/222', 'title' => 'Demo Location', 'storefrontAddress' => ['locality' => 'Demo City']],
            ],
        ];
    }

    public function listActionLinks(string $locationId): array
    {
        return self::$links[$locationId] ?? [];
    }

    public function createActionLink(string $locationId, array $body): array
    {
        self::$seq++;
        $link = array_merge([
            'name'         => "locations/{$locationId}/placeActionLinks/" . self::$seq,
            'providerType' => 'MERCHANT',
        ], $body);
        self::$links[$locationId][] = $link;
        return $link;
    }

    public function patchActionLink(string $name, array $body): array
    {
        foreach (self::$links as $loc => &$links) {
            foreach ($links as &$link) {
                if (($link['name'] ?? null) === $name) {
                    $link = array_merge($link, $body);
                    return $link;
                }
            }
        }
        return array_merge(['name' => $name], $body);
    }

    // ===== google-partner Lane A — connect + menu sync surface (in-memory) =====

    public function listAccounts(): array
    {
        return self::$accounts;
    }

    public function listLocations(string $account): array
    {
        return self::$locations[$account] ?? [];
    }

    public function getFoodMenus(string $locationId): array
    {
        return self::$foodMenus[$locationId] ?? [];
    }

    public function updateFoodMenus(string $locationId, array $foodMenus): array
    {
        $stored = array_merge(
            ['name' => "locations/{$locationId}/foodMenus"],
            $foodMenus
        );
        self::$foodMenus[$locationId] = $stored;

        return $stored;
    }
}
