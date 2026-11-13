<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\HungerStation;




/**
 * Fixture-backed client — the DEFAULT when a tenant has no live HS credentials and
 * the client used by all tests. Reads canned responses from rev/hungerstation/fixtures
 * so the whole pipeline (P2 order ingest, P3 status, P4 catalog) can be exercised
 * without any live HungerStation call. Never makes a network request.
 */
class FakeHungerStationClient implements HungerStationClientInterface
{
    /** Optional in-memory overrides so tests can script specific responses. */
    public array $overrides = [];

    public function fetchOrder(string $chainId, string $orderId): array
    {
        return $this->overrides['order'] ?? self::fixture('order-new');
    }

    public function updateOrderStatus(string $chainId, string $orderId, string $status, array $extra = []): array
    {
        return $this->overrides['status'] ?? ['order_id' => $orderId, 'status' => $status, 'ok' => true];
    }

    public function listCatalog(string $chainId, string $vendorId): array
    {
        return $this->overrides['catalog'] ?? self::fixture('catalog-list');
    }

    public function updateCatalog(string $chainId, string $vendorId, array $items): array
    {
        return $this->overrides['catalog_update'] ?? ['job_id' => 'job_fake_1', 'status' => 'QUEUED'];
    }

    public function exportCatalog(string $chainId, string $vendorId): array
    {
        return $this->overrides['export'] ?? ['job_id' => 'job_export_1', 'status' => 'QUEUED'];
    }

    /** Load a fixture JSON by name from tests/Fixtures/hungerstation. */
    public static function fixture(string $name): array
    {
        $path = base_path('tests/Fixtures/hungerstation/'.$name.'.json');
        if (! is_file($path)) {
            throw new HungerStationException("HS fixture not found: {$name}");
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }
}
