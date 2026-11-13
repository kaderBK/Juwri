<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor;

use App\Services\Verticals\VerticalRegistry;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;
use Juwri\Modules\Storefront\Storefront\ThemePackageValidator;

/**
 * Theme Studio phase E1: the automatic gates an AI-written section must pass
 * (spec §6 E1 step 3). ALL must pass; the report is fed back to the model for
 * a repair round and stored with every attempt.
 *
 *  shape     answer shape; type is a new slug (not already in the library)
 *  markup    ThemePackageValidator::markupIssues (script / on* / javascript: /
 *            iframe) + no <style>/<link>/<object>/<embed>/<base>/<meta>/srcdoc
 *  lint      ThemeLinter::lintFiles (strict parse, filter whitelist,
 *            snippets, external URLs, schema) with the library's quality
 *            rules as ERRORS: unescaped-setting-output, rtl-physical-prop,
 *            section-css-unscoped, img-missing-dimensions, price-missing-money,
 *            item-deep-link-missing, cart-add-missing
 *  contract  the library's "Every section MUST": .mbs-<type> root with an
 *            accessible name, no hard-coded visible text (| t / vocab),
 *            every | t key translated in en + ar, order CTAs only under
 *            flags.allow_order, no {% commerce %}, img alt, button type
 *  css       CssGate (budget, scope, logical props, tokens, sanitiser)
 *  render    strict upload-mode render × every targeted vertical demo ×
 *            {en, ar} (+ features on/off, empty store, preset blocks) within
 *            the section render budget; output re-scanned for markup
 *  browser   BrowserGate — optional (skipped with a reason when puppeteer /
 *            node is missing or it's disabled): 360px overflow, CLS, a11y,
 *            en/ar × mobile/desktop screenshots
 */
class SectionGates
{
    /** Linter warnings that are fatal for a library section. */
    public const FATAL_LINT_RULES = [
        'unescaped-setting-output', 'rtl-physical-prop', 'section-css-unscoped', 'img-missing-dimensions',
        'price-missing-money', 'item-deep-link-missing', 'cart-add-missing',
    ];

    public function __construct(
        protected SectionLibrary $library,
        protected ?BrowserGate $browser = null,
    ) {}

    /**
     * @param  array<mixed>  $answer  the raw model answer
     * @param  list<string>  $verticals  targeted verticals (registry types, demo datasets or 'all')
     * @param  string|null  $shotsDir  where the browser gate writes screenshots (null = no browser gate)
     * @return array{passed:bool,type:?string,gates:list<array{id:string,label:string,status:string,errors:list<string>,warnings:list<string>,meta:array<string,mixed>}>,screenshots:array<string,string>}
     */
    public function run(array $answer, array $verticals, ?string $shotsDir = null): array
    {
        [$candidate, $shapeProblems] = Candidate::fromAnswer($answer);
        if ($candidate && $this->library->sectionSource($candidate->type) !== null) {
            $shapeProblems[] = "type \"{$candidate->type}\" already exists in section library {$this->library->version} — choose a new, specific name.";
        }

        $gates = [$this->gate('shape', 'Answer shape', $shapeProblems)];
        if (! $candidate) {
            foreach (['markup' => 'Markup safety', 'lint' => 'Theme linter (strict)', 'contract' => 'Library contract', 'css' => 'Scoped CSS', 'render' => 'Render (demo stores × en/ar)', 'browser' => 'Browser (360px, a11y, screenshots)'] as $id => $label) {
                $gates[] = $this->gate($id, $label, [], [], [], 'skipped: the answer could not be read');
            }

            return ['passed' => false, 'type' => null, 'gates' => $gates, 'screenshots' => []];
        }

        $gates[] = $this->markup($candidate);
        $gates[] = $this->lint($candidate);
        $gates[] = $this->contract($candidate);
        $css = (new CssGate)->check($candidate->type, $candidate->css);
        $gates[] = $this->gate('css', 'Scoped CSS', $css['errors'], $css['warnings'], ['bytes' => $css['bytes'], 'budget' => Contract::CSS_MAX_BYTES]);
        $datasets = self::datasets($verticals);
        $gates[] = $this->render($candidate, $datasets);

        $screenshots = [];
        $blocking = array_filter($gates, fn (array $g) => $g['status'] === 'fail');
        if ($shotsDir === null) {
            $gates[] = $this->gate('browser', 'Browser (360px, a11y, screenshots)', [], [], [], 'skipped: not requested');
        } elseif ($blocking !== []) {
            $gates[] = $this->gate('browser', 'Browser (360px, a11y, screenshots)', [], [], [], 'skipped: earlier gates failed');
        } else {
            $browser = $this->browserGate($candidate, $datasets[0] ?? 'restaurant', $shotsDir);
            $screenshots = $browser['screenshots'];
            $gates[] = $this->gate('browser', 'Browser (360px, a11y, screenshots)', $browser['errors'], $browser['warnings'],
                ['axe' => $browser['axe'], 'pages' => $browser['pages']], $browser['skipped'] !== null ? 'skipped: '.$browser['skipped'] : null);
        }

        $passed = array_filter($gates, fn (array $g) => $g['status'] === 'fail') === [];

        return ['passed' => $passed, 'type' => $candidate->type, 'gates' => $gates, 'screenshots' => $screenshots];
    }

