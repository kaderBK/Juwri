<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderFailedException;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Storefront\Drops\BlockDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SectionDrop;

/**
 * P2-3: the OS 2.0 sectioned-template engine. A template is DATA
 * (templates/index.json listing section instances); sections are Liquid files
 * (sections/<type>.liquid) carrying their settings schema in {% schema %}.
 * Merchant customizations live in a separate record keyed by SECTION ID, so a
 * theme update replaces section code without wiping merchant settings.
 *
 * Guardrails (Shopify's documented limits): ≤25 sections per template,
 * ≤50 blocks per section. Every section renders through the sandboxed
 * LiquidRenderer (budget + whitelist); in live mode a failing section is
 * reported and skipped — one broken section never blanks the whole menu.
 */
class SectionedTemplate
{
    public const MAX_SECTIONS = 25;

    public const MAX_BLOCKS = 50;

    public function __construct(protected LiquidRenderer $renderer) {}

    /**
     * Render the theme's index template.
     *
     * @param  array<string,mixed>  $globals  Drop globals from ThemeContext::build()
     * @param  array<string,mixed>  $registers  server-set registers
     * @param  array<string,array<string,mixed>>  $merchantSettings  section id → values
     *                                                               (+ optional `_order`: list of section ids)
     * @param  'live'|'upload'  $mode
     * @param  array<string,mixed>|null  $layout  Theme Studio B0.2: the store's own layout
     *                                            document; replaces the theme's index.json
     *
     * @throws RenderFailedException in upload mode on any structural/section error.
     */
    public function render(
        MarketplaceTheme $theme,
        array $globals,
        array $registers = [],
        array $merchantSettings = [],
        string $mode = 'live',
        ?array $layout = null,
    ): string {
        $json = LayoutDocument::template($layout) ?? $theme->templateJson();
        $sections = is_array($json['sections'] ?? null) ? $json['sections'] : [];
        $order = is_array($json['order'] ?? null) ? $json['order'] : array_keys($sections);

        // Customizer section order (`sections._order`): listed ids first, the
        // rest keep their template order. A layout document carries its own order.
        $merchantOrder = $merchantSettings['_order'] ?? null;
        unset($merchantSettings['_order']);
        if ($layout === null && is_array($merchantOrder) && $merchantOrder !== []) {
            $listed = array_values(array_filter(
                array_unique(array_map('strval', $merchantOrder)),
                static fn (string $id) => in_array($id, $order, true)
            ));
            $order = array_merge($listed, array_values(array_diff($order, $listed)));
        }

        if (count($order) > self::MAX_SECTIONS) {
            throw new RenderFailedException(
                'template lists '.count($order).' sections (max '.self::MAX_SECTIONS.')'
            );
        }

        $studio = self::studioMode($registers);
        $strings = self::translations($layout, $registers);

        $out = '';
        foreach ($order as $sectionId) {
            $instance = $sections[$sectionId] ?? null;
            if (! is_array($instance) || empty($instance['type'])) {
                continue; // ordered id without a definition — data noise, skip
            }
            if (($instance['disabled'] ?? false) === true
                || ($merchantSettings[$sectionId]['_disabled'] ?? false) === true) {
                // Theme-data-disabled or merchant-hidden (P2-4 customizer). The
                // Studio editor still needs an addressable (empty) slot for it.
                if ($studio) {
                    $out .= self::placeholder((string) $sectionId, (string) $instance['type']);
                }

                continue;
            }

            try {
                $html = $this->renderSection(
                    $theme,
                    (string) $sectionId,
                    $instance,
                    $globals,
                    $registers,
                    $merchantSettings[$sectionId] ?? [],
                    $mode,
                    $strings,
                );
                $out .= $html;
                // Each section renders in its own Liquid context, so the
                // `{% commerce %}` tag's once-per-page guard must be carried
                // across sections here — a second runtime would bind every
                // add-to-cart twice.
                if (str_contains($html, 'window.MB_COMMERCE=')) {
                    $registers['__mb_commerce_emitted'] = true;
                }
            } catch (RenderFailedException $e) {
                if ($mode === 'upload') {
                    throw $e; // author must fix at submit time
                }
                report($e); // live: skip the broken section, keep the page
            }
        }

        return $out;
    }

