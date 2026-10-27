<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Juwri\Core\Models\PlatformBackupRun;
use Juwri\Core\Services\AbuseGuard;
use Juwri\Core\Services\Ops\BackupCrypto;

/**
 * P6-44: automated part of deploy/PROD-HARDENING-CHECKLIST.md. Exits non-zero listing each failed rule.
 * Cloud-only rules run with --cloud (no config key marks a Cloud install). Not checked (no config key exists):
 * a heavy-queue worker hint, and the app node count (multi-node
 * FILESYSTEM_DISK=local) — those stay manual items in the checklist. P6-65: warnings() never change the exit code.
 * P6-96b: Cloud with the media mirror on needs the key (media sealed with it); mirror off warns on Cloud.
 * P6-96: BACKUP_ENCRYPTION_KEY must be valid (production: required — J45, backups stop without it). P6-97: restore drill
 * off in production is a warning.
 */
class OpsPreflightCommand extends Command
{
    protected $signature = 'ops:preflight {--cloud : Also check juwri Cloud rules} {--json : Print the result as JSON}';

    protected $description = 'Check production configuration against the hardening checklist.';

    /**
     * @return array<string, array{0: string, 1: bool}> rule => [message, passes]
     */
    public function rules(bool $cloud): array
    {
        $rules = [
            'app_env' => ['APP_ENV must be production', config('app.env') === 'production'],
            'app_debug' => ['APP_DEBUG must be false', ! (bool) config('app.debug')],
            'app_key' => ['APP_KEY must be set to a strong key (32 random bytes)', $this->strongKey(config('app.key'))],
            'token_query_fallback' => ['TOKEN_QUERY_FALLBACK must be false', ! (bool) config('auth.token_query_fallback')],
            'password_policy_strict' => ['PASSWORD_POLICY_STRICT must be true', (bool) config('auth.password_policy_strict')],
            'hs_verify_signatures' => ['HS_VERIFY_SIGNATURES must be true', (bool) config('services.hungerstation.verify_signatures')],
            'whatsapp_verify_signatures' => ['WhatsApp webhook signature verification must stay on (settings.whatsapp_verify_signatures)', ! in_array(config('settings.whatsapp_verify_signatures'), ['0', 0, false], true)],
            'sms_inbound_allow_unsigned' => ['SMS_INBOUND_ALLOW_UNSIGNED must be false', ! (bool) config('sms.inbound_allow_unsigned')],
            'cors_allowed_origins' => ['CORS_ALLOWED_ORIGINS must be an explicit list, never *', ! in_array('*', (array) config('cors.allowed_origins'), true)],
            'cors_credentials_wildcard' => ['cors.supports_credentials must be false when CORS_ALLOWED_ORIGINS is * (credentialed wildcard)', ! ($this->corsWildcard() && (bool) config('cors.supports_credentials'))],
            'session_domain_shared' => ['SESSION_DOMAIN must not start with "." (a parent-domain cookie is shared across store subdomains and reseller custom domains); leave it empty for host-only cookies', ! str_starts_with(trim((string) config('session.domain')), '.')],
            'session_secure' => ['SESSION_SECURE_COOKIE must be true', (bool) config('session.secure')],
            'session_http_only' => ['session.http_only must be true', (bool) config('session.http_only')],
            'session_same_site' => ['session.same_site must be lax or strict', in_array(strtolower((string) config('session.same_site')), ['lax', 'strict'], true)],
            'abuse_mode_enforce' => ['ABUSE_MODE and every ABUSE_MODE_<GUARD> must resolve to enforce in production', $this->abuseEnforced()],
            'trusted_proxies' => ['TRUSTED_PROXIES must never be *, and must be set in proxy mode (SERVER_NAME=:port)', $this->trustedProxiesOk()],
            'queue_not_sync' => ['QUEUE_CONNECTION must not be sync in production', ! $this->prod() || config('queue.default') !== 'sync'],
            'cache_driver' => ['CACHE_DRIVER must not be array or null in production', ! $this->prod() || ! in_array(config('cache.default'), ['array', 'null'], true)],
            'session_driver' => ['SESSION_DRIVER must not be array or null in production', ! $this->prod() || ! in_array(config('session.driver'), ['array', 'null'], true)],
            'backup_encryption_key_valid' => ['BACKUP_ENCRYPTION_KEY is set but malformed (base64 of 32 bytes); dumps would be rejected', $this->backupKeyState() !== 'bad'],
            // J45 (P6-204): production backups stop without the key, so a missing key is a FAIL, not advice.
            'backup_encryption_key_production' => ['BACKUP_ENCRYPTION_KEY is not set in production: every backup will stop and alert until it is (php artisan backup:generate-key)', $cloud || ! $this->prod() || $this->backupKeyState() !== 'missing'], // Cloud: backup_encryption_key below
            'mail_mailer' => ['MAIL_MAILER must not be log or array in production', ! $this->prod() || ! in_array(config('mail.default'), ['log', 'array'], true)],
        ];
        if ($cloud) {
            $rules['ai_require_no_training'] = ['AI_REQUIRE_NO_TRAINING must be true (Cloud)', (bool) config('ai.require_no_training')];
            $rules['force_2fa_admin'] = ['FORCE_2FA_ADMIN must be true (Cloud)', (bool) config('auth.force_2fa_admin')];
            $rules['backup_encryption_key'] = ['BACKUP_ENCRYPTION_KEY must be set to base64 of 32 bytes (Cloud; php artisan backup:generate-key)', $this->backupKeyState() === 'ok'];
            $rules['media_backup_encrypted'] = ['The media mirror is on but BACKUP_ENCRYPTION_KEY is not usable: media would leave the box unencrypted (Cloud)', ! $this->mediaMirrorOn() || $this->backupKeyState() === 'ok'];
            $rules['breach_notice_bcc'] = ['BREACH_NOTICE_BCC must be set (Cloud, DPA 6.2)', trim((string) config('platform.breach_notice.bcc')) !== ''];
        }

        return $rules;
    }

