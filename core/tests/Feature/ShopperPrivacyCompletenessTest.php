<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Juwri\Modules\Customers\Services\CustomerMergeService;
use Juwri\Modules\Customers\Services\Privacy\ShopperPrivacyService;
use Tests\TestCase;

/**
 * P6-91 — the AccountDeletionPurgeCompletenessTest pattern for shoppers: every column of the migrated schema (Community
 * + the installed edition) that points at a storefront customer — a foreign key to `customers`, or a column named
 * `customer_id` / `*_customer_id` — must be classified in {@see ShopperPrivacyService::COLUMNS} (delete / anonymise /
 * ignore). A new customer-linked table that is not classified fails here, so the shopper's export and erasure can
 * never silently miss it.
 */
class ShopperPrivacyCompletenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_customer_linked_column_is_classified_for_export_and_erasure(): void
    {
        $found = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if ($table === 'customers') {
                continue;
            }
            $fk = [];
            foreach (Schema::getForeignKeys($table) as $key) {
                if ($key['foreign_table'] === 'customers' && count($key['columns']) === 1 && in_array($key['foreign_columns'][0] ?? 'id', ['id'], true)) {
                    $fk[$key['columns'][0]] = true;
                }
            }
            foreach (Schema::getColumns($table) as $col) {
                if (isset($fk[$col['name']]) || preg_match('/(^|_)customer_id$/', $col['name']) === 1) {
                    $found[] = $table.'.'.$col['name'];
                }
            }
        }
        $classified = [];
        foreach (ShopperPrivacyService::COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $classified[] = $table.'.'.$column;
            }
        }
        sort($found);
        $this->assertNotEmpty($found);
        $this->assertSame([], array_values(array_diff($found, $classified)),
            'customer-linked columns not classified in ShopperPrivacyService::COLUMNS (decide: delete, anonymise or ignore)');
        // and nothing is classified that the schema does not have (a renamed column would otherwise be skipped silently)
        $installed = array_filter($classified, fn ($c) => Schema::hasTable(explode('.', $c)[0]));
        $this->assertSame([], array_values(array_diff($installed, $found)), 'classified columns missing from the schema');
    }

    public function test_every_classified_column_is_handled_or_skipped_by_customer_merge(): void
    {
        $handled = array_values(CustomerMergeService::HANDLED);
        $skipped = array_keys(CustomerMergeService::SKIPPED);
        $missing = [];
        foreach (ShopperPrivacyService::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $decision) {
                if ($decision === 'ignore') {
                    continue;
                }
                $key = $table.'.'.$column;
                if (! in_array($key, $handled, true) && ! in_array($key, $skipped, true)) {
                    $missing[] = $key;
                }
            }
        }
        sort($missing);
        $this->assertSame([], $missing,
            'customer-linked columns classified for export/erasure but neither handled by CustomerMergeService::HANDLED nor listed in CustomerMergeService::SKIPPED');
    }
}
