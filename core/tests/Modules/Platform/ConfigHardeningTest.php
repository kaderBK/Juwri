<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * P0-6: CORS scoped and credential-safe, session cookies hardened, and a
 * debug-in-production guard.
 */
class ConfigHardeningTest extends TestCase
{
    public function test_cors_is_scoped_and_not_credentialed(): void
    {
        $paths = config('cors.paths');
        $this->assertNotContains('*', $paths, 'CORS must not apply to every path');
        $this->assertContains('api/*', $paths);
        $this->assertFalse(config('cors.supports_credentials'), 'token API must not send credentials cross-origin');
        $this->assertIsArray(config('cors.allowed_origins'));
    }

    public function test_session_cookies_are_hardened(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_debug_in_production_guard(): void
    {
        $origEnv = $this->app['env'];
        $origDebug = config('app.debug');

        try {
            config(['app.debug' => true]);
            $this->app['env'] = 'production';
            $this->assertTrue(app_debug_in_production());

            $this->app['env'] = 'local';
            $this->assertFalse(app_debug_in_production());

            config(['app.debug' => false]);
            $this->app['env'] = 'production';
            $this->assertFalse(app_debug_in_production());
        } finally {
            $this->app['env'] = $origEnv;
            config(['app.debug' => $origDebug]);
        }
    }
}
