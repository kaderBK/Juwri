<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Juwri\Core\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O1 — /healthz probe. Returns 200 + a checks map when healthy, 503 when the
 * hard DB check fails, and gates the detailed map behind HEALTHZ_TOKEN.
 */
class HealthCheckTest extends TestCase
{
    public function test_healthz_returns_ok_and_checks_when_healthy(): void
    {
        $res = $this->getJson('/healthz');

        $res->assertOk()
            ->assertJson(['status' => 'ok'])
            ->assertJsonStructure(['status', 'checks' => ['database', 'cache', 'storage'], 'version']);

        $this->assertSame('ok', $res->json('checks.database'));
        $this->assertSame(config('appinfo.version'), $res->json('version'));
    }

    public function test_healthz_returns_503_when_database_unreachable(): void
    {
        // Direct controller call with a throwing db manager — avoids poisoning the
        // shared :memory: sqlite connection / middleware.
        $this->app->bind('db', fn () => new class
        {
            public function connection()
            {
                return new class
                {
                    public function getPdo()
                    {
                        throw new \PDOException('down');
                    }

                    public function select($q = null, $b = [])
                    {
                        throw new \PDOException('down');
                    }
                };
            }
        });

        // A persistent (MySQL / PostgreSQL) connection may already be resolved into the facade.
        DB::clearResolvedInstance('db');

        $response = (new HealthController)->check(Request::create('/healthz'));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('error', $response->getData(true)['status']);
        $this->assertSame('down', $response->getData(true)['checks']['database']);
    }

    public function test_detail_gated_behind_token_when_configured(): void
    {
        config(['app.healthz_token' => 'sekret']);

        // No token → bare status only.
        $this->getJson('/healthz')->assertOk()->assertJsonMissingPath('checks');

        // Correct token → full detail.
        $this->getJson('/healthz?token=sekret')
            ->assertOk()
            ->assertJsonStructure(['status', 'checks', 'version']);
    }

    public function test_production_hides_detail_when_health_token_is_unset(): void
    {
        config(['app.healthz_token' => '']);
        app()->detectEnvironment(static fn (): string => 'production');

        $this->getJson('/healthz')
            ->assertOk()
            ->assertJson(['status' => 'ok'])
            ->assertJsonMissingPath('checks')
            ->assertJsonMissingPath('version');
    }

    public function test_route_is_not_shadowed_by_slug_catchall(): void
    {
        // A real JSON health payload proves /healthz resolved to HealthController,
        // not the {slug} storefront catch-all (which would 404/redirect).
        $this->getJson('/healthz')->assertOk()->assertJson(['status' => 'ok']);
    }
}
