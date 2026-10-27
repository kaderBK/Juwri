<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\StoreCustomBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Content\Services\LockedTemplates\RichText;
use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Models\ThemeSettingVersion;
use Juwri\Modules\Storefront\Services\Theme\ContrastGuard;
use Juwri\Modules\Storefront\Services\Theme\FontPairs;
use Juwri\Modules\Storefront\Services\Theme\SchemaV2;
use Juwri\Modules\Storefront\Services\Theme\ThemeKeys;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;

/**
 * Platform P3: schema-driven theme customizer. The schema is DATA (theme.json in
 * the theme dir) — the customizer only accepts values that validate against it, so
 * a restaurant can restyle its menu without any code execution. Draft → publish.
 */
class ThemeCustomizerService
{
    /** @var array<string,array> normalized schemas, keyed by template */
    private static array $schemaMemo = [];

    /**
     * Load a theme's settings schema (empty if the theme has no theme.json).
     *
     * Customizer v2: whatever the file's own version, it comes back in the
     * normalized v2 shape — shared fragments expanded, sections and fields
     * filled out — so callers only ever handle one structure.
     */
    public static function schemaFor(string $template): array
    {
        if (str_starts_with($template, 'mp:')) {
            return SchemaV2::normalize(self::marketplaceSchema($template), true);
        }

        // Normalizing means a file read, a json_decode and a fragment expansion.
        // A storefront render asks for the schema once per section lookup, so it
        // is memoized for the life of the process.
        if (! array_key_exists($template, self::$schemaMemo)) {
            self::$schemaMemo[$template] = SchemaV2::normalize(self::rawSchemaFor($template));
        }

        return self::$schemaMemo[$template];
    }

    /** Test seam: forget memoized schemas after a fixture edits a theme.json. */
    public static function flushSchemaMemo(): void
    {
        self::$schemaMemo = [];
    }