    /**
     * Targeted verticals → DemoStorefront datasets (registry group mapping:
     * food → restaurant, goods → retail, services → laundry, stays → hotel;
     * `all` or nothing → every dataset).
     *
     * @param  list<string>  $verticals
     * @return list<string>
     */
    public static function datasets(array $verticals): array
    {
        $out = [];
        $registry = app(VerticalRegistry::class);
        foreach ($verticals as $v) {
            $v = strtolower(trim((string) $v));
            if ($v === 'all') {
                return DemoStorefront::VERTICALS;
            }
            if (in_array($v, DemoStorefront::VERTICALS, true)) {
                $out[] = $v;

                continue;
            }
            $group = $v === 'food' || $v === 'goods' || $v === 'services' || $v === 'stays'
                ? $v
                : ($registry->exists($v) ? (string) ($registry->config($v)['group'] ?? '') : '');
            $mapped = match ($group) {
                'food' => 'restaurant', 'goods' => 'retail', 'services' => 'laundry', 'stays' => 'hotel', default => null,
            };
            if ($mapped) {
                $out[] = $mapped;
            }
        }
        $out = array_values(array_unique($out));

        return $out === [] ? DemoStorefront::VERTICALS : $out;
    }

    /** @return array{id:string,label:string,status:string,errors:list<string>,warnings:list<string>,meta:array<string,mixed>} */
    protected function markup(Candidate $c): array
    {
        $errors = ThemePackageValidator::markupIssues("sections/{$c->type}.liquid", $c->liquid);
        array_push($errors, ...ThemePackageValidator::markupIssues("sections/{$c->type}.css", $c->css));
        $extra = [
            '/<\s*style\b/i' => 'a <style> tag (section CSS goes in "css")',
            '/<\s*link\b/i' => 'a <link> tag',
            '/<\s*(object|embed|applet|base|meta)\b/i' => 'an <object>/<embed>/<base>/<meta> tag',
            '/\ssrcdoc\s*=/i' => 'a srcdoc attribute',
            '/<\s*form\b[^>]*\saction\s*=/i' => 'a <form action> (forms post through the runtime)',
            '/\b(data|vbscript)\s*:\s*text\/html/i' => 'a data:text/html URL',
        ];
        foreach ($extra as $pattern => $label) {
            if (preg_match($pattern, $c->liquid)) {
                $errors[] = "sections/{$c->type}.liquid contains {$label} — not allowed.";
            }
        }

        return $this->gate('markup', 'Markup safety', $errors);
    }

    /** @return array{id:string,label:string,status:string,errors:list<string>,warnings:list<string>,meta:array<string,mixed>} */
    protected function lint(Candidate $c): array
    {
        $files = [
            'theme.json' => (string) json_encode(['name' => 'ai-section', 'slug' => 'ai-section', 'version' => '1.0.0', 'library' => $this->library->version]),
            "sections/{$c->type}.liquid" => $c->source(),
            "sections/{$c->type}.css" => $c->css,
        ];
        $report = app(ThemeLinter::class)->lintFiles($files);

        $errors = [];
        $warnings = [];
        foreach ($report['errors'] as $e) {
            $errors[] = "{$e['rule']}: {$e['message']}";
        }
        foreach ($report['warnings'] as $w) {
            if (in_array($w['rule'], self::FATAL_LINT_RULES, true)) {
                $errors[] = "{$w['rule']}: {$w['message']}";
            } elseif ($w['rule'] !== 'commerce-tag-missing') { // the theme emits {% commerce %}, never a section
                $warnings[] = "{$w['rule']}: {$w['message']}";
            }
        }

        return $this->gate('lint', 'Theme linter (strict)', $errors, $warnings);
    }

