<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Services\ThemeCustomizerService;
use Juwri\Modules\Storefront\Services\Theme\KitThemes;
use Juwri\Modules\Storefront\Services\Theme\SchemaV2;
use Juwri\Modules\Storefront\Services\Theme\ThemeKeys;
use Tests\TestCase;

/**
 * Customizer v2 — WI-C0-6: the drift guard.
 *
 * The customizer got confusing because nothing stopped a theme from growing a
 * second control for something it already had, or a schema field nothing read,
 * or a blade key no schema declared. Each of those is a rule here, and each
 * failure message names the theme and the field so the fix is obvious.
 */
class ThemeSchemaLintTest extends TestCase
{
    /** Blade keys that are deliberately not customizer fields (read from elsewhere). */
    private const BLADE_KEY_WHITELIST = [
        'sections._order',   // written by the section reorder UI, not a schema field
    ];

    /**
     * Keys the SHARED layout reads for every template, whether or not that
     * template's own schema offers a control for them. WI-T1 turns these into
     * real design tokens declared once; until then a theme is free to expose
     * them or not.
     */
    private const GLOBAL_LAYOUT_KEYS = [
        'colors.primary',
        'colors.background',
        'fonts.body',
        // P3-01: the theme-tokens partial reads the dark-scheme keys for every
        // template; only themes that declare them (the kit themes) resolve a value.
        'scheme.mode',
        'colors_dark.primary',
        'colors_dark.background',
        'colors_dark.surface',
        'colors_dark.text',
        'colors_dark.accent',
    ];

    /**
     * Fields a PHP helper resolves instead of a blade. The blade scan below only
     * sees `theme_setting(...)` calls inside .blade.php files, so a control read
     * from PHP looks dead (nothing reads it) and, if its id sits under a shared
     * fragment's prefix, looks hand-copied — both false here. `stories.entry` is
     * the per-store Stories entry-point select on folio + pageview; the layouts
     * call `stories_entry_for($store->id)` (Helper.php), which reads
     * theme_setting(..., 'stories.entry') and resolves store → admin default →
     * 'ribbon'. It is a NEW per-theme control, not a copy of the shared `stories`
     * fragment, so it is exempt from the reserved-prefix and dead-control rules
     * only — every other key stays fully guarded.
     */
    private const HELPER_READ_KEYS = [
        'stories.entry',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SchemaV2::flushFragments();
        ThemeKeys::flush();
        // Schemas are memoized per template for the life of the process, so a
        // test that ran earlier could otherwise hand this one a stale copy.
        ThemeCustomizerService::flushSchemaMemo();
    }

    public function test_field_ids_are_unique_within_a_theme(): void
    {
        foreach ($this->schemas() as $theme => $schema) {
            $seen = [];
            foreach ($this->fieldsOf($schema) as $field) {
                $id = $field['id'];
                $this->assertArrayNotHasKey(
                    $id,
                    $seen,
                    "Theme [{$theme}] declares the field id [{$id}] twice — one concept, one id."
                );
                $seen[$id] = true;
            }
        }
    }

    public function test_no_two_fields_in_a_theme_share_a_label(): void
    {
        foreach ($this->schemas() as $theme => $schema) {
            $byLabel = [];
            foreach ($this->fieldsOf($schema) as $field) {
                $label = $this->normalizeLabel($field['label']);
                if ($label === '') {
                    continue;
                }
                if (isset($byLabel[$label])) {
                    $this->fail(
                        "Theme [{$theme}] shows the label \"{$field['label']}\" on two different controls: "
                        ."[{$byLabel[$label]}] and [{$field['id']}]. An owner cannot tell them apart."
                    );
                }
                $byLabel[$label] = $field['id'];
            }
            $this->assertTrue(true);
        }
    }

    public function test_every_theme_key_a_blade_reads_is_declared_or_aliased(): void
    {
        foreach ($this->bladeKeysByTheme() as $theme => $keys) {
            $schema = ThemeCustomizerService::schemaFor($theme);
            if ($schema === []) {
                continue;
            }
            $declared = array_column($this->fieldsOf($schema), 'id');
            foreach ($schema['sections'] ?? [] as $section) {
                $declared[] = 'sections.'.$section['id'];
            }

            foreach ($keys as $key) {
                if (in_array($key, self::BLADE_KEY_WHITELIST, true)
                    || in_array($key, self::GLOBAL_LAYOUT_KEYS, true)) {
                    continue;
                }
                $canonical = ThemeKeys::canonical($theme, $key);
                $this->assertTrue(
                    in_array($key, $declared, true) || in_array($canonical, $declared, true),
                    "Theme [{$theme}] reads theme_setting('{$key}') but no schema field or alias declares it. "
                    .'Add it to the theme.json (or to config/theme-aliases.php if it is a legacy name).'
                );
            }
        }
    }

