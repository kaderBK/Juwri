<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Views;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Support\HexColor;

/**
 * Small helpers for "public site" Blade views that rendered on every request: bio blocks, marketing
 * pages, owner-facing helper pages. They live in a class because a closure literal (`fn (...)` /
 * `function (...)`) inside a compiled view keeps about 250 bytes in the worker on every include on
 * PHP 8.4, whether or not it runs (P6-36/P6-36b soak): a view rendered per request with a closure
 * grows a long-lived Octane worker. Pair with \Juwri\Core\Support\ViewHelpers (storefront / kiosk /
 * dashboard kit); this file holds view helpers that are out of scope there or owned by the public
 * site module.
 */
final class PublicSiteViewHelpers
{
    /**
     * Bio gallery entries: trimmed image and caption, with the URL resolved through media_url.
     *
     * @param  array<int|string, mixed>  $raw
     * @return array<int, array{url: string, caption: string}>
     */
    public static function galleryImageEntries(array $raw, ?int $storeId): array
    {
        $out = [];
        foreach (array_slice($raw, 0, 12) as $r) {
            if (is_array($r)) {
                $src = trim((string) ($r['image'] ?? ''));
                $caption = trim((string) ($r['caption'] ?? ''));
            } else {
                $src = trim((string) $r);
                $caption = '';
            }
            if ($src === '') {
                continue;
            }
            $url = Str::startsWith($src, ['http://', 'https://', '/']) ? $src : media_url('storage/bio/', $src, $storeId);

            $out[] = ['url' => $url, 'caption' => $caption];
        }

        return $out;
    }

    /**
     * Font weights: cast to int, keep only the 100..900 range, sorted ascending.
     *
     * @param  iterable<mixed>  $weights
     * @return array<int, int>
     */
    public static function validFontWeights(iterable $weights): array
    {
        $out = [];
        foreach ($weights as $w) {
            $n = (int) $w;
            if ($n >= 100 && $n <= 900) {
                $out[] = $n;
            }
        }
        sort($out);

        return $out !== [] ? $out : [400];
    }

    /** Trimmed, sanitised CSS value (the colour/radius/length/font tokens a preset $slug draws). */
    public static function safeCssValue(mixed $v, mixed $fb): string
    {
        $use = $v !== null && $v !== '' ? $v : $fb;

        return preg_replace('/[^#a-z0-9(),.%\s_-]/i', '', (string) $use) ?? '';
    }