    /** @return array{id:string,label:string,status:string,errors:list<string>,warnings:list<string>,meta:array<string,mixed>} */
    protected function contract(Candidate $c): array
    {
        $errors = [];
        $warnings = [];
        $type = preg_quote($c->type, '/');
        $body = (string) preg_replace('/{%-?\s*comment\s*-?%}.*?{%-?\s*endcomment\s*-?%}/s', '', $c->liquid);

        if (! preg_match('/<section\b[^>]*\bclass\s*=\s*"[^"]*\bmbs-'.$type.'(?=[\s"{])[^>]*>/i', $body, $root)) {
            $errors[] = "the section root must be <section class=\"mbs-{$c->type}\" …>.";
        } elseif (! preg_match('/\saria-(labelledby|label)\s*=/i', $root[0])) {
            $errors[] = 'the <section> root needs aria-labelledby (its heading id) or aria-label.';
        }

        if (preg_match('/{%-?\s*commerce\b/i', $body)) {
            $errors[] = 'do not emit {% commerce %} in a section — the theme emits it once.';
        }
        if (preg_match('/data-mb-add\b/i', $body) && ! str_contains($body, 'flags.allow_order')) {
            $errors[] = 'data-mb-add (order controls) must only render inside {% if flags.allow_order %}.';
        }
        if (preg_match('/allow_order\s*:\s*true\b/i', $body)) {
            $errors[] = 'pass allow_order: flags.allow_order to card snippets, never a literal true.';
        }

        // Visible text must be translatable: strip Liquid, then look for letters.
        $plain = (string) preg_replace(['/{{.*?}}|{%.*?%}/s', '/&#?[a-z0-9]+;/i'], ' ', $body);
        $literals = [];
        if (preg_match_all('/>([^<>]+)</u', $plain, $m)) {
            foreach ($m[1] as $text) {
                if (preg_match('/\p{L}{2,}/u', $text)) {
                    $literals[] = trim(preg_replace('/\s+/', ' ', $text));
                }
            }
        }
        if (preg_match_all('/\s(alt|aria-label|placeholder|title)\s*=\s*"([^"]*)"/i', $plain, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $attr, $value]) {
                if (preg_match('/\p{L}{2,}/u', $value)) {
                    $literals[] = "{$attr}=\"".trim($value).'"';
                }
            }
        }
        foreach (array_slice(array_values(array_unique($literals)), 0, 6) as $text) {
            $errors[] = 'hard-coded text "'.mb_substr($text, 0, 60)."\" — use {{ '…' | t }}, vocab.* or a setting.";
        }

        // Every | t key must be translated in en and ar (draft strings or the library's).
        preg_match_all('/([\'"])((?:(?!\1).){1,200})\1\s*\|\s*t\b/u', $body, $keys);
        $libEn = $this->library->locales('en');
        $libAr = $this->library->locales('ar');
        foreach (array_unique($keys[2]) as $key) {
            if (! isset($c->locales['en'][$key]) && ! isset($libEn[$key])) {
                $errors[] = "string '{$key}' has no English value in locales.en.";
            }
            if (! isset($c->locales['ar'][$key]) && ! isset($libAr[$key])) {
                $errors[] = "string '{$key}' has no Arabic translation in locales.ar.";
            }
        }
        foreach ($c->locales['ar'] as $key => $value) {
            if ($value !== '' && ! preg_match('/\p{Arabic}/u', $value) && preg_match('/\p{L}{3,}/u', $value)) {
                $warnings[] = "locales.ar '{$key}' does not look Arabic.";
            }
        }
        if (! isset($c->locales['ar'][(string) ($c->schema['name'] ?? '')])) {
            $warnings[] = 'add the Arabic schema name to locales.ar (editor label).';
        }

        preg_match_all('/<img\b[^>]*>/i', $body, $imgs);
        foreach ($imgs[0] as $tag) {
            if (! preg_match('/\salt\s*=/i', $tag)) {
                $errors[] = '<img> without alt — use the item/setting name.';
                break;
            }
        }
        preg_match_all('/<button\b[^>]*>/i', $body, $buttons);
        foreach ($buttons[0] as $tag) {
            if (! preg_match('/\stype\s*=/i', $tag)) {
                $errors[] = '<button> without type="button".';
                break;
            }
        }

        $known = array_keys($this->library->snippets());
        preg_match_all('/{%-?\s*render\s+[\'"]([a-z0-9\-_]+)[\'"]/i', $body, $rendered);
        foreach (array_unique($rendered[1]) as $snippet) {
            if (! in_array($snippet, $known, true)) {
                $errors[] = "snippet '{$snippet}' is not a library snippet (available: ".implode(', ', $known).').';
            }
        }

