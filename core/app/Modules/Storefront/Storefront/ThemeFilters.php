<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Juwri\Modules\Storefront\Storefront\Drops\BlockDrop;
use Keepsuit\Liquid\Filters\FiltersProvider;

/**
 * P2-2b: the CLOSED filter whitelist a sandboxed theme may use. Every public
 * method here becomes a Liquid filter (snake_cased); anything a theme calls
 * that is NOT here throws at upload under strictFilters. Protected helpers are
 * excluded from registration by keepsuit (public-only), so they stay private
 * to this provider.
 *
 * Security notes:
 *  - `t` is READ-ONLY. Unlike the app's `___()` helper it NEVER creates a
 *    missing language key (that helper does `File::put` — a render-time write).
 *  - `media_url` scopes to the current restaurant via a server-set register
 *    (un-forgeable by theme markup) and only accepts a whitelisted path token,
 *    never a theme-supplied storage path.
 *  - `theme_asset` rejects traversal and, once the P2-6 asset store exists,
 *    resolves via a lookup map (key lookup, not path concat → `..` impossible).
 */
class ThemeFilters extends FiltersProvider
{
    /** Whitelisted media path tokens → real storage prefixes. */
    protected const MEDIA_PATHS = [
        'menu' => 'storage/menu/',
        'logo' => 'storage/store/logo/',
        'cover' => 'storage/store/cover/',
        'allergy' => 'storage/allergies/',
        'banner' => 'storage/menu_banners/',
    ];

    /**
     * Translate a key, READ-ONLY. Order: platform language files → the
     * theme's bundled locales/<lang>.json (server-set register) → the key
     * itself. Never writes a key (no DB/file mutation during render).
     */
    public function t(mixed $key): string
    {
        $key = (string) $key;
        $slug = Str::slug($key, '_');

        if (Lang::has('lang.'.$slug)) {
            return (string) trans('lang.'.$slug, [], get_lang());
        }

        // B4: theme-bundled translations, passed as a register (pure map —
        // themes cannot forge platform strings, only supply their own).
        if (isset($this->context)) {
            $map = $this->context->getRegister('theme_locale_map');
            if (is_array($map) && isset($map[$key]) && is_string($map[$key])) {
                return $map[$key];
            }
        }

        return $key;
    }

    /** Format a price with the restaurant's currency symbol. */
    public function money(mixed $value): string
    {
        return price_symbol_format((float) $value);
    }

    /** Format a price with the ISO currency code. */
    public function moneyCode(mixed $value): string
    {
        return price_code_format((float) $value);
    }

    /**
     * Restaurant-scoped media URL for a stored filename.
     * `{{ item.image | media_url: 'menu' }}` — the second arg is a whitelisted
     * token, not a path; the restaurant id comes from the server, not markup.
     */
    public function mediaUrl(mixed $filename, string $type = 'menu'): string
    {
        $filename = (string) $filename;
        if ($filename === '' || $this->hasTraversal($filename)) {
            return '';
        }

        $path = self::MEDIA_PATHS[$type] ?? self::MEDIA_PATHS['menu'];

        return media_url($path, $filename, $this->storeId());
    }

    /**
     * URL for one of the theme's own bundled assets.
     * `{{ 'css/style.css' | theme_asset }}`. Rejects traversal; when the P2-6
     * per-version asset map is present it is a pure key lookup.
     */
    public function themeAsset(mixed $asset): string
    {
        $asset = (string) $asset;
        if ($asset === '' || $this->hasTraversal($asset)) {
            return '';
        }

        $map = isset($this->context) ? $this->context->getRegister('theme_asset_map') : null;
        if (is_array($map)) {
            // Pure lookup — a key that isn't a real asset returns '' (never a path).
            return (string) ($map[$asset] ?? '');
        }

        $base = isset($this->context) ? (string) ($this->context->getRegister('theme_asset_base') ?? '') : '';

        return $base !== '' ? rtrim($base, '/').'/'.ltrim($asset, '/') : '';
    }

    /**
     * Theme Studio C1: print an owner text setting.
     * `{{ section.settings.heading | studio_text: 'heading' }}` — HTML-escaped
     * (rich-text settings print as stored: they are sanitized on save). In the
     * owner's studio preview ONLY (server-set register) the output is wrapped
     * in `<span data-studio-text="<sid>.<key>">` (a `<div>` for rich text) so
     * the editor can edit it inline; everywhere else it is plain output.
     */
    public function studioText(mixed $value, mixed $key = ''): string
    {
        $key = is_scalar($key) ? (string) $key : '';
        $richKeys = $this->register('studio_richtext_keys');
        $rich = $key !== '' && is_array($richKeys) && in_array($key, $richKeys, true);

        $text = is_scalar($value) ? (string) $value : '';
        $out = $rich ? $text : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        $sectionId = $this->register('studio_section_id');
        if ($this->register('studio_mode') !== true || ! is_string($sectionId) || $sectionId === ''
            || ! preg_match('/^[a-z0-9_\-.~]{1,128}$/i', $key)) {
            return $out;
        }

        $tag = $rich ? 'div' : 'span';

        return '<'.$tag.' data-studio-text="'.htmlspecialchars($sectionId.'.'.$key, ENT_QUOTES, 'UTF-8').'">'.$out.'</'.$tag.'>';
    }

    /**
     * Theme Studio C1: `<div class="card"{{ block | studio_block }}>` — the
     * block's ` data-studio-block="<bid>"` attribute in the owner's studio
     * preview, nothing anywhere else.
     */
    public function studioBlock(mixed $block): string
    {
        if ($this->register('studio_mode') !== true) {
            return '';
        }
        $id = $block instanceof BlockDrop ? $block->id() : (is_scalar($block) ? (string) $block : '');
        if ($id === '') {
            return '';
        }

        return ' data-studio-block="'.htmlspecialchars($id, ENT_QUOTES, 'UTF-8').'"';
    }

    /** A server-set register, or null outside a render. */
    protected function register(string $name): mixed
    {
        return isset($this->context) ? $this->context->getRegister($name) : null;
    }

    /** Reject path traversal / absolute / scheme / null-byte in theme input. */
    protected function hasTraversal(string $value): bool
    {
        return str_contains($value, '..')
            || str_starts_with($value, '/')
            || str_contains($value, '\\')
            || str_contains($value, "\0")
            || (bool) preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $value);
    }

    /** Current restaurant id from the server-set register (never theme input). */
    protected function storeId(): ?int
    {
        if (! isset($this->context)) {
            return null;
        }
        $id = $this->context->getRegister('store_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