    /**
     * Advisory rules: printed, never fail the run.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public function warnings(bool $cloud): array
    {
        $warnings = [
            'log_level_debug' => ['LOG_LEVEL is debug in production (disk use, data leakage)', ! $this->prod() || ! $this->logsDebug()],
        ];
        $warnings['cors_wildcard_cookie_paths'] = ['CORS is open to * on paths beyond api/* that may carry cookies/session (check cors.paths)', ! $this->prod() || ! $this->corsWildcard() || $this->corsApiOnly()];
        $warnings['session_domain_set'] = ['SESSION_DOMAIN is set: cookies are shared across subdomains; empty (host-only) is safest for reseller custom domains', ! $this->prod() || trim((string) config('session.domain')) === ''];
        $warnings['session_cookie_prefix'] = ['Session cookie name should start with __Host- (needs Secure, Path=/, no Domain); XSRF-TOKEN and remember-me cookies follow the same session config flags', ! $this->prod() || str_starts_with((string) config('session.cookie'), '__Host-') || ! empty(config('hosts.cookies')) && config('hosts.mode') === 'split'];
        if ($cloud) {
            $warnings['media_backup_off'] = ['The media mirror is off (Cloud): uploaded files are in no off-box backup (Settings → General)', $this->mediaMirrorOn()];
        }
        // P6-166: a box that dies takes its local dumps with it.
        $warnings['offbox_target'] = ['No off-box backup target: DB dumps live only on this server (set AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_BUCKET; docs/ops/backup-restore.md)', ! $this->prod() || ((bool) config('filesystems.disks.s3.key') && (bool) config('filesystems.disks.s3.bucket'))];
        $warnings['restore_check_off'] = ['The weekly backup restore drill is off in production (Settings → General)', ! $this->prod() || PlatformBackupRun::restoreCheckEnabled()];
        if ($cloud) {
            $warnings['pharmacy_countries'] = ['PHARMACY_ENABLED_COUNTRIES is empty (Cloud): pharmacy module off everywhere', (array) config('pharmacy.enabled_countries') !== []];
        }

        return $warnings;
    }

    public function handle(): int
    {
        $failed = [];
        foreach ($this->rules((bool) $this->option('cloud')) as $rule => [$message, $ok]) {
            if (! $ok) {
                $failed[$rule] = $message;
            }
        }

        $warned = [];
        foreach ($this->warnings((bool) $this->option('cloud')) as $rule => [$message, $ok]) {
            if (! $ok) {
                $warned[$rule] = $message;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $failed === [], 'failed' => $failed, 'warnings' => $warned], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }
        foreach ($warned as $rule => $message) {
            $this->warn("  warning [{$rule}] {$message}");
        }
        if ($failed === []) {
            $this->info('Preflight passed.');
        } else {
            $this->error(count($failed).' preflight rule(s) failed:');
            foreach ($failed as $rule => $message) {
                $this->line("  [{$rule}] {$message}");
            }
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function corsWildcard(): bool
    {
        return in_array('*', (array) config('cors.allowed_origins'), true);
    }

    private function corsApiOnly(): bool
    {
        foreach ((array) config('cors.paths', ['api/*']) as $path) {
            if (! str_starts_with((string) $path, 'api/')) {
                return false;
            }
        }

        return true;
    }

    /** 'ok' | 'missing' | 'bad'. Never returns or prints the key. */
    private function mediaMirrorOn(): bool
    {
        return (bool) config('settings.platform_media_backup_enabled');
    }

    private function backupKeyState(): string
    {
        try {
            return BackupCrypto::currentKey() === null ? 'missing' : 'ok';
        } catch (\Throwable) {
            return 'bad';
        }
    }

    private function prod(): bool
    {
        return config('app.env') === 'production';
    }

    /** The default log channel (or any channel a stack fans out to) logs at debug. */
    private function logsDebug(): bool
    {
        $default = (string) config('logging.default');
        $channels = config("logging.channels.{$default}.driver") === 'stack'
            ? (array) config("logging.channels.{$default}.channels")
            : [$default];
        foreach ($channels as $channel) {
            if (strtolower((string) config("logging.channels.{$channel}.level", 'debug')) === 'debug') {
                return true;
            }
        }

        return false;
    }

    /** Every abuse guard (admin DB override included) resolves to enforce. Only checked when APP_ENV=production. */
    private function abuseEnforced(): bool
    {
        if (config('app.env') !== 'production') {
            return true;
        }
        foreach (array_keys((array) config('abuse.modes')) as $guard) {
            if (AbuseGuard::mode((string) $guard) !== AbuseGuard::ENFORCE) {
                return false;
            }
        }

        return true;
    }

    /** "*" lets anyone forge X-Forwarded-For; proxy mode with nothing trusted puts every visitor in one IP bucket. */
    private function trustedProxiesOk(): bool
    {
        $proxies = config('trustedproxy.proxies');
        if ($proxies === '*' || in_array('*', (array) $proxies, true)) {
            return false;
        }
        $proxyMode = (bool) preg_match('/^:\d+$/', trim((string) config('trustedproxy.server_name')));

        return ! $proxyMode || (array) $proxies !== [];
    }

    private function strongKey(mixed $key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }
        $raw = Str::startsWith($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return is_string($raw) && strlen($raw) >= 32 && count(array_unique(str_split($raw))) > 8;
    }
}
