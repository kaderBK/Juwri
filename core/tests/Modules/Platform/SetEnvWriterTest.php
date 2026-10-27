<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Tests\TestCase;

/**
 * WI-H8 — set_env() must line-anchor its match and always quote/escape the value,
 * so writing APP_KEY never bleeds into PUSHER_APP_KEY, and an operator-supplied
 * password with a space / # / $ / quote / regex-backreference stays intact and
 * keeps the .env parseable.
 *
 * Every test writes to a THROWAWAY env file — set_env() targets
 * app()->environmentFilePath(), so without this harness it would overwrite the
 * developer's real .env.
 */
class SetEnvWriterTest extends TestCase
{
    private string $envFile;

    private ?string $originalPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envFile = sys_get_temp_dir().'/setenv-test-'.uniqid().'.env';
        file_put_contents($this->envFile, "APP_ENV=testing\n");
        $this->originalPath = $this->app->environmentPath();
        $this->app->useEnvironmentPath(dirname($this->envFile));
        $this->app->loadEnvironmentFrom(basename($this->envFile));
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
        return (string) file_get_contents($this->envFile);
    }

    private function seedEnv(string $contents): void
    {
        file_put_contents($this->envFile, $contents);
    }

    /** Parse the throwaway env with the repo's own dotenv parser and read a value back. */
    private function parseValue(string $key): string
    {
        $repo = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
        Dotenv::create($repo, dirname($this->envFile), basename($this->envFile))->load();

        return (string) $repo->get($key);
    }

    public function test_writing_app_key_does_not_touch_the_pusher_keys(): void
    {
        $this->seedEnv("APP_KEY=\nPUSHER_APP_KEY=\nVITE_PUSHER_APP_KEY=\"\${PUSHER_APP_KEY}\"\n");

        set_env('APP_KEY', 'base64:AAA=');

        $env = $this->env();
        $this->assertMatchesRegularExpression('/^PUSHER_APP_KEY=$/m', $env);
        $this->assertStringContainsString('VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"', $env);
        $this->assertMatchesRegularExpression('/^APP_KEY="base64:AAA="$/m', $env);
    }

    public function test_a_password_with_a_space_produces_a_parseable_env(): void
    {
        set_env('DB_PASSWORD', 'my pass word');

        $this->assertSame('my pass word', $this->parseValue('DB_PASSWORD'));
    }

    public function test_a_password_with_a_hash_is_not_truncated(): void
    {
        set_env('DB_PASSWORD', 'abc#def');

        $this->assertSame('abc#def', $this->parseValue('DB_PASSWORD'));
    }

    public function test_a_value_containing_a_dollar_brace_is_written_literally(): void
    {
        set_env('DB_PASSWORD', 'p${HOME}q');

        $this->assertSame('p${HOME}q', $this->parseValue('DB_PASSWORD'));
    }

    public function test_a_value_that_looks_like_a_regex_backreference_is_written_literally(): void
    {
        set_env('DB_PASSWORD', '$1$abc');

        $this->assertSame('$1$abc', $this->parseValue('DB_PASSWORD'));
    }

    public function test_a_value_containing_a_double_quote_round_trips(): void
    {
        set_env('DB_PASSWORD', 'he said "hi"');

        $this->assertSame('he said "hi"', $this->parseValue('DB_PASSWORD'));
    }

    public function test_a_missing_key_is_appended_exactly_once(): void
    {
        set_env('BRAND_NEW_KEY', 'a');
        set_env('BRAND_NEW_KEY', 'b');

        $env = $this->env();
        $this->assertSame(1, substr_count($env, 'BRAND_NEW_KEY='));
        $this->assertSame('b', $this->parseValue('BRAND_NEW_KEY'));
    }

    public function test_all_anchored_duplicates_are_rewritten(): void
    {
        $this->seedEnv("FOO=one\nBAR=x\nFOO=two\n");

        set_env('FOO', 'new');

        $env = $this->env();
        $this->assertSame(0, substr_count($env, 'FOO=one'));
        $this->assertSame(0, substr_count($env, 'FOO=two'));
        $this->assertSame(2, substr_count($env, 'FOO="new"'));
    }

    public function test_a_key_that_is_a_suffix_of_another_key_is_untouched(): void
    {
        $this->seedEnv("WIDGET_TOKEN=keep\nTOKEN=old\n");

        set_env('TOKEN', 'new');

        $env = $this->env();
        $this->assertMatchesRegularExpression('/^WIDGET_TOKEN=keep$/m', $env);
        $this->assertMatchesRegularExpression('/^TOKEN="new"$/m', $env);
    }
}
