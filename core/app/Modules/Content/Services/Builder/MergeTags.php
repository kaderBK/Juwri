<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Builder;

use Illuminate\Support\Carbon;
use Juwri\Core\Support\LocalizedContent;

/**
 * Server-side merge tags for Builder.js html-mode pages (CMS / reseller / biolink).
 *
 * WHY: builder HTML is a stored static string — no Blade, so it cannot show live
 * data (store name, currency, current year…). Operators type a token like
 * `{{store.name}}` in the builder; at RENDER time (the echo choke point, right
 * before Html::cleanPage) we substitute the resolved, HTML-escaped value. No code
 * execution — pure, escaped data interpolation, so it cannot introduce XSS.
 *
 * Two on-page forms are handled, because when a token sits inside a URL attribute
 * (href/src) DOMDocument percent-encodes the braces on save — exactly the gotcha
 * the email builder hit — so `{{store.url}}` may be stored as `%7B%7Bstore.url%7D%7D`.
 *
 * Unknown `{{…}}` tokens are left untouched (a typo stays visible to the operator
 * rather than silently vanishing). Only the allow-listed keys in a values map are
 * replaced; callers build that map from their own in-scope models.
 */
class MergeTags
{
    /**
     * The published token vocabulary — key => human label (for the editor palette
     * and docs). Callers need only supply the keys they can resolve; anything not
     * supplied is left as a literal token.
     *
     * @return array<string, string>
     */
    public static function available(): array
    {
        return [
            'store.name' => 'Store / site name',
            'store.tagline' => 'Store tagline',
            'store.phone' => 'Store phone',
            'store.email' => 'Store email',
            'store.address' => 'Store address',
            'store.url' => 'Store public URL',
            'store.currency' => 'Currency symbol',
            'page.title' => 'This page title',
            'year' => 'Current year',
            'date' => 'Today’s date (localised)',
        ];
    }

    /**
     * Substitute the given values into the HTML. Values are HTML-escaped here, so
     * callers pass RAW strings (never pre-escaped, or they double-encode).
     *
     * @param  array<string, string|int|null>  $values  token key => raw value
     */
    public static function apply(?string $html, array $values): string
    {
        $html = (string) $html;
        if ($html === '' || $values === []) {
            return $html;
        }

        $search = [];
        $replace = [];
        foreach ($values as $key => $value) {
            $safe = htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
            // Plain form: {{ key }} with optional inner whitespace.
            $search[] = '/\{\{\s*'.preg_quote($key, '/').'\s*\}\}/u';
            $replace[] = $safe;
            // Percent-encoded form (token stored inside a URL attribute).
            $search[] = '/%7B%7B\s*'.preg_quote($key, '/').'\s*%7D%7D/iu';
            $replace[] = self::urlSafe($safe);
        }

        return (string) preg_replace($search, $replace, $html);
    }

    /**
     * For the percent-encoded (URL-attribute) token form: keep the already
     * HTML-escaped value but percent-encode only the characters that would break
     * the attribute. A full rawurlencode() would double-encode an ordinary https
     * URL, so we target the risky characters alone.
     */
    private static function urlSafe(string $value): string
    {
        return strtr($value, [' ' => '%20', '"' => '%22', "'" => '%27', '<' => '%3C', '>' => '%3E']);
    }

    /**
     * Build a full token map: EVERY published key is present, filled from $data
     * where available and '' otherwise. Empty-but-known tokens substitute to blank
     * (a cleaner visitor experience than leaking a raw `{{store.phone}}`); only
     * genuinely unknown tokens survive as literals for the operator to notice.
     *
     * @param  array<string, mixed>  $data  key => raw value (missing keys → '')
     * @return array<string, string>
     */
    public static function storeContext(array $data = []): array
    {
        $map = [];
        foreach (array_keys(self::available()) as $key) {
            $map[$key] = isset($data[$key]) ? (string) $data[$key] : '';
        }
        // Always-resolvable defaults (only if the caller didn't supply them).
        // Carbon directly (not the now() facade helper) so this stays usable
        // outside a booted app — e.g. plain-TestCase unit tests.
        if (($map['year'] ?? '') === '') {
            $map['year'] = date('Y');
        }
        if (($map['date'] ?? '') === '') {
            $map['date'] = Carbon::now()->translatedFormat('j F Y');
        }

        return $map;
    }

    /**
     * Template gallery previews: real values where known, a sample brand where
     * blank — so a preview never shows a raw `{{store.name}}` or an empty gap.
     *
     * @param  array<string, string>  $tokens
     * @return array<string, string>
     */
    public static function forPreview(array $tokens = []): array
    {
        $map = self::storeContext($tokens);
        if ($map['store.name'] === '') {
            $map['store.name'] = ___('Your brand');
        }

        return $map;
    }

    /**
     * CMS page tokens (Juwri\Modules\Content\Models\Page + the shared $settings, which is an object
     * on the storefront and an array elsewhere). data_get handles both.
     *
     * @return array<string, string>
     */
    public static function forPage(mixed $page, mixed $settings = null): array
    {
        return self::storeContext([
            'store.name' => (string) (data_get($settings, 'name') ?? data_get($settings, 'site_name') ?? ''),
            'store.tagline' => (string) (data_get($settings, 'tagline') ?? ''),
            'store.email' => (string) (data_get($settings, 'email') ?? ''),
            'store.phone' => (string) (data_get($settings, 'phone') ?? ''),
            'store.address' => (string) (data_get($settings, 'address') ?? ''),
            'store.currency' => (string) (data_get($settings, 'currency_symbol') ?? data_get($settings, 'currency') ?? ''),
            'store.url' => url('/'),
            'page.title' => (string) (data_get($page, 'title') ?? ''),
        ]);
    }

    /**
     * Reseller white-label page tokens (a reseller row + optional page; Reseller module, ee).
     *
     * @return array<string, string>
     */
    public static function forReseller(mixed $reseller, mixed $page = null): array
    {
        // Reseller page titles are per-locale maps ({"en": "…", "ar": "…"}); resolve
        // through the one locale-fallback helper instead of casting the array.
        $title = data_get($page, 'title');
        if (is_array($title)) {
            $fallback = (string) (data_get($reseller, 'content_default_locale') ?: config('app.locale', 'en'));
            $title = LocalizedContent::resolve($title, get_lang(), $fallback);
        }

        return self::storeContext([
            'store.name' => (string) (data_get($reseller, 'brand_name') ?? ''),
            'store.email' => (string) (data_get($reseller, 'support_email') ?? ''),
            'store.url' => url('/'),
            'page.title' => is_scalar($title) ? (string) $title : '',
        ]);
    }

    /**
     * Biolink / storefront store tokens (Juwri\Core\Models\Store).
     *
     * @return array<string, string>
     */
    public static function forPost(mixed $store): array
    {
        $slug = (string) (data_get($store, 'slug') ?? '');

        return self::storeContext([
            'store.name' => (string) (data_get($store, 'title') ?? ''),
            'store.url' => $slug !== '' ? url('/'.$slug) : url('/'),
            'page.title' => (string) (data_get($store, 'bio_title') ?: data_get($store, 'title') ?? ''),
        ]);
    }
}
