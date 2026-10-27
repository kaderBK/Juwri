<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * P6-112: error pages that need nothing from the database or the session.
 *
 * A 500 or 503 is most likely exactly when the database, cache or session store is down, so these pages
 * (a) pick the language from the language cookie, then Accept-Language, then the configured default, reading
 * only the request and the lang/ folder; (b) take direction from a fixed list of right-to-left codes; (c) use
 * the app name from config, not the site settings; (d) render as plain PHP, so no view composer (which queries
 * settings, themes and languages) runs; (e) never print the exception message, file or trace.
 *
 * Nothing here is remembered between calls except the lang/ folder listing (a pure file read), so it is safe in
 * a long-lived Octane worker.
 */
final class ErrorPage
{
    /** Plain (unencrypted) cookie the storefront and the language banner write; see App\Http\Middleware\EncryptCookies. */
    public const LANG_COOKIE = 'Quick_user_lang_code';

    /** Locales written right-to-left. */
    public const RTL = ['ar', 'fa', 'he', 'ur', 'ps', 'sd', 'dv', 'yi', 'ckb', 'arc'];

    /** English source text per status; translated through lang/<locale>/lang.php with the usual slug key. */
    private const TEXT = [
        403 => ['Access forbidden', 'You do not have permission to view this resource.'],
        404 => ['Page not found', 'The page you are looking for might have been moved or no longer exists.'],
        413 => ['File too large', 'Your host stopped this upload — the file exceeds :limit MB. To send a larger file, ask your host to raise upload_max_filesize and post_max_size in php.ini.'],
        419 => ['Session expired', 'For your security, your session has timed out. Reload the page and try again.'],
        429 => ['Slow down a little', 'Too many requests in a short time. Wait a moment, then try again.'],
        500 => ['Something went wrong', 'We hit an unexpected error. Our team has been notified.'],
        503 => ['Down for maintenance', 'We are making the app better. Back online shortly.'],
    ];

    /**
     * Statuses answered by this class instead of a Blade view (they must work with the database down).
     * 413 is included because a too-large POST can come in while the database is unreachable — the user
     * still needs the upload-too-large hint, not a 500.
     */
    public static function handles(int $status): bool
    {
        return $status >= 500 || $status === 413;
    }

    /** @return list<string> locale codes that have a lang/<code>/lang.php */
    public static function supportedLocales(): array
    {
        static $codes = null;
        if ($codes === null) {
            $codes = [];
            foreach (glob(lang_path('*/lang.php')) ?: [] as $file) {
                $codes[] = basename(dirname($file));
            }
            sort($codes);
        }

        return $codes;
    }

    public static function defaultLocale(): string
    {
        $default = strtolower((string) config('app.locale', 'en'));

        return in_array($default, self::supportedLocales(), true) ? $default : 'en';
    }

