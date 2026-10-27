<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use RuntimeException;
use ZipArchive;

/**
 * P2-5: validate a theme .zip BEFORE extraction — the marketplace's front
 * door. Built on the P0-5 PluginZipValidator hardening (traversal, symlinks,
 * zip bombs) plus the theme-specific rules from the package spec (§6) and
 * GUARDRAILS #7/#9:
 *
 *  - fixed layout: theme.json manifest at root; templates/index.liquid or
 *    templates/index.json entrypoint; only known top-level dirs; depth ≤ 3;
 *  - caps: 10 MB zip total, 2 MB per file;
 *  - closed extension whitelist — NO .php anywhere, NO .js in v1 (themes get
 *    interactivity from the platform commerce runtime);
 *  - dangerous-markup scan: <script bodies, on*= handlers, javascript: URLs,
 *    <iframe> in .liquid; <script>/<foreignObject>/on*= in .svg; P6-263: meta
 *    http-equiv, <base>, plugin tags, off-site forms, data: links, and markup
 *    built inside Liquid strings/captures. The render-time gate for anything
 *    Liquid assembles anyway is Rendering\ThemeOutputSanitizer.
 *  - manifest: valid JSON with name + slug + version (slug/version must be
 *    resolver-safe — they become filesystem path segments).
 *
 * Returns the parsed manifest so callers never re-read the zip. The full
 * author-facing lint report is P4-2; these are the hard security gates.
 */
class ThemePackageValidator
{
    public const MAX_ENTRIES = 1000;

    public const MAX_TOTAL_BYTES = 10 * 1024 * 1024;  // 10 MB uncompressed

    public const MAX_ENTRY_BYTES = 2 * 1024 * 1024;   // 2 MB per file

    public const MAX_DEPTH = 3;

    public const ALLOWED_EXTENSIONS = [
        'liquid', 'json', 'css', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'woff2',
    ];

    public const ALLOWED_ROOTS = [
        'templates', 'sections', 'snippets', 'assets', 'config', 'locales', 'layout',
    ];

    /** Root-level files allowed outside the dirs above. */
    public const ALLOWED_ROOT_FILES = [
        'theme.json', 'preview.png',
    ];