    /** The current `current_language()` row, or `null` when not defined / it throws (rescue, no report). */
    public static function currentLanguageOrNull(): mixed
    {
        if (! function_exists('current_language')) {
            return null;
        }
        try {
            return current_language();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** `current_language()` when defined, else `null` (no exception handling, like the inline original). */
    public static function currentLanguageRaw(): mixed
    {
        return function_exists('current_language') ? current_language() : null;
    }

    /** The current locale code from `get_lang()`, or `app()->getLocale()` when not defined / it throws. */
    public static function getLangOrLocale(): string
    {
        if (function_exists('get_lang')) {
            try {
                return (string) get_lang();
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return (string) app()->getLocale();
    }

    /** A testimonial's localised field for the current locale, falling back to a default value. */
    public static function testimonialLocalizedField(mixed $t, string $field, mixed $fallback): mixed
    {
        $lang = function_exists('get_lang') ? (string) get_lang() : '';
        $translations = $t->translations ?? null;
        $byLang = ! empty($translations) ? ($translations->{$lang}->{$field} ?? null) : null;

        return ! empty($byLang) ? $byLang : $fallback;
    }

    /**
     * Types of enabled blocks on a site page (enabled unless the `enabled` flag is false).
     *
     * @param  iterable<mixed>  $blocks
     * @return array<int, mixed>
     */
    public static function enabledBlockTypes(iterable $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if (is_array($b) && ($b['enabled'] ?? true) !== false) {
                $out[] = $b['type'] ?? null;
            }
        }

        return $out;
    }

    /**
     * Footer guide links [route, label] for a list of guide slugs (named when known, else ucwords the slug).
     *
     * @param  array<int, string>  $slugs
     * @param  array<string, string>  $names
     * @return array<int, array{0: string, 1: string}>
     */
    public static function guideFooterLinks(array $slugs, array $names): array
    {
        $out = [];
        foreach ($slugs as $slug) {
            $out[] = [route('guides.show', $slug), $names[$slug] ?? ucwords(str_replace('-', ' ', $slug))];
        }

        return $out;
    }

    /**
     * Footer legal links [url, label] for a list of legal document keys.
     *
     * @param  array<int, string>  $keys
     * @return array<int, array{0: string, 1: string}>
     */
    public static function legalFooterLinks(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (LegalDocuments::hiddenAsUnfilled($key)) {
                continue;
            }
            $out[] = [LegalDocuments::url($key), LegalDocuments::label($key)];
        }

        return $out;
    }

    /**
     * Languages offered by the locale-suggest banner: code, name, direction, URL (without the current locale).
     *
     * @param  iterable<mixed>  $langs
     * @return Collection<int, array{code: string, name: string, dir: string, url: mixed}>
     */
    public static function localeSuggestOffered(iterable $langs, mixed $current): Collection
    {
        $currentCode = $current ? strtolower((string) ($current->code ?? '')) : null;
        $out = [];
        foreach ($langs as $l) {
            if ($currentCode !== null && strtolower((string) ($l->code ?? '')) === $currentCode) {
                continue;
            }
            $code = strtolower((string) ($l->code ?? ''));
            $out[] = [
                'code' => $code,
                'name' => (string) ($l->name ?? ''),
                'dir' => (string) ($l->direction ?? 'ltr'),
                'url' => function_exists('lang_url') ? lang_url($l->code) : url('lang/'.$l->code),
            ];
        }

        return new Collection($out);
    }

    /**
     * The longest string in an iterable (first occurrence when tied, like `sortByDesc(...)->first()`).
     *
     * @param  iterable<mixed>  $strings
     */
    public static function widestString(iterable $strings): mixed
    {
        $widest = null;
        $maxLen = -1;
        foreach ($strings as $s) {
            $len = mb_strlen((string) $s);
            if ($len > $maxLen) {
                $maxLen = $len;
                $widest = $s;
            }
        }

        return $widest;
    }

    /**
     * Compare table cell: bool -> tick/dash with an a11y label; string -> plain value.
     *
     * @return array{cls: string, text: string, aria: mixed}
     */
    public static function compareCell(mixed $v): array
    {
        if ($v === true) {
            return ['cls' => 'mk-yes', 'text' => '✓', 'aria' => ___('Included')];
        }
        if ($v === false) {
            return ['cls' => 'mk-no', 'text' => '—', 'aria' => ___('Not included')];
        }

        return ['cls' => 'cmp-val', 'text' => (string) $v, 'aria' => null];
    }

    /** Hex brand colour: reseller primary colour, else the platform theme colour (validated `#RRGGBB`). */
    public static function headBrandColor(mixed $mkReseller, mixed $settings): ?string
    {
        $hex = ($mkReseller && ! empty($mkReseller->primary_color)) ? $mkReseller->primary_color : (data_get($settings, 'theme_color') ?: null);

        return HexColor::normalize($hex); // BL-036 R1
    }

    /**
     * Theme-store URL with the given query params overridden/removed (nulls/empty strings/empty arrays dropped).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function marketplaceUrlWithOverrides(array $overrides): string
    {
        $req = function_exists('request') ? request() : null;
        $current = $req ? (array) $req->query() : [];
        $params = [];
        foreach (array_merge($current, $overrides) as $k => $v) {
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $params[$k] = $v;
        }
        $query = http_build_query($params);

        return route('marketplace.index').($query !== '' ? '?'.$query : '');
    }

    /**
     * Bookings times URL with extra params merged into a base set of `keep` params (null/'' dropped).
     *
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $keep
     */
    public static function bookingsTimesUrl(
        array $extra,
        array $keep,
        mixed $reschedule,
        mixed $slug,
        mixed $token,
    ): string {
        $base = $reschedule
            ? route('bookings.rescheduleTimes', ['slug' => $slug, 'token' => $token])
            : route('bookings.times', ['slug' => $slug]);
        $params = [];
        foreach ($extra + $keep as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $params[$k] = $v;
        }

        return $base.'?'.http_build_query($params);
    }

    /**
     * WhatsApp provider select options: known provider keys get a friendly label, others fall back to `ucfirst`.
     *
     * @param  iterable<mixed>  $providers
     * @return array<string, string>
     */
    public static function waProviderOptions(iterable $providers): array
    {
        $labels = ['link' => 'wa.me link (free)', 'twilio' => 'Twilio', 'meta' => 'Meta Cloud API'];
        $out = [];
        foreach ($providers as $p) {
            $out[$p] = $labels[$p] ?? ucfirst((string) $p);
        }

        return $out;
    }

    /**
     * Custom-block template select options: `id => translated title`.
     *
     * @param  iterable<mixed>  $templates
     * @return array<int|string, string>
     */
    public static function templateOptionLabels(iterable $templates): array
    {
        $out = [];
        foreach ($templates as $t) {
            $out[$t['id']] = ___($t['title']);
        }

        return $out;
    }

    /**
     * Normalise bio sources/devices rows to a `[label => count]` shape (object or array input).
     *
     * @return array<string, int>
     */
    public static function normaliseInsightsRows(mixed $rows): array
    {
        $out = [];
        foreach ((array) $rows as $k => $v) {
            if (is_array($v)) {
                $label = $v['source'] ?? $v['device'] ?? $v['label'] ?? $k;
                $out[(string) $label] = (int) ($v['count'] ?? $v['clicks'] ?? 0);
            } else {
                $out[(string) $k] = (int) $v;
            }
        }

        return $out;
    }
}