    /** The theme.json exactly as it sits on disk — the lint test and tooling need the unmodified file. */
    public static function rawSchemaFor(string $template): array
    {
        $path = base_path('../assets/store_templates/'.$template.'/theme.json');
        if (! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }

    /**
     * P2-4: persistence key for theme settings. Marketplace themes store under
     * the VERSIONLESS `mp:<slug>` so merchant customizations survive version
     * updates (the render side reads the same key). First-party = the slug.
     */
    public static function settingsKey(string $template): string
    {
        if (str_starts_with($template, 'mp:')) {
            return preg_replace('/@.*$/', '', $template);
        }

        return $template;
    }

    /**
     * P2-4: build the customizer UI schema for a marketplace theme, in the
     * exact shape the existing schema-driven blade already renders (settings
     * groups + section toggles) — so the UI needs zero changes:
     *  - theme-level `config/settings_schema.json` groups pass through;
     *  - each template section becomes a group whose field ids are namespaced
     *    `sections.<sectionId>.<key>`;
     *  - `sections` lists per-instance visibility toggles.
     */
    private static function marketplaceSchema(string $template): array
    {
        $theme = MarketplaceThemeResolver::resolve($template);
        if (! $theme) {
            return [];
        }

        $groups = [];
        // Theme Studio B0.5: themes built on the section library are styled by
        // the shared design tokens, so they get the same Design controls
        // (colours, corners, spacing, font pair) as the first-party themes.
        if ($theme->library() !== null) {
            $groups[] = ['include' => 'tokens'];
        }
        $raw = $theme->configSettingsSchema();
        if (is_array($raw)) {
            foreach ($raw as $group) {
                $fields = [];
                foreach ($group['settings'] ?? $group['fields'] ?? [] as $def) {
                    if (! empty($def['id'])) {
                        $fields[] = self::uiField($def);
                    }
                }
                if ($fields) {
                    $groups[] = ['group' => $group['name'] ?? $group['group'] ?? 'Theme', 'fields' => $fields];
                }
            }
        }

        $sections = [];
        if ($theme->isSectioned()) {
            $json = $theme->templateJson();
            $order = is_array($json['order'] ?? null) ? $json['order'] : array_keys($json['sections'] ?? []);
            foreach ($order as $sid) {
                $instance = $json['sections'][$sid] ?? null;
                if (! is_array($instance) || empty($instance['type'])) {
                    continue;
                }
                $source = $theme->sectionSource((string) $instance['type']);
                if ($source === null) {
                    continue;
                }
                $schema = SectionedTemplate::extractSchema($source);
                $label = ($schema['name'] ?? $instance['type']);

                $fields = [];
                foreach ($schema['settings'] ?? [] as $def) {
                    if (empty($def['id'])) {
                        continue;
                    }
                    $field = self::uiField($def);
                    $field['id'] = 'sections.'.$sid.'.'.$def['id'];
                    $fields[] = $field;
                }
                if ($fields) {
                    $groups[] = ['group' => $label.' — '.$sid, 'fields' => $fields];
                }
                $sections[] = ['id' => $sid, 'label' => $label, 'default' => true];
            }
        }

        return ['settings' => $groups, 'sections' => $sections, 'marketplace' => true];
    }

    /**
     * Map a Shopify-style setting def to the UI field shape the blade renders.
     *
     * Theme Studio B0.8: the richer setting types keep their own UI type (the
     * editor renders a dedicated control for each) and carry the constraints
     * the control needs — min/max/step for range and number, the allowed
     * values for font_pair and color_token, the list cap for item_list.
     * Unknown types still fall back to a plain text input.
     */
    private static function uiField(array $def): array
    {
        $type = match ($def['type'] ?? 'text') {
            'checkbox', 'toggle' => 'toggle',
            'color' => 'color',
            'select' => 'select',
            'range', 'number', 'textarea', 'image_picker', 'font_pair', 'color_token',
            'richtext', 'url', 'category', 'item_list', 'branch', 'custom_block' => (string) $def['type'],
            default => 'text',
        };
        $out = ['id' => $def['id'], 'type' => $type, 'label' => $def['label'] ?? $def['id']];
        if (array_key_exists('default', $def)) {
            $out['default'] = $def['default'];
        }
        if (! empty($def['info']) && is_string($def['info'])) {
            $out['help'] = $def['info'];
        }
        switch ($type) {
            case 'select':
                $out['options'] = array_map(
                    fn ($o) => is_array($o) ? ($o['value'] ?? '') : $o,
                    $def['options'] ?? []
                );
                break;
            case 'range':
            case 'number':
                foreach (['min', 'max', 'step'] as $bound) {
                    if (isset($def[$bound]) && is_numeric($def[$bound])) {
                        $out[$bound] = $def[$bound] + 0;
                    }
                }
                if (isset($def['unit']) && is_string($def['unit'])) {
                    $out['unit'] = $def['unit'];
                }
                break;
            case 'font_pair':
                $out['options'] = FontPairs::optionValues();
                $out['option_labels'] = [FontPairs::noneValue() => 'Theme default']
                    + FontPairs::optionLabels();
                break;
            case 'color_token':
                $out['options'] = self::COLOR_TOKENS;
                break;
            case 'item_list':
                $out['max'] = self::itemListMax($def);
                break;
        }

        return $out;
    }

    /** Theme Studio B0.8: the design-token names a `color_token` setting may pick. */
    public const COLOR_TOKENS = ['primary', 'background', 'surface', 'text', 'accent'];

    /** Theme Studio B0.8: hard cap on an `item_list` setting (a def may lower it). */
    public const ITEM_LIST_MAX = 24;

    /** Theme Studio B0.8: visible-character budget of a `richtext` setting. */
    public const RICHTEXT_MAX = 2000;

    /**
     * The effective item_list cap for a def: its own `max`, never above ITEM_LIST_MAX.
     *
     * @param  array<string,mixed>  $def
     */
    private static function itemListMax(array $def): int
    {
        $max = isset($def['max']) && is_numeric($def['max']) ? (int) $def['max'] : self::ITEM_LIST_MAX;

        return max(1, min($max, self::ITEM_LIST_MAX));
    }

    /**
     * Theme Studio B0.8: validate one value of a B0.8 setting type. Shared by
     * validate() (flat customizer values) and validateSectionSettings()
     * (layout documents), so both surfaces enforce the same contract.
     *
     * With a store, picker ids and image filenames must name that store's OWN
     * rows; without one only the shape is checked.
     *
     * @param  array<string,mixed>  $def  the setting definition (min/max for range/number, max for item_list)
     * @return string|null the error, or null when valid
     */
    private static function studioValueError(string $type, array $def, mixed $val, ?Store $store): ?string
    {
        switch ($type) {
            case 'range':
            case 'number':
                if (! is_int($val) && ! is_float($val) && ! (is_string($val) && is_numeric($val))) {
                    return 'Must be a number.';
                }
                $num = $val + 0;
                if (isset($def['min']) && is_numeric($def['min']) && $num < $def['min']) {
                    return 'Below minimum.';
                }
                if (isset($def['max']) && is_numeric($def['max']) && $num > $def['max']) {
                    return 'Above maximum.';
                }

                return null;
            case 'textarea':
                if (! is_string($val)) {
                    return 'Must be a string.';
                }

                return mb_strlen($val) > 5000 ? 'Too long.' : null;
            case 'image_picker':
                if (! is_string($val)) {
                    return 'Must be a string.';
                }
                if ($val === '') {
                    return null; // no image chosen
                }
                if (! self::isSafeMediaFilename($val)) {
                    return 'Invalid image.';
                }
                if ($store && ! MediaAsset::where('store_id', $store->id)->where('filename', $val)->exists()) {
                    return 'Image is not in this store\'s media library.';
                }

                return null;
            case 'font_pair':
                return in_array($val, FontPairs::optionValues(), true) ? null : 'Unknown font pair.';
            case 'color_token':
                return in_array($val, self::COLOR_TOKENS, true) ? null : 'Unknown colour token.';
            case 'richtext':
                if (! is_string($val)) {
                    return 'Must be a string.';
                }

                // Only the canonical allow-listed form is ever stored: sanitize
                // first (sanitizeValues / sanitizeSectionSettings), then this passes.
                return RichText::clean($val, self::RICHTEXT_MAX, true, true) === $val
                    ? null : 'Contains formatting that is not allowed.';
            case 'url':
                if (! is_string($val)) {
                    return 'Must be a string.';
                }

                return $val === '' || self::isSafeUrl($val) ? null : 'Must be an http(s) or site-relative link.';
            case 'custom_block':
                // Theme Studio phase A: one of THIS store's custom blocks ('' = none).
                if ($val === '' || $val === null) {
                    return null;
                }
                $id = self::rowId($val);
                if ($id === null) {
                    return 'Invalid selection.';
                }
                if ($store && ! self::storeOwns($type, $store, [$id])) {
                    return 'Not found in this store.';
                }

                return null;
            case 'category':
            case 'branch':
                $id = self::rowId($val);
                if ($id === null) {
                    return 'Invalid selection.';
                }
                if ($store && ! self::storeOwns($type, $store, [$id])) {
                    return 'Not found in this store.';
                }

                return null;
            case 'item_list':
                if (! is_array($val) || ! array_is_list($val)) {
                    return 'Must be a list.';
                }
                if (count($val) > self::itemListMax($def)) {
                    return 'Too many items.';
                }
                $ids = [];
                foreach ($val as $v) {
                    $id = self::rowId($v);
                    if ($id === null) {
                        return 'Invalid selection.';
                    }
                    $ids[] = $id;
                }
                if (count(array_unique($ids)) !== count($ids)) {
                    return 'Duplicate items.';
                }
                if ($store && $ids !== [] && ! self::storeOwns($type, $store, $ids)) {
                    return 'Not found in this store.';
                }

                return null;
        }

        return null;
    }

    /** B0.8 types handled by studioValueError(). */
    private static function isStudioType(string $type): bool
    {
        return in_array($type, [
            'range', 'textarea', 'image_picker', 'font_pair', 'color_token',
            'richtext', 'url', 'category', 'item_list', 'branch', 'custom_block',
        ], true);
    }

    /** A bare stored-media filename: no path, no traversal, no URL. */
    private static function isSafeMediaFilename(string $name): bool
    {
        return strlen($name) <= 191
            && ! str_contains($name, '..')
            && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name);
    }

