<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * O12a — rotation + prod level. The default stack rotates daily (14-day
 * retention) and the level defaults to `warning` in production, `debug`
 * elsewhere, unless LOG_LEVEL is set.
 */
class LoggingDefaultsTest extends TestCase
{
    public function test_default_stack_rotates_daily(): void
    {
        $this->assertSame(['daily'], config('logging.channels.stack.channels'));
        $this->assertSame(14, config('logging.channels.daily.days'));
    }

    public function test_level_defaults_to_warning_in_production_and_debug_otherwise(): void
    {
        // The config file reads env('APP_ENV') / env('LOG_LEVEL'). env() resolves
        // across putenv + $_ENV + $_SERVER with $_SERVER taking precedence (phpunit
        // seeds APP_ENV=testing there), so every source must be set for the
        // override to take. Original APP_ENV is 'testing'; LOG_LEVEL is unset.
        $origAppEnv = $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'testing';

        $forceEnv = function (string $appEnv): void {
            putenv('LOG_LEVEL');
            unset($_ENV['LOG_LEVEL'], $_SERVER['LOG_LEVEL']);
            putenv('APP_ENV='.$appEnv);
            $_ENV['APP_ENV'] = $appEnv;
            $_SERVER['APP_ENV'] = $appEnv;
        };

        try {
            $forceEnv('production');
            $prod = require config_path('logging.php');
            $this->assertSame('warning', $prod['channels']['daily']['level']);
            $this->assertSame('warning', $prod['channels']['single']['level']);

            $forceEnv('local');
            $local = require config_path('logging.php');
            $this->assertSame('debug', $local['channels']['daily']['level']);
        } finally {
            putenv('APP_ENV='.$origAppEnv);
            $_ENV['APP_ENV'] = $origAppEnv;
            $_SERVER['APP_ENV'] = $origAppEnv;
        }
    }

    public function test_slow_query_threshold_is_configured(): void
    {
        $this->assertSame(500, config('logging.slow_query_ms'));
    }
}
