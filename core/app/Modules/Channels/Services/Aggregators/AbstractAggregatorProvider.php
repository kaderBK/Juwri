<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\Aggregators;

use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Core\Models\Credential;
use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Shared behaviour for aggregator providers: HMAC-SHA256 webhook verification against
 * the connection's BYOK secret (from the Credential vault), and a default no-op status
 * push. Concrete providers override mapOrder() + the payload accessors, and pushStatus()
 * when they support outbound status.
 */
abstract class AbstractAggregatorProvider implements AggregatorProvider
{
    /** Header carrying the HMAC signature; override per provider. */
    protected string $signatureHeader = 'X-Aggregator-Signature';

    /** Credential key holding the shared webhook secret. */
    protected string $secretKey = 'webhook_secret';

    public function resolveConnection(Request $request, array $payload): ?AggregatorConnection
    {
        $storeId = $this->externalStoreId($payload);
        return $storeId ? AggregatorConnection::resolve($this->key(), $storeId) : null;
    }

    public function verifyWebhook(Request $request, AggregatorConnection $connection): bool
    {
        $secret = $this->webhookSecret($connection);
        if ($secret === null || $secret === '') {
            // No secret configured → reject (never accept an unverifiable webhook).
            return false;
        }
        $provided = (string) $request->header($this->signatureHeader, '');
        if ($provided === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $this->normalizeSignature($provided));
    }

    /** Some providers prefix the signature (e.g. "sha256="); strip to raw hex. */
    protected function normalizeSignature(string $sig): string
    {
        return str_contains($sig, '=') && !ctype_xdigit($sig)
            ? trim(substr($sig, strpos($sig, '=') + 1))
            : $sig;
    }

    protected function webhookSecret(AggregatorConnection $connection): ?string
    {
        $creds = Credential::for($this->key(), (int) $connection->store_id) ?? [];
        return $creds[$this->secretKey] ?? null;
    }

    public function pushStatus(Order $order, AggregatorConnection $connection): void
    {
        // default: no outbound status support
    }
}
