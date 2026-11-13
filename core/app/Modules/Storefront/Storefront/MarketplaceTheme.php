<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;


/**
 * P2-9: a resolved marketplace/Liquid theme version on disk. Immutable value
 * object — the renderer reads `templateSource()`, the `theme_asset` filter
 * resolves through `assetMap()` (pure key lookup, so `..` is impossible by
 * construction). Until P2-6 lands the versioned store, files live under a
 * base dir; slug/version are regex-validated by the resolver so they can
 * never carry path segments.
 */
class MarketplaceTheme
{
    /** @var array<string,mixed>|null parsed theme.json (instance memo) */
    private ?array $manifestCache = null;

    /** @var array<string,string>|null merged snippet map (instance memo — read once per render, not per section) */
    private ?array $snippetCache = null;

    private ?SectionLibrary $libraryCache = null;

    private bool $libraryResolved = false;

    public function __construct(
        public readonly string $slug,
        public readonly string $version,
        protected string $dir,
    ) {}

    /** Absolute path of a subdir/file inside this version (lint/inspection). */
    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->dir : $this->dir.'/'.ltrim($relative, '/');
    }

    /** The stored .liquid entrypoint for this version (single-file themes). */
    public function templateSource(): string
    {
        return (string) file_get_contents($this->dir.'/templates/index.liquid');
    }

    /**
     * The parsed theme.json (empty when missing or invalid).
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        if ($this->manifestCache === null) {
            $path = $this->dir.'/theme.json';
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $this->manifestCache = is_array($decoded) ? $decoded : [];
        }

        return $this->manifestCache;
    }

    /**
     * Theme Studio B0.1: the section library this version is pinned to
     * (`"library"` in theme.json), or null when it declares none / an unknown one.
     */
    public function library(): ?SectionLibrary
    {
        if (! $this->libraryResolved) {
            $version = $this->manifest()['library'] ?? null;
            $this->libraryCache = $version === null ? null : SectionLibrary::forVersion($version);
            $this->libraryResolved = true;
        }

        return $this->libraryCache;
    }

    /**
     * Theme Studio B0.10: the commerce runtime bundle this version opts into
     * (`"commerce_runtime": "v2"` in theme.json). Anything missing or outside
     * the allow-list falls back to the platform default, so existing themes
     * (crave, bazaar) keep exactly the bundle they render with today.
     */
    public function commerceRuntimeVersion(): string
    {
        $pinned = $this->manifest()['commerce_runtime'] ?? null;

        return is_string($pinned) && in_array($pinned, CommerceRuntime::VERSIONS, true)
            ? $pinned
            : (string) config('platform.commerce.runtime_version', 'v1');
    }

    /**
     * P2-6: the version's snippet map for `{% render 'name' %}` — snippet name
     * (filename minus .liquid, slug-validated) → source. Pure key lookup.
     * Studio themes also see their library's snippets; the theme's own win.
     *
     * @return array<string,string>
     */
    public function snippets(): array
    {
        if ($this->snippetCache !== null) {
            return $this->snippetCache;
        }

        $map = $this->library()?->snippets() ?? [];
        foreach (glob($this->dir.'/snippets/*.liquid') ?: [] as $file) {
            $name = basename($file, '.liquid');
            if (preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $name)) {
                $map[$name] = (string) file_get_contents($file);
            }
        }

        return $this->snippetCache = $map;
    }

    /**
     * B4 (P2-2b follow-up): the theme's bundled translations for one language
     * (locales/<lang>.json) — the read-only fallback the `t` filter consults
     * after platform keys. Language code is regex-guarded (path segment).
     *
     * @return array<string,string>
     */
    public function locales(string $lang): array
    {
        if (! preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/i', $lang)) {
            return [];
        }
        $base = $this->library()?->locales($lang) ?? [];
        $path = $this->dir.'/locales/'.strtolower($lang).'.json';
        if (! is_file($path)) {
            return $base;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        // Theme strings override library strings of the same key.
        return array_merge($base, is_array($decoded) ? array_filter($decoded, 'is_string') : []);
    }

    /**
     * P2-4: the theme-level settings schema (config/settings_schema.json,
     * Shopify-style groups). Null when the theme ships none.
     */
    public function configSettingsSchema(): ?array
    {
        $path = $this->dir.'/config/settings_schema.json';
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** OS 2.0 (P2-3): does this version use a sectioned JSON template? */
    public function isSectioned(): bool
    {
        return is_file($this->dir.'/templates/index.json');
    }

    /** The parsed OS 2.0 template JSON ({sections: {...}, order: [...]}). */
    public function templateJson(): array
    {
        $decoded = json_decode((string) file_get_contents($this->dir.'/templates/index.json'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Source of one section file. Type is slug-validated here (defense in
     * depth — SectionedTemplate validates too) so it can never carry a path.
     * Falls back to the pinned section library when the theme doesn't ship it.
     */
    public function sectionSource(string $type): ?string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $type)) {
            return null;
        }
        $path = $this->dir.'/sections/'.$type.'.liquid';

        return is_file($path)
            ? (string) file_get_contents($path)
            : $this->library()?->sectionSource($type);
    }

    /**
     * Relative asset path → public URL (+mtime cache-bust), from the theme's
     * public asset dir. Feeds the `theme_asset` filter's lookup map.
     *
     * @return array<string,string>
     */
    public function assetMap(): array
    {
        $publicDir = public_path('assets/marketplace-themes/'.$this->slug.'/'.$this->version);
        if (! is_dir($publicDir)) {
            return [];
        }

        $map = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($publicDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($publicDir))), '/');
            $map[$rel] = asset('assets/marketplace-themes/'.$this->slug.'/'.$this->version.'/'.$rel)
                .'?v='.$file->getMTime();
        }

        return $map;
    }
}
