<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use App\Http\Controllers\DeveloperDocsController;
use Illuminate\Support\Str;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Keepsuit\Liquid\Filters\StandardFilters;

/**
 * P4-2: the theme-check-equivalent linter — the author-facing QUALITY report
 * run on every submission (the P2-5 validator remains the hard security gate).
 * Errors fail a submission; warnings ship with the review.
 *
 * Rules:
 *  liquid-syntax     every .liquid must parse under the STRICT environment
 *  json-invalid      every .json must decode
 *  missing-section   template JSON references a section type with no file
 *  missing-snippet   {% render 'x' %} names a snippet the theme doesn't ship
 *  unknown-filter    a filter outside the whitelist (closed contract)
 *  external-url      http(s) URL in liquid/css — assets/fonts must be bundled
 *  schema-invalid    a section {% schema %} isn't a valid settings schema
 *  limit-exceeded    >25 sections / >50 blocks in the template JSON
 *  unused-assign     (warning) an assigned variable never read
 *
 * Feature-wiring warnings (all non-fatal — a "looks like a storefront but
 * missing the wiring" advisory, not a hard gate; see featureWiringWarnings()):
 *  commerce-tag-missing    order/cart markup present but no {% commerce %}
 *  item-deep-link-missing  item cards rendered but none carry data-mb-open
 *  cart-add-missing        item cards rendered but no data-mb-add anywhere
 *  price-missing-money     raw {{ item.price }} rendered without | money
 *
 * Theme Studio B0.9 quality rules:
 *  rtl-physical-prop        (warning) physical left/right CSS (margin-left,
 *                           text-align:right, ...) in css files or inline
 *                           style="" -- use logical properties so RTL works
 *  unescaped-setting-output (warning) {{ section.settings.x }} / block /
 *                           global settings output without an escaping or
 *                           formatting filter (settings reach Liquid raw)
 *  section-css-unscoped     per-section css (sections/<type>.css) with a
 *                           selector not starting .mbs-<type> -- ERROR for
 *                           the section library (lintLibraryCss/lintLibrary),
 *                           warning for a theme's own files
 *  img-missing-dimensions   (warning) <img> without width AND height (CLS)
 */