    /**
     * Theme Studio C1: render ONE section of a layout (the editor's fragment
     * endpoint swaps it in place). Null when the id is not in the template.
     * The commerce runtime is already on the page, so it is never re-emitted.
     *
     * @param  array<string,mixed>  $globals
     * @param  array<string,mixed>  $registers
     * @param  array<string,array<string,mixed>>  $merchantSettings
     * @param  array<string,mixed>|null  $layout
     *
     * @throws RenderFailedException when the section itself fails
     */
    public function renderOne(
        MarketplaceTheme $theme,
        string $sectionId,
        array $globals,
        array $registers = [],
        array $merchantSettings = [],
        ?array $layout = null,
    ): ?string {
        $json = LayoutDocument::template($layout) ?? $theme->templateJson();
        $instance = $json['sections'][$sectionId] ?? null;
        if (! is_array($instance) || empty($instance['type'])) {
            return null;
        }
        unset($merchantSettings['_order']);
        if (($instance['disabled'] ?? false) === true
            || ($merchantSettings[$sectionId]['_disabled'] ?? false) === true) {
            return self::studioMode($registers) ? self::placeholder($sectionId, (string) $instance['type']) : '';
        }

        $registers['__mb_commerce_emitted'] = true;

        return $this->renderSection(
            $theme, $sectionId, $instance, $globals, $registers, $merchantSettings[$sectionId] ?? [], 'live',
            self::translations($layout, $registers),
        );
    }

    /**
     * Theme Studio C1: studio mode is a SERVER-set register (owner + ?studio=1 only).
     *
     * @param  array<string,mixed>  $registers
     */
    public static function studioMode(array $registers): bool
    {
        return ($registers['studio_mode'] ?? false) === true;
    }

    /**
     * Theme Studio C4: the language this page renders in — the explicit
     * `render_language` register, else the storefront's current menu language
     * (StoreController hands it over in `widget_context`), else the app locale.
     *
     * @param  array<string,mixed>  $registers
     */
    public static function renderLanguage(array $registers): string
    {
        if (is_string($registers['render_language'] ?? null) && $registers['render_language'] !== '') {
            return strtolower($registers['render_language']);
        }
        $current = $registers['widget_context']['default_menu_language'] ?? null;
        $code = is_object($current) ? ($current->code ?? null) : (is_array($current) ? ($current['code'] ?? null) : null);

        return strtolower(is_string($code) && $code !== '' ? $code : (string) app()->getLocale());
    }

    /**
     * Theme Studio C4: the layout's `i18n` strings for the render language, or
     * [] when the page renders in the store's default language (whose text is
     * the plain settings) or the layout has none.
     *
     * @param  array<string,mixed>|null  $layout
     * @param  array<string,mixed>  $registers
     * @return array<string,string>
     */
    protected static function translations(?array $layout, array $registers): array
    {
        $all = is_array($layout['i18n'] ?? null) ? $layout['i18n'] : [];
        if ($all === []) {
            return [];
        }
        $lang = self::renderLanguage($registers);
        $options = $registers['widget_context']['postOptions'] ?? null;
        $default = is_object($options) ? ($options->default_language ?? null) : null;
        if (is_string($default) && strtolower($default) === $lang) {
            return [];
        }
        $strings = $all[$lang] ?? null;

        return is_array($strings) ? array_filter($strings, 'is_string') : [];
    }

    /**
     * Theme Studio C4: replace text settings with their translation for the
     * render language. Only text-type keys; each value is sanitized exactly
     * like a stored setting (a hand-edited row can never inject markup).
     *
     * @param  array<string,mixed>  $values
     * @param  array<string,array<string,mixed>>  $textDefs  id → text setting def
     * @param  array<string,string>  $strings  path → text
     * @return array<string,mixed>
     */
    protected static function localize(array $values, array $textDefs, array $strings, string $prefix): array
    {
        foreach ($textDefs as $key => $def) {
            $text = $strings[$prefix.$key] ?? null;
            if ($text === null) {
                continue;
            }
            $clean = LayoutDocument::cleanText($def, $text);
            if ($clean !== null) {
                $values[$key] = $clean;
            }
        }

        return $values;
    }

    /** The `data-studio-*` attributes of a section wrapper (studio mode only). */
    protected static function studioAttrs(string $sectionId, string $type): string
    {
        return ' data-studio-section="'.htmlspecialchars($sectionId, ENT_QUOTES).'"'
            .' data-studio-type="'.htmlspecialchars($type, ENT_QUOTES).'"';
    }

    /** An empty, hidden wrapper for a hidden section (studio mode only). */
    protected static function placeholder(string $sectionId, string $type): string
    {
        return '<div class="mb-section" id="mb-section-'.htmlspecialchars($sectionId, ENT_QUOTES).'"'
            .self::studioAttrs($sectionId, $type).' data-studio-disabled="1" hidden></div>';
    }

