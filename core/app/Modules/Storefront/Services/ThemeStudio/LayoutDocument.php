<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio;

use Juwri\Core\Models\Store;
use App\Services\ThemeCustomizerService;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\ThemeSettingValues;

/**
 * Theme Studio B0.2: a store's own layout for a sectioned theme — the same
 * shape as the theme's templates/index.json plus room for theme-level values:
 *
 *   {schema: 1,
 *    sections: {sid: {type, settings{}, disabled?, blocks{bid: {type, settings{}}}, block_order[],
 *                     style?: {css, prompt, created_at}}},   // E2 AI CSS variant (SectionRestyler)
 *    order: [sid…],
 *    theme: {…},   // phase B0.3/B0.5 (theme settings, tokens, fonts)
 *    i18n:  {lang: {"sid.key" | "sid.bid.key": text}}}   // C4 per-language text
 *
 * normalize() fixes structure (ids, order, limits); validate() checks every
 * section/block type and setting against the theme's own {% schema %}. Section
 * settings reach Liquid unescaped, so a layout is only ever stored after both.
 */
class LayoutDocument
{
    public const SCHEMA = 1;

    public const ID_PATTERN = '/^[a-z0-9][a-z0-9_\-~]{0,63}$/i';

    public const TYPE_PATTERN = '/^[a-z0-9][a-z0-9\-_]*$/i';

    /** C4: a setting id inside an i18n key. */
    public const KEY_PATTERN = '/^[a-z0-9_][a-z0-9_\-]{0,63}$/i';

    /** C4: a menu language code (`ar`, `pt-br`). */
    public const LANG_PATTERN = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i';

    /** Setting types whose value is owner text (translatable, `studio_text`-wrappable). */
    public const TEXT_TYPES = ['text', 'textarea', 'richtext', 'inline_richtext', 'html'];

    /** C4: ceiling of a translated plain `text` setting without its own `max`. */
    public const I18N_TEXT_MAX = 1000;

    /** C4: ceiling of any translated value (textarea / richtext markup). */
    public const I18N_HARD_MAX = 10000;

    /**
     * Structural clean-up. Drops malformed entries, de-duplicates and completes
     * `order`/`block_order`, and enforces the engine limits.
     *
     * @param  array<string,mixed>  $raw
     * @return array{schema:int,sections:array<string,array<string,mixed>>,order:list<string>,theme:array<string,mixed>,i18n:array<string,mixed>}
     *
     * @throws \InvalidArgumentException when a limit is exceeded
     */
    public static function normalize(array $raw): array
    {
        $sections = [];
        foreach (is_array($raw['sections'] ?? null) ? $raw['sections'] : [] as $sid => $instance) {
            $sid = (string) $sid;
            if (! preg_match(self::ID_PATTERN, $sid) || ! is_array($instance)) {
                continue;
            }
            $type = $instance['type'] ?? null;
            if (! is_string($type) || ! preg_match(self::TYPE_PATTERN, $type)) {
                continue;
            }

            $section = [
                'type' => $type,
                'settings' => is_array($instance['settings'] ?? null) ? $instance['settings'] : [],
            ];
            if (($instance['disabled'] ?? false) === true) {
                $section['disabled'] = true;
            }
            // E2: the section's AI CSS variant — shape here; sanitize() cleans the
            // CSS for THIS sid, validate() refuses what cannot be made safe.
            if (($style = SectionRestyler::normalizeStyle($instance['style'] ?? null)) !== null) {
                $section['style'] = $style;
            }

            $blocks = [];
            foreach (is_array($instance['blocks'] ?? null) ? $instance['blocks'] : [] as $bid => $block) {
                $bid = (string) $bid;
                if (! preg_match(self::ID_PATTERN, $bid) || ! is_array($block)) {
                    continue;
                }
                $btype = $block['type'] ?? null;
                if (! is_string($btype) || ! preg_match(self::TYPE_PATTERN, $btype)) {
                    continue;
                }
                $blocks[$bid] = [
                    'type' => $btype,
                    'settings' => is_array($block['settings'] ?? null) ? $block['settings'] : [],
                ];
            }
            if (count($blocks) > SectionedTemplate::MAX_BLOCKS) {
                throw new \InvalidArgumentException("section '{$sid}' has ".count($blocks).' blocks (max '.SectionedTemplate::MAX_BLOCKS.')');
            }
            if ($blocks !== []) {
                $section['blocks'] = $blocks;
                $section['block_order'] = self::completeOrder($instance['block_order'] ?? null, array_keys($blocks));
            }

            $sections[$sid] = $section;
        }

        if (count($sections) > SectionedTemplate::MAX_SECTIONS) {
            throw new \InvalidArgumentException(count($sections).' sections (max '.SectionedTemplate::MAX_SECTIONS.')');
        }

        return [
            'schema' => self::SCHEMA,
            'sections' => $sections,
            'order' => self::completeOrder($raw['order'] ?? null, array_keys($sections)),
            'theme' => is_array($raw['theme'] ?? null) ? $raw['theme'] : [],
            'i18n' => self::normalizeI18n($raw['i18n'] ?? null, $sections),
        ];
    }

