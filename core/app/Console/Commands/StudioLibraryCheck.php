<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\Drops\BlockDrop;
use Juwri\Modules\Storefront\Storefront\Drops\FeaturesDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SectionDrop;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;

/**
 * Theme Studio B3: the section library's own quality gate. Renders every
 * library section in STRICT upload mode (unknown variable / filter = error)
 * against the demo storefront in English and Arabic, with every `features`
 * switch on and then off, plus a brand-new empty store (no hours, no social,
 * no banners, no menu, ordering off), and (B1c) against every vertical demo
 * store with its own features. Also runs the theme linter over the
 * library as if it were a package pinned to it. Exits non-zero on any error.
 *
 * The globals are built exactly like MarketplaceController@preview does
 * (ThemeContext::build + ThemeContext/CommerceRuntime registers + the
 * library's locale map), so a section that passes here renders in preview.
 */
class StudioLibraryCheck extends Command
{
    protected $signature = 'studio:library-check {--library=1 : Section library version}';

    protected $description = 'Render + lint every Theme Studio library section (strict upload mode, en/ar, features on/off, empty store)';

    public function handle(): int
    {
        $library = SectionLibrary::forVersion((string) $this->option('library'));
        if (! $library) {
            $this->error('Unknown section library version: '.$this->option('library'));

            return self::FAILURE;
        }

        $types = $library->sectionTypes();
        if ($types === []) {
            $this->error('Library '.$library->version.' ships no sections.');

            return self::FAILURE;
        }

        $failures = [];
        $renders = 0;
        $originalLocale = app()->getLocale();

        try {
            foreach ($this->scenarios() as $label => [$lang, $features, $dataset]) {
                $vars = $dataset === 'empty' ? $this->emptyStoreVars() : DemoStorefront::vars($dataset);
                $vars['settings'] = [];
                // null = the dataset's own switches (a vertical store's real capabilities).
                if ($features !== null) {
                    $vars['extras']['features'] = $this->features($features);
                }

                $language = $vars['menu_languages']->firstWhere('code', $lang) ?? $vars['default_menu_language'];
                $vars['default_menu_language'] = $language;
                app()->setLocale($lang);

                $themeContext = app(ThemeContext::class);
                $globals = $themeContext->build($vars);
                $registers = $themeContext->registers($vars)
                    + $this->commerceRegisters($vars)
                    + ['theme_asset_map' => [], 'theme_locale_map' => $library->locales($lang)];

                foreach ($types as $type) {
                    foreach ($this->blockVariants($library, $type) as $blocksLabel => $blocks) {
                        $renders++;
                        $where = "{$type} [{$label}{$blocksLabel}]";
                        try {
                            $html = $this->renderSection($library, $type, $globals, $registers, $blocks);
                        } catch (\Throwable $e) {
                            $cause = $e->getPrevious() ? ' ('.$e->getPrevious()->getMessage().')' : '';
                            $failures[] = "{$where}: ".$e->getMessage().$cause;

                            continue;
                        }
                        if (trim($html) !== '' && ! str_contains($html, 'mbs-'.$type)) {
                            $failures[] = "{$where}: output has no .mbs-{$type} root";
                        }
                    }
                }
            }
        } finally {
            app()->setLocale($originalLocale);
        }

        $lint = $this->lint($library, $types);
        foreach ($lint['errors'] as $err) {
            $failures[] = "lint {$err['rule']} {$err['file']}: {$err['message']}";
        }
        foreach ($lint['warnings'] as $warn) {
            $this->line("  <comment>warning</comment> {$warn['rule']} {$warn['file']}: {$warn['message']}");
        }

        // Every CSS source must stay scoped to its own `.mbs-<name>` root
        // (ThemeLinter::lintLibraryCss — the same rule the marketplace linter
        // applies), so one section can never restyle another.
        foreach (['sections', 'snippets'] as $kind) {
            foreach (glob(base_path('../tools/liquid-library/src/'.$kind.'/*.css')) ?: [] as $file) {
                $name = basename($file, '.css');
                $cssLint = ThemeLinter::lintLibraryCss($name, (string) file_get_contents($file));
                foreach ($cssLint['errors'] as $err) {
                    $failures[] = "css {$kind}/{$name}.css: ".$err['message'];
                }
                foreach ($cssLint['warnings'] as $warn) {
                    $this->line("  <comment>warning</comment> css {$kind}/{$name}.css: ".$warn['message']);
                }
            }
        }

        $css = public_path('assets/liquid-library/'.$library->version.'/library.css');
        if (! is_file($css)) {
            $failures[] = "library.css missing at assets/liquid-library/{$library->version}/library.css (run tools/liquid-library/build.mjs)";
        }

        $this->info(sprintf(
            'Library %s: %d sections, %d snippets, %d renders.',
            $library->version, count($types), count($library->snippets()), $renders
        ));

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error('  '.$failure);
            }
            $this->error(count($failures).' failure(s).');

