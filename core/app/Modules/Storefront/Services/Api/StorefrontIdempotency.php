<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Storefront\Models\StorefrontApiIdempotency;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3-06b (REVIEW, Opus: idempotency; kit/api README §2.7, API-24c) — `Idempotency-Key` for the headless storefront
 * API's writes that are not orders (orders keep theirs in `orders.client_uuid`):
 *
 *  - first call with a key: a row is claimed (UNIQUE store + operation + key — a concurrent twin loses the insert),
 *    the handler runs, and a 2xx/4xx answer (not 429 and not 5xx, which a retry should run again) is stored,
 *    encrypted, until `expires_at`;
 *  - the same key + the same request hash: the stored answer again, with `Idempotent-Replayed: true`;
 *  - the same key + another request hash, or a twin still running: 409 `idempotency_conflict`.
 */
final class StorefrontIdempotency
{
    /**
     * @param  Closure(): Response  $handler
     *
     * @throws StorefrontApiException
     */
    public function run(StorefrontApiContext $ctx, string $operation, string $key, string $requestHash, int $ttlSeconds, Closure $handler): Response
    {
        $existing = $this->find($ctx, $operation, $key);
        if ($existing !== null) {
            return $this->replay($existing, $requestHash);
        }
        // A key whose answer expired may be used again: its old row would hold the UNIQUE slot.
        StorefrontApiIdempotency::query()->where('store_id', $ctx->store->id)->where('operation', $operation)
            ->where('idempotency_key', $key)->where('expires_at', '<=', now())->delete();

        try {
            /** @var StorefrontApiIdempotency $row */
            $row = DB::transaction(fn () => StorefrontApiIdempotency::query()->create([ // savepoint: a lost race must not abort an outer PostgreSQL transaction
                'account_id' => (int) $ctx->store->account_id,
                'store_id' => (int) $ctx->store->id,
                'operation' => $operation,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'created_at' => now(),
                'expires_at' => now()->addSeconds($ttlSeconds),
            ]));
        } catch (QueryException) {
            $twin = $this->find($ctx, $operation, $key);
            if ($twin === null) {
                throw new StorefrontApiException(409, 'idempotency_conflict', ___('A request with this Idempotency-Key is still being processed.'));
            }

            return $this->replay($twin, $requestHash);
        }

        try {
            $response = $handler();
        } catch (\Throwable $e) {
            $row->delete(); // nothing was answered: a retry with the same key runs again

            throw $e;
        }

        $status = $response->getStatusCode();
        if ($status >= 500 || $status === 429) {
            $row->delete();
        } else {
            $row->forceFill(['response_status' => $status, 'response_body' => (string) $response->getContent()])->save();
        }

        return $response;
    }

    /** Remove answers whose time is up (all stores; daily). */
    public static function prune(): int
    {
        return (int) TenantContext::bypass(
            fn () => StorefrontApiIdempotency::query()->where('expires_at', '<', now())->delete()
        );
    }

    private function find(StorefrontApiContext $ctx, string $operation, string $key): ?StorefrontApiIdempotency
    {
        return StorefrontApiIdempotency::query()
            ->where('store_id', $ctx->store->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $key)
            ->where('expires_at', '>', now())
            ->first();
    }

    /** @throws StorefrontApiException */
    private function replay(StorefrontApiIdempotency $row, string $requestHash): Response
    {
        if (! hash_equals($row->request_hash, $requestHash)) {
            throw StorefrontOrderPlacement::conflict();
        }
        if ($row->response_status === null) {
            throw new StorefrontApiException(409, 'idempotency_conflict', ___('A request with this Idempotency-Key is still being processed.'));
        }

        return response((string) $row->response_body, (int) $row->response_status, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            'Idempotent-Replayed' => 'true',
        ]);
    }
}
