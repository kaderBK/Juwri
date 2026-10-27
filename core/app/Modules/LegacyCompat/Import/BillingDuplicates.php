<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * Pre-scan of the source billing tables for rows the target's unique keys would reject (like the duplicate-identity
 * pre-flight on users): `transaction` (payment id + gateway; invoice number) and `billing_receipts` (one receipt per
 * payment). Keys are compared trimmed and case-insensitively (a MySQL target compares them so); an empty value is
 * "none" (imported as NULL) and never collides. The FIRST row of a key (lowest id) is imported; every later one is
 * blocked and reported by id, so one bad row never stops the run.
 */
final class BillingDuplicates
{
    /** @return array<string, array<int, string>> source table => row id => reason */
    public static function scan(SourceReader $source): array
    {
        $out = [];
        $keys = [
            'transaction' => [
                'payment id and gateway' => ['payment_id', 'transaction_gatway'],
                'invoice number' => ['invoice_number'],
            ],
            'billing_receipts' => ['payment (transaction_id)' => ['transaction_id']],
        ];
        foreach ($keys as $table => $sets) {
            $columns = $source->columns($table);
            $sets = array_filter($sets, static fn (array $set): bool => array_diff($set, $columns) === []);
            if ($sets === []) {
                continue;
            }
            $seen = [];
            $source->chunk($table, function (array $rows) use ($table, $sets, &$seen, &$out): void {
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    foreach ($sets as $label => $set) {
                        $parts = [];
                        foreach ($set as $column) {
                            $parts[] = mb_strtolower(trim((string) ($row[$column] ?? '')));
                        }
                        if (in_array('', $parts, true)) {
                            continue;
                        }
                        $key = $label.'|'.implode("\0", $parts);
                        if (isset($seen[$key])) {
                            $out[$table][$id] = 'duplicate '.$label.' (first: '.$table.'#'.$seen[$key].')';

                            continue 2;
                        }
                        $seen[$key] = $id;
                    }
                }
            });
        }

        return $out;
    }
}
