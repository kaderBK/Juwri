<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Pci;

use Juwri\Core\Editions\EditionPackages;

/**
 * PCI DSS 4.0 req. 6.4.3: the authorised script list of every payment page, kept as code.
 *
 * config/payment_scripts.php holds the Community pages and scripts; an installed edition package adds its own
 * through its manifest key `payment_scripts` (a PHP file returning `pages` + `scripts` in the same shape). A
 * script on a payment page that is not listed here — or a listed third-party script without its pinned SRI hash —
 * fails the inventory test (tests/Feature/Pci), and its host is not in the page's enforced CSP, so the browser
 * refuses it too.
 *
 * Script entry: `src` (first-party path from the site root, or the full https:// URL of a third-party file;
 * `*` = any characters), `pages` (page keys, `*` wildcards, or `shell:<layout>` = every page of that layout), `reason` (business justification), and for a
 * third-party file either `integrity` (sha384-…, the vendor serves a fixed file) or `sri_exempt` (why SRI is
 * impossible — a vendor SDK updated in place; then the CSP host allow-list is the only pin). `csp` adds the
 * extra sources the script needs (connect-src, frame-src, form-action …); `loaded_by` names the script that
 * injects it when it is not a tag in the page.
 *
 * Page entry: `views` (the Blade views that render it), `shell` (its layout), `kind` (`entry` = hosts payment entry; `choice` = the
 * gateway choice that sends the buyer to payment entry), `reason`, optional `unsafe_eval` (why the page's shell
 * needs eval — the dashboard shell's Alpine) and `csp` extra sources for the page itself.
 */
final class PaymentScriptInventory
{
    /** @var array{pages: array<string, array<string, mixed>>, scripts: array<string, array<string, mixed>>, excluded: array<string, array<string, mixed>>}|null */
    private static ?array $packageEntries = null;

    /** @return array<string, array<string, mixed>> */
    public static function pages(): array
    {
        return self::merged()['pages'];
    }

    /** @return array<string, array<string, mixed>> */
    public static function scripts(): array
    {
        return self::merged()['scripts'];
    }

    /** @return array<string, array<string, mixed>> scripts of a shared layout that the layout switches off on payment pages */
    public static function excluded(): array
    {
        return self::merged()['excluded'];
    }

