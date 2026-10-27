<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use Juwri\Modules\Pos\Services\Printing\PrintBridgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PARITY-RUN T1-3: the frozen bridge contract (rev/parity/contracts/print-bridge.md)
 * asserted against a mocked bridge. T8 writes the other half; if either side
 * drifts, one of these fails.
 */
class PrintBridgeClientTest extends TestCase
{
    private function client(?string $token = 'pair-token'): PrintBridgeClient
    {
        return new PrintBridgeClient('http://127.0.0.1:8543', $token);
    }

    // ── /health: feature detection ───────────────────────────────────────────

    public function test_health_returns_the_printer_inventory(): void
    {
        Http::fake(['127.0.0.1:8543/health' => Http::response([
            'ok' => true, 'version' => '1.0.0',
            'printers' => [['id' => 'tcp://192.168.1.50:9100', 'label' => 'Kitchen', 'kind' => 'network', 'status' => 'online']],
        ])]);

        $health = $this->client()->health();

        $this->assertTrue($health['ok']);
        $this->assertSame('1.0.0', $health['version']);
        $this->assertSame('tcp://192.168.1.50:9100', $this->client()->printers()[0]['id']);
    }

    /**
     * "A missing bridge is never an error state, only a narrower set of available
     * targets" — the contract's own words. Null, not an exception.
     */
    public function test_absent_bridge_is_null_not_an_exception(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        $this->assertNull($this->client()->health());
        $this->assertSame([], $this->client()->printers());
    }

    public function test_health_sends_the_pairing_token_as_a_bearer(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'printers' => []])]);

        $this->client('secret-40-chars')->health();

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer secret-40-chars'));
    }

    // ── /print: payload shape ────────────────────────────────────────────────

    public function test_print_posts_the_contract_payload(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'jobId' => 'ord-1-front-1-paid'])]);

        $res = $this->client()->print('ord-1-front-1-paid', 'tcp://192.168.1.50:9100', 80, "\x1B@hi");

        $this->assertTrue($res['ok']);
        Http::assertSent(function (Request $r) {
            return $r->url() === 'http://127.0.0.1:8543/print'
                && $r['jobId'] === 'ord-1-front-1-paid'
                && $r['target'] === 'tcp://192.168.1.50:9100'
                && $r['widthMm'] === 80
                && base64_decode($r['dataB64']) === "\x1B@hi";
        });
    }

    public function test_width_is_clamped_to_the_two_legal_values(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->client()->print('j', 'usb', 999, 'x');

        Http::assertSent(fn (Request $r) => $r['widthMm'] === 80);
    }

    public function test_oversized_payload_is_refused_before_the_wire(): void
    {
        Http::fake();

        $res = $this->client()->print('j', 'usb', 80, str_repeat('x', 600 * 1024));

        $this->assertFalse($res['ok']);
        $this->assertSame('payload_too_large', $res['error']);
        Http::assertNothingSent();
    }

    // ── error vocabulary ─────────────────────────────────────────────────────

    public function test_protocol_status_codes_map_to_the_closed_vocabulary(): void
    {
        // One stub closure driven by a mutable status: Http::fake() MERGES stubs,
        // so re-faking inside the loop would leave the first stub winning.
        $status = 401;
        Http::fake(function () use (&$status) {
            return Http::response(['ok' => false], $status);
        });

        foreach ([401 => 'unauthorized', 409 => 'unpaired', 400 => 'bad_request', 413 => 'payload_too_large'] as $code => $error) {
            $status = $code;

            $res = $this->client()->print('j-' . $status, 'usb', 80, 'x');

            $this->assertFalse($res['ok']);
            $this->assertSame($error, $res['error'], "HTTP {$status} must map to {$error}.");
        }
    }

    /**
     * A printer-level failure is HTTP 200 with ok=false — the UI shows the reason
     * rather than treating it as a transport bug. It is terminal: retrying a
     * powered-off printer three times just delays the message.
     */
    public function test_printer_level_failure_is_surfaced_without_retrying(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'jobId' => 'j', 'error' => 'econnrefused', 'detail' => '192.168.1.50:9100'])]);

        $res = $this->client()->print('j', 'tcp://192.168.1.50:9100', 80, 'x');

        $this->assertFalse($res['ok']);
        $this->assertSame('econnrefused', $res['error']);
        Http::assertSentCount(1);
    }

    // ── retries ──────────────────────────────────────────────────────────────

    /**
     * The whole reason retries are safe: the SAME jobId every time, so the
     * bridge's replay cache collapses them into one physical ticket.
     */
    public function test_transient_failures_retry_with_the_same_job_id(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'etimedout'])]);

        $res = $this->client()->print('ord-9-kitchen-2-kot', 'usb', 80, 'x');

        $this->assertFalse($res['ok']);
        Http::assertSentCount(3); // 1 attempt + 2 retries (printing.bridge_retries)
        Http::assertSent(fn (Request $r) => $r['jobId'] === 'ord-9-kitchen-2-kot');
    }

    public function test_a_retry_that_succeeds_reports_success(): void
    {
        Http::fakeSequence()
            ->push(['ok' => false, 'error' => 'device_busy'], 200)
            ->push(['ok' => true, 'jobId' => 'j'], 200);

        $res = $this->client()->print('j', 'usb', 80, 'x');

        $this->assertTrue($res['ok']);
        Http::assertSentCount(2);
    }

    public function test_job_id_is_truncated_to_the_contract_limit(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);

        $this->client()->print(str_repeat('a', 120), 'usb', 80, 'x');

        Http::assertSent(fn (Request $r) => strlen($r['jobId']) === 64);
    }
}
