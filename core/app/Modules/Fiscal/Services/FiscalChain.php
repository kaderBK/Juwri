<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Support\Sql;
use Juwri\Modules\Fiscal\Models\FiscalDocument;

/**
 * TAX-RUN F0-P2: chain-safe issuance of fiscal documents.
 *
 * CHAIN INTEGRITY IS SACRED. Every issuance is serialized under a DB row lock so
 * that, even under concurrent POS sales on one device, the sequence
 * (number / ICV counter / previous_hash) is strictly ordered with no gaps and no
 * duplicates. Documents are append-only: a rejection never rewrites history — a
 * correction is a NEW document that references the old one, and the chain
 * continues from the LAST document regardless of its acceptance state (per ZATCA
 * rules).
 */
class FiscalChain
{
    /**
     * Atomically allocate the next slot on a restaurant's document chain and
     * persist a draft FiscalDocument.
     *
     * @param  array<string,mixed>  $attrs  document attributes (country, type,
     *                                      order_id, total, tax_total, currency, branch_id, payload, ...)
     */
    public static function issue(int $storeId, string $deviceKey, array $attrs): FiscalDocument
    {
        $deviceKey = $deviceKey !== '' ? $deviceKey : 'default';
        $type = (string) ($attrs['type'] ?? 'simplified');

        // The row lock cannot serialize the FIRST document on a device (the
        // lockForUpdate query matches zero rows → no lock), so two concurrent
        // first-issuers could both read last=null. The unique indexes
        // (store_id, device_key, counter) and (store_id, type, seq) are
        // the real backstop: the losing insert throws a duplicate-key error, and we
        // retry against the now-committed row. A handful of retries covers any
        // realistic contention on one device.
        for ($attempt = 0; ; $attempt++) {
            try {
                return self::allocate($storeId, $deviceKey, $type, $attrs);
            } catch (QueryException $e) {
                // P4-01: a document for this source already exists (a concurrent issuer won) — return it, never a
                // second one (the unique (store_id, source_ref) key; wasRecentlyCreated stays false).
                if (! empty($attrs['source_ref']) && self::isDuplicateKey($e)) {
                    $existing = FiscalDocument::where('store_id', $storeId)->where('source_ref', $attrs['source_ref'])->first();
                    if ($existing) {
                        return $existing;
                    }
                }
                if ($attempt >= 5 || ! self::isDuplicateKey($e)) {
                    throw $e;
                }
                usleep(2000 * ($attempt + 1));
            }
        }
    }

    private static function allocate(int $storeId, string $deviceKey, string $type, array $attrs): FiscalDocument
    {
        return DB::transaction(function () use ($storeId, $deviceKey, $type, $attrs) {
            // Serialize the chain: lock the restaurant's rows for this device so
            // two concurrent issuers cannot read the same "last" document.
            $last = FiscalDocument::where('store_id', $storeId)
                ->where('device_key', $deviceKey)
                ->lockForUpdate()
                ->orderByDesc('counter')
                ->orderByDesc('id')
                ->first();

            // Gapless per restaurant+type numbering (separate lock scope but same tx).
            $typeCount = Sql::lockedCount(FiscalDocument::where('store_id', $storeId)
                ->where('type', $type));

            $counter = ($last->counter ?? 0) + 1;
            $seq = $typeCount + 1;
            $previousHash = $last?->hash; // null for the first doc on the device

            $doc = FiscalDocument::create(array_merge([
                'country' => $attrs['country'] ?? '',
                'type' => $type,
                'status' => 'draft',
                'currency' => $attrs['currency'] ?? null,
                'total' => $attrs['total'] ?? 0,
                'tax_total' => $attrs['tax_total'] ?? 0,
            ], $attrs, [
                'store_id' => $storeId,
                'device_key' => $deviceKey,
                'counter' => $counter,
                'seq' => $seq,
                'number' => self::formatNumber($type, $seq),
                'previous_hash' => $previousHash,
                'uuid' => (string) Str::uuid(),
            ]));

            return $doc;
        });
    }

    private static function isDuplicateKey(QueryException $e): bool
    {
        // MySQL 1062 / SQLite "UNIQUE constraint failed" / Postgres 23505.
        return in_array((string) ($e->errorInfo[1] ?? ''), ['1062'], true)
            || str_contains(strtolower($e->getMessage()), 'unique')
            || (string) ($e->errorInfo[0] ?? '') === '23505';
    }

    /**
     * Set this document's own hash (the PIH source for the next doc). Idempotent:
     * only writes when unset so a resubmit never rewrites the chain link.
     */
    public static function seal(FiscalDocument $doc, string $hash): void
    {
        if (! $doc->hash) {
            $doc->update(['hash' => $hash]);
        }
    }

    /**
     * Rejection → correction: issue a NEW document that references the rejected
     * one and continue the chain from the LAST document (never rewriting
     * history). The rejected document is marked superseded. Returns the new doc.
     */
    public static function correct(FiscalDocument $rejected, array $overrides = []): FiscalDocument
    {
        $attrs = array_merge([
            'country' => $rejected->country,
            'type' => $rejected->type,
            'order_id' => $rejected->order_id,
            'transaction_id' => $rejected->transaction_id,
            'total' => $rejected->total,
            'tax_total' => $rejected->tax_total,
            'currency' => $rejected->currency,
            'branch_id' => $rejected->branch_id,
            'payload' => $rejected->payload,
        ], $overrides, [
            'supersedes_id' => $rejected->id,
        ]);

        $new = self::issue($rejected->store_id, (string) $rejected->device_key, $attrs);
        $rejected->transitionTo('superseded');

        return $new;
    }

    private static function formatNumber(string $type, int $seq): string
    {
        $prefix = match ($type) {
            'credit_note' => 'CN-',
            'standard' => 'STD-',
            default => 'SIM-',
        };

        return $prefix.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