    /**
     * The customizer's worst habit was offering controls that changed nothing.
     * v2 inherits a known set of them; that set is committed as a baseline and
     * this test enforces two things at once: no NEW dead control may appear,
     * and a baseline entry must be deleted the moment its theme starts reading
     * it. The file only ever shrinks, and it is the debt list for WI-T1..T5.
     */
    public function test_no_new_dead_control_appears_and_the_baseline_only_shrinks(): void
    {
        $baseline = $this->deadControlBaseline();

        foreach ($this->schemas() as $theme => $schema) {
            // P3-00: kit themes are imported as assets only; their Blade views land with
            // P3-05 and later, so until a theme has a view folder it has nothing to read.
            if (KitThemes::isKit($theme)
                && ! is_dir(resource_path('views/store_templates/'.$theme))) {
                continue;
            }
            $read = $this->bladeKeysByTheme()[$theme] ?? [];
            $known = $baseline[$theme] ?? [];

            foreach ($this->fieldsOf($schema) as $field) {
                $id = $field['id'];
                if (str_starts_with($id, 'sections.')
                    || in_array($id, self::GLOBAL_LAYOUT_KEYS, true)
                    || in_array($id, self::HELPER_READ_KEYS, true)) {
                    continue;
                }
                if (in_array($id, $read, true)) {
                    continue;
                }
                $this->assertContains(
                    $id,
                    $known,
                    "Theme [{$theme}] offers the control [{$id}] (\"{$field['label']}\") but no blade reads it — "
                    .'it is a switch that does nothing. Wire it, or drop it from the theme.json.'
                );
            }

            foreach ($known as $id) {
                $this->assertNotContains(
                    $id,
                    $read,
                    "Theme [{$theme}] now reads [{$id}], so it is no longer a dead control. "
                    .'Delete that line from tests/Fixtures/theme-dead-controls.json.'
                );
            }
        }
    }

    /** @return array<string,array<int,string>> */
    private function deadControlBaseline(): array
    {
        $path = base_path('tests/Fixtures/theme-dead-controls.json');
        $this->assertFileExists($path, 'the dead-control baseline is part of the contract');
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'the dead-control baseline must be a JSON object');