    /**
     * @param  array<string,mixed>  $instance  the section entry from the template JSON
     * @param  array<string,mixed>  $merchant  merchant values for THIS section id
     * @param  array<string,string>  $strings  C4: the render language's `i18n` map (path → text)
     */
    protected function renderSection(
        MarketplaceTheme $theme,
        string $sectionId,
        array $instance,
        array $globals,
        array $registers,
        array $merchant,
        string $mode,
        array $strings = [],
    ): string {
        $type = (string) $instance['type'];
        $source = $theme->sectionSource($type);
        if ($source === null) {
            throw new RenderFailedException("section type '{$type}' has no source file");
        }

        $schema = static::extractSchema($source);

        // Settings precedence: schema defaults ← template values ← merchant values.
        $settings = array_merge(
            static::schemaDefaults($schema),
            is_array($instance['settings'] ?? null) ? $instance['settings'] : [],
            $merchant,
        );

        // C4: the render language's text replaces the default-language text.
        $textDefs = $strings === [] ? null : LayoutDocument::textDefs($schema);
        if ($textDefs !== null) {
            $settings = self::localize($settings, $textDefs['section'], $strings, $sectionId.'.');
        }

        $blocks = $this->buildBlocks($instance, $schema, $textDefs === null ? [] : $strings, $sectionId, $textDefs['blocks'] ?? []);

        $data = $globals;
        $data['section'] = new SectionDrop($sectionId, $type, $settings, $blocks);

        // Theme Studio C1: the `studio_text` filter needs this section's id and
        // which settings are rich text (printed raw — already sanitized).
        $registers['studio_section_id'] = $sectionId;
        $registers['studio_richtext_keys'] = static::richtextKeys($schema);

        $html = $this->renderer->render($source, $data, $mode, $registers, $theme->snippets());

        // Stable wrapper so the P2-4 editor can address sections in the DOM.
        return '<div class="mb-section" id="mb-section-'.htmlspecialchars($sectionId, ENT_QUOTES).'"'
            .(self::studioMode($registers) ? self::studioAttrs($sectionId, $type) : '').'>'
            .$html.'</div>';
    }

    /**
     * @param  array<string,string>  $strings  C4 translations (path → text)
     * @param  array<string,array<string,array<string,mixed>>>  $textDefs  block type → text defs
     * @return array<int,BlockDrop>
     */
    protected function buildBlocks(array $instance, array $schema, array $strings = [], string $sectionId = '', array $textDefs = []): array
    {
        $defs = is_array($instance['blocks'] ?? null) ? $instance['blocks'] : [];
        $order = is_array($instance['block_order'] ?? null) ? $instance['block_order'] : array_keys($defs);

        if (count($order) > self::MAX_BLOCKS) {
            throw new RenderFailedException(
                'section has '.count($order).' blocks (max '.self::MAX_BLOCKS.')'
            );
        }

        // Per-type defaults from the schema's block definitions.
        $blockDefaults = [];
        foreach ($schema['blocks'] ?? [] as $blockDef) {
            if (! empty($blockDef['type'])) {
                $blockDefaults[$blockDef['type']] = static::settingsDefaults($blockDef['settings'] ?? []);
            }
        }

        $blocks = [];
        foreach ($order as $blockId) {
            $def = $defs[$blockId] ?? null;
            if (! is_array($def) || empty($def['type'])) {
                continue;
            }
            $type = (string) $def['type'];
            $values = array_merge(
                $blockDefaults[$type] ?? [],
                is_array($def['settings'] ?? null) ? $def['settings'] : [],
            );
            if ($strings !== [] && isset($textDefs[$type])) {
                $values = self::localize($values, $textDefs[$type], $strings, $sectionId.'.'.$blockId.'.');
            }
            $blocks[] = new BlockDrop((string) $blockId, $type, $values);
        }

        return $blocks;
    }

    /** Extract + decode the {% schema %} JSON from a section source. */
    public static function extractSchema(string $source): array
    {
        if (! preg_match('/{%-?\s*schema\s*-?%}(.*?){%-?\s*endschema\s*-?%}/s', $source, $m)) {
            return [];
        }
        $decoded = json_decode(trim($m[1]), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $schema
     * @return list<string> ids of the schema's rich-text settings
     */
    protected static function richtextKeys(array $schema): array
    {
        $out = [];
        foreach ($schema['settings'] ?? [] as $def) {
            if (is_array($def) && ! empty($def['id']) && in_array($def['type'] ?? '', ['richtext', 'inline_richtext'], true)) {
                $out[] = (string) $def['id'];
            }
        }

        return $out;
    }

    /** Defaults from a section schema's top-level settings list. */
    public static function schemaDefaults(array $schema): array
    {
        return static::settingsDefaults($schema['settings'] ?? []);
    }

    /** @param  array<int,array<string,mixed>>  $defs  Shopify-style settings defs */
    protected static function settingsDefaults(array $defs): array
    {
        $out = [];
        foreach ($defs as $def) {
            if (! empty($def['id']) && array_key_exists('default', $def)) {
                $out[$def['id']] = $def['default'];
            }
        }

        return $out;
    }
}
