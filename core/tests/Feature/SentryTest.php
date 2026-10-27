<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * O2 — Sentry error capture. With a DSN the reportable handler forwards
 * exceptions to the `sentry` binding; with a blank DSN the handler is inert
 * (the package may bind a no-op hub, but the DSN guard skips capture).
 */
class SentryTest extends TestCase
{
    public function test_captures_exception_when_dsn_and_binding_present(): void
    {
        config(['sentry.dsn' => 'https://public@example.ingest.sentry.io/1']);

        $sentry = \Mockery::mock();
        $sentry->shouldReceive('captureException')->once();
        $this->app->instance('sentry', $sentry);

        $handler = $this->app->make(\App\Exceptions\Handler::class);
        $handler->report(new \RuntimeException('boom'));

        // Mockery 'once' expectation is asserted on tearDown.
        $this->addToAssertionCount(1);
    }

    public function test_no_op_when_dsn_blank(): void
    {
        config(['sentry.dsn' => null]);

        // Even if a no-op hub is bound, the DSN guard must skip capture — and it
        // must never throw.
        $sentry = \Mockery::mock();
        $sentry->shouldReceive('captureException')->never();
        $this->app->instance('sentry', $sentry);

        $handler = $this->app->make(\App\Exceptions\Handler::class);
        $handler->report(new \RuntimeException('boom'));

        $this->addToAssertionCount(1);
    }

    public function test_release_is_tagged_and_dsn_defaults_blank(): void
    {
        $this->assertSame(config('appinfo.version'), config('sentry.release'));
        $this->assertEmpty(config('sentry.dsn'), 'DSN must default to blank (disabled)');
    }
}