    /**
     * An http(s) URL or a site-relative path. `javascript:`, `data:`,
     * protocol-relative `//host` and anything with whitespace/quotes/angle
     * brackets are rejected.
     */
    public static function isSafeUrl(string $url): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F<>"\'`\\\\]/', $url)) {
            return false;
        }

        return (bool) preg_match('#^https?://[^/?\#]+#i', $url)
            || (bool) preg_match('#^/(?!/)#', $url);
    }

    /** A positive row id from an int or a digit string, else null. */
    private static function rowId(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_string($v) && preg_match('/^[1-9][0-9]{0,18}$/', $v)) {
            return (int) $v;
        }

        return null;
    }

    /**
     * Do ALL these ids belong to the store?
     *
     * @param  list<int>  $ids
     */
    private static function storeOwns(string $type, Store $store, array $ids): bool
    {
        $ids = array_values(array_unique($ids));
        $query = match ($type) {
            'category' => MenuCategory::query()->where('store_id', $store->id),
            'item_list' => Menu::query()->where('store_id', $store->id),
            'branch' => Branch::query()->where('store_id', $store->id),
            'custom_block' => StoreCustomBlock::query()->where('store_id', $store->id),
            default => null,
        };
        if ($query === null) {
            return false;
        }

        return $query->whereIn('id', $ids)->count() === count($ids);
    }

    /**
     * Theme Studio B0.8: the choices the editor's category / item / branch
     * pickers offer — the store's OWN rows, id + display name. Items are
     * capped so a huge catalogue cannot bloat the page.
     *
     * Theme Studio phase A adds `custom_block`: the store's own custom blocks.
     *
     * @return array{category: list<array{id:int,name:string}>, item_list: list<array{id:int,name:string}>, branch: list<array{id:int,name:string}>, custom_block: list<array{id:int,name:string}>}
     */
    public static function pickerOptions(Store $store, int $itemLimit = 500): array
    {
        $map = static fn ($rows) => $rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) ($r->name ?? ('#'.$r->id))])->values()->all();

        return [
            'category' => $map(MenuCategory::query()->where('store_id', $store->id)
                ->orderBy('position')->orderBy('id')->get(['id', 'name'])),
            'item_list' => $map(Menu::query()->where('store_id', $store->id)
                ->orderBy('position')->orderBy('id')->limit($itemLimit)->get(['id', 'name'])),
            'branch' => $map(Branch::query()->where('store_id', $store->id)
                ->orderBy('position')->orderBy('id')->get(['id', 'name'])),
            'custom_block' => $map(StoreCustomBlock::query()->where('store_id', $store->id)
                ->orderBy('id')->get(['id', 'name'])),
        ];
    }

    /**
     * Theme Studio B0.8: normalize submitted flat customizer values before
     * validation — rich text is rebuilt from the allow-list (never stored as
     * typed) and numeric strings from number/range inputs become numbers.
     *
     * @param  array<string,mixed>  $schema  normalized customizer schema (schemaFor)
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    public static function sanitizeValues(array $schema, array $values): array
    {
        return self::sanitizeByType(
            array_map(fn ($f) => (string) ($f['type'] ?? 'text'), self::fields($schema)),
            $values
        );
    }

    /**
     * Theme Studio B0.8: sanitizeValues() for one section's (or block's)
     * {% schema %} settings — call before validateSectionSettings().
     *
     * @param  array<string,mixed>  $sectionSchema  decoded {% schema %}
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    public static function sanitizeSectionSettings(array $sectionSchema, array $values): array
    {
        $types = [];
        foreach ($sectionSchema['settings'] ?? [] as $def) {
            if (is_array($def) && ! empty($def['id'])) {
                $types[(string) $def['id']] = (string) ($def['type'] ?? 'text');
            }
        }

        return self::sanitizeByType($types, $values);
    }

    /**
     * @param  array<string,string>  $types  id => type
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private static function sanitizeByType(array $types, array $values): array
    {
        foreach ($values as $key => $val) {
            $type = $types[$key] ?? null;
            if ($type === 'richtext' && is_string($val)) {
                $clean = RichText::clean($val, self::RICHTEXT_MAX, true, true);
                if ($clean !== null) {
                    $values[$key] = $clean;
                }
            } elseif (in_array($type, ['range', 'number'], true) && is_string($val) && is_numeric($val)) {
                $values[$key] = $val + 0;
            } elseif ($type === 'url' && is_string($val)) {
                $values[$key] = trim($val);
            }
        }

        return $values;
    }

    /**
     * P2-4: translate the FLAT stored settings (UI shape: `sections.<sid>.<key>`
     * fields + `sections.<sid>` visibility booleans) into the nested per-section
     * map SectionedTemplate consumes. Tolerates an already-nested `sections`
     * array (older records / API writers).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function sectionValues(?array $settings): array
    {
        $settings = $settings ?? [];
        $out = [];

        if (is_array($settings['sections'] ?? null)) {
            foreach ($settings['sections'] as $sid => $vals) {
                if (is_array($vals)) {
                    $out[$sid] = $vals;
                }
            }
        }

        foreach ($settings as $key => $val) {
            if (! str_starts_with((string) $key, 'sections.')) {
                continue;
            }
            $rest = substr($key, strlen('sections.'));
            if ($rest === '_order') {
                if (is_array($val)) {
                    $out['_order'] = array_values(array_map('strval', array_filter($val, 'is_scalar')));
                }

                continue;
            }
            if (str_contains($rest, '.')) {
                [$sid, $setting] = explode('.', $rest, 2);
                $out[$sid][$setting] = $val;
            } elseif ($val === false) {
                $out[$rest]['_disabled'] = true; // visibility toggle off
            }
        }

        return $out;
    }

    /** Flatten schema field definitions to [id => field]. */
    public static function fields(array $schema): array
    {
        $out = [];
        foreach ($schema['settings'] ?? [] as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                if (! empty($field['id'])) {
                    $out[$field['id']] = $field;
                }
            }
        }

        return $out;
    }

    /**
     * Validate submitted values against the schema. Returns [key => error].
     *
     * Theme Studio B0.8: pass the store to also check that picker ids
     * (category / item_list / branch) and image_picker filenames name that
     * store's own rows; without it only the value shape is checked.
     */
    public static function validate(array $schema, array $values, ?Store $store = null): array
    {
        $fields = self::fields($schema);
        $sectionIds = array_column($schema['sections'] ?? [], 'id');
        $errors = [];

        foreach ($values as $key => $val) {
            // WI-L2: `sections._order` is the drag-reordered section order — a list
            // of known section ids. Anything not a known id is rejected, so the
            // order can never name a section that does not exist.
            if ($key === 'sections._order') {
                if (! is_array($val) || array_diff($val, $sectionIds) !== []) {
                    $errors[$key] = 'Invalid section order.';
                }

                continue;
            }
            // `sections.<id>` (one segment) = visibility toggle. P2-4:
            // `sections.<id>.<setting>` (two segments) is a namespaced section
            // FIELD — registered in the fields map, validated below by type.
            if (str_starts_with($key, 'sections.') && ! str_contains(substr($key, strlen('sections.')), '.')) {
                $sid = substr($key, strlen('sections.'));
                if (! in_array($sid, $sectionIds, true)) {
                    $errors[$key] = 'Unknown section.';
                } elseif (! is_bool($val)) {
                    $errors[$key] = 'Section toggle must be boolean.';
                }

                continue;
            }
            if (! isset($fields[$key])) {
                $errors[$key] = 'Unknown setting.';

                continue;
            }
            $type = $fields[$key]['type'] ?? 'text';
            // Theme Studio B0.8 types: null = "clear back to the default".
            if (self::isStudioType($type)) {
                if ($val !== null && ($error = self::studioValueError($type, $fields[$key], $val, $store)) !== null) {
                    $errors[$key] = $error;
                }

                continue;
            }
            switch ($type) {
                case 'color':
                    // Splash v2: an optional colour ('' / null) means "unset — fall
                    // back to the theme/brand colour". Set values must be hex.
                    if ($val === null || $val === '') {
                        break;
                    }
                    if (! is_string($val) || ! preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) {
                        $errors[$key] = 'Invalid colour.';
                    }
                    break;
                case 'toggle':
                    if (! is_bool($val)) {
                        $errors[$key] = 'Must be boolean.';
                    }
                    break;
                case 'select':
                    if (! in_array($val, $fields[$key]['options'] ?? [], true)) {
                        $errors[$key] = 'Invalid option.';
                    }
                    break;
                case 'number':
                    if (! is_numeric($val)) {
                        $errors[$key] = 'Must be a number.';
                    } elseif (isset($fields[$key]['min']) && $val < $fields[$key]['min']) {
                        $errors[$key] = 'Below minimum.';
                    } elseif (isset($fields[$key]['max']) && $val > $fields[$key]['max']) {
                        $errors[$key] = 'Above maximum.';
                    }
                    break;
                case 'text':
                case 'image':
                default:
                    // null = "clear this override back to the theme default"
                    // (ConvertEmptyStringsToNull turns a cleared input into null).
                    // WI-L1: a text field may also carry a {locale: string} map when
                    // the schema is localized — every value in it must be a string.
                    // Splash v2: the splash text fields localize the same way (the
                    // editor already offered them language tabs in localized
                    // schemas; before this the resulting map failed validation).
                    if ($val === null || is_string($val)) {
                        break;
                    }
                    $localizable = str_starts_with($key, 'texts.')
                        || in_array($key, ['splash.title', 'splash.subtitle', 'splash.cta_label'], true);
                    if (($schema['texts']['localized'] ?? false) && $localizable && is_array($val)) {
                        foreach ($val as $lv) {
                            if ($lv !== null && ! is_string($lv)) {
                                $errors[$key] = 'Each localized value must be a string.';
                                break;
                            }
                        }
                        break;
                    }
                    $errors[$key] = 'Must be a string.';
            }
        }

        return $errors;
    }

    /**
     * WI-S1: the value set a preset produces — the preset's values laid over the
     * theme's own defaults, validated against the schema.
     *
     * A preset is a curated starting point ("Midnight", "Daylight"), not a
     * separate storage concept: applying one simply stages these values as a
     * draft the owner keeps editing. Anything the preset does not name falls
     * back to the theme default, so a partial preset (colours only) leaves the
     * texts and toggles alone.
     *
     * @return array<string,mixed>|null null when the preset id is unknown
     */
    public static function presetValues(array $schema, string $presetId): ?array
    {
        foreach ($schema['presets'] ?? [] as $preset) {
            if (($preset['id'] ?? null) !== $presetId) {
                continue;
            }
            $values = array_merge(self::defaults($schema), is_array($preset['values'] ?? null) ? $preset['values'] : []);

            // A preset is authored DATA, held to the same schema contract as any
            // owner input — a typo in a preset can never smuggle an invalid value
            // onto a storefront.
            $errors = self::validate($schema, $values);

            return array_diff_key($values, $errors);
        }

        return null;
    }

    /** Default values from the schema (fields + section toggles). */
    public static function defaults(array $schema): array
    {
        $d = [];
        foreach (self::fields($schema) as $id => $field) {
            if (array_key_exists('default', $field)) {
                $d[$id] = $field['default'];
            }
        }
        foreach ($schema['sections'] ?? [] as $section) {
            $d['sections.'.$section['id']] = $section['default'] ?? true;
        }

        return $d;
    }

    public static function saveDraft(Store $store, string $template, array $values): ThemeSetting
    {
        $schema = self::schemaFor($template);
        // v2: an alias submitted by an old client (or an imported file) is folded
        // onto its canonical id BEFORE validation, so only canonical ids are ever
        // stored and the alias map can be retired without touching the data.
        $values = ThemeKeys::fold($template, $values);
        // Theme Studio B0.8: rich text is rebuilt from the allow-list before it
        // is validated or stored; picker ids must be this store's own rows.
        $values = self::sanitizeValues($schema, $values);
        $errors = self::validate($schema, $values, $store);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        // Read-merge-write under a row lock: two saves (or a save racing a publish) on the same store/template would
        // otherwise each merge onto the row they read and the later write would drop the other's values.
        return DB::transaction(function () use ($store, $template, $schema, $values) {
            $ts = self::lockedSetting($store, $template);
            if (! $ts->exists) {
                $ts->settings = self::defaults($schema);
            }
            // P3-01: a theme with dark support (the kit themes) REFUSES a colour that
            // makes the light or dark scheme unreadable — nothing is saved. Other themes
            // keep the warn-only guard (K4): failures() is [] for them.
            $inForce = array_merge($ts->settings ?? [], $ts->draft ?? []);
            self::refuseUnreadable($template, $inForce, array_merge($inForce, $values));
            $ts->draft = array_merge($ts->draft ?? [], $values);
            $ts->save();

            return $ts;
        });
    }

    /**
     * P3-01: throw (422, per field) when the change from $before to $after introduces a
     * failing contrast pair on a dark-capable theme. Each message names the pair, the
     * scheme and the ratio, so the owner knows what to change.
     *
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     */
    private static function refuseUnreadable(string $template, array $before, array $after): void
    {
        $refusals = ContrastGuard::refusals($template, $before, $after);
        if ($refusals === []) {
            return;
        }
        $messages = [];
        foreach ($refusals as $f) {
            $messages[$f['field']][] = ___(
                $f['scheme'] === 'dark'
                    ? ':pair is too hard to read in the dark scheme (contrast :ratio, at least :need is needed). Nothing was saved. Pick a stronger colour.'
                    : ':pair is too hard to read in the light scheme (contrast :ratio, at least :need is needed). Nothing was saved. Pick a stronger colour.',
                [
                    'pair' => ___((string) $f['label']),
                    'ratio' => $f['ratio'].':1',
                    'need' => rtrim(rtrim(number_format((float) $f['need'], 1), '0'), '.').':1',
                ]
            );
        }

        throw ValidationException::withMessages($messages);
    }

    /** The store's ThemeSetting row for $template, locked for update inside the caller's transaction (or a new one). */
    private static function lockedSetting(Store $store, string $template): ThemeSetting
    {
        $key = ['store_id' => $store->id, 'template' => self::settingsKey($template)];

        return ThemeSetting::query()->where($key)->lockForUpdate()->first() ?? new ThemeSetting($key);
    }

    /**
     * Translate the owner-facing strings in a normalized schema.
     *
     * Group and field labels are DATA in theme.json, written in English. Left
     * raw they sat untranslated in the middle of an otherwise Arabic dashboard.
     * They are localized here rather than inside schemaFor() because that result
     * is memoized per template, and a memo shared across locales would serve one
     * language's labels into another request.
     */
    public static function localizeSchema(array $schema): array
    {
        if ($schema === []) {
            return $schema;
        }

        foreach ($schema['settings'] ?? [] as $gi => $group) {
            $schema['settings'][$gi]['group'] = ___($group['group'] ?? '');
            foreach ($group['fields'] ?? [] as $fi => $field) {
                $schema['settings'][$gi]['fields'][$fi]['label'] = ___($field['label'] ?? '');
                if (! empty($field['help'])) {
                    $schema['settings'][$gi]['fields'][$fi]['help'] = ___($field['help']);
                }
                foreach ($field['option_labels'] ?? [] as $value => $label) {
                    $schema['settings'][$gi]['fields'][$fi]['option_labels'][$value] = ___($label);
                }
                // CC run: card-grid sublabels are owner-facing text — localized
                // like option_labels. `option_previews` are asset PATHS and must
                // never pass through the translator.
                foreach ($field['option_sublabels'] ?? [] as $value => $label) {
                    $schema['settings'][$gi]['fields'][$fi]['option_sublabels'][$value] = ___($label);
                }
            }
        }
        foreach ($schema['sections'] ?? [] as $si => $section) {
            $schema['sections'][$si]['label'] = ___($section['label'] ?? '');
        }
        foreach ($schema['presets'] ?? [] as $pi => $preset) {
            $schema['presets'][$pi]['label'] = ___($preset['label'] ?? '');
            // A thumb is stored as a repo-relative asset path; the editor needs a
            // URL. Absolute URLs (a marketplace theme might carry one) pass through.
            $thumb = (string) ($preset['thumb'] ?? '');
            if ($thumb !== '' && ! preg_match('#^https?://#', $thumb)) {
                $schema['presets'][$pi]['thumb'] = asset($thumb);
            }
        }

        return $schema;
    }

    /**
     * CC run (SPEC §5): the inherit card must answer "inherit WHAT?".
     *
     * When the schema declares `stories.entry`, its `option_sublabels.inherit`
     * is filled with the LIVE platform default — "Currently: <label>" resolved
     * from the `stories_entry_default` Option (code fallback 'ribbon' — the §2
     * resolution chain) and humanised via the field's own option_labels. Called
     * on the page payload AFTER localizeSchema(), so the labels involved are
     * already in the dashboard's language; never computed inside schemaFor()
     * because that result is memoized and the Option is live data.
     *
     * @param  array<string,mixed>  $schema  normalized + localized schema
     * @return array<string,mixed>
     */
    public static function withStoriesInheritSublabel(array $schema): array
    {
        foreach ($schema['settings'] ?? [] as $gi => $group) {
            foreach ($group['fields'] ?? [] as $fi => $field) {
                if (($field['id'] ?? null) !== 'stories.entry') {
                    continue;
                }
                $resolved = PlatformSettings::get('stories_entry_default', false);
                $resolved = is_string($resolved) && $resolved !== '' ? $resolved : 'ribbon';
                $label = $field['option_labels'][$resolved] ?? $resolved;
                $schema['settings'][$gi]['fields'][$fi]['option_sublabels']['inherit'] = ___('Currently').': '.$label;
            }
        }

        return $schema;
    }

    /**
     * Throw the draft away and go back to what is published.
     *
     * The editor needs this to make "Discard" honest — before it existed the
     * only way out of an unwanted draft was to overwrite every field by hand.
     */
    public static function discardDraft(Store $store, string $template): ?ThemeSetting
    {
        $ts = ThemeSetting::where('store_id', $store->id)
            ->where('template', self::settingsKey($template))->first();
        if ($ts) {
            $ts->draft = null;
            $ts->layout_draft = null; // Theme Studio B0.2: the layout edit is part of the draft
            $ts->save();
        }

        return $ts;
    }

    public static function publish(Store $store, string $template): ThemeSetting
    {
        // firstOrNew, not firstOrFail: a store that has never saved a draft still
        // has a publishable state (the schema defaults). The old firstOrFail 404'd,
        // and because the editor showed no error the owner just saw nothing happen.
        // Locked like saveDraft(): a draft saved while this publish runs must not be wiped by `draft = null`.
        return DB::transaction(fn () => self::publishLocked($store, $template));
    }

    private static function publishLocked(Store $store, string $template): ThemeSetting
    {
        $ts = self::lockedSetting($store, $template);
        if (! $ts->exists) {
            $ts->settings = self::defaults(self::schemaFor($template));
        }
        // A null draft value is a "clear override" tombstone: drop the key entirely
        // so theme_setting() falls back to the theme's default.
        $published = array_filter(
            array_merge($ts->settings ?? [], $ts->draft ?? []),
            static fn ($v) => $v !== null
        );
        // P3-01: same refusal as saveDraft, as a backstop for a draft written before it
        // (or by another path): a dark-capable theme never goes live unreadable.
        self::refuseUnreadable($template, $ts->settings ?? [], $published);
        $ts->settings = $published;
        $ts->draft = null;
        // Theme Studio B0.2: a pending layout edit goes live with the settings.
        if ($ts->layout_draft !== null) {
            $ts->layout = $ts->layout_draft;
            $ts->layout_draft = null;
        }
        $ts->save();

        // WI-V1: every publish is snapshotted, so "revert" is a new version rather
        // than a destructive edit and the owner can always get back to a look they
        // shipped before.
        self::snapshotVersion($store, self::settingsKey($template), $ts->settings ?? [], 'publish', $ts->layout);

        return $ts;
    }

    /**
     * WI-V1: append an immutable snapshot of a store's published settings.
     *
     * @param  array<string,mixed>  $settings
     * @param  array<string,mixed>|null  $layout  Theme Studio B0.2 layout document (null = theme default)
     */
    public static function snapshotVersion(Store $store, string $template, array $settings, string $label, ?array $layout = null): ThemeSettingVersion
    {
        return ThemeSettingVersion::create([
            'store_id' => $store->id,
            'template' => $template,
            'settings' => $settings,
            'layout' => $layout,
            'label' => $label,
            'created_by' => auth()->id(),
            'published_at' => now(),
        ]);
    }

    /**
     * WI-V1: revert the store to a past version — as a NEW publish, never by
     * mutating history. The version's settings become the live settings and are
     * themselves snapshotted (labelled `revert`), so the timeline only grows.
     */
    public static function revertToVersion(Store $store, string $template, int $versionId): ?ThemeSetting
    {
        $key = self::settingsKey($template);
        $version = ThemeSettingVersion::where('id', $versionId)
            ->where('store_id', $store->id)
            ->where('template', $key)
            ->first();
        if (! $version) {
            return null;
        }

        $ts = ThemeSetting::firstOrNew(['store_id' => $store->id, 'template' => $key]);
        $ts->settings = is_array($version->settings) ? $version->settings : [];
        $ts->draft = null;
        // A version from before Studio layouts has none → the theme default applies again.
        $ts->layout = is_array($version->layout) ? $version->layout : null;
        $ts->layout_draft = null;
        $ts->save();

        self::snapshotVersion($store, $key, $ts->settings, 'revert', $ts->layout);

        return $ts;
    }

    /**
     * WI-V1: the published history for the editor's History panel — newest first,
     * each row carrying the count of keys that changed from the version before it.
     *
     * @return array<int,array{id:int,label:string,at:?string,changed:int}>
     */
    public static function versionHistory(Store $store, string $template): array
    {
        $rows = ThemeSettingVersion::where('store_id', $store->id)
            ->where('template', self::settingsKey($template))
            ->orderBy('id')
            ->get(['id', 'label', 'settings', 'published_at']);

        $out = [];
        $previous = null;
        foreach ($rows as $row) {
            $settings = is_array($row->settings) ? $row->settings : [];
            $out[] = [
                'id' => (int) $row->id,
                'label' => (string) $row->label,
                'at' => optional($row->published_at)->toIso8601String(),
                'changed' => $previous === null ? 0 : count(self::diffKeys($previous, $settings)),
            ];
            $previous = $settings;
        }

        return array_reverse($out);
    }

    /**
     * WI-V1: the changed keys between a version and the current published state,
     * old→new, for the diff view.
     *
     * @return array<int,array{key:string, from:mixed, to:mixed}>
     */
    public static function versionDiff(Store $store, string $template, int $versionId): array
    {
        $key = self::settingsKey($template);
        $version = ThemeSettingVersion::where('id', $versionId)
            ->where('store_id', $store->id)->where('template', $key)->first();
        if (! $version) {
            return [];
        }
        $current = ThemeSetting::where('store_id', $store->id)->where('template', $key)->value('settings');
        $current = is_array($current) ? $current : [];
        $old = is_array($version->settings) ? $version->settings : [];

        $diff = [];
        foreach (self::diffKeys($old, $current) as $k) {
            $diff[] = ['key' => $k, 'from' => $old[$k] ?? null, 'to' => $current[$k] ?? null];
        }

        return $diff;
    }

    /**
     * WI-V2: filter an imported settings blob down to what this template's schema
     * accepts — dropping unknown keys and schema-invalid values — and report what
     * was dropped so the owner sees exactly what did and didn't carry over.
     *
     * @param  array<string,mixed>  $settings
     * @return array{0:array<string,mixed>,1:array<int,string>} [accepted, dropped keys]
     */
    public static function sanitizeImport(string $template, array $settings): array
    {
        $schema = self::schemaFor($template);
        $settings = ThemeKeys::fold($template, $settings); // a legacy export folds to canonical
        $errors = self::validate($schema, $settings);

        $accepted = [];
        $dropped = [];
        foreach ($settings as $key => $value) {
            if (isset($errors[$key])) {
                $dropped[] = (string) $key;

                continue;
            }
            $accepted[(string) $key] = $value;
        }

        return [$accepted, $dropped];
    }

    /** @return array<int,string> keys whose value differs between the two sets */
    private static function diffKeys(array $a, array $b): array
    {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        $changed = [];
        foreach ($keys as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
                $changed[] = (string) $k;
            }
        }

        return $changed;
    }

    /**
     * P2-3 (OS 2.0): validate merchant values against ONE section's
     * {% schema %} settings list (Shopify-style defs: id/type/default,
     * `options` for select, min/max for range). Returns [key => error];
     * unknown keys are rejected — the schema is the closed contract.
     *
     * Theme Studio B0.8: the richer types (textarea, image_picker, font_pair,
     * color_token, richtext, url, category, item_list, branch) are checked by
     * studioValueError(). Pass the store to check picker ids and media
     * filenames against its own rows; without it only the shape is checked.
     *
     * @param  array  $sectionSchema  decoded {% schema %} (SectionedTemplate::extractSchema)
     * @param  array<string,mixed>  $values
     * @return array<string,string>
     */
    public static function validateSectionSettings(array $sectionSchema, array $values, ?Store $store = null): array
    {
        $defs = [];
        foreach ($sectionSchema['settings'] ?? [] as $def) {
            if (! empty($def['id'])) {
                $defs[$def['id']] = $def;
            }
        }

        $errors = [];
        foreach ($values as $key => $val) {
            if (! isset($defs[$key])) {
                $errors[$key] = 'Unknown setting.';

                continue;
            }
            $type = (string) ($defs[$key]['type'] ?? 'text');
            if ($type !== 'range' && self::isStudioType($type)) {
                if (($error = self::studioValueError($type, $defs[$key], $val, $store)) !== null) {
                    $errors[$key] = $error;
                }

                continue;
            }
            switch ($type) {
                case 'color':
                    if (! is_string($val) || ! preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) {
                        $errors[$key] = 'Invalid colour.';
                    }
                    break;
                case 'checkbox':
                case 'toggle':
                    if (! is_bool($val)) {
                        $errors[$key] = 'Must be boolean.';
                    }
                    break;
                case 'select':
                    $allowed = array_map(
                        fn ($o) => is_array($o) ? ($o['value'] ?? null) : $o,
                        $defs[$key]['options'] ?? []
                    );
                    if (! in_array($val, $allowed, true)) {
                        $errors[$key] = 'Invalid option.';
                    }
                    break;
                case 'range':
                case 'number':
                    if (! is_numeric($val)) {
                        $errors[$key] = 'Must be a number.';
                    } elseif (isset($defs[$key]['min']) && $val < $defs[$key]['min']) {
                        $errors[$key] = 'Below minimum.';
                    } elseif (isset($defs[$key]['max']) && $val > $defs[$key]['max']) {
                        $errors[$key] = 'Above maximum.';
                    }
                    break;
                case 'text':
                default:
                    if (! is_string($val)) {
                        $errors[$key] = 'Must be a string.';
                    }
            }
        }

        return $errors;
    }
}
