<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Support\Facades\Artisan;

function preflightSafeConfig(): void
{
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'auth.token_query_fallback' => false,
        'auth.password_policy_strict' => true,
        'services.hungerstation.verify_signatures' => true,
        'sms.inbound_allow_unsigned' => false,
        'settings.whatsapp_verify_signatures' => '1',
        'cors.allowed_origins' => ['https://example.test'],
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'session.domain' => null,
        'session.cookie' => '__Host-site_session',
        'cors.supports_credentials' => false,
        'cors.paths' => ['api/*'],
        'ai.require_no_training' => true,
        'auth.force_2fa_admin' => true,
        'abuse.default_mode' => 'enforce',
        'trustedproxy.proxies' => ['127.0.0.1/8'],
        'trustedproxy.server_name' => ':8080',
        'queue.default' => 'redis',
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'mail.default' => 'smtp',
        'platform.breach_notice.bcc' => 'security@example.test',
        'pharmacy.enabled_countries' => ['AE'],
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['daily'],
        'logging.channels.daily.level' => 'warning',
        'backup.encryption_key' => 'base64:'.base64_encode(random_bytes(32)),
        'settings.platform_backup_restore_check_enabled' => null,
        'settings.platform_media_backup_enabled' => '1',
        'filesystems.disks.s3.key' => 'AKIAPREFLIGHTTEST',
        'filesystems.disks.s3.secret' => 'preflight-s3-secret-value',
        'filesystems.disks.s3.bucket' => 'juwri-backups',
    ]);
    foreach (array_keys((array) config('abuse.modes')) as $g) {
        config(['abuse.modes.'.$g => null, 'settings.abuse_'.$g.'_mode' => null]);
    }
}

/** @return array<string, string> */
function preflightFailed(array $options = []): array
{
    Artisan::call('ops:preflight', $options + ['--json' => true]);

    return json_decode(Artisan::output(), true)['failed'];
}

it('passes with a hardened config and exits zero', function () {
    preflightSafeConfig();
    expect(Artisan::call('ops:preflight', ['--cloud' => true]))->toBe(0);
    expect(preflightFailed(['--cloud' => true]))->toBe([]);
});

