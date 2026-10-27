<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Printing;

use Illuminate\Support\Facades\Http;

/**
 * PARITY-RUN T1-3: HTTP client for the LAN print bridge.
 *
 * Implements `rev/parity/contracts/print-bridge.md` verbatim — that file is
 * FROZEN; T8 writes the other half. Two rules from the contract shape every
 * method here:
 *
 *   1. A MISSING BRIDGE IS NOT AN ERROR. `/health` failing means "this operator
 *      has no desktop shell", which is the common case. Callers get null/ok=false
 *      and fall back to Web Serial, RawBT or the browser print dialog.
 *   2. RETRIES REUSE THE SAME jobId. The bridge keeps the last 200 job ids for
 *      ~10 min and replays the original result instead of printing again, so a
 *      timeout can be retried without risking a duplicate kitchen ticket.
 *
 * Printer-level failures come back as HTTP 200 with `ok=false` and a reason from
 * a closed vocabulary; only protocol problems use status codes.
 */
class PrintBridgeClient
{
    private string $base;
    private ?string $token;

    /** @var array<string, mixed> P6-298: Guzzle options (SsrfGuard pin) for server-side calls. */
    private array $options;

    /** @param  array<string, mixed>  $options */
    public function __construct(?string $baseUrl = null, ?string $token = null, array $options = [])
    {
        $this->base = rtrim($baseUrl ?: (string) config('printing.bridge_url'), '/');
        $this->token = $token;
        $this->options = $options;
    }

    /**
     * Feature-detect the bridge. Returns the decoded /health body, or null when
     * the bridge is absent/unreachable/unauthorised — never throws.
     *
     * @return array{ok:bool,version?:string,printers?:array}|null
     */
    public function health(): ?array
    {
        try {
            $res = $this->request()->get($this->base . '/health');
        } catch (\Throwable $e) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $body = (array) $res->json();

        return isset($body['ok']) ? $body : null;
    }

    /** Printers the bridge reports, normalised to the contract's shape. */
    public function printers(): array
    {
        $health = $this->health();

        return array_values(array_filter((array) ($health['printers'] ?? []), 'is_array'));
    }

    /**
     * POST /print. `$rawBytes` is the raw ESC/POS string — this method does the
     * base64 encoding, mirroring the kiosk endpoint's payload shape.
     *
     * @return array{ok:bool,jobId:string,error?:string,detail?:string}
     */
    public function print(string $jobId, string $target, int $widthMm, string $rawBytes): array
    {
        $jobId = substr($jobId, 0, 64);

        if (strlen($rawBytes) > (int) config('printing.max_payload_bytes')) {
            return ['ok' => false, 'jobId' => $jobId, 'error' => 'payload_too_large'];
        }

        $payload = [
            'jobId'   => $jobId,
            'target'  => $target,
            'widthMm' => in_array($widthMm, [80, 57], true) ? $widthMm : 80,
            'dataB64' => base64_encode($rawBytes),
        ];

        $attempts = 1 + max(0, (int) config('printing.bridge_retries'));
        $last = ['ok' => false, 'jobId' => $jobId, 'error' => 'econnrefused'];

        for ($i = 0; $i < $attempts; $i++) {
            try {
                $res = $this->request()->post($this->base . '/print', $payload);
            } catch (\Throwable $e) {
                // Transport-level: connection refused / DNS / TLS. Retry with the
                // SAME jobId — the bridge de-dupes, so this cannot double-print.
                $last = ['ok' => false, 'jobId' => $jobId, 'error' => $this->classify($e)];
                continue;
            }

            $status = $res->status();

            // Protocol-level failures are terminal: retrying a bad token or an
            // unpaired bridge just burns the cashier's time.
            if (in_array($status, [400, 401, 409, 413], true)) {
                return [
                    'ok'    => false,
                    'jobId' => $jobId,
                    'error' => [400 => 'bad_request', 401 => 'unauthorized', 409 => 'unpaired', 413 => 'payload_too_large'][$status],
                ];
            }

            if ($status >= 500) {
                $last = ['ok' => false, 'jobId' => $jobId, 'error' => 'internal'];
                continue;
            }

            $body = (array) $res->json();
            if (! empty($body['ok'])) {
                return ['ok' => true, 'jobId' => (string) ($body['jobId'] ?? $jobId)];
            }

            // ok=false at HTTP 200 is a printer problem the operator can act on
            // (paper, power, cable) — surface the reason, do not retry blindly
            // unless it is a transient one.
            $error = (string) ($body['error'] ?? 'internal');
            $last = ['ok' => false, 'jobId' => $jobId, 'error' => $error, 'detail' => (string) ($body['detail'] ?? '')];
            if (! in_array($error, ['etimedout', 'device_busy'], true)) {
                return $last;
            }
        }

        return $last;
    }

    /** Map a transport exception onto the contract's closed error vocabulary. */
    private function classify(\Throwable $e): string
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return 'etimedout';
        }

        return 'econnrefused';
    }

    private function request()
    {
        $req = Http::timeout((int) config('printing.bridge_timeout'))
            ->acceptJson()
            ->withoutRedirecting();
        if ($this->options !== []) {
            $req = $req->withOptions($this->options);
        }

        return $this->token ? $req->withToken($this->token) : $req;
    }
}