    /**
     * @return array the parsed theme.json manifest
     *
     * @throws RuntimeException when the archive is unsafe or malformed.
     */
    public static function assertSafe(string $zipPath): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is not enabled.');
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open the theme zip file.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('Theme archive has too many files.');
            }

            $total = 0;
            $manifestRaw = null;
            $hasEntrypoint = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');

                if ($name === '' || str_contains($name, "\0")) {
                    throw new RuntimeException('Theme archive contains an invalid entry name.');
                }

                $normalized = str_replace('\\', '/', $name);
                $isDir = str_ends_with($normalized, '/');

                // Traversal / absolute / drive / UNC (P0-5 pattern).
                if (
                    str_starts_with($normalized, '/')
                    || preg_match('#^[a-zA-Z]:#', $normalized)
                    || preg_match('#(^|/)\.\.(/|$)#', $normalized)
                ) {
                    throw new RuntimeException('Theme archive contains an illegal path: '.$name);
                }

                // Symlinks (unix mode in external attrs).
                $unixMode = (($stat['external_attr'] ?? 0) >> 16) & 0xFFFF;
                if (($unixMode & 0xF000) === 0xA000) {
                    throw new RuntimeException('Theme archive contains a symlink: '.$name);
                }

                if ($isDir) {
                    continue;
                }

                $path = trim($normalized, '/');
                $segments = explode('/', $path);

                if (count($segments) > self::MAX_DEPTH) {
                    throw new RuntimeException('Theme archive nests too deep: '.$name);
                }

                // Layout: root files vs whitelisted top-level dirs.
                if (count($segments) === 1) {
                    if (! in_array($segments[0], self::ALLOWED_ROOT_FILES, true)) {
                        throw new RuntimeException('Unexpected file at theme root: '.$name);
                    }
                } elseif (! in_array($segments[0], self::ALLOWED_ROOTS, true)) {
                    throw new RuntimeException('Unexpected directory in theme: '.$segments[0]);
                }

                // Extension whitelist — .php can never hide anywhere.
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                    throw new RuntimeException('File type not allowed in a theme: '.$name);
                }

                // Size caps (zip bomb / oversized assets).
                $size = (int) ($stat['size'] ?? 0);
                if ($size > self::MAX_ENTRY_BYTES) {
                    throw new RuntimeException('Theme archive contains an oversized file: '.$name);
                }
                $total += $size;
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('Theme archive is too large (possible zip bomb).');
                }

                // Content scans for text formats that reach the browser.
                if (in_array($ext, ['liquid', 'svg'], true)) {
                    self::assertMarkupSafe($path, (string) $zip->getFromIndex($i));
                }

                if ($path === 'theme.json') {
                    $manifestRaw = (string) $zip->getFromIndex($i);
                }
                if ($path === 'templates/index.liquid' || $path === 'templates/index.json') {
                    $hasEntrypoint = true;
                }
            }

            if ($manifestRaw === null) {
                throw new RuntimeException('Theme archive is missing theme.json at its root.');
            }
            if (! $hasEntrypoint) {
                throw new RuntimeException('Theme archive is missing templates/index.liquid or templates/index.json.');
            }

            return self::assertManifestValid($manifestRaw);
        } finally {
            $zip->close();
        }
    }

    /**
     * GUARDRAILS #7/#9: v1 themes ship ZERO custom JS and SVG is attack
     * surface. Reject script bodies, inline handlers, javascript: URLs,
     * iframes, and (svg) foreignObject before anything is stored.
     */
    protected static function assertMarkupSafe(string $path, string $content): void
    {
        $issues = self::markupIssues($path, $content);
        if ($issues !== []) {
            throw new RuntimeException($issues[0]);
        }
    }

    /**
     * Dev MCP P4-1: same markup rules, ALL findings collected (the MCP
     * validate_theme tool reports every issue; the zip gate throws the first).
     *
     * @return list<string>
     */
    public static function markupIssues(string $path, string $content): array
    {
        // Browsers decode entities in attribute values and ignore control/space
        // characters inside a URL scheme, so `jav&#x61;script:` and `java\tscript:`
        // must be checked on the decoded, whitespace-stripped text. `/` and quotes
        // also end an attribute name (`<svg/onload=…>`), not only whitespace.
        $content .= "\n".preg_replace('/[\x00-\x20]+/', '', html_entity_decode(
            preg_replace('/(&#x?[0-9a-f]+)(?![0-9a-f;])/i', '$1;', $content), // `&#97` needs no `;` in a browser
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));

        $checks = [
            '/<\s*script\b/i' => 'a <script> tag',
            '/[\s\/"\']on[a-z]+\s*=/i' => 'an inline event handler',
            '/(java|vb)script\s*:/i' => 'a javascript: URL',
            '/<\s*iframe\b/i' => 'an <iframe>',
            // P6-263: non-script attacks CSP does not stop — redirects, base hijack, plugins, phishing forms.
            '/<\s*meta\b[^>]*http-equiv/i' => 'a <meta http-equiv> (refresh/redirect)',
            '/<\s*base\b/i' => 'a <base> tag',
            '/<\s*(object|embed|applet|frame|frameset|portal)\b/i' => 'an <object>/<embed>/<frame> plugin tag',
            '/[\s"\'\/](srcdoc|formaction)\s*=/i' => 'a srcdoc/formaction attribute',
            '/<\s*form\b[^>]*\baction\s*=\s*["\']?\s*(https?:|\/\/|[a-z][a-z0-9+.-]*:)/i' => 'a <form> posting off-site',
            '/\b(href|action|data|xlink:href)\s*=\s*["\']?\s*data:/i' => 'a data: URL link',
            '/\bsrc\s*=\s*["\']?\s*data:(?!image\/)/i' => 'a non-image data: URL',
            '/http-equiv/i' => 'an http-equiv attribute',
        ];
        if (str_ends_with($path, '.liquid')) {
            // P6-263: markup assembled inside Liquid (string literals carrying `<`, or scheme pieces) is how a tag
            // the scan rejects gets built at render time (`{{ "<scr" | append: "ipt>" }}`). Theme markup belongs
            // in the template text; Liquid strings never need a raw `<` (use the platform filters instead).
            $checks['/\{[{%][^}]*?("[^"}]*(<|&lt;|&#0*60;?|&#x0*3c;?|\\\\u003c|\\\\x3c|javascript:|data:text)[^"}]*"|\'[^\'}]*(<|&lt;|&#0*60;?|&#x0*3c;?|\\\\u003c|\\\\x3c|javascript:|data:text)[^\'}]*\')[^}]*?[}%]\}/i']
                = 'markup or a script URL built inside a Liquid string';
            $checks['/\{%-?\s*capture\b.*?<.*?\{%-?\s*endcapture/is'] = 'markup captured into a Liquid variable';
        }
        if (str_ends_with($path, '.svg')) {
            $checks['/<\s*foreignObject\b/i'] = 'a foreignObject element';
        }

        $issues = [];
        foreach ($checks as $pattern => $label) {
            if (preg_match($pattern, $content)) {
                $issues[] = "Theme file {$path} contains {$label} — not allowed.";
            }
        }

        return $issues;
    }

    /**
     * Dev MCP P4-1: layout/extension rules for one relative path — shared
     * vocabulary with the zip gate (same constants), usable on an in-memory
     * file map. Returns null when the path is fine.
     */
    public static function pathIssue(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        if (
            $normalized === '' || str_contains($normalized, "\0")
            || str_starts_with($normalized, '/')
            || preg_match('#^[a-zA-Z]:#', $normalized)
            || preg_match('#(^|/)\.\.(/|$)#', $normalized)
        ) {
            return "Illegal path: {$path}";
        }

        $clean = trim($normalized, '/');
        $segments = explode('/', $clean);

        if (count($segments) > self::MAX_DEPTH) {
            return 'Nests too deep (max '.self::MAX_DEPTH." levels): {$path}";
        }
        if (count($segments) === 1) {
            if (! in_array($segments[0], self::ALLOWED_ROOT_FILES, true)) {
                return "Unexpected file at theme root: {$path} (allowed: ".implode(', ', self::ALLOWED_ROOT_FILES).')';
            }
        } elseif (! in_array($segments[0], self::ALLOWED_ROOTS, true)) {
            return "Unexpected top-level directory: {$segments[0]} (allowed: ".implode(', ', self::ALLOWED_ROOTS).')';
        }

        $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return "File type not allowed in a theme: {$path} (allowed: ".implode(', ', self::ALLOWED_EXTENSIONS).')';
        }

        return null;
    }

    /**
     * Dev MCP P4-1: package-level lint of an in-memory {path => content} map.
     * Collects ALL findings instead of throwing; `package` mode additionally
     * requires the manifest + entrypoint (files mode lints fragments).
     *
     * @param  array<string, string>  $files
     * @return list<array{file: string, code: string, message: string}>
     */
    public static function lintFileMap(array $files, string $mode = 'files'): array
    {
        $findings = [];
        $total = 0;

        foreach ($files as $path => $content) {
            $path = (string) $path;
            $content = (string) $content;

            if (($issue = self::pathIssue($path)) !== null) {
                $findings[] = ['file' => $path, 'code' => 'layout', 'message' => $issue];

                continue;
            }

            $size = strlen($content);
            if ($size > self::MAX_ENTRY_BYTES) {
                $findings[] = ['file' => $path, 'code' => 'size', 'message' => 'File exceeds the 2 MB per-file cap.'];
            }
            $total += $size;

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['liquid', 'svg'], true)) {
                foreach (self::markupIssues($path, $content) as $msg) {
                    $findings[] = ['file' => $path, 'code' => 'markup', 'message' => $msg];
                }
            }
        }

        if ($total > self::MAX_TOTAL_BYTES) {
            $findings[] = ['file' => '(package)', 'code' => 'size', 'message' => 'Package exceeds the 10 MB total cap.'];
        }

        if ($mode === 'package') {
            if (! array_key_exists('theme.json', $files)) {
                $findings[] = ['file' => 'theme.json', 'code' => 'manifest', 'message' => 'Missing theme.json at the package root.'];
            } else {
                foreach (self::manifestIssues((string) $files['theme.json']) as $msg) {
                    $findings[] = ['file' => 'theme.json', 'code' => 'manifest', 'message' => $msg];
                }
            }
            if (! array_key_exists('templates/index.liquid', $files) && ! array_key_exists('templates/index.json', $files)) {
                $findings[] = ['file' => 'templates/', 'code' => 'layout', 'message' => 'Missing entrypoint: templates/index.liquid or templates/index.json.'];
            }
        }

        return $findings;
    }

    /** @return array the decoded manifest */
    protected static function assertManifestValid(string $raw): array
    {
        $issues = self::manifestIssues($raw);
        if ($issues !== []) {
            throw new RuntimeException($issues[0]);
        }

        $manifest = json_decode($raw, true);
        $version = $manifest['version'];
        $manifest['version'] = is_int($version) ? (string) $version : $version;

        return $manifest;
    }

    /**
     * Dev MCP P4-1: manifest rules as an issue list (same messages the zip
     * gate throws — first issue == thrown message).
     *
     * @return list<string>
     */
    public static function manifestIssues(string $raw): array
    {
        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return ['theme.json is not valid JSON.'];
        }

        $issues = [];

        $name = $manifest['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            $issues[] = 'theme.json must declare a name.';
        }

        $slug = $manifest['slug'] ?? null;
        if (! is_string($slug) || ! preg_match('/^[a-z0-9][a-z0-9\-]*$/i', $slug)) {
            $issues[] = 'theme.json must declare a safe slug (letters/numbers/dashes).';
        }

        $version = $manifest['version'] ?? null;
        $version = is_int($version) ? (string) $version : $version;
        if (! is_string($version) || ! preg_match('/^[a-z0-9][a-z0-9.\-]*$/i', $version)) {
            $issues[] = 'theme.json must declare a safe version.';
        }

        // Theme Studio B0.1: optional pin to a platform section library version.
        if (array_key_exists('library', $manifest)) {
            $library = $manifest['library'];
            $library = is_int($library) ? (string) $library : $library;
            if (! is_string($library) || ! preg_match(SectionLibrary::VERSION_PATTERN, $library)) {
                $issues[] = 'theme.json "library" must be a library version like "1".';
            } elseif (SectionLibrary::forVersion($library) === null) {
                $issues[] = "theme.json pins section library {$library}, which this platform doesn't ship.";
            }
        }

        // Theme Studio B0.10: optional commerce runtime pin (allow-list only).
        if (array_key_exists('commerce_runtime', $manifest)
            && ! in_array($manifest['commerce_runtime'], CommerceRuntime::VERSIONS, true)) {
            $issues[] = 'theme.json "commerce_runtime" must be one of: '.implode(', ', CommerceRuntime::VERSIONS).'.';
        }

        return $issues;
    }
}