    /** Cookie, then Accept-Language, then the default. No database, no session. */
    public static function resolveLocale(?Request $request = null): string
    {
        $supported = self::supportedLocales();
        $request ??= request();

        $cookie = strtolower(trim((string) $request->cookies->get(self::LANG_COOKIE, '')));
        if ($cookie !== '' && in_array($cookie, $supported, true)) {
            return $cookie;
        }

        $header = (string) $request->headers->get('Accept-Language', '');
        $ranked = [];
        foreach (explode(',', $header) as $i => $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $param, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($q > 0) {
                $ranked[] = [$q, -$i, $tag];
            }
        }
        usort($ranked, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        foreach ($ranked as [, , $tag]) {
            $primary = explode('-', str_replace('_', '-', $tag))[0];
            if (in_array($tag, $supported, true)) {
                return $tag;
            }
            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return self::defaultLocale();
    }

    public static function isRtl(string $locale): bool
    {
        return in_array(strtolower($locale), self::RTL, true);
    }

    /** One source string in `$locale`, falling back to the English source text. */
    public static function t(string $text, string $locale): string
    {
        $key = 'lang.'.Str::slug($text, '_');
        $found = app('translator')->get($key, [], $locale, false);

        return is_string($found) && $found !== '' && $found !== $key ? $found : $text;
    }

    /**
     * Substitute `:key` placeholders in a translated string with ints/strings (values cast to string).
     *
     * @param  array<string, mixed>  $context
     */
    public static function interpolate(string $text, array $context): string
    {
        if ($context === []) {
            return $text;
        }

        return preg_replace_callback('/:([a-z_][a-z0-9_]*)/i', static function (array $m) use ($context): string {
            $key = $m[1];

            return array_key_exists($key, $context) ? (string) $context[$key] : $m[0];
        }, $text) ?? $text;
    }

    /**
     * Translated title and description for a status.
     *
     * @param  array<string, scalar>  $context  values for any `:key` placeholders in the source text (e.g. `['limit' => 8]` for 413)
     * @return array{0: string, 1: string}
     */
    public static function text(int $status, string $locale, array $context = []): array
    {
        [$title, $description] = self::TEXT[$status] ?? self::TEXT[500];

        return [
            self::interpolate(self::t($title, $locale), $context),
            self::interpolate(self::t($description, $locale), $context),
        ];
    }

    /**
     * Complete HTML document. Inline CSS only: no asset, font, script or request leaves the page.
     *
     * @param  array<string, scalar>  $context  placeholder values for `:key` substitutions in the source text
     */
    public static function html(int $status, string $locale, array $context = []): string
    {
        [$title, $description] = self::text($status, $locale, $context);
        $dir = self::isRtl($locale) ? 'rtl' : 'ltr';
        $name = (string) config('app.name', 'Juwri');
        $home = self::t('Back to home', $locale);
        $label = self::t('Error', $locale);
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $nonce = '';
        try {
            $n = app()->bound('csp-nonce') ? (string) app('csp-nonce') : '';
            $nonce = $n !== '' ? ' nonce="'.$e($n).'"' : '';
        } catch (Throwable) {
        }

        return '<!DOCTYPE html>'."\n"
            .'<html lang="'.$e($locale).'" dir="'.$dir.'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="color-scheme" content="light dark"><meta name="robots" content="noindex, nofollow">'
            .'<title>'.$e($title).' - '.$e($name).'</title>'
            .'<style'.$nonce.'>'.self::css().'</style></head>'
            .'<body class="err" data-error-status="'.$status.'"><main>'
            .'<p class="o">'.$e($label).'</p><h1 class="c">'.$status.'</h1><h2>'.$e($title).'</h2>'
            .'<p class="d">'.$e($description).'</p><a class="b" href="/">'.$e($home).'</a>'
            .'</main></body></html>';
    }

    /**
     * Response for a status handled here. Headers (Retry-After, Refresh) pass through untouched.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, scalar>  $context  placeholder values for `:key` substitutions in the source text
     */
    public static function response(int $status, ?Request $request = null, array $headers = [], array $context = []): Response
    {
        $locale = self::resolveLocale($request);

        return new Response(
            self::html($status, $locale, $context),
            $status,
            $headers + ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'Content-Language' => $locale],
        );
    }

    /**
     * P6-173: last-resort fallback Response.
     *
     * Used when even the normal ErrorPage::response() path blew up (the database is down, the translator
     * is half-constructed, the view loader's gone). Touches nothing on disk, no config, no translator,
     * no app() calls, no views. The page is a self-contained English HTML string with an inline style.
     * Never prints the exception message, file or trace.
     */
    public static function emergency(int $status): Response
    {
        $title = $status === 503 ? 'Down for maintenance' : 'Something went wrong';
        $body = '<!DOCTYPE html><html lang="en" dir="ltr"><head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="color-scheme" content="light dark">'
            .'<meta name="robots" content="noindex, nofollow">'
            .'<title>'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</title>'
            .'<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            .'padding:16px;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif;'
            .'background:#fff;color:#1f2937;text-align:center}'
            .'main{max-width:480px}p{margin:0 0 8px;color:#6b7280}h1{font-size:4.5rem;line-height:1;'
            .'margin:0 0 8px;color:#6366f1}h2{font-size:1.25rem;margin:0 0 24px}'
            .'a{display:inline-block;padding:10px 20px;border-radius:8px;background:#4f46e5;color:#fff;'
            .'text-decoration:none;font-weight:600}'
            .'@media (prefers-color-scheme:dark){body{background:#121212;color:#e5e7eb}p{color:#9ca3af}}</style>'
            .'</head><body><main>'
            .'<p>Error</p><h1>'.(int) $status.'</h1>'
            .'<h2>'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</h2>'
            .'<a href="/">Back to home</a>'
            .'</main></body></html>';

        return new Response(
            $body,
            $status,
            ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store'],
        );
    }

    public static function css(): string
    {
        return 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;'
            .'font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif;background:#fff;color:#1f2937;text-align:center}'
            .'main{max-width:480px}.o{margin:0 0 8px;font-size:.8rem;letter-spacing:.08em;text-transform:uppercase;color:#6b7280}'
            .'.c{font-size:4.5rem;line-height:1;margin:0 0 8px;color:#6366f1}h2{font-size:1.25rem;margin:0 0 8px}'
            .'.d{color:#6b7280;margin:0 0 24px}.b{display:inline-block;padding:10px 20px;border-radius:8px;background:#4f46e5;color:#fff;'
            .'text-decoration:none;font-weight:600}@media (prefers-color-scheme:dark){body{background:#121212;color:#e5e7eb}.d,.o{color:#9ca3af}}';
    }
}
