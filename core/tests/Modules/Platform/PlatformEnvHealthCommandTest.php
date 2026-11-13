<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * WI-H8 — the platform:env-health CLI exits 0 on a clean env and non-zero on a
 * leak, printing KEY NAMES only, never a value. Uses a throwaway env file.
 */
class PlatformEnvHealthCommandTest extends TestCase
{
    private string $envFile;

    private ?string $originalPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envFile = sys_get_temp_dir().'/envhealth-cmd-'.uniqid().'.env';
        $this->originalPath = $this->app->environmentPath();
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
        if ($this->originalPath) {
            $this->app->useEnvironmentPath($this->originalPath);
        }
        parent::tearDown();
    }

    private function useEnv(string $contents): void
    {
        file_put_contents($this->envFile, $contents);
        $this->app->useEnvironmentPath(dirname($this->envFile));
        $this->app->loadEnvironmentFrom(basename($this->envFile));
    }

    public function test_it_exits_zero_on_a_clean_env(): void
    {
        $this->useEnv("APP_KEY=\"base64:CLEAN=\"\nPUSHER_APP_KEY=\n");

        $this->artisan('platform:env-health')->assertExitCode(0);
    }

    public function test_it_exits_non_zero_and_names_the_key_on_a_leak(): void
    {
        $leak = 'base64:LEAKEDKEYVALUE12345=';
        $this->useEnv("APP_KEY=\"{$leak}\"\nPUSHER_APP_KEY=\"{$leak}\"\n");

        $this->artisan('platform:env-health')
            ->expectsOutputToContain('PUSHER_APP_KEY')
            ->assertExitCode(1);
    }
}
