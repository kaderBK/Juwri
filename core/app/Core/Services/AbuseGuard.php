<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use Illuminate\Support\Facades\Request;
use Juwri\Core\Models\AbuseEvent;

/**
 * Abuse Guard P1 (1.2): the ONE reader every guard consults.
 *
 * Settings live in the Options system (`PlatformSettings::set('abuse_<key>', $v)`)
 * and surface at `config('settings.abuse_<key>')`. Every guard ships in WARN mode:
 * a would-be block is logged to abuse_events and the request proceeds. Only when
 * the admin flips a guard to ENFORCE does hit() return false (caller 429s/aborts).
 *
 *   mode('ai')                 → off | warn | enforce   (default enforce in production, else warn)
 *   value('ai', 'user_daily_cap', 200) → numeric/string setting with code default
 *   hit('ai', 'generate', [...]) → true = allow, false = deny (enforce only)
 */
class AbuseGuard
{
    public const OFF = 'off';

    public const WARN = 'warn';

    public const ENFORCE = 'enforce';

    /**
     * Active mode for a guard. Code default: ENFORCE in production, WARN elsewhere (config/abuse.php).
     *
     * Precedence (S6): explicit admin DB setting → per-guard env override →
     * global default (env ABUSE_MODE via config('abuse.default_mode')).
     */
    public static function mode(string $guard): string
    {
        // 1. Explicit admin DB override (Options → settings.abuse_<guard>_mode).
        $dbMode = config('settings.abuse_'.$guard.'_mode');
        if (self::isValidMode($dbMode)) {
            return $dbMode;
        }

        // 2. Per-guard env override (config/abuse.php modes map).
        $envMode = config('abuse.modes.'.$guard);
        if (self::isValidMode($envMode)) {
            return $envMode;
        }

        // 3. Global default (env ABUSE_MODE; config default enforce in production, else warn).
        $default = config('abuse.default_mode', self::WARN);

        return self::isValidMode($default) ? $default : self::WARN;
    }

    /** True when $mode is one of the three recognised modes. */
    protected static function isValidMode(mixed $mode): bool
    {
        return in_array($mode, [self::OFF, self::WARN, self::ENFORCE], true);
    }

    /** A numeric/string setting for a guard with a code default. */
    public static function value(string $guard, string $key, $default = null)
    {
        $v = config('settings.abuse_'.$guard.'_'.$key, null);

        return ($v === null || $v === '') ? $default : $v;
    }

    /**
     * Register a would-be block. Returns TRUE when the request may proceed.
     *   off     → allow, no log.
     *   warn    → log an abuse_event, allow.
     *   enforce → log an abuse_event, deny (return false).
     */
    public static function hit(string $guard, string $action, array $ctx = []): bool
    {
        $mode = self::mode($guard);

        if ($mode === self::OFF) {
            return true;
        }

        self::log($guard, $action, $mode, $ctx);

        return $mode !== self::ENFORCE;
    }

    /** Write one evidence row. Best-effort — never let logging break the request. */
    protected static function log(string $guard, string $action, string $mode, array $ctx): void
    {
        try {
            AbuseEvent::create([
                'guard' => $guard,
                'action' => $action,
                'user_id' => $ctx['user_id'] ?? optional(auth()->user())->id,
                'reseller_id' => $ctx['reseller_id'] ?? null,
                'ip' => $ctx['ip'] ?? (self::hasRequest() ? Request::ip() : null),
                'meta' => $ctx['meta'] ?? (empty($ctx) ? null : $ctx),
                'mode' => $mode,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // swallow — abuse logging must never take down a real request
        }
    }

    protected static function hasRequest(): bool
    {
        return app()->bound('request') && app('request') !== null;
    }

    /** Count of events for a guard in the trailing $hours (admin badge). */
    public static function recentCount(string $guard, int $hours = 24): int
    {
        return AbuseEvent::where('guard', $guard)
            ->where('created_at', '>=', now()->subHours($hours))
            ->count();
    }
}