    /**
     * C4: `i18n.<lang>["<sid>.<key>" | "<sid>.<bid>.<key>"] = string`, only for
     * section / block ids that exist. Language codes lower-cased; empty strings
     * dropped (a blank translation means "use the default language").
     *
     * @param  array<string,array<string,mixed>>  $sections  normalized sections
     * @return array<string,array<string,string>>
     */
    protected static function normalizeI18n(mixed $raw, array $sections): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $lang => $strings) {
            if (! is_string($lang) || ! preg_match(self::LANG_PATTERN, $lang) || ! is_array($strings)) {
                continue;
            }
            $lang = strtolower($lang);
            foreach ($strings as $path => $value) {
                if (! is_string($value) || trim($value) === '' || mb_strlen($value) > self::I18N_HARD_MAX) {
                    continue;
                }
                $parts = self::i18nPath($path);
                if ($parts === null) {
                    continue;
                }
                [$sid, $bid] = $parts;
                if (! isset($sections[$sid]) || ($bid !== null && ! isset($sections[$sid]['blocks'][$bid]))) {
                    continue;
                }
                $out[$lang][(string) $path] = $value;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Split an i18n key into [sid, bid|null, setting id]; null when malformed.
     *
     * @return array{0:string,1:?string,2:string}|null
     */
    public static function i18nPath(mixed $path): ?array
    {
        if (! is_string($path)) {
            return null;
        }
        $parts = explode('.', $path);
        $n = count($parts);
        if ($n < 2 || $n > 3) {
            return null;
        }
        foreach ($parts as $i => $part) {
            if (! preg_match($i === $n - 1 ? self::KEY_PATTERN : self::ID_PATTERN, $part)) {
                return null;
            }
        }

        return $n === 3 ? [$parts[0], $parts[1], $parts[2]] : [$parts[0], null, $parts[1]];
    }

    /**
     * C4: the text-type setting definitions of a section (and of its block
     * types), keyed by setting id — the only settings a translation may carry.
     *
     * @param  array<string,mixed>  $schema  a section schema
     * @return array{section:array<string,array<string,mixed>>,blocks:array<string,array<string,array<string,mixed>>>}
     */
    public static function textDefs(array $schema): array
    {
        $pick = static function (mixed $defs): array {
            $out = [];
            foreach (is_array($defs) ? $defs : [] as $def) {
                if (is_array($def) && ! empty($def['id']) && in_array($def['type'] ?? 'text', self::TEXT_TYPES, true)) {
                    $out[(string) $def['id']] = $def;
                }
            }

            return $out;
        };

        $blocks = [];
        foreach (is_array($schema['blocks'] ?? null) ? $schema['blocks'] : [] as $def) {
            if (is_array($def) && isset($def['type'])) {
                $blocks[(string) $def['type']] = $pick($def['settings'] ?? []);
            }
        }

        return ['section' => $pick($schema['settings'] ?? []), 'blocks' => $blocks];
    }

    /**
     * C4: one translated value made safe exactly like a stored setting of that
     * definition (richtext allow-list …); null when it does not survive or is
     * longer than the setting allows.
     *
     * @param  array<string,mixed>  $def
     */
    public static function cleanText(array $def, mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $key = (string) $def['id'];
        $clean = ThemeCustomizerService::sanitizeSectionSettings(['settings' => [$def]], [$key => $value])[$key] ?? null;
        if (! is_string($clean) || trim($clean) === '' || mb_strlen($clean) > self::textMax($def)) {
            return null;
        }

        return ThemeCustomizerService::validateSectionSettings(['settings' => [$def]], [$key => $clean]) === [] ? $clean : null;
    }

    /**
     * Length budget of a translated text: the definition's own numeric
     * `max_length` / `max`, else a per-type ceiling.
     *
     * @param  array<string,mixed>  $def
     */
    public static function textMax(array $def): int
    {
        foreach (['max_length', 'max'] as $key) {
            if (isset($def[$key]) && is_numeric($def[$key]) && (int) $def[$key] > 0) {
                return min((int) $def[$key], self::I18N_HARD_MAX);
            }
        }

        return ($def['type'] ?? 'text') === 'text' ? self::I18N_TEXT_MAX : self::I18N_HARD_MAX;
    }

    /**
     * C4: keep only translations a store can use — languages among the store's
     * menu languages other than its default (the default lives in `settings`),
     * paths naming a text-type setting of an existing section / block — and
     * sanitize each value like the setting itself. Over-long / malformed
     * values are then reported by validate(), not silently cut.
     *
     * @param  array<string,mixed>  $layout  normalized
     * @return array<string,array<string,string>>
     */
    public static function sanitizeI18n(MarketplaceTheme $theme, array $layout, ?Store $store = null): array
    {
        $allowed = $store ? StoreLayouts::languages($store) : null;
        $cache = [];
        $out = [];
        foreach (is_array($layout['i18n'] ?? null) ? $layout['i18n'] : [] as $lang => $strings) {
            $lang = strtolower((string) $lang);
            if (! is_array($strings)
                || ($allowed !== null && (! in_array($lang, $allowed['codes'], true) || $lang === $allowed['default']))) {
                continue;
            }
            foreach ($strings as $path => $value) {
                $def = self::textDefFor($theme, $layout, $path, $cache);
                if ($def === null || ! is_string($value)) {
                    continue;
                }
                $key = (string) $def['id'];
                $clean = ThemeCustomizerService::sanitizeSectionSettings(['settings' => [$def]], [$key => $value])[$key] ?? null;
                if (is_string($clean) && trim($clean) !== '') {
                    $out[$lang][(string) $path] = $clean;
                }
            }
        }

        return $out;
    }

    /**
     * The text definition an i18n path names in this layout, or null.
     *
     * @param  array<string,mixed>  $layout
     * @param  array<string,mixed>  $cache  section type → textDefs()|null, filled lazily
     * @return array<string,mixed>|null
     */
    protected static function textDefFor(MarketplaceTheme $theme, array $layout, mixed $path, array &$cache): ?array
    {
        $parts = self::i18nPath($path);
        if ($parts === null) {
            return null;
        }
        [$sid, $bid, $key] = $parts;
        $section = $layout['sections'][$sid] ?? null;
        if (! is_array($section) || ! is_string($section['type'] ?? null)) {
            return null;
        }
        $type = $section['type'];
        if (! array_key_exists($type, $cache)) {
            $source = $theme->sectionSource($type);
            $cache[$type] = $source === null ? null : self::textDefs(SectionedTemplate::extractSchema($source));
        }
        $defs = $cache[$type];
        if ($defs === null) {
            return null;
        }
        if ($bid === null) {
            return $defs['section'][$key] ?? null;
        }
        $btype = $section['blocks'][$bid]['type'] ?? null;

        return is_string($btype) ? ($defs['blocks'][$btype][$key] ?? null) : null;
    }

    /**
     * C4: every default-language text of a layout a translation can target —
     * `path` → effective value (schema default ← stored setting), non-empty only.
     *
     * @param  array<string,mixed>  $layout  normalized
     * @return array<string,array{text:string,def:array<string,mixed>}>
     */
    public static function translatableTexts(MarketplaceTheme $theme, array $layout): array
    {
        $out = [];
        foreach ($layout['sections'] ?? [] as $sid => $section) {
            $source = $theme->sectionSource((string) $section['type']);
            if ($source === null) {
                continue;
            }
            $defs = self::textDefs(SectionedTemplate::extractSchema($source));
            $collect = static function (array $defs, array $values, string $prefix) use (&$out): void {
                foreach ($defs as $key => $def) {
                    $value = $values[$key] ?? ($def['default'] ?? null);
                    if (is_string($value) && trim(strip_tags($value)) !== '') {
                        $out[$prefix.$key] = ['text' => $value, 'def' => $def];
                    }
                }
            };
            $collect($defs['section'], is_array($section['settings'] ?? null) ? $section['settings'] : [], $sid.'.');
            foreach ($section['blocks'] ?? [] as $bid => $block) {
                $collect($defs['blocks'][$block['type']] ?? [], is_array($block['settings'] ?? null) ? $block['settings'] : [], $sid.'.'.$bid.'.');
            }
        }

        return $out;
    }

    /**
     * C5: bring a stored layout in line with the theme's CURRENT section code.
     *
     *  - a section type the theme / library no longer ships is dropped (with
     *    its order entry and translations) and reported;
     *  - a block type its section no longer declares is dropped and reported;
     *  - a schema `"migrations": {"<old id>": "<new id>"}` map (section or block
     *    level) renames stored settings + their translations, when the old id is
     *    no longer declared (the new value wins if both are stored).
     *
     * Idempotent: a migrated layout migrates to itself with no notices.
     *
     * @param  array<string,mixed>  $layout  normalized
     * @return array{layout:array<string,mixed>,notices:list<array<string,string>>,changed:bool}
     */
    public static function migrate(MarketplaceTheme $theme, array $layout): array
    {
        $notices = [];
        $renames = []; // old i18n path => new i18n path
        $dropped = []; // i18n path prefixes to drop

        foreach ($layout['sections'] ?? [] as $sid => $section) {
            $sid = (string) $sid;
            $source = $theme->sectionSource((string) $section['type']);
            if ($source === null) {
                unset($layout['sections'][$sid]);
                $dropped[] = $sid.'.';
                $notices[] = ['kind' => 'section_removed', 'sid' => $sid, 'type' => (string) $section['type']];

                continue;
            }
            $schema = SectionedTemplate::extractSchema($source);

            [$settings, $moved] = self::renameSettings($schema, is_array($section['settings'] ?? null) ? $section['settings'] : []);
            $layout['sections'][$sid]['settings'] = $settings;
            foreach ($moved as $old => $new) {
                $renames[$sid.'.'.$old] = $sid.'.'.$new;
            }

            $blockDefs = [];
            foreach (is_array($schema['blocks'] ?? null) ? $schema['blocks'] : [] as $def) {
                if (is_array($def) && isset($def['type'])) {
                    $blockDefs[(string) $def['type']] = $def;
                }
            }
            foreach ($section['blocks'] ?? [] as $bid => $block) {
                $bid = (string) $bid;
                $def = $blockDefs[$block['type']] ?? null;
                if ($def === null) {
                    unset($layout['sections'][$sid]['blocks'][$bid]);
                    $layout['sections'][$sid]['block_order'] = array_values(array_filter(
                        $layout['sections'][$sid]['block_order'] ?? [],
                        static fn ($id) => (string) $id !== $bid
                    ));
                    $dropped[] = $sid.'.'.$bid.'.';
                    $notices[] = ['kind' => 'block_removed', 'sid' => $sid, 'bid' => $bid, 'type' => (string) $block['type'], 'section_type' => (string) $section['type']];

                    continue;
                }
                [$bsettings, $bmoved] = self::renameSettings($def, is_array($block['settings'] ?? null) ? $block['settings'] : []);
                $layout['sections'][$sid]['blocks'][$bid]['settings'] = $bsettings;
                foreach ($bmoved as $old => $new) {
                    $renames[$sid.'.'.$bid.'.'.$old] = $sid.'.'.$bid.'.'.$new;
                }
            }
            if (array_key_exists('blocks', $layout['sections'][$sid]) && $layout['sections'][$sid]['blocks'] === []) {
                unset($layout['sections'][$sid]['blocks'], $layout['sections'][$sid]['block_order']);
            }
        }

        $changed = $notices !== [] || $renames !== [];
        if ($notices !== []) {
            $layout['order'] = array_values(array_filter(
                is_array($layout['order'] ?? null) ? $layout['order'] : [],
                static fn ($sid) => isset($layout['sections'][(string) $sid])
            ));
        }

        foreach (is_array($layout['i18n'] ?? null) ? $layout['i18n'] : [] as $lang => $strings) {
            $next = [];
            foreach (is_array($strings) ? $strings : [] as $path => $value) {
                $path = (string) $path;
                foreach ($dropped as $prefix) {
                    if (str_starts_with($path, $prefix)) {
                        $changed = true;

                        continue 2;
                    }
                }
                if (isset($renames[$path])) {
                    $next[$renames[$path]] ??= $value;

                    continue;
                }
                $next[$path] = $value;
            }
            if ($next === []) {
                unset($layout['i18n'][$lang]);
            } else {
                $layout['i18n'][$lang] = $next;
            }
        }

        return ['layout' => $layout, 'notices' => $notices, 'changed' => $changed];
    }

    /**
     * Apply a schema's `migrations` rename map to stored values.
     *
     * @param  array<string,mixed>  $schema  a section schema or a block definition
     * @param  array<string,mixed>  $settings
     * @return array{0:array<string,mixed>,1:array<string,string>} settings, old → new actually moved
     */
    protected static function renameSettings(array $schema, array $settings): array
    {
        $map = is_array($schema['migrations'] ?? null) ? $schema['migrations'] : [];
        if ($map === []) {
            return [$settings, []];
        }
        $declared = [];
        foreach (is_array($schema['settings'] ?? null) ? $schema['settings'] : [] as $def) {
            if (is_array($def) && ! empty($def['id'])) {
                $declared[(string) $def['id']] = true;
            }
        }

        $moved = [];
        foreach ($map as $old => $new) {
            $old = (string) $old;
            if (! is_string($new) || $new === '' || $new === $old || isset($declared[$old]) || ! isset($declared[$new])
                || ! array_key_exists($old, $settings)) {
                continue;
            }
            if (! array_key_exists($new, $settings)) {
                $settings[$new] = $settings[$old];
            }
            unset($settings[$old]);
            $moved[$old] = $new;
        }

        return [$settings, $moved];
    }

    /**
     * Canonicalize every setting value of a normalized layout against the
     * schemas that declare it (ThemeCustomizerService::sanitizeSectionSettings:
     * richtext allow-list, numeric strings → numbers, trimmed urls) — call
     * before validate(), and store the result. Unknown section / block types
     * are left as they are for validate() to report.
     *
     * @param  array<string,mixed>  $layout  normalized
     * @return array<string,mixed>
     */
    public static function sanitize(MarketplaceTheme $theme, array $layout): array
    {
        if (is_array($layout['theme']['settings'] ?? null)) {
            $layout['theme']['settings'] = ThemeSettingValues::sanitize($theme, $layout['theme']['settings']);
        }

        foreach ($layout['sections'] ?? [] as $sid => $section) {
            $source = $theme->sectionSource($section['type']);
            if ($source === null) {
                continue;
            }
            $schema = SectionedTemplate::extractSchema($source);
            $layout['sections'][$sid]['settings'] = ThemeCustomizerService::sanitizeSectionSettings($schema, $section['settings'] ?? []);
            if (is_array($section['style'] ?? null)
                && ($css = SectionRestyler::clean($section['style']['css'] ?? null, (string) $sid)) !== null) {
                $layout['sections'][$sid]['style']['css'] = $css;
            }

            $blockDefs = [];
            foreach ($schema['blocks'] ?? [] as $def) {
                if (is_array($def) && isset($def['type'])) {
                    $blockDefs[(string) $def['type']] = $def;
                }
            }
            foreach ($section['blocks'] ?? [] as $bid => $block) {
                if (isset($blockDefs[$block['type']])) {
                    $layout['sections'][$sid]['blocks'][$bid]['settings'] = ThemeCustomizerService::sanitizeSectionSettings($blockDefs[$block['type']], $block['settings'] ?? []);
                }
            }
        }

        return $layout;
    }

    /**
     * Check a normalized layout against the theme: every section type must have
     * a source (theme or pinned library), every setting must pass that section's
     * schema, every block type must be declared by the section, and every block
     * setting must pass the block's schema.
     *
     * With a store, image / category / item / branch pickers must reference
     * that store's OWN rows (a layout can never point at another tenant's
     * media or menu); without one only the value shape is checked.
     *
     * @param  array<string,mixed>  $layout  normalized, then sanitize()d
     * @return array<string,string> "sections.<sid>[.blocks.<bid>].<key>" → error
     */
    public static function validate(MarketplaceTheme $theme, array $layout, ?Store $store = null): array
    {
        $errors = [];

        // B0.3: theme-level values must be settings the theme declares.
        $themeSettings = is_array($layout['theme']['settings'] ?? null) ? $layout['theme']['settings'] : [];
        foreach (ThemeSettingValues::validate($theme, $themeSettings, $store) as $key => $error) {
            $errors["theme.settings.{$key}"] = $error;
        }

        // C4: stored translations must name a text setting and fit it (length + format).
        $cache = [];
        foreach (is_array($layout['i18n'] ?? null) ? $layout['i18n'] : [] as $lang => $strings) {
            foreach (is_array($strings) ? $strings : [] as $path => $value) {
                $def = self::textDefFor($theme, $layout, $path, $cache);
                if ($def === null) {
                    $errors["i18n.{$lang}.{$path}"] = 'Not a translatable setting.';
                } elseif (! is_string($value) || mb_strlen($value) > self::textMax($def)) {
                    $errors["i18n.{$lang}.{$path}"] = 'Too long.';
                } elseif (($error = ThemeCustomizerService::validateSectionSettings(['settings' => [$def]], [(string) $def['id'] => $value], $store)[(string) $def['id']] ?? null) !== null) {
                    $errors["i18n.{$lang}.{$path}"] = $error;
                }
            }
        }

        foreach ($layout['sections'] ?? [] as $sid => $section) {
            $source = $theme->sectionSource($section['type']);
            if ($source === null) {
                $errors["sections.{$sid}.type"] = "unknown section type '{$section['type']}'";

                continue;
            }
            $schema = SectionedTemplate::extractSchema($source);

            foreach (ThemeCustomizerService::validateSectionSettings($schema, $section['settings'] ?? [], $store) as $key => $error) {
                $errors["sections.{$sid}.{$key}"] = $error;
            }

            // E2: a stored CSS variant must be exactly what SectionRestyler makes of it.
            if (array_key_exists('style', $section)) {
                $css = is_array($section['style']) ? ($section['style']['css'] ?? null) : null;
                if (! is_string($css) || SectionRestyler::clean($css, (string) $sid) !== $css) {
                    $errors["sections.{$sid}.style"] = 'This section style could not be used.';
                }
            }

            $blockDefs = [];
            foreach ($schema['blocks'] ?? [] as $def) {
                if (is_array($def) && isset($def['type'])) {
                    $blockDefs[(string) $def['type']] = $def;
                }
            }
            foreach ($section['blocks'] ?? [] as $bid => $block) {
                $def = $blockDefs[$block['type']] ?? null;
                if ($def === null) {
                    $errors["sections.{$sid}.blocks.{$bid}.type"] = "section '{$section['type']}' has no block type '{$block['type']}'";

                    continue;
                }
                foreach (ThemeCustomizerService::validateSectionSettings($def, $block['settings'] ?? [], $store) as $key => $error) {
                    $errors["sections.{$sid}.blocks.{$bid}.{$key}"] = $error;
                }
            }
        }

        return $errors;
    }

    /**
     * The render-time template ({sections, order}) of a stored layout, or null
     * when the store has none (the theme's index.json applies).
     *
     * @param  array<string,mixed>|null  $layout
     * @return array{sections:array<string,mixed>,order:list<string>}|null
     */
    public static function template(?array $layout): ?array
    {
        if (! is_array($layout) || ! is_array($layout['sections'] ?? null)) {
            return null;
        }

        return [
            'sections' => $layout['sections'],
            'order' => is_array($layout['order'] ?? null) ? array_values($layout['order']) : array_keys($layout['sections']),
        ];
    }

    /**
     * Keep the listed ids that exist (first occurrence wins), then append any
     * existing id the list forgot, so nothing is silently hidden by a bad order.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    protected static function completeOrder(mixed $order, array $ids): array
    {
        $out = [];
        foreach (is_array($order) ? $order : [] as $id) {
            $id = (string) $id;
            if (in_array($id, $ids, true) && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        foreach ($ids as $id) {
            if (! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
