<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use Juwri\Modules\Integrations\Models\WebhookDelivery;
use Tests\TestCase;

/** P6-218: response size cap (streamed), no retry on 4xx except 408/429. */
class WebhookResponseCapTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DeliverWebhookJob::$transport = null;
        parent::tearDown();
    }

    private function endpoint(): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'user_id' => 1, 'url' => 'https://8.8.8.8/hook',
            'events' => ['*'], 'active' => true, 'secret' => 's',
        ]);
    }

    private function job(WebhookEndpoint $ep): DeliverWebhookJob
    {
        return new DeliverWebhookJob($ep->id, 'order.created', ['x' => 1], 'msg_cap', now()->getTimestamp());
    }

    public function test_capped_writer_aborts_past_the_limit(): void
    {
        $buffer = '';
        $oversize = false;
        $w = DeliverWebhookJob::cappedWriter($buffer, $oversize, 10);
        $this->assertSame(6, $w(null, 'abcdef'));
        $this->assertFalse($oversize);
        $this->assertSame(0, $w(null, 'ghijklmnop'), 'abort signal once past cap');
        $this->assertTrue($oversize);
        $this->assertSame(10, strlen($buffer), 'never holds more than the cap');
    }

    public function test_capped_writer_default_cap_is_64k(): void
    {
        $buffer = '';
        $oversize = false;
        $w = DeliverWebhookJob::cappedWriter($buffer, $oversize);
        $chunk = str_repeat('a', 16384);
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(16384, $w(null, $chunk));
        }
        $this->assertSame(0, $w(null, 'b'));
        $this->assertSame(65536, strlen($buffer));
    }

    public function test_oversize_body_is_logged_as_failure_and_not_retried(): void
    {
        config(['queue.default' => 'database']);
        DeliverWebhookJob::$transport = fn () => [200, str_repeat('x', 100), true];
        $ep = $this->endpoint();

        $this->job($ep)->handle(); // must not throw (no retry)

        $d = WebhookDelivery::where('endpoint_id', $ep->id)->sole();
        $this->assertFalse((bool) $d->success);
        $this->assertStringContainsString('larger than 65536', $d->response);
        $this->assertSame(1, $ep->fresh()->consecutive_failures);
    }

    public function test_410_is_not_retried(): void
    {
        config(['queue.default' => 'database']);
        DeliverWebhookJob::$transport = fn () => [410, 'gone', false];
        $ep = $this->endpoint();

        $this->job($ep)->handle();

        $this->assertDatabaseHas('webhook_deliveries', ['endpoint_id' => $ep->id, 'http_status' => 410, 'success' => false]);
    }

    public function test_503_is_retried_with_backoff(): void
    {
        config(['queue.default' => 'database']);
        DeliverWebhookJob::$transport = fn () => [503, 'busy', false];
        $ep = $this->endpoint();
        $job = $this->job($ep);
        $this->assertGreaterThanOrEqual(60, $job->backoff()[0]);

        $this->expectException(\RuntimeException::class);
        $job->handle();
    }

    public function test_retry_policy(): void
    {
        foreach ([400, 401, 403, 404, 410, 422] as $s) {
            $this->assertFalse(DeliverWebhookJob::shouldRetry($s), "no retry on {$s}");
        }
        foreach ([408, 429, 500, 502, 503, null] as $s) {
            $this->assertTrue(DeliverWebhookJob::shouldRetry($s), 'retry on '.var_export($s, true));
        }
        $this->assertFalse(DeliverWebhookJob::shouldRetry(200, true));
    }
}
