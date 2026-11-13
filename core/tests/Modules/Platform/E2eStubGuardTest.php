<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Providers\E2eStubServiceProvider;
use PHPUnit\Framework\TestCase;

/**
 * rev/platform-e2e-v2 V0.2 — the fake-gateway/AI stubs must be PROD-SAFE: they
 * arm ONLY when the app is local/testing AND config('app.e2e_stubs') is truthy.
 * The production default (flag off) must never arm them.
 */
class E2eStubGuardTest extends TestCase
{
    /** The armed() gate is off by default (production default = flag unset/false). */
    public function test_not_armed_when_flag_off(): void
    {
        $this->refreshApplicationWith('production', false);
        $this->assertFalse(E2eStubServiceProvider::armed(), 'stubs must be OFF in production');

        $this->refreshApplicationWith('local', false);
        $this->assertFalse(E2eStubServiceProvider::armed(), 'stubs must be OFF when the flag is false, even in local');
    }

    /** Even with the flag ON, production must NOT arm the stubs. */
    public function test_not_armed_in_production_even_with_flag_on(): void
    {
        $this->refreshApplicationWith('production', true);
        $this->assertFalse(E2eStubServiceProvider::armed(), 'stubs must stay OFF in production regardless of the flag');
    }

    /** Local/testing WITH the flag on arms the stubs (the intended E2E path). */
    public function test_armed_in_local_with_flag_on(): void
    {
        $this->refreshApplicationWith('local', true);
        $this->assertTrue(E2eStubServiceProvider::armed(), 'stubs must arm in local/testing when the flag is on');

        $this->refreshApplicationWith('testing', true);
        $this->assertTrue(E2eStubServiceProvider::armed(), 'stubs must arm in testing when the flag is on');
    }

    /** Boot a minimal container with a chosen environment + flag, no DB/HTTP. */
    private function refreshApplicationWith(string $env, bool $flag): void
    {
        $app = new \Illuminate\Foundation\Application(realpath(__DIR__ . '/../../../'));
        $app->detectEnvironment(fn () => $env);
        $config = new \Illuminate\Config\Repository();
        $config->set('app.e2e_stubs', $flag);
        $app->instance('config', $config);
        \Illuminate\Container\Container::setInstance($app);
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    }
}
