<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Tests\TestCase;

/**
 * PARITY-RUN follow-up #4: pins `push:vapid-keys`.
 *
 * The two things that must never regress are safety properties, not features:
 * the private key must not leak into output, and an install that already has
 * keys must not lose its subscriptions to an accidental rotation. Every test
 * here writes to a THROWAWAY env file so a real .env is never touched.
 */
class VapidKeyGenerationTest extends TestCase
{
    private string $envFile;
    private ?string $originalPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envFile = sys_get_temp_dir().'/vapid-test-'.uniqid().'.env';
        file_put_contents($this->envFile, "APP_ENV=testing\n");
        // Point the app at the throwaway file for the duration of the test.
        $this->originalPath = $this->app->environmentPath();
        $this->app->useEnvironmentPath(dirname($this->envFile));
        $this->app->loadEnvironmentFrom(basename($this->envFile));
        // Start from "no keys configured".
        config(['webpush.public_key' => '', 'webpush.private_key' => '']);
    }

    protected function tearDown(): void
    {
        @unlink($this->envFile);
        if ($this->originalPath) {
            $this->app->useEnvironmentPath($this->originalPath);
        }
        parent::tearDown();
    }

    private function env(): string
    {
        return file_get_contents($this->envFile);
    }

    public function test_it_writes_all_three_keys_into_env(): void
    {
        $this->artisan('push:vapid-keys')->assertExitCode(0);

        $env = $this->env();
        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY="[A-Za-z0-9_\-]{80,}"$/m', $env);
        $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY="[A-Za-z0-9_\-]{40,}"$/m', $env);
        $this->assertMatchesRegularExpression('/^VAPID_SUBJECT="/m', $env);
    }

    public function test_the_private_key_never_appears_in_output_by_default(): void
    {
        // Capture the command's own output, then the private key it wrote, and
        // assert the two never overlap.
        $code = \Illuminate\Support\Facades\Artisan::call('push:vapid-keys');
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $code);

        preg_match('/VAPID_PRIVATE_KEY="([^"]+)"/', $this->env(), $m);
        $private = $m[1] ?? '';
        $this->assertNotEmpty($private);

        $this->assertStringNotContainsString($private, $output, 'The private key leaked into command output.');
        $this->assertStringContainsString('hidden', $output);
    }

    public function test_show_reveals_the_private_key(): void
    {
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('push:vapid-keys --show'));
        $output = \Illuminate\Support\Facades\Artisan::output();

        preg_match('/VAPID_PRIVATE_KEY="([^"]+)"/', $this->env(), $m);
        $this->assertStringContainsString($m[1], $output);
    }

    public function test_print_only_writes_nothing(): void
    {
        $before = $this->env();
        $this->artisan('push:vapid-keys --print-only')->assertExitCode(0);

        $this->assertSame($before, $this->env(), '--print-only must not touch .env');
        $this->assertStringNotContainsString('VAPID_PUBLIC_KEY', $this->env());
    }

    public function test_it_refuses_to_rotate_existing_keys_without_force(): void
    {
        config(['webpush.public_key' => 'existing', 'webpush.private_key' => 'existing']);

        $this->artisan('push:vapid-keys')
            ->expectsOutputToContain('already has VAPID keys')
            ->assertExitCode(1);

        // The env was not rewritten.
        $this->assertStringNotContainsString('VAPID_PUBLIC_KEY', $this->env());
    }

    public function test_force_rotates_existing_keys(): void
    {
        config(['webpush.public_key' => 'existing', 'webpush.private_key' => 'existing']);

        $this->artisan('push:vapid-keys --force')->assertExitCode(0);
        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY="[A-Za-z0-9_\-]{80,}"$/m', $this->env());
    }

    public function test_a_second_run_replaces_rather_than_duplicates(): void
    {
        $this->artisan('push:vapid-keys')->assertExitCode(0);
        $this->artisan('push:vapid-keys --force')->assertExitCode(0);

        $this->assertSame(1, substr_count($this->env(), 'VAPID_PUBLIC_KEY='), 'the key must appear exactly once');
    }
}
