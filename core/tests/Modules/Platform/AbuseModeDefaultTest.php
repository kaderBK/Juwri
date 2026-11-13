<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Services\AbuseGuard;

/**
 * P6-55: config/abuse.php defaults every guard to enforce in production and warn elsewhere;
 * ABUSE_MODE (global) and ABUSE_MODE_<GUARD> override it.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function abuseConfigWithEnv(array $env): array
{
    $keys = ['APP_ENV', 'ABUSE_MODE', 'ABUSE_MODE_WEBHOOK_IN', 'ABUSE_MODE_UPLOAD', 'ABUSE_MODE_PUBLIC_WRITE',
        'ABUSE_MODE_SIGNUP', 'ABUSE_MODE_MCP', 'ABUSE_MODE_AI', 'ABUSE_MODE_STORE_CAP'];
    $saved = [];
    foreach ($keys as $k) {
        $saved[$k] = [$_ENV[$k] ?? null, $_SERVER[$k] ?? null, getenv($k)];
        unset($_ENV[$k], $_SERVER[$k]);
        putenv($k);
        if (array_key_exists($k, $env)) {
            $_ENV[$k] = $_SERVER[$k] = $env[$k];
            putenv($k.'='.$env[$k]);
        }
    }
    try {
        return require base_path('config/abuse.php');
    } finally {
        foreach ($saved as $k => [$e, $s, $g]) {
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
            if ($e !== null) {
                $_ENV[$k] = $e;
            }
            if ($s !== null) {
                $_SERVER[$k] = $s;
            }
            if ($g !== false) {
                putenv($k.'='.$g);
            }
        }
    }
}

/** Resolve one guard's mode from a freshly loaded config/abuse.php. */
function abuseModeUnder(array $env, string $guard): string
{
    $cfg = abuseConfigWithEnv($env);
    config(['abuse' => $cfg, 'settings.abuse_'.$guard.'_mode' => null]);

    return AbuseGuard::mode($guard);
}

$guards = ['webhook_in', 'upload', 'public_write', 'signup', 'mcp', 'ai', 'store_cap'];

it('lists exactly the known buckets', function () use ($guards) {
    expect(array_keys(abuseConfigWithEnv([])['modes']))->toBe($guards);
});

it('defaults to enforce in production', function (string $guard) {
    expect(abuseModeUnder(['APP_ENV' => 'production'], $guard))->toBe(AbuseGuard::ENFORCE);
})->with($guards);

it('defaults to warn outside production', function (string $guard, string $env) {
    expect(abuseModeUnder(['APP_ENV' => $env], $guard))->toBe(AbuseGuard::WARN);
})->with($guards)->with(['local', 'staging', 'testing']);

it('lets the global ABUSE_MODE override the production default', function (string $guard) {
    expect(abuseModeUnder(['APP_ENV' => 'production', 'ABUSE_MODE' => 'warn'], $guard))->toBe(AbuseGuard::WARN);
    expect(abuseModeUnder(['APP_ENV' => 'local', 'ABUSE_MODE' => 'enforce'], $guard))->toBe(AbuseGuard::ENFORCE);
})->with($guards);

it('lets a per-bucket env override win over the default', function (string $guard) {
    $var = 'ABUSE_MODE_'.strtoupper($guard);
    expect(abuseModeUnder(['APP_ENV' => 'production', $var => 'off'], $guard))->toBe(AbuseGuard::OFF);
    expect(abuseModeUnder(['APP_ENV' => 'local', $var => 'enforce'], $guard))->toBe(AbuseGuard::ENFORCE);
    expect(abuseModeUnder(['APP_ENV' => 'production', 'ABUSE_MODE' => 'warn', $var => 'enforce'], $guard))->toBe(AbuseGuard::ENFORCE);
})->with($guards);
