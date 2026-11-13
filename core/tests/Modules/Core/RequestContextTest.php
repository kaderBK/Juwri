<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Http\Middleware\AddRequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * O12b — request-id + tenant log context. Every response carries an
 * X-Request-Id (echoing an inbound one when present), and the middleware pushes
 * request_id + user_id + store_id into the log context.
 */
class RequestContextTest extends TestCase
{
    public function test_response_carries_a_request_id_header(): void
    {
        $res = $this->get('/healthz');
        $res->assertOk();
        $this->assertNotEmpty($res->headers->get('X-Request-Id'), 'response must carry a correlation id');
    }

    public function test_an_inbound_request_id_is_preserved(): void
    {
        $res = $this->get('/healthz', ['X-Request-Id' => 'trace-abc-123']);
        $this->assertSame('trace-abc-123', $res->headers->get('X-Request-Id'));
    }

    public function test_it_pushes_request_id_user_id_and_store_id_into_log_context(): void
    {
        Log::shouldReceive('withContext')->once()->with(Mockery::on(function ($ctx) {
            return is_array($ctx)
                && array_key_exists('request_id', $ctx)
                && array_key_exists('user_id', $ctx)
                && array_key_exists('store_id', $ctx)
                && ! empty($ctx['request_id']);
        }));

        $request = Request::create('/x', 'GET');
        $response = new AddRequestContext()->handle($request, fn () => response('ok'));

        $this->assertSame('ok', $response->getContent());
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }
}