        return $decoded['themes'] ?? [];
    }

    public function test_shared_blocks_are_referenced_as_fragments_not_copied(): void
    {
        foreach ($this->rawSchemas() as $theme => $raw) {
            foreach ($raw['settings'] ?? [] as $group) {
                foreach ($group['fields'] ?? [] as $field) {
                    if (! is_array($field) || empty($field['id'])) {
                        continue;
                    }
                    if (in_array((string) $field['id'], self::HELPER_READ_KEYS, true)) {
                        continue; // read by a PHP helper, not a shared-fragment copy
                    }
                    foreach (['splash.', 'search.', 'stories.'] as $prefix) {
                        $this->assertStringStartsNotWith(
                            $prefix,
                            (string) $field['id'],
                            "Theme [{$theme}] hand-copies [{$field['id']}] instead of including the shared "
                            ."'".rtrim($prefix, '.')."' fragment."
                        );
                    }
                }
            }
        }
    }

    public function test_every_include_names_a_fragment_that_exists(): void
    {
        foreach ($this->rawSchemas() as $theme => $raw) {
            foreach ($raw['settings'] ?? [] as $group) {
                if (empty($group['include'])) {
                    continue;
                }
                $this->assertFileExists(
                    SchemaV2::fragmentPath((string) $group['include']),
                    "Theme [{$theme}] includes the unknown fragment [{$group['include']}]."
                );
            }
        }
    }

    public function test_no_legacy_key_survives_in_a_schema_or_a_blade(): void
    {
        $legacy = array_keys(config('theme-aliases')['*'] ?? []);

        foreach ($this->schemas() as $theme => $schema) {
            foreach ($this->fieldsOf($schema) as $field) {
                $this->assertNotContains(
                    $field['id'],
                    $legacy,
                    "Theme [{$theme}] still declares the legacy field [{$field['id']}] — it has a canonical id now."
                );
            }
        }

        foreach ($this->bladeKeysByTheme() as $theme => $keys) {
            foreach ($keys as $key) {
                $this->assertNotContains(
                    $key,
                    $legacy,
                    "Theme [{$theme}] still reads the legacy key [{$key}] — sweep it to its canonical id."
                );
            }
        }
    }

    /** @return array<string,array> normalized schema per theme */
    private function schemas(): array
    {
        $out = [];
        foreach ($this->rawSchemas() as $theme => $raw) {
            $out[$theme] = ThemeCustomizerService::schemaFor($theme);
        }

        return $out;
    }

    /** @return array<string,array> theme.json exactly as it sits on disk */
    private function rawSchemas(): array
    {
        $out = [];
        foreach (glob(base_path('../assets/store_templates/*/theme.json')) as $path) {
            $theme = basename(dirname($path));
            $out[$theme] = json_decode((string) file_get_contents($path), true) ?: [];
        }
        $this->assertNotEmpty($out, 'no theme schemas found');

        return $out;
    }

    /** @return array<int,array{id:string,label:string}> */
    private function fieldsOf(array $schema): array
    {
        $out = [];
        foreach ($schema['settings'] ?? [] as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $out[] = ['id' => (string) $field['id'], 'label' => (string) ($field['label'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * Every `theme_setting($x, 'key')` a theme's own blades read, plus the shared
     * partials (which run for every theme).
     *
     * @return array<string,array<int,string>>
     */
    private function bladeKeysByTheme(): array
    {
        $root = resource_path('views/store_templates');
        $shared = array_merge(
            $this->keysIn($root.'/layout.blade.php'),
            $this->keysInDir($root.'/_partials')
        );

        $out = [];
        foreach (glob(base_path('../assets/store_templates/*/theme.json')) as $path) {
            $theme = basename(dirname($path));
            $dir = $root.'/'.$theme;
            $keys = array_merge($shared, $this->keysInDir($dir));
            // P3-05: a kit theme's views sit in sub folders (sections/, components/), share the kit folder, and read the
            // settings through the view model (`$vm['theme']['settings']['key']`) — THEME-CONTRACT section 4
            if (KitThemes::isKit($theme) && is_dir($dir)) {
                $keys = array_merge($keys, $this->keysInTree($root.'/_kit'), $this->keysInTree($dir));
            }
            $out[$theme] = array_values(array_unique($keys));
        }

        return $out;
    }

    /** @return array<int,string> */
    private function keysInTree(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $keys = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with((string) $file, '.blade.php')) {
                $keys = array_merge($keys, $this->keysIn((string) $file));
            }
        }

        return $keys;
    }

    /** @return array<int,string> */
    private function keysInDir(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $keys = [];
        foreach (glob($dir.'/*.blade.php') as $file) {
            $keys = array_merge($keys, $this->keysIn($file));
        }

        return $keys;
    }

    /** @return array<int,string> */
    private function keysIn(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }
        // Blade comments document the keys a partial reads (`theme_setting(..., 'splash.*')`),
        // which is prose, not a read — strip them before matching.
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file)) ?? '';

        preg_match_all("/theme_setting\(\s*[^,]+,\s*'([^']+)'/", $source, $viaHelper);
        // The shared layout reads the whole settings row once and indexes into it
        // (`$__cv['colors.primary']`) rather than calling the helper per key.
        preg_match_all("/\\\$__cv\[\s*'([^']+)'\s*\]/", $source, $viaBulk);

        // kit themes: the settings arrive inside the view model
        preg_match_all("/\['settings'\]\[\s*'([^']+)'\s*\]/", $source, $viaVm);

        // the view model hands the customised texts over without their `texts.` prefix
        preg_match_all("/\['texts'\]\[\s*'([^']+)'\s*\]/", $source, $viaTexts);

        return array_merge($viaHelper[1] ?? [], $viaBulk[1] ?? [], $viaVm[1] ?? [], array_map(fn ($k) => 'texts.'.$k, $viaTexts[1] ?? []));
    }

    private function normalizeLabel(string $label): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($label)) ?? '');
    }
}
