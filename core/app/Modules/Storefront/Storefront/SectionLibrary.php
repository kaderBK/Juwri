<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

/**
 * Theme Studio B0.1: the platform-owned, versioned Liquid section library
 * (resources/liquid-library/<version>/{sections,snippets,locales}). A theme opts
 * in with `"library": "<version>"` in its theme.json; its own sections/snippets/
 * locales then fall back to this library, and the theme's files win on a name
 * clash. The version is pinned per theme version, so a library release never
 * changes an installed theme until that theme is re-released against it.
 *
 * Immutable value object; the version is regex-validated so it can never carry
 * a path segment.
 */
class SectionLibrary
{
    public const VERSION_PATTERN = '/^[0-9]+(\.[0-9]+)*$/';

    /** @var array<string,string>|null */
    private ?array $snippetCache = null;

    public function __construct(
        public readonly string $version,
        protected string $dir,
    ) {}

    /** The library for a version, or null when the version is unsafe or not shipped. */
    public static function forVersion(mixed $version): ?self
    {
        $version = is_int($version) ? (string) $version : $version;
        if (! is_string($version) || ! preg_match(self::VERSION_PATTERN, $version)) {
            return null;
        }

        $dir = static::basePath().'/'.$version;

        return is_dir($dir) ? new self($version, $dir) : null;
    }

    public static function basePath(): string
    {
        return rtrim((string) (config('platform.liquid_library.path') ?: resource_path('liquid-library')), '/');
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->dir : $this->dir.'/'.ltrim($relative, '/');
    }

    /** Source of one library section, or null. Type is slug-validated (no path). */
    public function sectionSource(string $type): ?string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $type)) {
            return null;
        }
        $path = $this->dir.'/sections/'.$type.'.liquid';

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Section types the library ships (file names minus .liquid).
     *
     * @return list<string>
     */
    public function sectionTypes(): array
    {
        $types = [];
        foreach (glob($this->dir.'/sections/*.liquid') ?: [] as $file) {
            $name = basename($file, '.liquid');
            if (preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $name)) {
                $types[] = $name;
            }
        }
        sort($types);

        return $types;
    }

    /**
     * Snippet name → source, same naming rule as MarketplaceTheme::snippets().
     *
     * @return array<string,string>
     */
    public function snippets(): array
    {
        if ($this->snippetCache !== null) {
            return $this->snippetCache;
        }

        $map = [];
        foreach (glob($this->dir.'/snippets/*.liquid') ?: [] as $file) {
            $name = basename($file, '.liquid');
            if (preg_match('/^[a-z0-9][a-z0-9\-_]*$/i', $name)) {
                $map[$name] = (string) file_get_contents($file);
            }
        }

        return $this->snippetCache = $map;
    }

    /**
     * The library's storefront strings for one language: locales/<lang>.json
     * plus any fragments in locales/<lang>/*.json (merged in file-name order),
     * so section groups can each ship their own strings file.
     *
     * @return array<string,string>
     */
    public function locales(string $lang): array
    {
        if (! preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/i', $lang)) {
            return [];
        }
        $lang = strtolower($lang);
        $files = [$this->dir.'/locales/'.$lang.'.json'];
        $fragments = glob($this->dir.'/locales/'.$lang.'/*.json') ?: [];
        sort($fragments);

        $out = [];
        foreach (array_merge($files, $fragments) as $path) {
            if (! is_file($path)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $out = array_merge($out, array_filter($decoded, 'is_string'));
            }
        }

        return $out;
    }
}