    /** The `excluded` entry id for $src on $page, or null. */
    public static function excludedMatch(string $src, string $page): ?string
    {
        $normalized = self::normalize($src);
        foreach (self::excluded() as $id => $entry) {
            if (self::wildcard((string) ($entry['src'] ?? ''), $normalized) && self::onPage($entry, $page)) {
                return (string) $id;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function page(string $key): ?array
    {
        return self::pages()[$key] ?? null;
    }

    /** The payment page a Blade view renders, or null. */
    public static function pageForView(string $view): ?string
    {
        foreach (self::pages() as $key => $page) {
            if (in_array($view, (array) ($page['views'] ?? []), true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /** @return list<string> every view of every payment page */
    public static function views(): array
    {
        $views = [];
        foreach (self::pages() as $page) {
            foreach ((array) ($page['views'] ?? []) as $view) {
                $views[] = (string) $view;
            }
        }

        return array_values(array_unique($views));
    }

    /** Test seam: forget the edition packages' entries (EditionPackages::fake). */
    public static function flush(): void
    {
        self::$packageEntries = null;
    }

    /**
     * A script URL as the inventory compares it: first-party → its path from the site root (no query: the
     * `?v=` cache busters change on every deploy); third-party → https://host/path?query (protocol-relative
     * `//host` is read as https, which is what a payment page must load).
     */
    public static function normalize(string $src): string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5));
        if (str_starts_with($src, '//')) {
            $src = 'https:'.$src;
        }
        $parts = parse_url($src);
        if ($parts === false) {
            return $src;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '' || in_array($host, self::firstPartyHosts(), true)) {
            $base = self::basePath();
            if ($base !== '' && str_starts_with($path, $base.'/')) {
                $path = substr($path, strlen($base));
            }

            return '/'.ltrim($path, '/');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public static function isFirstParty(string $normalized): bool
    {
        return str_starts_with($normalized, '/');
    }

    /** The inventory entry id that authorises $src on $page, or null. */
    public static function match(string $src, string $page): ?string
    {
        $normalized = self::normalize($src);
        foreach (self::scripts() as $id => $entry) {
            if (self::wildcard((string) ($entry['src'] ?? ''), $normalized) && self::onPage($entry, $page)) {
                return (string) $id;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $entry */
    public static function onPage(array $entry, string $page): bool
    {
        $shell = (string) (self::page($page)['shell'] ?? '');
        foreach ((array) ($entry['pages'] ?? []) as $pattern) {
            $pattern = (string) $pattern;
            // `shell:<name>` = every page drawn by that layout (storefront, bare, dashboard, reseller, marketplace …).
            if (str_starts_with($pattern, 'shell:') ? $shell !== '' && substr($pattern, 6) === $shell : self::wildcard($pattern, $page)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What is wrong with a rendered payment page against the inventory: unlisted scripts, third-party scripts
     * without their pinned SRI hash, plain-http scripts, inline scripts without the CSP nonce.
     *
     * @return list<string>
     */
    public static function violations(string $html, string $page): array
    {
        $out = [];
        $set = ScriptSet::fromHtml($html);
        foreach ($set->external() as $tag) {
            $src = (string) $tag->src;
            $normalized = self::normalize($src);
            if (str_starts_with($normalized, 'http://')) {
                $out[] = "plain-http script {$normalized}";
            }
            $id = self::match($src, $page);
            if ($id === null) {
                $out[] = "unlisted script {$normalized} on {$page}";

                continue;
            }
            $integrity = self::scripts()[$id]['integrity'] ?? null;
            if (is_string($integrity) && $integrity !== '') {
                if ($tag->integrity !== $integrity) {
                    $out[] = "script {$normalized} ({$id}) has integrity ".json_encode($tag->integrity).", the inventory pins {$integrity}";
                }
                if ($tag->crossorigin !== 'anonymous') {
                    $out[] = "script {$normalized} ({$id}) has no crossorigin=\"anonymous\" (SRI on a cross-origin script needs CORS)";
                }
            }
        }
        foreach ($set->inline() as $tag) {
            if (! $tag->nonced) {
                $out[] = 'inline script without the CSP nonce on '.$page.': '.mb_substr(trim(preg_replace('/\s+/', ' ', $tag->body) ?? ''), 0, 80);
            }
        }

        return $out;
    }

    /**
     * The sources a page's CSP grants for $directive: every third-party script of the page (its `csp.script-src`,
     * or its exact URL) plus each entry's and the page's own extra sources for that directive.
     *
     * @return list<string>
     */
    public static function cspSources(string $page, string $directive): array
    {
        $sources = [];
        foreach (self::scripts() as $entry) {
            if (! self::onPage($entry, $page)) {
                continue;
            }
            $csp = (array) ($entry['csp'] ?? []);
            if ($directive === 'script-src' && ! self::isFirstParty((string) ($entry['src'] ?? '/'))) {
                foreach ((array) ($csp['script-src'] ?? [self::cspSourceOf((string) $entry['src'])]) as $source) {
                    $sources[] = (string) $source;
                }
            } elseif ($directive !== 'script-src') {
                foreach ((array) ($csp[$directive] ?? []) as $source) {
                    $sources[] = (string) $source;
                }
            }
        }
        foreach ((array) ((self::page($page)['csp'] ?? [])[$directive] ?? []) as $source) {
            $sources[] = (string) $source;
        }

        return array_values(array_unique($sources));
    }

    /** A third-party file's CSP source: its exact URL (a path-exact source), or scheme://host when it has a wildcard or query. */
    public static function cspSourceOf(string $src): string
    {
        $parts = parse_url($src);
        $origin = strtolower((string) ($parts['scheme'] ?? 'https')).'://'.strtolower((string) ($parts['host'] ?? ''));
        if (str_contains($src, '*') || isset($parts['query'])) {
            return $origin;
        }

        return $origin.($parts['path'] ?? '');
    }

    /**
     * Inventory self-check: every entry justified, every third-party file either SRI-pinned or exempt with a reason.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $out = [];
        foreach (self::pages() as $key => $page) {
            if (trim((string) ($page['reason'] ?? '')) === '') {
                $out[] = "page {$key}: no reason";
            }
            if (! in_array($page['kind'] ?? null, ['entry', 'choice'], true)) {
                $out[] = "page {$key}: kind must be entry or choice";
            }
            if ((array) ($page['views'] ?? []) === []) {
                $out[] = "page {$key}: no views";
            }
            if (array_key_exists('unsafe_eval', $page) && trim((string) $page['unsafe_eval']) === '') {
                $out[] = "page {$key}: unsafe_eval needs its reason";
            }
        }
        foreach (self::scripts() as $id => $entry) {
            $src = (string) ($entry['src'] ?? '');
            if ($src === '') {
                $out[] = "script {$id}: no src";
            }
            if (trim((string) ($entry['reason'] ?? '')) === '') {
                $out[] = "script {$id}: no business reason";
            }
            if ((array) ($entry['pages'] ?? []) === []) {
                $out[] = "script {$id}: no pages";
            }
            if (self::isFirstParty($src)) {
                continue;
            }
            if (! str_starts_with($src, 'https://')) {
                $out[] = "script {$id}: a third-party script must be https://";
            }
            $integrity = (string) ($entry['integrity'] ?? '');
            $exempt = trim((string) ($entry['sri_exempt'] ?? ''));
            if ($integrity === '' && $exempt === '') {
                $out[] = "script {$id}: third-party script needs an SRI hash (integrity) or the reason it cannot have one (sri_exempt)";
            }
            if ($integrity !== '' && ! preg_match('#^sha(256|384|512)-[A-Za-z0-9+/]+=*$#', $integrity)) {
                $out[] = "script {$id}: integrity is not a sha256/384/512 SRI hash";
            }
            if ($integrity !== '' && str_contains($src, '*')) {
                $out[] = "script {$id}: an SRI-pinned file needs an exact src";
            }
        }

        return $out;
    }

    private static function wildcard(string $pattern, string $value): bool
    {
        if ($pattern === '') {
            return false;
        }
        $regex = '#^'.str_replace('\*', '.*', preg_quote($pattern, '#')).'$#';

        return preg_match($regex, $value) === 1;
    }

    /** @return list<string> */
    private static function firstPartyHosts(): array
    {
        $hosts = [];
        foreach ([config('app.url'), config('app.asset_url')] as $url) {
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }
        if (app()->bound('request')) {
            $hosts[] = strtolower(request()->getHost());
        }

        return array_values(array_unique($hosts));
    }

    private static function basePath(): string
    {
        $path = parse_url((string) config('app.url'), PHP_URL_PATH);

        return is_string($path) ? rtrim($path, '/') : '';
    }

    /** @return array{pages: array<string, array<string, mixed>>, scripts: array<string, array<string, mixed>>, excluded: array<string, array<string, mixed>>} */
    private static function merged(): array
    {
        $extra = self::packageEntries();

        /** @var array<string, array<string, mixed>> $pages */
        $pages = array_merge((array) config('payment_scripts.pages', []), $extra['pages']);
        /** @var array<string, array<string, mixed>> $scripts */
        $scripts = array_merge((array) config('payment_scripts.scripts', []), $extra['scripts']);
        /** @var array<string, array<string, mixed>> $excluded */
        $excluded = array_merge((array) config('payment_scripts.excluded', []), $extra['excluded']);

        return ['pages' => $pages, 'scripts' => $scripts, 'excluded' => $excluded];
    }

    /** @return array{pages: array<string, array<string, mixed>>, scripts: array<string, array<string, mixed>>, excluded: array<string, array<string, mixed>>} the edition packages' entries */
    private static function packageEntries(): array
    {
        if (self::$packageEntries !== null) {
            return self::$packageEntries;
        }
        $pages = $scripts = $excluded = [];
        foreach (EditionPackages::all() as $package) {
            $file = $package['manifest']['payment_scripts'] ?? null;
            if (! is_string($file) || ! is_file($file)) {
                continue;
            }
            $extra = (static fn (string $f): mixed => require $f)($file);
            if (is_array($extra)) {
                $pages = array_merge($pages, (array) ($extra['pages'] ?? []));
                $scripts = array_merge($scripts, (array) ($extra['scripts'] ?? []));
                $excluded = array_merge($excluded, (array) ($extra['excluded'] ?? []));
            }
        }

        /** @var array<string, array<string, mixed>> $pages */
        /** @var array<string, array<string, mixed>> $scripts */
        /** @var array<string, array<string, mixed>> $excluded */
        return self::$packageEntries = ['pages' => $pages, 'scripts' => $scripts, 'excluded' => $excluded];
    }
}