        return $this->gate('contract', 'Library contract', array_values(array_unique($errors)), $warnings);
    }

    /**
     * @param  list<string>  $datasets
     * @return array{id:string,label:string,status:string,errors:list<string>,warnings:list<string>,meta:array<string,mixed>}
     */
    protected function render(Candidate $c, array $datasets): array
    {
        $errors = [];
        $warnings = [];
        $renders = new SectionRenderer($this->library)->renderAll($c, $datasets);
        $nonEmpty = ['en' => false, 'ar' => false];
        $slowest = 0;
        foreach ($renders as $r) {
            $slowest = max($slowest, $r['ms']);
            if (! $r['ok']) {
                $errors[] = "{$r['label']}: {$r['error']}";

                continue;
            }
            if ($r['ms'] > SectionRenderer::MAX_RENDER_MS) {
                $errors[] = "{$r['label']}: render took {$r['ms']} ms (budget ".SectionRenderer::MAX_RENDER_MS.' ms).';
            }
            if ($r['bytes'] > SectionRenderer::MAX_OUTPUT_BYTES) {
                $errors[] = "{$r['label']}: output is {$r['bytes']} bytes (budget ".SectionRenderer::MAX_OUTPUT_BYTES.').';
            }
            if (! $r['empty']) {
                if (in_array($r['vertical'], $datasets, true)) {
                    $nonEmpty[$r['lang']] = true;
                }
                if (! str_contains($r['html'], 'mbs-'.$c->type)) {
                    $errors[] = "{$r['label']}: output has no .mbs-{$c->type} root.";
                }
                foreach (ThemePackageValidator::markupIssues('output', $r['html']) as $issue) {
                    $errors[] = "{$r['label']}: rendered {$issue}";
                }
            }
        }
        foreach ($nonEmpty as $lang => $any) {
            if (! $any) {
                $errors[] = "renders nothing on every targeted demo store in {$lang} — ship defaults/presets that show real content.";
            }
        }

        return $this->gate('render', 'Render (demo stores × en/ar)', array_values(array_unique(array_slice($errors, 0, 30))), $warnings, [
            'renders' => count($renders),
            'datasets' => $datasets,
            'slowest_ms' => $slowest,
            'budget_ms' => SectionRenderer::MAX_RENDER_MS,
        ]);
    }

    /**
     * @return array{status:string,errors:list<string>,warnings:list<string>,skipped:?string,axe:?string,pages:list<array<string,mixed>>,screenshots:array<string,string>}
     */
    protected function browserGate(Candidate $c, string $dataset, string $shotsDir): array
    {
        $browser = $this->browser ?? app(BrowserGate::class);
        if (($reason = $browser->unavailableReason()) !== null) {
            return ['status' => 'skipped', 'errors' => [], 'warnings' => [], 'skipped' => $reason, 'axe' => null, 'pages' => [], 'screenshots' => []];
        }
        $renderer = new SectionRenderer($this->library);
        $pages = [];
        foreach (['en', 'ar'] as $lang) {
            $pages[] = ['name' => $lang, 'lang' => $lang, 'html' => $renderer->page($c, $renderer->renderFor($c, $dataset, $lang), $lang)];
        }

        return $browser->run($pages, $shotsDir);
    }

    /**
     * @param  array<int,string>  $errors
     * @param  array<int,string>  $warnings
     * @param  array<string,mixed>  $meta
     * @return array{id:string,label:string,status:string,errors:list<string>,warnings:list<string>,meta:array<string,mixed>}
     */
    protected function gate(string $id, string $label, array $errors, array $warnings = [], array $meta = [], ?string $skipped = null): array
    {
        $status = $skipped !== null ? 'skipped' : ($errors === [] ? 'pass' : 'fail');
        if ($skipped !== null) {
            $meta['reason'] = $skipped;
        }

        return ['id' => $id, 'label' => $label, 'status' => $status, 'errors' => array_values($errors), 'warnings' => array_values($warnings), 'meta' => $meta];
    }

    /**
     * The failures as a compact list for the repair prompt.
     *
     * @param  array{gates:list<array{id:string,status:string,errors:list<string>}>}  $report
     * @return list<string>
     */
    public static function failures(array $report): array
    {
        $out = [];
        foreach ($report['gates'] as $g) {
            if ($g['status'] === 'fail') {
                foreach ($g['errors'] as $e) {
                    $out[] = "[{$g['id']}] {$e}";
                }
            }
        }

        return array_slice($out, 0, 40);
    }
}
