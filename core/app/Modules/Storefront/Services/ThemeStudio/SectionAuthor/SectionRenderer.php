<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor;

use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\Drops\BlockDrop;
use Juwri\Modules\Storefront\Storefront\Drops\SectionDrop;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\ThemeContext;

/**
 * Theme Studio phase E1: renders an AI-written section exactly like
 * `studio:library-check` renders a library section — STRICT upload mode
 * (unknown variable / filter = error), globals from ThemeContext::build over
 * DemoStorefront::vars(<vertical>), the library's snippets + locales (plus
 * the draft's own strings), schema defaults, and once more with the first
 * preset's blocks. Scenarios: every targeted vertical × {en, ar} with that
 * vertical's real feature switches, the restaurant store with every feature
 * on and off, and a brand-new empty store.
 */
class SectionRenderer
{
    /** Section-level render budget (well inside the engine's 5 s / 1 MB page budget). */
    public const MAX_RENDER_MS = 1500;

    public const MAX_OUTPUT_BYTES = 200_000;

    public function __construct(protected SectionLibrary $library) {}

    /**
     * @param  list<string>  $verticals  DemoStorefront verticals to render against
     * @return list<array{label:string,vertical:string,lang:string,blocks:bool,ok:bool,ms:int,bytes:int,empty:bool,error:?string,html:string}>
     */
    public function renderAll(Candidate $candidate, array $verticals): array
    {
        $results = [];
        $original = app()->getLocale();
        $source = $candidate->source();
        $schema = SectionedTemplate::extractSchema($source);

        try {
            foreach ($this->scenarios($verticals) as $label => [$lang, $features, $dataset]) {
                [$globals, $registers] = $this->context($candidate, $lang, $features, $dataset);
                foreach ($this->blockVariants($schema) as $withBlocks => $blocks) {
                    $results[] = $this->renderOne($candidate, $source, $schema, $globals, $registers, $blocks)
                        + ['label' => $label.($withBlocks ? ' +blocks' : ''), 'vertical' => $dataset, 'lang' => $lang, 'blocks' => (bool) $withBlocks];
                }
            }
        } finally {
            app()->setLocale($original);
        }

        return $results;
    }

