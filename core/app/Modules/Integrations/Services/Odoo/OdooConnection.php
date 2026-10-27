<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Odoo;

use Juwri\Core\Models\Credential;
use Juwri\Core\Settings\StoreSettings;

/**
 * P4-21: one store's Odoo connection. The address, database, user and API key live in the store's encrypted
 * credential vault row (feature `odoo`; never a platform default — each store connects its own Odoo); the mapping
 * (Odoo tax, payment journals, stock picking type) is plain store settings. Never serialised with the key.
 */
final class OdooConnection
{
    public const FEATURE = 'odoo';

    /** Store settings of the mapping (non-secret). */
    public const SETTINGS = ['odoo_enabled', 'odoo_tax_id', 'odoo_journal_cash_id', 'odoo_journal_bank_id', 'odoo_picking_type_id', 'odoo_map'];

    public function __construct(
        public readonly int $storeId,
        public readonly string $url,
        public readonly string $database,
        public readonly string $username,
        public readonly string $apiKey,
        public readonly bool $enabled,
        public readonly ?int $taxId,
        public readonly ?int $cashJournalId,
        public readonly ?int $bankJournalId,
        public readonly ?int $pickingTypeId,
        /** @var array{tax: array<string, int>, payment: array<string, int>, order_type: array<string, array{tax_id?: int, picking_type_id?: int}>} */
        public readonly array $map = ['tax' => [], 'payment' => [], 'order_type' => []],
    ) {}

    /**
     * The per-item mapping (store setting `odoo_map`, JSON; see OdooMapping): by Juwri tax, payment method and order
     * type. Missing entries fall back to the single fields above (the original mapping), so a store that never maps
     * anything per item keeps sending exactly what it sent before.
     */
    public function taxFor(?string $orderType): ?int
    {
        return $this->map['order_type'][(string) $orderType]['tax_id'] ?? $this->map['tax']['store'] ?? $this->taxId;
    }

    /** The Odoo tax put on the untaxed lines (delivery, tip, …): a 0 % tax, or none. */
    public function untaxedTaxId(): ?int
    {
        return $this->map['tax']['untaxed'] ?? null;
    }

    public function journalFor(string $method): ?int
    {
        $method = strtolower(trim($method));
        if (isset($this->map['payment'][$method])) {
            return $this->map['payment'][$method];
        }

        return in_array($method, ['cash', 'cod', 'pos_cash'], true) || str_contains($method, 'cash') ? $this->cashJournalId : $this->bankJournalId;
    }

    public function pickingFor(?string $orderType): ?int
    {
        return $this->map['order_type'][(string) $orderType]['picking_type_id'] ?? $this->pickingTypeId;
    }

    public static function for(int $storeId): ?self
    {
        $row = Credential::query()->where('store_id', $storeId)->where('feature', self::FEATURE)->where('active', true)->first();
        $s = is_array($row?->secrets) ? $row->secrets : [];
        if (($s['url'] ?? '') === '' || ($s['database'] ?? '') === '' || ($s['username'] ?? '') === '' || ($s['api_key'] ?? '') === '') {
            return null;
        }
        $id = fn (string $name): ?int => ($v = StoreSettings::int($storeId, $name)) > 0 ? $v : null;

        return new self(
            storeId: $storeId,
            url: (string) $s['url'],
            database: (string) $s['database'],
            username: (string) $s['username'],
            apiKey: (string) $s['api_key'],
            enabled: StoreSettings::bool($storeId, 'odoo_enabled'),
            taxId: $id('odoo_tax_id'),
            cashJournalId: $id('odoo_journal_cash_id'),
            bankJournalId: $id('odoo_journal_bank_id'),
            pickingTypeId: $id('odoo_picking_type_id'),
            map: OdooMapping::read($storeId),
        );
    }

    /** Connected and switched on: new paid orders and refunds are queued. */
    public static function active(int $storeId): bool
    {
        $c = self::for($storeId);

        return $c !== null && $c->enabled;
    }

    /** @return array<string, mixed> never the API key */
    public function __debugInfo(): array
    {
        return ['storeId' => $this->storeId, 'url' => $this->url, 'database' => $this->database, 'username' => $this->username];
    }
}