            return self::FAILURE;
        }

        $this->info('All library sections render cleanly.');

        return self::SUCCESS;
    }

    /**
     * label => [lang, features on/off (null = the dataset's own), dataset]
     * where dataset is a DemoStorefront vertical or 'empty' (brand-new store).
     * Theme Studio B1c: every section also renders against each vertical demo
     * store (retail, pharmacy, laundry, hotel, car parts) in en + ar with that
     * vertical's real feature switches — a section not meant for a vertical
     * must still render cleanly there (hidden/empty is fine).
     *
     * @return array<string,array{0:string,1:bool|null,2:string}>
     */
    protected function scenarios(): array
    {
        $scenarios = [
            'en features-on' => ['en', true, 'restaurant'],
            'en features-off' => ['en', false, 'restaurant'],
            'ar features-on' => ['ar', true, 'restaurant'],
            'ar features-off' => ['ar', false, 'restaurant'],
            'en empty-store' => ['en', false, 'empty'],
            'ar empty-store' => ['ar', true, 'empty'],
        ];
        foreach (DemoStorefront::VERTICALS as $vertical) {
            if ($vertical === 'restaurant') {
                continue;
            }
            foreach (['en', 'ar'] as $lang) {
                $scenarios["{$lang} {$vertical}"] = [$lang, null, $vertical];
            }
        }

        return $scenarios;
    }

    /** @return array<string,bool> every FeaturesDrop switch set to $on */
    protected function features(bool $on): array
    {
        $out = [];
        foreach (new \ReflectionClass(FeaturesDrop::class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === FeaturesDrop::class
                && ! $method->isStatic() && ! $method->isConstructor()) {
                $out[$method->getName()] = $on;
            }
        }

        return $out;
    }

    /**
     * A section with blocks is rendered twice: with none, and with its first
     * preset's blocks (so every block type is exercised).
     *
     * @return array<string,list<BlockDrop>>
     */
    protected function blockVariants(SectionLibrary $library, string $type): array
    {
        $schema = SectionedTemplate::extractSchema((string) $library->sectionSource($type));
        $variants = ['' => []];
        $preset = $schema['presets'][0]['blocks'] ?? null;
        if (! is_array($preset) || $preset === []) {
            return $variants;
        }

        $defaults = [];
        foreach ($schema['blocks'] ?? [] as $def) {
            foreach ($def['settings'] ?? [] as $setting) {
                if (! empty($def['type']) && ! empty($setting['id']) && array_key_exists('default', $setting)) {
                    $defaults[$def['type']][$setting['id']] = $setting['default'];
                }
            }
        }

        $blocks = [];
        foreach (array_values($preset) as $i => $block) {
            $blockType = (string) ($block['type'] ?? '');
            $blocks[] = new BlockDrop(
                'b'.$i,
                $blockType,
                array_merge($defaults[$blockType] ?? [], is_array($block['settings'] ?? null) ? $block['settings'] : []),
            );
        }
        $variants[' +blocks'] = $blocks;

        return $variants;
    }

    /**
     * @param  array<string,mixed>  $globals
     * @param  array<string,mixed>  $registers
     * @param  list<BlockDrop>  $blocks
     */
    protected function renderSection(SectionLibrary $library, string $type, array $globals, array $registers, array $blocks): string
    {
        $source = (string) $library->sectionSource($type);
        $schema = SectionedTemplate::extractSchema($source);
        if (empty($schema['name'])) {
            throw new \RuntimeException('{% schema %} is missing or has no name');
        }

        $data = $globals;
        $data['section'] = new SectionDrop('check-'.$type, $type, SectionedTemplate::schemaDefaults($schema), $blocks);

        return app(LiquidRenderer::class)->render($source, $data, 'upload', $registers, $library->snippets());
    }

    /**
     * Commerce registers; the config needs a session/URL context the CLI may lack.
     *
     * @param  array<string,mixed>  $vars
     * @return array<string,mixed>
     */
    protected function commerceRegisters(array $vars): array
    {
        try {
            return app(CommerceRuntime::class)->registers($vars);
        } catch (\Throwable $e) {
            $this->line('  <comment>note</comment> commerce registers unavailable: '.$e->getMessage());

            return [];
        }
    }

    /**
     * The library linted as if it were a package pinned to it.
     *
     * @param  list<string>  $types
     * @return array{errors: list<array{rule:string,file:string,message:string}>, warnings: list<array{rule:string,file:string,message:string}>}
     */
    protected function lint(SectionLibrary $library, array $types): array
    {
        $files = ['theme.json' => (string) json_encode(['name' => 'library-check', 'slug' => 'library-check', 'version' => '1.0.0', 'library' => $library->version])];
        foreach ($types as $type) {
            $files['sections/'.$type.'.liquid'] = (string) $library->sectionSource($type);
        }
        foreach ($library->snippets() as $name => $source) {
            $files['snippets/'.$name.'.liquid'] = $source;
        }

        $result = app(ThemeLinter::class)->lintFiles($files);

        return ['errors' => $result['errors'], 'warnings' => $result['warnings']];
    }

    /**
     * A brand-new store: nothing configured, ordering off.
     *
     * @return array<string,mixed>
     */
    protected function emptyStoreVars(): array
    {
        $demo = DemoStorefront::vars();

        $store = new Store(['title' => 'New Store', 'slug' => 'new-store']);
        $store->id = 0;

        return [
            'store' => $store,
            'postOptions' => (object) [],
            'total_menus' => [],
            'allergies' => collect(),
            'menuBanners' => collect(),
            'branches' => collect(),
            'activeBranch' => null,
            'table' => null,
            'customer' => null,
            'menu_languages' => $demo['menu_languages'],
            'default_menu_language' => $demo['default_menu_language'],
            'scans_today' => 0,
            'flags' => ['allow_order' => false],
            'extras' => ['hours' => [], 'stories' => [], 'social' => [], 'announcement' => null, 'features' => []],
        ];
    }
}
