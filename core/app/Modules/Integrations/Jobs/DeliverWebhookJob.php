<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Jobs;

use App\Models\WebhookEndpoint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Core\Support\LegacyStoreKeys;
use Juwri\Modules\Integrations\Models\WebhookDelivery;

/**
 * Queued delivery of a single webhook event to a single endpoint.
 *
 * Reliability follows the Standard Webhooks conventions:
 *  - the signature is computed over `{id}.{timestamp}.{body}` (HMAC-SHA256) so a
 *    captured request cannot be replayed against a different id/time;
 *  - failed deliveries retry with exponential backoff + jitter;
 *  - every attempt is logged to `webhook_deliveries` with its attempt number.
 *
 * Under the `sync` queue driver (no worker running) we deliver once and never
 * throw, so a dead endpoint can never block the order request that fired it.
 * When a real queue is configured, a non-2xx response throws to trigger a retry.
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    /** Attempts (1 initial + 4 retries). */
    public int $tries = 5;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    /** P6-218: most of a receiver's reply we ever read (bytes); the rest is dropped mid-stream. */
    public const MAX_RESPONSE_BYTES = 65536;

    /**
     * Test seam: replaces the HTTP call. fn(string $url, string $body, array $headers): array{0: ?int, 1: string, 2: bool}
     * (status, response text, oversize). Never set in production code.
     *
     * @var (\Closure(string, string, array<int, string>): array{0: ?int, 1: string, 2: bool})|null
     */
    public static ?\Closure $transport = null;

    public function __construct(
        public int $endpointId,
        public string $event,
        public array $payload,
        public string $messageId,
        public int $timestamp,
    ) {}

    /**
     * Exponential backoff with jitter (seconds): ~1m, 5m, 15m, 1h.
     * Jitter is derived from the message id so it is deterministic per event
     * (no reliance on random(), which is unavailable in some contexts) while
     * still spreading retries across endpoints during an outage.
     */
    public function backoff(): array
    {
        $jitter = (hexdec(substr(md5($this->messageId), 0, 4)) % 30);

        return [60 + $jitter, 300 + $jitter, 900 + $jitter, 3600 + $jitter];
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::find($this->endpointId);
        if (! $endpoint || ! $endpoint->active) {
            return; // endpoint deleted or disabled since the event fired
        }

        // GAP-4: SSRF guard — never let an owner-supplied URL point us at loopback,
        // private, link-local or the cloud metadata endpoint. A blocked URL can never
        // succeed, so record the failure (which may auto-disable) and do not retry.
        // F6: one lookup — the same vetted IP is used for the connection below.
        $pin = SsrfGuard::pin($endpoint->url);
        if ($pin === null) {
            WebhookDelivery::create([
                'endpoint_id' => $endpoint->id,
                'event_id' => $this->messageId,
                'event' => $this->event,
                'payload' => $this->payload,
                'http_status' => null,
                'attempt' => $this->attempts(),
                'response' => 'Blocked: URL is not a safe public https endpoint (SSRF guard).',
                'success' => false,
            ]);
            $endpoint->recordDeliveryResult(false, 'Blocked: URL is not a safe public https endpoint.');

            return;
        }

        $body = json_encode([
            'id' => $this->messageId,
            'event' => $this->event,
            'created_at' => now()->toIso8601String(),
            // P1-04: receivers built on the old key keep working (LegacyStoreKeys mirrors store_id).
            'data' => LegacyStoreKeys::mirror($this->payload),
        ], JSON_UNESCAPED_SLASHES);

        $signature = $endpoint->signStandard($this->messageId, $this->timestamp, $body);

        $httpStatus = null;
        $response = null;
        $success = false;

        $headers = [
            'Content-Type: application/json',
            'User-Agent: '.config('settings.site_title', 'juwri').'-Webhooks/1',
            // Standard Webhooks headers
            'webhook-id: '.$this->messageId,
            'webhook-timestamp: '.$this->timestamp,
            'webhook-signature: v1,'.$signature,
            // Legacy headers (kept for backward compatibility)
            'X-Webhook-Event: '.$this->event,
            'X-Webhook-Signature: '.hash_hmac('sha256', $body, $endpoint->secret ?? ''),
        ];
        $oversize = false;

        try {
            if (self::$transport !== null) {
                [$httpStatus, $response, $oversize] = (self::$transport)($endpoint->url, $body, $headers);
            } else {
                [$httpStatus, $response, $oversize] = $this->send($endpoint->url, $body, $headers, $pin);
            }
            $success = ! $oversize && $httpStatus !== null && $httpStatus >= 200 && $httpStatus < 300;
            if ($oversize) {
                $response = 'Response larger than '.self::MAX_RESPONSE_BYTES.' bytes; read stopped. '.$response;
            }
        } catch (\Throwable $e) {
            $response = $e->getMessage();
        }

        WebhookDelivery::create([
            'endpoint_id' => $endpoint->id,
            'event_id' => $this->messageId,
            'event' => $this->event,
            'payload' => $this->payload,
            'http_status' => $httpStatus,
            'attempt' => $this->attempts(),
            'response' => mb_strcut((string) $response, 0, 500, 'UTF-8'),
            'success' => $success,
        ]);

        // Track the streak — a run of failures auto-disables the endpoint.
        $endpoint->recordDeliveryResult($success);

        // Only ask the queue to retry when a real worker exists. Under `sync`
        // there is nothing to retry into and throwing would surface to the
        // request that triggered the webhook.
        if (! $success && self::shouldRetry($httpStatus, $oversize)
            && config('queue.default') !== 'sync' && $this->attempts() < $this->tries) {
            throw new \RuntimeException("Webhook delivery to {$endpoint->url} failed with HTTP {$httpStatus}");
        }
    }

    /**
     * P6-218: a 4xx means the receiver rejected this request; sending it again
     * will not help, except 408 (timeout) and 429 (rate limited). An oversize
     * reply is the receiver misbehaving, not a transient fault: no retry.
     */
    public static function shouldRetry(?int $status, bool $oversize = false): bool
    {
        if ($oversize) {
            return false;
        }
        if ($status !== null && $status >= 400 && $status < 500) {
            return $status === 408 || $status === 429;
        }

        return true; // network error, 5xx, 3xx (redirects are not followed)
    }

    /**
     * P6-218: streamed read — the body is collected chunk by chunk and the
     * transfer is aborted as soon as it passes MAX_RESPONSE_BYTES, so a hostile
     * endpoint cannot fill the worker's memory.
     *
     * @param  array<int, string>  $headers
     * @param  array{host: string, port: int, ip: string}  $pin
     * @return array{0: ?int, 1: string, 2: bool}
     */
    private function send(string $url, string $body, array $headers, array $pin): array
    {
        $buffer = '';
        $oversize = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_WRITEFUNCTION => self::cappedWriter($buffer, $oversize),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            // Hardening: never follow redirects (a 30x could point at an
            // internal host, bypassing the pre-flight SSRF check) and only
            // ever speak HTTPS.
            CURLOPT_FOLLOWLOCATION => false,
            // F6: connect to the IP the SSRF guard vetted, not a second DNS answer.
            CURLOPT_RESOLVE => [SsrfGuard::resolveEntry($pin)],
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($ok === false && ! $oversize) {
            $buffer = curl_error($ch);
        }
        curl_close($ch);

        return [$status > 0 ? $status : null, $buffer, $oversize];
    }

    /**
     * CURLOPT_WRITEFUNCTION that appends to $buffer up to MAX_RESPONSE_BYTES and
     * returns 0 (abort) once the limit is passed.
     */
    public static function cappedWriter(string &$buffer, bool &$oversize, int $max = self::MAX_RESPONSE_BYTES): \Closure
    {
        return function ($ch, string $chunk) use (&$buffer, &$oversize, $max): int {
            $room = $max - strlen($buffer);
            if (strlen($chunk) > $room) {
                $buffer .= substr($chunk, 0, max(0, $room));
                $oversize = true;

                return 0; // abort the transfer
            }
            $buffer .= $chunk;

            return strlen($chunk);
        };
    }
}