it('fails when a rule is broken and exits non-zero', function (string $rule, array $override, bool $cloud) {
    preflightSafeConfig();
    config($override);
    $options = $cloud ? ['--cloud' => true] : [];
    expect(Artisan::call('ops:preflight', $options))->toBe(1);
    expect(preflightFailed($options))->toHaveKey($rule)->toHaveCount(1);
})->with([
    'app_env' => ['app_env', ['app.env' => 'local'], false],
    'app_debug' => ['app_debug', ['app.debug' => true], false],
    'app_key missing' => ['app_key', ['app.key' => ''], false],
    'app_key weak' => ['app_key', ['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))], false],
    'token_query_fallback' => ['token_query_fallback', ['auth.token_query_fallback' => true], false],
    'password_policy_strict' => ['password_policy_strict', ['auth.password_policy_strict' => false], false],
    'hs_verify_signatures' => ['hs_verify_signatures', ['services.hungerstation.verify_signatures' => false], false],
    'whatsapp_verify_signatures' => ['whatsapp_verify_signatures', ['settings.whatsapp_verify_signatures' => '0'], false],
    'sms_inbound_allow_unsigned' => ['sms_inbound_allow_unsigned', ['sms.inbound_allow_unsigned' => true], false],
    'cors_allowed_origins' => ['cors_allowed_origins', ['cors.allowed_origins' => ['*']], false],
    'session_domain_shared' => ['session_domain_shared', ['session.domain' => '.example.test'], false],
    'session_secure' => ['session_secure', ['session.secure' => false], false],
    'session_http_only' => ['session_http_only', ['session.http_only' => false], false],
    'session_same_site' => ['session_same_site', ['session.same_site' => 'none'], false],
    'ai_require_no_training' => ['ai_require_no_training', ['ai.require_no_training' => false], true],
    'force_2fa_admin' => ['force_2fa_admin', ['auth.force_2fa_admin' => false], true],
    'abuse global warn' => ['abuse_mode_enforce', ['abuse.default_mode' => 'warn'], false],
    'abuse one guard warn' => ['abuse_mode_enforce', ['abuse.modes.signup' => 'warn'], false],
    'abuse admin override off' => ['abuse_mode_enforce', ['settings.abuse_upload_mode' => 'off'], false],
    'trusted proxies star' => ['trusted_proxies', ['trustedproxy.proxies' => '*'], false],
    'proxy mode, nothing trusted' => ['trusted_proxies', ['trustedproxy.proxies' => []], false],
    'queue sync' => ['queue_not_sync', ['queue.default' => 'sync'], false],
    'cache array' => ['cache_driver', ['cache.default' => 'array'], false],
    'cache null' => ['cache_driver', ['cache.default' => 'null'], false],
    'session array' => ['session_driver', ['session.driver' => 'array'], false],
    'session null' => ['session_driver', ['session.driver' => 'null'], false],
    'mail log' => ['mail_mailer', ['mail.default' => 'log'], false],
    'mail array' => ['mail_mailer', ['mail.default' => 'array'], false],
    'breach bcc unset' => ['breach_notice_bcc', ['platform.breach_notice.bcc' => ''], true],
    'backup key unset (Cloud)' => ['backup_encryption_key', ['backup.encryption_key' => '', 'settings.platform_media_backup_enabled' => '0'], true],
    'backup key malformed' => ['backup_encryption_key_valid', ['backup.encryption_key' => 'base64:c2hvcnQ='], false],
    'J45: backup key unset (self-host production)' => ['backup_encryption_key_production', ['backup.encryption_key' => '', 'settings.platform_media_backup_enabled' => '0'], false],
]);

it('passes the P6-65 rules with production-safe values', function (array $override, bool $cloud) {
    preflightSafeConfig();
    config($override);
    expect(Artisan::call('ops:preflight', $cloud ? ['--cloud' => true] : []))->toBe(0);
})->with([
    'queue database' => [['queue.default' => 'database'], false],
    'cache file' => [['cache.default' => 'file'], false],
    'session file' => [['session.driver' => 'file'], false],
    'mail smtp' => [['mail.default' => 'smtp'], false],
    'breach bcc set' => [['platform.breach_notice.bcc' => 'sec@example.test'], true],
    'breach bcc unset off Cloud' => [['platform.breach_notice.bcc' => ''], false],
]);

it('warns without failing', function (string $rule, array $override, bool $cloud) {
    preflightSafeConfig();
    config($override);
    $options = $cloud ? ['--cloud' => true] : [];
    expect(Artisan::call('ops:preflight', $options))->toBe(0);
    Artisan::call('ops:preflight', $options + ['--json' => true]);
    $out = json_decode(Artisan::output(), true);
    expect($out['failed'])->toBe([])->and($out['warnings'])->toHaveKey($rule)->toHaveCount(1);
    Artisan::call('ops:preflight', $options);
    expect(Artisan::output())->toContain("warning [{$rule}]");
})->with([
    'log level debug' => ['log_level_debug', ['logging.channels.daily.level' => 'debug'], false],
    'pharmacy countries empty' => ['pharmacy_countries', ['pharmacy.enabled_countries' => []], true],
    'media mirror off (Cloud)' => ['media_backup_off', ['settings.platform_media_backup_enabled' => '0'], true],
    'restore drill switched off in production' => ['restore_check_off', ['settings.platform_backup_restore_check_enabled' => '0'], false],
    'P6-166: no off-box target in production' => ['offbox_target', ['filesystems.disks.s3.key' => null, 'filesystems.disks.s3.bucket' => null], false],
    'P6-166: no off-box target on Cloud' => ['offbox_target', ['filesystems.disks.s3.bucket' => ''], true],
]);

it('P6-166: never prints the off-box keys', function () {
    preflightSafeConfig();
    Artisan::call('ops:preflight', ['--cloud' => true]);
    expect(Artisan::output())->not->toContain('preflight-s3-secret-value')->not->toContain('AKIAPREFLIGHTTEST');
});

it('P6-96b: Cloud fails when the media mirror is on without a usable backup key', function () {
    preflightSafeConfig();
    config(['backup.encryption_key' => '']);
    expect(Artisan::call('ops:preflight', ['--cloud' => true]))->toBe(1);
    expect(preflightFailed(['--cloud' => true]))->toHaveKeys(['media_backup_encrypted', 'backup_encryption_key']);
    config(['settings.platform_media_backup_enabled' => '0']);
    expect(preflightFailed(['--cloud' => true]))->not->toHaveKey('media_backup_encrypted');
});

it('J45: self-host production fails (no longer warns) when the backup key is missing', function () {
    preflightSafeConfig();
    config(['backup.encryption_key' => '']);
    expect(Artisan::call('ops:preflight'))->toBe(1);
    expect(preflightFailed([]))->toHaveKey('backup_encryption_key_production');
    Artisan::call('ops:preflight', ['--json' => true]);
    expect(json_decode(Artisan::output(), true)['warnings'])->not->toHaveKeys(['media_backup_plain', 'backup_encryption_key']);
});

it('never prints the backup key', function () {
    preflightSafeConfig();
    $key = (string) config('backup.encryption_key');
    Artisan::call('ops:preflight', ['--cloud' => true]);
    expect(Artisan::output())->not->toContain(substr($key, 7));
    config(['backup.encryption_key' => 'base64:c2hvcnQ=']);
    Artisan::call('ops:preflight', ['--cloud' => true]);
    expect(Artisan::output())->not->toContain('c2hvcnQ=');
});

it('has no warnings with a hardened config', function () {
    preflightSafeConfig();
    Artisan::call('ops:preflight', ['--cloud' => true, '--json' => true]);
    expect(json_decode(Artisan::output(), true)['warnings'])->toBe([]);
    config(['pharmacy.enabled_countries' => [], 'app.env' => 'production']);
    Artisan::call('ops:preflight', ['--json' => true]);
    expect(json_decode(Artisan::output(), true)['warnings'])->toBe([]);
});

it('skips Cloud rules without --cloud', function () {
    preflightSafeConfig();
    config(['ai.require_no_training' => false, 'auth.force_2fa_admin' => false]);
    expect(Artisan::call('ops:preflight'))->toBe(0);
});

it('lists failed rules in text output', function () {
    preflightSafeConfig();
    config(['app.debug' => true]);
    Artisan::call('ops:preflight');
    expect(Artisan::output())->toContain('[app_debug]')->toContain('APP_DEBUG must be false');
});

it('accepts an empty TRUSTED_PROXIES when bundled Caddy terminates TLS (SERVER_NAME is a domain)', function () {
    preflightSafeConfig();
    config(['trustedproxy.proxies' => [], 'trustedproxy.server_name' => 'shop.example.test']);
    expect(preflightFailed())->not->toHaveKey('trusted_proxies');
});

function preflightWarnings(): array
{
    Artisan::call('ops:preflight', ['--json' => true]);

    return json_decode(Artisan::output(), true)['warnings'];
}

it('warns, without failing, on cookie-path CORS wildcard, shared session domain and unprefixed cookie', function () {
    preflightSafeConfig();
    expect(preflightWarnings())->toBe([]);

    config(['cors.allowed_origins' => ['*'], 'cors.paths' => ['api/*', 'sanctum/csrf-cookie']]);
    expect(preflightWarnings())->toHaveKey('cors_wildcard_cookie_paths');

    config(['session.domain' => 'example.test']);
    expect(preflightWarnings())->toHaveKey('session_domain_set');

    config(['session.cookie' => 'site_session']);
    expect(preflightWarnings())->toHaveKey('session_cookie_prefix');
});

it('fails a credentialed wildcard CORS config on both the wildcard and credentials rules', function () {
    preflightSafeConfig();
    config(['cors.allowed_origins' => ['*'], 'cors.supports_credentials' => true]);
    expect(preflightFailed())->toHaveKeys(['cors_allowed_origins', 'cors_credentials_wildcard']);
});
