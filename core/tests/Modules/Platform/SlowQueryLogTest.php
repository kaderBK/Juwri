<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Support\SlowQueryLogger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * O12c — slow-query logging. Fires only on mysql, only past the configured
 * threshold, and is disabled when the threshold is 0. Pure `evaluate()` covers
 * every branch without a real database (the sqlite suite never registers the
 * listener).
 */
class SlowQueryLogTest extends TestCase
{
    public function test_it_logs_a_slow_mysql_query(): void
    {
        config()->set('logging.slow_query_ms', 500);

        $ctx = SlowQueryLogger::evaluate('select * from orders', 620.5, 'mysql');

        $this->assertIsArray($ctx);
        $this->assertSame(620.5, $ctx['ms']);
        $this->assertSame('select * from orders', $ctx['sql']);
        $this->assertArrayHasKey('route', $ctx);
    }

    public function test_it_is_inert_on_sqlite(): void
    {
        config()->set('logging.slow_query_ms', 500);

        $this->assertNull(SlowQueryLogger::evaluate('select * from orders', 900.0, 'sqlite'));
    }

    public function test_it_is_disabled_at_threshold_zero(): void
    {
        config()->set('logging.slow_query_ms', 0);

        $this->assertNull(SlowQueryLogger::evaluate('select * from orders', 5000.0, 'mysql'));
    }

    public function test_a_fast_query_is_not_logged(): void
    {
        config()->set('logging.slow_query_ms', 500);

        $this->assertNull(SlowQueryLogger::evaluate('select 1', 12.0, 'mysql'));
    }

    public function test_handle_warns_for_a_slow_mysql_query(): void
    {
        config()->set('logging.slow_query_ms', 500);

        $conn = Mockery::mock(\Illuminate\Database\Connection::class);
        $conn->shouldReceive('getName')->andReturn('mysql');
        $conn->shouldReceive('getDriverName')->andReturn('mysql');

        Log::shouldReceive('warning')->once()->with('[slow-query]', Mockery::type('array'));

        SlowQueryLogger::handle(new QueryExecuted('select * from orders', [], 700.0, $conn));
    }
}