    /**
     * One render in a vertical + language (the default preset's blocks when
     * the section has any) — the screenshot source.
     */
    public function renderFor(Candidate $candidate, string $vertical, string $lang): string
    {
        $original = app()->getLocale();
        try {
            $source = $candidate->source();
            $schema = SectionedTemplate::extractSchema($source);
            [$globals, $registers] = $this->context($candidate, $lang, null, $vertical);
            $variants = $this->blockVariants($schema);
            $blocks = end($variants) ?: [];

            return $this->renderOne($candidate, $source, $schema, $globals, $registers, $blocks)['html'];
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * A standalone page for the browser gate / screenshots: the library
     * stylesheet (file URL) + the draft CSS + the section markup.
     */
    public function page(Candidate $candidate, string $html, string $lang): string
    {
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        $libraryCss = public_path('assets/liquid-library/'.$this->library->version.'/library.css');
        $link = is_file($libraryCss) ? '<link rel="stylesheet" href="file://'.htmlspecialchars($libraryCss, ENT_QUOTES).'">' : '';

        return '<!doctype html><html lang="'.$lang.'" dir="'.$dir.'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"><title>'.htmlspecialchars($candidate->type).'</title>'
            .$link
            .'<style>html,body{margin:0;background:#fff;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}'
            .':root{--tc-primary:#1f6feb;--tc-on-primary:#fff;--tc-text:#141414;--tc-text-rgb:20,20,20;--tc-text-muted:#5b5b5b;--tc-background:#fff;--tc-surface:#f6f6f6}</style>'
            .'<style>'.str_replace('</', '<\/', $candidate->css).'</style>'
            .'</head><body><main data-ai-section-root>'.$html.'</main></body></html>';
    }

    /**
     * label => [lang, features on/off (null = dataset's own), dataset].
     *
     * @param  list<string>  $verticals
     * @return array<string,array{0:string,1:bool|null,2:string}>
     */
    public function scenarios(array $verticals): array
    {
        $scenarios = [];
        foreach ($verticals as $vertical) {
            foreach (['en', 'ar'] as $lang) {
                $scenarios["{$lang} {$vertical}"] = [$lang, null, $vertical];
            }
        }
        foreach (['en', 'ar'] as $lang) {
            $scenarios["{$lang} features-on"] = [$lang, true, 'restaurant'];
            $scenarios["{$lang} features-off"] = [$lang, false, 'restaurant'];
            $scenarios["{$lang} empty-store"] = [$lang, false, 'empty'];
        }

        return $scenarios;
    }

    /**
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $globals
     * @param  array<string,mixed>  $registers
     * @param  list<BlockDrop>  $blocks
     * @return array{ok:bool,ms:int,bytes:int,empty:bool,error:?string,html:string}
     */
    protected function renderOne(Candidate $candidate, string $source, array $schema, array $globals, array $registers, array $blocks): array
    {
        $data = $globals;
        $data['section'] = new SectionDrop('ai-'.$candidate->type, $candidate->type, SectionedTemplate::schemaDefaults($schema), $blocks);

        $start = hrtime(true);
        try {
            $html = app(LiquidRenderer::class)->render($source, $data, 'upload', $registers, $this->library->snippets());
        } catch (\Throwable $e) {
            $cause = $e->getPrevious() ? ' ('.$e->getPrevious()->getMessage().')' : '';

            return ['ok' => false, 'ms' => (int) ((hrtime(true) - $start) / 1e6), 'bytes' => 0, 'empty' => true,
                'error' => mb_substr($e->getMessage().$cause, 0, 400), 'html' => ''];
        }
        $ms = (int) ((hrtime(true) - $start) / 1e6);

        return ['ok' => true, 'ms' => $ms, 'bytes' => strlen($html), 'empty' => trim($html) === '', 'error' => null, 'html' => $html];
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    protected function context(Candidate $candidate, string $lang, ?bool $features, string $dataset): array
    {
        $helper = self::libraryCheck();
        $vars = $dataset === 'empty' ? $helper->emptyVars() : DemoStorefront::vars($dataset);
        $vars['settings'] = [];
        if ($features !== null) {
            $vars['extras']['features'] = $helper->allFeatures($features);
        }
        $language = $vars['menu_languages']->firstWhere('code', $lang) ?? $vars['default_menu_language'];
        $vars['default_menu_language'] = $language;
        app()->setLocale($lang);

        $themeContext = app(ThemeContext::class);
        $globals = $themeContext->build($vars);
        try {
            $commerce = app(CommerceRuntime::class)->registers($vars);
        } catch (\Throwable) {
            $commerce = [];
        }
        $registers = $themeContext->registers($vars) + $commerce + [
            'theme_asset_map' => [],
            'theme_locale_map' => array_merge($this->library->locales($lang), $candidate->locales[$lang] ?? []),
        ];

        return [$globals, $registers];
    }

    /**
     * No blocks, and (when the schema has a preset with blocks) the first
     * preset's blocks with their defaults — studio:library-check's rule.
     *
     * @param  array<string,mixed>  $schema
     * @return array<int,list<BlockDrop>> 0 => none, 1 => preset blocks
     */
    protected function blockVariants(array $schema): array
    {
        $variants = [0 => []];
        $preset = $schema['presets'][0]['blocks'] ?? null;
        if (! is_array($preset) || $preset === []) {
            return $variants;
        }
        $defaults = [];
        foreach ((array) ($schema['blocks'] ?? []) as $def) {
            foreach ((array) ($def['settings'] ?? []) as $setting) {
                if (! empty($def['type']) && ! empty($setting['id']) && array_key_exists('default', $setting)) {
                    $defaults[$def['type']][$setting['id']] = $setting['default'];
                }
            }
        }
        $blocks = [];
        foreach (array_values($preset) as $i => $block) {
            $blockType = (string) ($block['type'] ?? '');
            $blocks[] = new BlockDrop('b'.$i, $blockType, array_merge(
                $defaults[$blockType] ?? [],
                is_array($block['settings'] ?? null) ? $block['settings'] : [],
            ));
        }
        $variants[1] = $blocks;

        return $variants;
    }

    protected static function libraryCheck(): LibraryCheckAccess
    {
        return new LibraryCheckAccess;
    }
}
