<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dashboard client-error beacon (ClientErrorController). Client-side breakage
 * (extension DOM mutation, Alpine errors) never reaches the server, so the
 * browser reports it here. Guardrails mirror /csp-report: bots dropped, and the
 * LOG is rate-limited to one line per fingerprint per hour while the metric
 * keeps the full aggregate.
 */
class ClientErrorBeaconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function beacon(array $payload, string $ua = 'Mozilla/5.0 (Macintosh) Chrome/120'): TestResponse
    {
        return $this->postJson('/dash/client-error', $payload, ['User-Agent' => $ua]);
    }

    public function test_a_client_error_is_metered_and_answers_204(): void
    {
        $this->beacon(['message' => 'Something broke', 'kind' => 'error'])->assertNoContent();

        $this->assertDatabaseHas('platform_metrics', ['metric' => 'client.error', 'label' => 'app']);
    }

    public function test_extension_origin_errors_are_tagged_not_blamed_on_the_app(): void
    {
        $this->beacon([
            'message' => "Failed to execute 'removeChild' on 'Node'",
            'stack' => 'at e (chrome-extension://hoklmm/frame_start.js:1:9231)',
        ])->assertNoContent();

        $this->assertDatabaseHas('platform_metrics', ['metric' => 'client.error', 'label' => 'extension']);
        $this->assertDatabaseMissing('platform_metrics', ['metric' => 'client.error', 'label' => 'app']);
    }

    public function test_bots_are_dropped_before_any_work(): void
    {
        $this->beacon(['message' => 'boom'], 'Googlebot/2.1')->assertNoContent();

        $this->assertDatabaseMissing('platform_metrics', ['metric' => 'client.error']);
    }

    public function test_a_blank_message_is_ignored(): void
    {
        $this->beacon(['message' => '   '])->assertNoContent();

        $this->assertDatabaseMissing('platform_metrics', ['metric' => 'client.error']);
    }

    public function test_the_log_write_is_rate_limited_per_fingerprint(): void
    {
        $message = 'Repeated error';
        $stack = "at foo (app.js:1:2)\nat bar (app.js:3:4)";
        $fp = substr(hash('sha256', $message.'|'.explode("\n", $stack)[0]), 0, 16);

        $this->assertFalse(Cache::has('clienterr:'.$fp));
        $this->beacon(['message' => $message, 'stack' => $stack])->assertNoContent();

        // The one-per-hour log gate is now claimed, so a second identical beacon
        // logs nothing (the metric still counts both).
        $this->assertTrue(Cache::has('clienterr:'.$fp));
        $this->beacon(['message' => $message, 'stack' => $stack])->assertNoContent();

        $this->assertSame(2, (int) \DB::table('platform_metrics')
            ->where('metric', 'client.error')->sum('count'));
    }

    public function test_store_id_is_derived_from_the_dashboard_path(): void
    {
        // No assertion on the log line itself (channel write) — just that a path
        // with a restaurant id is accepted and metered like any other.
        $this->beacon(['message' => 'x', 'path' => '/restaurants/3079/menu'])->assertNoContent();
        $this->assertDatabaseHas('platform_metrics', ['metric' => 'client.error']);
    }
}