class ThemeLinter
{
    /** @return array{errors: list<array{rule:string,file:string,message:string}>, warnings: list<array{rule:string,file:string,message:string}>, passed: bool} */
    public function lint(MarketplaceTheme $theme): array
    {
        $errors = [];
        $warnings = [];

        $liquidFiles = $this->liquidFiles($theme);
        $snippets = array_keys($theme->snippets());
        $allowedFilters = $this->allowedFilters();
        $globalSettingTypes = $this->globalSettingTypes($theme->configSettingsSchema());

        // 1. Strict parse + per-file scans.
        $strictEnv = (new LiquidEnvironmentFactory)->upload($theme->snippets());
        $featureBodies = [];
        foreach ($liquidFiles as $rel => $source) {
            try {
                $strictEnv->parseString($source);
            } catch (\Throwable $e) {
                $errors[] = ['rule' => 'liquid-syntax', 'file' => $rel, 'message' => $e->getMessage()];
                // Still run the textual scans below — give the author everything at once.
            }

            $body = $this->stripSchemaBlocks($source);
            $featureBodies[$rel] = $body;

            // Unknown filters (closed whitelist).
            foreach ($this->usedFilters($body) as $filter) {
                if (! in_array($filter, $allowedFilters, true)) {
                    $errors[] = ['rule' => 'unknown-filter', 'file' => $rel, 'message' => "filter '{$filter}' is not in the whitelist"];
                }
            }

            // Missing snippets.
            foreach ($this->renderedSnippets($body) as $name) {
                if (! in_array($name, $snippets, true)) {
                    $errors[] = ['rule' => 'missing-snippet', 'file' => $rel, 'message' => "snippet '{$name}' is not shipped in snippets/"];
                }
            }

            // External URLs (bundle your assets — house rule).
            foreach ($this->externalUrls($body) as $url) {
                $errors[] = ['rule' => 'external-url', 'file' => $rel, 'message' => "external URL not allowed: {$url}"];
            }

            // Section schema shape.
            if (str_starts_with($rel, 'sections/')) {
                $schema = SectionedTemplate::extractSchema($source);
                foreach ($schema['settings'] ?? [] as $i => $def) {
                    if (empty($def['id']) || empty($def['type'])) {
                        $errors[] = ['rule' => 'schema-invalid', 'file' => $rel, 'message' => "settings[{$i}] must declare id and type"];
                    }
                }
            }

            // Unused assigns (warning).
            foreach ($this->unusedAssigns($body) as $var) {
                $warnings[] = ['rule' => 'unused-assign', 'file' => $rel, 'message' => "'{$var}' is assigned but never used"];
            }

            // Theme Studio B0.9 quality warnings.
            array_push($warnings, ...$this->liquidQualityWarnings($rel, $source, $body, $globalSettingTypes));
        }
        if (($library = $theme->library()) && $theme->isSectioned()) {
            $featureBodies += $this->librarySectionBodies($library, $theme->templateJson(), $featureBodies);
            $featureBodies += $this->librarySnippetBodies($library, $featureBodies);
        }
        $warnings = array_merge($warnings, $this->featureWiringWarnings($featureBodies));

        // 2. CSS external URLs + (B0.9) logical properties.
        foreach ($this->cssFiles($theme) as $rel => $css) {
            foreach ($this->externalUrls($css) as $url) {
                $errors[] = ['rule' => 'external-url', 'file' => $rel, 'message' => "external URL not allowed: {$url}"];
            }
            if (($w = $this->rtlWarning($rel, $css)) !== null) {
                $warnings[] = $w;
            }
        }
        // B0.9: a theme's own per-section css must be scoped (advisory here --
        // the library gate treats the same finding as an error).
        foreach ($this->sectionCssFiles($theme) as $rel => [$type, $css]) {
            array_push($warnings, ...self::unscopedCssFindings($type, $css, $rel));
        }

        // 3. Template JSON: validity, section refs, limits.
        if ($theme->isSectioned()) {
            $json = $theme->templateJson();
            if ($json === []) {
                $errors[] = ['rule' => 'json-invalid', 'file' => 'templates/index.json', 'message' => 'not a valid JSON object'];
            }
            $sections = is_array($json['sections'] ?? null) ? $json['sections'] : [];
            $order = is_array($json['order'] ?? null) ? $json['order'] : array_keys($sections);

            if (count($order) > SectionedTemplate::MAX_SECTIONS) {
                $errors[] = ['rule' => 'limit-exceeded', 'file' => 'templates/index.json', 'message' => count($order).' sections (max '.SectionedTemplate::MAX_SECTIONS.')'];
            }
            foreach ($sections as $sid => $instance) {
                $type = is_array($instance) ? ($instance['type'] ?? null) : null;
                if (! $type || $theme->sectionSource((string) $type) === null) {
                    $errors[] = ['rule' => 'missing-section', 'file' => 'templates/index.json', 'message' => "section '{$sid}' references type '".($type ?? '?')."' with no sections/{$type}.liquid"];
                }
                $blocks = is_array($instance['blocks'] ?? null) ? $instance['blocks'] : [];
                if (count($blocks) > SectionedTemplate::MAX_BLOCKS) {
                    $errors[] = ['rule' => 'limit-exceeded', 'file' => 'templates/index.json', 'message' => "section '{$sid}' has ".count($blocks).' blocks (max '.SectionedTemplate::MAX_BLOCKS.')'];
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'passed' => $errors === []];
    }

    /**
     * Dev MCP P4-2: the same lint pipeline over an in-memory {path => source}
     * map (no MarketplaceTheme, no disk) — powers the public validate_theme
     * MCP tool so authors get the marketplace's own verdict pre-submission.
     * Package-level layout/manifest gates live in ThemePackageValidator::
     * lintFileMap; this covers the Liquid/CSS/template-JSON rules.
     *
     * @param  array<string,string>  $files
     * @return array{errors: list<array{rule:string,file:string,message:string}>, warnings: list<array{rule:string,file:string,message:string}>, passed: bool}
     */
    public function lintFiles(array $files): array
    {
        $errors = [];
        $warnings = [];

        $liquidFiles = [];
        $snippetSources = [];
        foreach ($files as $rel => $source) {
            $rel = str_replace('\\', '/', (string) $rel);
            if (str_ends_with($rel, '.liquid')
                && preg_match('#^(templates|sections|snippets|layout)/[^/]+$#', $rel)) {
                $liquidFiles[$rel] = (string) $source;
                if (str_starts_with($rel, 'snippets/')) {
                    $snippetSources[basename($rel, '.liquid')] = (string) $source;
                }
            }
        }
        // Theme Studio B0.1: a package pinned to a section library also sees the
        // library's snippets and section types (its own files win on a clash).
        $manifest = json_decode((string) ($files['theme.json'] ?? ''), true);
        $library = is_array($manifest) && isset($manifest['library'])
            ? SectionLibrary::forVersion($manifest['library'])
            : null;
        if ($library) {
            $snippetSources += $library->snippets();
        }

        $snippets = array_keys($snippetSources);
        $allowedFilters = $this->allowedFilters();
        $settingsSchema = json_decode((string) ($files['config/settings_schema.json'] ?? ''), true);
        $globalSettingTypes = $this->globalSettingTypes(is_array($settingsSchema) ? $settingsSchema : null);

        $strictEnv = (new LiquidEnvironmentFactory)->upload($snippetSources);
        $featureBodies = [];
        foreach ($liquidFiles as $rel => $source) {
            try {
                $strictEnv->parseString($source);
            } catch (\Throwable $e) {
                $errors[] = ['rule' => 'liquid-syntax', 'file' => $rel, 'message' => $e->getMessage()];
            }

            $body = $this->stripSchemaBlocks($source);
            $featureBodies[$rel] = $body;

            foreach ($this->usedFilters($body) as $filter) {
                if (! in_array($filter, $allowedFilters, true)) {
                    $errors[] = ['rule' => 'unknown-filter', 'file' => $rel, 'message' => "filter '{$filter}' is not in the whitelist"];
                }
            }
            foreach ($this->renderedSnippets($body) as $sname) {
                if (! in_array($sname, $snippets, true)) {
                    $errors[] = ['rule' => 'missing-snippet', 'file' => $rel, 'message' => "snippet '{$sname}' is not shipped in snippets/"];
                }
            }
            foreach ($this->externalUrls($body) as $url) {
                $errors[] = ['rule' => 'external-url', 'file' => $rel, 'message' => "external URL not allowed: {$url}"];
            }
            if (str_starts_with($rel, 'sections/')) {
                $schema = SectionedTemplate::extractSchema($source);
                foreach ($schema['settings'] ?? [] as $i => $def) {
                    if (empty($def['id']) || empty($def['type'])) {
                        $errors[] = ['rule' => 'schema-invalid', 'file' => $rel, 'message' => "settings[{$i}] must declare id and type"];
                    }
                }
            }
            foreach ($this->unusedAssigns($body) as $var) {
                $warnings[] = ['rule' => 'unused-assign', 'file' => $rel, 'message' => "'{$var}' is assigned but never used"];
            }
            array_push($warnings, ...$this->liquidQualityWarnings($rel, $source, $body, $globalSettingTypes));
        }
        if ($library) {
            $featureBodies += $this->librarySectionBodies($library, $files['templates/index.json'] ?? null, $featureBodies);
            $featureBodies += $this->librarySnippetBodies($library, $featureBodies);
        }
        $warnings = array_merge($warnings, $this->featureWiringWarnings($featureBodies));

        foreach ($files as $rel => $css) {
            if (str_ends_with(strtolower((string) $rel), '.css')) {
                $rel = str_replace('\\', '/', (string) $rel);
                foreach ($this->externalUrls((string) $css) as $url) {
                    $errors[] = ['rule' => 'external-url', 'file' => $rel, 'message' => "external URL not allowed: {$url}"];
                }
                if (($w = $this->rtlWarning($rel, (string) $css)) !== null) {
                    $warnings[] = $w;
                }
                if (($type = self::sectionCssType($rel)) !== null) {
                    array_push($warnings, ...self::unscopedCssFindings($type, (string) $css, $rel));
                }
            }
        }

        if (array_key_exists('templates/index.json', $files)) {
            $json = json_decode((string) $files['templates/index.json'], true);
            if (! is_array($json)) {
                $errors[] = ['rule' => 'json-invalid', 'file' => 'templates/index.json', 'message' => 'not a valid JSON object'];
                $json = [];
            }
            $sections = is_array($json['sections'] ?? null) ? $json['sections'] : [];
            $order = is_array($json['order'] ?? null) ? $json['order'] : array_keys($sections);

            if (count($order) > SectionedTemplate::MAX_SECTIONS) {
                $errors[] = ['rule' => 'limit-exceeded', 'file' => 'templates/index.json', 'message' => count($order).' sections (max '.SectionedTemplate::MAX_SECTIONS.')'];
            }
            foreach ($sections as $sid => $instance) {
                $type = is_array($instance) ? ($instance['type'] ?? null) : null;
                if (! $type || (! array_key_exists("sections/{$type}.liquid", $files)
                    && ($library?->sectionSource((string) $type) === null))) {
                    $errors[] = ['rule' => 'missing-section', 'file' => 'templates/index.json', 'message' => "section '{$sid}' references type '".($type ?? '?')."' with no sections/{$type}.liquid"];
                }
                $blocks = is_array($instance['blocks'] ?? null) ? $instance['blocks'] : [];
                if (count($blocks) > SectionedTemplate::MAX_BLOCKS) {
                    $errors[] = ['rule' => 'limit-exceeded', 'file' => 'templates/index.json', 'message' => "section '{$sid}' has ".count($blocks).' blocks (max '.SectionedTemplate::MAX_BLOCKS.')'];
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'passed' => $errors === []];
    }

    /**
     * Theme Studio B0.9: the section-library CSS gate. Every selector in a
     * library section's css must start with `.mbs-<type>` (so one section can
     * never restyle another or the shell); physical left/right properties are
     * reported too. The library build treats `errors` as fatal.
     *
     * @return array{errors: list<array{rule:string,file:string,message:string}>, warnings: list<array{rule:string,file:string,message:string}>, passed: bool}
     */
    public static function lintLibraryCss(string $type, string $css): array
    {
        $file = "sections/{$type}.css";
        $errors = self::unscopedCssFindings($type, $css, $file);
        $warning = (new self)->rtlWarning($file, $css);

        return ['errors' => $errors, 'warnings' => $warning ? [$warning] : [], 'passed' => $errors === []];
    }

    /**
     * Theme Studio B0.9: lint every per-section css file a library version
     * ships (`<library>/sections/<type>.css`) with lintLibraryCss().
     *
     * @return array{errors: list<array{rule:string,file:string,message:string}>, warnings: list<array{rule:string,file:string,message:string}>, passed: bool}
     */
    public function lintLibrary(SectionLibrary $library): array
    {
        $errors = [];
        $warnings = [];
        foreach (glob($library->path('sections').'/*.css') ?: [] as $file) {
            $report = self::lintLibraryCss(basename($file, '.css'), (string) file_get_contents($file));
            array_push($errors, ...$report['errors']);
            array_push($warnings, ...$report['warnings']);
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'passed' => $errors === []];
    }

    /**
     * B0.9 per-file Liquid warnings: unescaped setting output, physical
     * properties in inline style="", and unsized <img>.
     *
     * @param  array<string,string>  $globalTypes  theme setting id => type
     * @return list<array{rule:string,file:string,message:string}>
     */
    protected function liquidQualityWarnings(string $rel, string $source, string $body, array $globalTypes): array
    {
        $warnings = [];

        foreach ($this->unescapedSettingOutputs($body, $this->sectionSettingTypes($source), $globalTypes) as $expr) {
            $warnings[] = [
                'rule' => 'unescaped-setting-output',
                'file' => $rel,
                'message' => "'{{ {$expr} }}' outputs a merchant setting without an escaping filter — settings reach "
                    ."Liquid unescaped. Add '| escape' (or format it with money / t / media_url / url_encode / json).",
            ];
        }

        preg_match_all('/\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $body, $m);
        $inline = implode(";\n", array_map(fn ($a, $b) => $a !== '' ? $a : $b, $m[1], $m[2]));
        if ($inline !== '' && ($w = $this->rtlWarning($rel, $inline, 'inline style=""')) !== null) {
            $warnings[] = $w;
        }

        $unsized = 0;
        preg_match_all('/<img\b[^>]*>/i', $body, $imgs);
        foreach ($imgs[0] as $tag) {
            if (! preg_match('/\swidth\s*=/i', $tag) || ! preg_match('/\sheight\s*=/i', $tag)) {
                $unsized++;
            }
        }
        if ($unsized > 0) {
            $warnings[] = [
                'rule' => 'img-missing-dimensions',
                'file' => $rel,
                'message' => "{$unsized} <img> tag(s) without both width and height attributes — the page shifts as "
                    .'images load (CLS). Set the intrinsic width/height (CSS can still size them).',
            ];
        }

        return $warnings;
    }

    /** Setting types whose validated values cannot carry markup. */
    private const SAFE_SETTING_TYPES = [
        'color', 'checkbox', 'toggle', 'range', 'number', 'select', 'color_token', 'font_pair',
        'category', 'branch', 'item_list', 'image_picker', 'richtext',
    ];

    /** Filters that escape or reduce a value to a safe format. */
    private const SAFE_OUTPUT_FILTERS = [
        'escape', 'escape_once', 'studio_text', 'money', 'money_code', 't', 'media_url', 'theme_asset', 'url_encode',
        'url_escape', 'json', 'strip_html', 'size', 'plus', 'minus', 'times', 'divided_by', 'modulo',
        'round', 'ceil', 'floor', 'abs', 'at_least', 'at_most',
    ];

    /**
     * `{{ section.settings.x }}`-style outputs without a safe filter. Settings
     * whose declared type cannot carry markup (colour, number, select, a
     * sanitized richtext, ...) are skipped.
     *
     * @param  array{section: array<string,string>, block: array<string,string>}  $local
     * @param  array<string,string>  $global
     * @return list<string> the offending expressions (deduplicated)
     */
    protected function unescapedSettingOutputs(string $body, array $local, array $global): array
    {
        preg_match_all('/{{-?\s*((section\.settings|block\.settings|settings)\.([A-Za-z0-9_]+))\s*((?:\|[^}]*)?)-?}}/', $body, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $match) {
            [, $expr, $scope, $id, $chain] = $match;
            $type = match ($scope) {
                'section.settings' => $local['section'][$id] ?? null,
                'block.settings' => $local['block'][$id] ?? null,
                default => $global[$id] ?? null,
            };
            if ($type !== null && in_array($type, self::SAFE_SETTING_TYPES, true)) {
                continue;
            }
            preg_match_all('/\|\s*([a-z_][a-z0-9_]*)/i', $chain, $f);
            if (array_intersect(array_map('strtolower', $f[1]), self::SAFE_OUTPUT_FILTERS) !== []) {
                continue;
            }
            $out[$expr] = true;
        }

        return array_keys($out);
    }

    /**
     * id => type for a section file's own settings and (merged) block settings.
     *
     * @return array{section: array<string,string>, block: array<string,string>}
     */
    protected function sectionSettingTypes(string $source): array
    {
        $schema = SectionedTemplate::extractSchema($source);
        $types = ['section' => [], 'block' => []];
        foreach ($schema['settings'] ?? [] as $def) {
            if (is_array($def) && isset($def['id'], $def['type']) && is_string($def['id'])) {
                $types['section'][$def['id']] = (string) $def['type'];
            }
        }
        foreach ($schema['blocks'] ?? [] as $block) {
            foreach (is_array($block) ? ($block['settings'] ?? []) : [] as $def) {
                if (is_array($def) && isset($def['id'], $def['type']) && is_string($def['id'])) {
                    $types['block'][$def['id']] = (string) $def['type'];
                }
            }
        }

        return $types;
    }

    /**
     * id => type for theme-level settings (config/settings_schema.json).
     *
     * @param  array<mixed>|null  $schema
     * @return array<string,string>
     */
    protected function globalSettingTypes(?array $schema): array
    {
        $types = [];
        foreach ($schema ?? [] as $group) {
            foreach (is_array($group) ? ($group['settings'] ?? $group['fields'] ?? []) : [] as $def) {
                if (is_array($def) && isset($def['id'], $def['type']) && is_string($def['id'])) {
                    $types[$def['id']] = (string) $def['type'];
                }
            }
        }

        return $types;
    }

    /**
     * One rtl-physical-prop warning for a css blob (or null when clean),
     * naming each physical property found and its logical replacement.
     *
     * @return array{rule:string,file:string,message:string}|null
     */
    protected function rtlWarning(string $rel, string $css, string $where = ''): ?array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $found = [];
        if (preg_match_all('/(?<![\w-])((?:margin|padding|border)-(?:left|right)(?:-[a-z]+)?|left|right)\s*:/i', $css, $m)) {
            foreach ($m[1] as $prop) {
                $prop = strtolower($prop);
                $found[$prop] = match ($prop) {
                    'left' => 'inset-inline-start',
                    'right' => 'inset-inline-end',
                    default => str_replace(['-left', '-right'], ['-inline-start', '-inline-end'], $prop),
                };
            }
        }
        if (preg_match_all('/(?<![\w-])(text-align|float)\s*:\s*(left|right)\b/i', $css, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $prop, $side]) {
                $prop = strtolower($prop);
                $logical = strtolower($side) === 'left' ? 'start' : 'end';
                $found[$prop.': '.strtolower($side)] = $prop === 'float' ? "float: inline-{$logical}" : "text-align: {$logical}";
            }
        }
        if ($found === []) {
            return null;
        }

        $pairs = [];
        foreach ($found as $physical => $logical) {
            $pairs[] = "{$physical} → {$logical}";
        }

        return [
            'rule' => 'rtl-physical-prop',
            'file' => $rel,
            'message' => 'physical left/right CSS'.($where !== '' ? " in {$where}" : '').' breaks right-to-left stores — use logical properties: '
                .implode(', ', $pairs).'.',
        ];
    }

    /** `sections/<type>.css` anywhere in a path → the section type, else null. */
    protected static function sectionCssType(string $rel): ?string
    {
        return preg_match('#(?:^|/)sections/([a-z0-9][a-z0-9_-]*)\.css$#i', $rel, $m) ? strtolower($m[1]) : null;
    }

    /**
     * A theme's own per-section css files (any `sections/<type>.css` below the
     * version root).
     *
     * @return array<string,array{0:string,1:string}> rel path => [type, css]
     */
    protected function sectionCssFiles(MarketplaceTheme $theme): array
    {
        $root = $theme->path();
        if (! is_dir($root)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'css') {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            if (($type = self::sectionCssType($rel)) !== null) {
                $out[$rel] = [$type, (string) file_get_contents($file->getPathname())];
            }
        }

        return $out;
    }

    /**
     * Every style-rule selector must start with `.mbs-<type>` (followed by a
     * non-name character, or a BEM `__element` / `--modifier`). Grouping at-rules (`@media`, `@supports`,
     * `@container`, `@layer`) are descended into; `@keyframes` / `@font-face` bodies are skipped.
     *
     * @return list<array{rule:string,file:string,message:string}>
     */
    protected static function unscopedCssFindings(string $type, string $css, string $file): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $scope = '.mbs-'.$type;
        $bad = [];

        $len = strlen($css);
        $prelude = '';
        for ($i = 0; $i < $len; $i++) {
            $c = $css[$i];
            if ($c === ';' || $c === '}') {
                $prelude = ''; // @import/@charset, or the end of a grouping rule

                continue;
            }
            if ($c !== '{') {
                $prelude .= $c;

                continue;
            }

            $head = trim($prelude);
            $prelude = '';
            if (preg_match('/^@(media|supports|container|layer|document)\b/i', $head)) {
                continue; // grouping rule: its children are style rules
            }
            if (! str_starts_with($head, '@')) {
                foreach (self::splitSelectors($head) as $selector) {
                    // `.mbs-hero__title` / `.mbs-hero--wide` (BEM) belong to the
                    // section; `.mbs-hero-cover` is a DIFFERENT section's class.
                    $rest = substr($selector, strlen($scope));
                    if (! str_starts_with($selector, $scope)
                        || (preg_match('/^[\w-]/', $rest) && ! preg_match('/^(__|--)[a-z0-9]/i', $rest))) {
                        $bad[$selector] = true;
                    }
                }
            }
            // Skip this rule's body (declarations, nested rules, keyframe steps).
            $level = 1;
            while ($level > 0 && ++$i < $len) {
                if ($css[$i] === '{') {
                    $level++;
                } elseif ($css[$i] === '}') {
                    $level--;
                }
            }
        }

        $out = [];
        foreach (array_keys($bad) as $selector) {
            $out[] = [
                'rule' => 'section-css-unscoped',
                'file' => $file,
                'message' => "selector '{$selector}' must start with '{$scope}' — section CSS may only style its own section.",
            ];
        }

        return $out;
    }

    /**
     * Split a selector list on top-level commas (not inside :is() or [attr]).
     *
     * @return list<string>
     */
    private static function splitSelectors(string $list): array
    {
        $parts = [];
        $buf = '';
        $nest = 0;
        foreach (str_split($list) as $ch) {
            if ($ch === '(' || $ch === '[') {
                $nest++;
            } elseif ($ch === ')' || $ch === ']') {
                $nest = max(0, $nest - 1);
            }
            if ($ch === ',' && $nest === 0) {
                $parts[] = trim($buf);
                $buf = '';

                continue;
            }
            $buf .= $ch;
        }
        $parts[] = trim($buf);

        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * Theme Studio B0.1: bodies of the library sections a template uses (and the
     * theme doesn't override), so feature-wiring checks see the whole page. The
     * library itself is linted on its own (not re-reported per theme).
     *
     * @param  array<string,mixed>|string|null  $templateJson
     * @param  array<string,string>  $themeBodies  rel path → body already collected
     * @return array<string,string>
     */
    protected function librarySectionBodies(SectionLibrary $library, array|string|null $templateJson, array $themeBodies): array
    {
        $json = is_string($templateJson) ? json_decode($templateJson, true) : $templateJson;
        $sections = is_array($json) && is_array($json['sections'] ?? null) ? $json['sections'] : [];

        $out = [];
        foreach ($sections as $instance) {
            $type = is_array($instance) ? (string) ($instance['type'] ?? '') : '';
            if ($type === '' || isset($themeBodies["sections/{$type}.liquid"])) {
                continue;
            }
            $source = $library->sectionSource($type);
            if ($source !== null) {
                $out["library/sections/{$type}.liquid"] = $this->stripSchemaBlocks($source);
            }
        }

        return $out;
    }

    /**
     * The bodies of the LIBRARY's snippets.
     *
     * The advisory feature-wiring scan has to see them: the hooks it looks
     * for — `data-mb-open` on a card, `data-mb-add` on its add button — live
     * in the library's card snippet for every theme that uses the library's
     * cards. Without them, every library-based theme is warned that its
     * items "cannot be opened/deep-linked" and that "nothing can be added to
     * the cart", while the library's card does exactly that. The library
     * itself is linted on its own (not re-reported per theme).
     *
     * @param  array<string,string>  $themeBodies  rel path → body already collected
     * @return array<string,string>
     */
    protected function librarySnippetBodies(SectionLibrary $library, array $themeBodies): array
    {
        $out = [];
        foreach ($library->snippets() as $name => $source) {
            // The theme's own snippet of the same name wins.
            if (isset($themeBodies['snippets/'.$name.'.liquid'])) {
                continue;
            }
            $out['library/snippets/'.$name.'.liquid'] = $this->stripSchemaBlocks((string) $source);
        }

        return $out;
    }

    /** @return array<string,string> rel path → source */
    protected function liquidFiles(MarketplaceTheme $theme): array
    {
        $out = [];
        foreach (['templates', 'sections', 'snippets', 'layout'] as $dir) {
            foreach (glob($theme->path($dir).'/*.liquid') ?: [] as $file) {
                $out[$dir.'/'.basename($file)] = (string) file_get_contents($file);
            }
        }

        return $out;
    }

    /** @return array<string,string> */
    protected function cssFiles(MarketplaceTheme $theme): array
    {
        $out = [];
        $base = $theme->path('assets');
        if (! is_dir($base)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'css') {
                $out['assets/'.$file->getBasename()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    /** The closed filter whitelist: platform filters + the engine's standard set. */
    protected function allowedFilters(): array
    {
        $standard = [];
        foreach (new \ReflectionClass(StandardFilters::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if (! $m->isStatic() && ! str_starts_with($m->getName(), '__')) {
                $standard[] = Str::snake($m->getName());
            }
        }

        return array_values(array_unique(array_merge($standard, DeveloperDocsController::filterReference())));
    }

    protected function stripSchemaBlocks(string $source): string
    {
        return (string) preg_replace('/{%-?\s*schema\s*-?%}.*?{%-?\s*endschema\s*-?%}/s', '', $source);
    }

    /** @return list<string> */
    protected function usedFilters(string $body): array
    {
        // Filters appear as `| name` inside output/tag markup only.
        preg_match_all('/{{.*?}}|{%.*?%}/s', $body, $chunks);
        $found = [];
        foreach ($chunks[0] as $chunk) {
            preg_match_all('/\|\s*([a-z_][a-z0-9_]*)/i', $chunk, $m);
            foreach ($m[1] as $f) {
                $found[] = strtolower($f);
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    protected function renderedSnippets(string $body): array
    {
        preg_match_all('/{%-?\s*render\s+[\'"]([a-z0-9\-_]+)[\'"]/i', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> */
    protected function externalUrls(string $body): array
    {
        preg_match_all('#https?://[^\s"\'()<>]+#i', $body, $m);

        return array_values(array_unique($m[0]));
    }

    /** @return list<string> */
    protected function unusedAssigns(string $body): array
    {
        preg_match_all('/{%-?\s*assign\s+([a-z_][a-z0-9_]*)\s*=/i', $body, $m);
        $unused = [];
        foreach (array_unique($m[1]) as $var) {
            $uses = preg_match_all('/\b'.preg_quote($var, '/').'\b/', $body);
            if ($uses <= 1) { // only the assignment itself
                $unused[] = $var;
            }
        }

        return $unused;
    }

    /**
     * Class 1 (see class docblock): heuristic, ADVISORY-ONLY checks across the
     * whole theme file set. `validate_theme`'s hard gates (parse/filters/
     * snippets/schema) prove a theme is safe and well-formed; they say nothing
     * about whether it actually WORKS as a storefront. A theme with a static
     * menu, real markup, zero `{% commerce %}`, zero `data-mb-add` and zero
     * `data-mb-open` lints 100% clean today — this closes that gap without
     * turning it into a hard rejection (a scroll-page theme with no cart is a
     * legitimate design choice; the author should just be told, not blocked).
     *
     * Runs once over the merged file set rather than per-file: {% commerce %}
     * canonically lives in the index template while the item loop/cart markup
     * it powers usually lives in a section or snippet (see crave/1 vs the
     * starter theme), so a per-file check would false-positive on every
     * multi-file theme.
     *
     * @param  array<string,string>  $bodies  rel path => schema-stripped source
     * @return list<array{rule:string,file:string,message:string}>
     */
    protected function featureWiringWarnings(array $bodies): array
    {
        $warnings = [];

        $hasCommerce = false;
        $hasAffordance = false;
        $affordanceFile = null;
        $hasItemsLoop = false;
        $itemsLoopFile = null;
        $hasOpen = false;
        $hasAdd = false;

        foreach ($bodies as $rel => $body) {
            if (! $hasCommerce && $this->hasCommerceTag($body)) {
                $hasCommerce = true;
            }
            if (! $hasAffordance && $this->hasOrderAffordance($body)) {
                $hasAffordance = true;
                $affordanceFile = $rel;
            }
            if (! $hasItemsLoop && $this->hasItemsLoop($body)) {
                $hasItemsLoop = true;
                $itemsLoopFile = $rel;
            }
            if (! $hasOpen && $this->hasAttr($body, 'data-mb-open')) {
                $hasOpen = true;
            }
            if (! $hasAdd && $this->hasAttr($body, 'data-mb-add')) {
                $hasAdd = true;
            }
            if ($this->rawUnmoneyedPriceOccurs($body)) {
                $warnings[] = [
                    'rule' => 'price-missing-money',
                    'file' => $rel,
                    'message' => "raw '{{ item.price }}' rendered without the | money filter — currency symbol, "
                        ."position and RTL layout will be wrong. Use '{{ item.price | money }}'.",
                ];
            }
        }

        if ($hasAffordance && ! $hasCommerce) {
            $warnings[] = [
                'rule' => 'commerce-tag-missing',
                'file' => $affordanceFile ?? 'templates/index.liquid',
                'message' => 'order/cart markup is present but no {% commerce %} tag was found anywhere in the '
                    .'theme: cart/checkout will not work. Add {% commerce %} once, typically in your index template.',
            ];
        }
        if ($hasItemsLoop && ! $hasOpen) {
            $warnings[] = [
                'rule' => 'item-deep-link-missing',
                'file' => $itemsLoopFile ?? 'templates/index.liquid',
                'message' => 'item cards are rendered but none carry data-mb-open — items cannot be opened/'
                    .'deep-linked. Add data-mb-open="{{ item.id }}" to each item card.',
            ];
        }
        if ($hasItemsLoop && ! $hasAdd) {
            $warnings[] = [
                'rule' => 'cart-add-missing',
                'file' => $itemsLoopFile ?? 'templates/index.liquid',
                'message' => 'item cards are rendered but no data-mb-add was found anywhere in the theme — '
                    .'nothing can be added to the cart. Add data-mb-add="{{ item.id }}" to each item card.',
            ];
        }

        return $warnings;
    }

    /** `{% commerce %}` anywhere in the file. */
    protected function hasCommerceTag(string $body): bool
    {
        return (bool) preg_match('/{%-?\s*commerce\s*-?%}/i', $body);
    }

    /**
     * The theme is rendering order/cart UI (so it implies checkout should
     * work): it branches on `flags.allow_order`, or wires an add-to-cart /
     * cart-mount / open-cart control.
     */
    protected function hasOrderAffordance(string $body): bool
    {
        return (bool) preg_match('/flags\.allow_order/i', $body)
            || $this->hasAttr($body, 'data-mb-add')
            || $this->hasAttr($body, 'data-mb-cart-mount')
            || $this->hasAttr($body, 'data-mb-open-cart');
    }

    /** A `{% for x in y.items %}`-shaped loop — the item-card render pattern. */
    protected function hasItemsLoop(string $body): bool
    {
        return (bool) preg_match('/{%-?\s*for\s+\w+\s+in\s+[\w.]*items\b/i', $body);
    }

    /**
     * Whether a `data-mb-*` attribute name is present as an EXACT attribute
     * (not a longer sibling — e.g. `data-mb-open` must not match inside
     * `data-mb-open-cart`, and `data-mb-add` must not match a hypothetical
     * `data-mb-address`).
     */
    protected function hasAttr(string $body, string $attr): bool
    {
        return (bool) preg_match('/'.preg_quote($attr, '/').'(?![\w-])/i', $body);
    }

    /**
     * A raw `{{ item.price }}` (optionally piped through filters that don't
     * include `money`/`money_code`) rendered as visible text — i.e. NOT inside
     * an HTML attribute value (`data-mb-price="{{ item.price }}"` is a legit
     * machine-readable value for the runtime's sort/filter/search, not
     * user-facing currency text, and must not be flagged).
     */
    protected function rawUnmoneyedPriceOccurs(string $body): bool
    {
        if (! preg_match_all('/{{-?\s*item\.price\s*(\|[^}]*)?-?}}/i', $body, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($matches[0] as $i => [, $offset]) {
            $filterChain = $matches[1][$i][0] ?? '';
            if (preg_match('/\bmoney(_code)?\b/i', $filterChain)) {
                continue; // already currency-formatted
            }
            if (substr($body, max(0, $offset - 2), 2) === '="') {
                continue; // inside an HTML attribute value, e.g. data-mb-price="{{ item.price }}"
            }

            return true;
        }

        return false;
    }
}
