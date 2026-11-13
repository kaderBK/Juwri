<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Content\Models\DocsEvent;
use Juwri\Modules\Content\Services\Docs\DocsAssistantService;
use Juwri\Modules\Content\Services\Docs\HelpPillarPaths;
use Symfony\Component\HttpFoundation\Response;

/**
 * DOCS-PLATFORM D1-1: the public Help Center — serves the owner-facing guides
 * in resources/docs/users as rendered pages. Slugs are filesystem-derived but
 * constrained to lowercase kebab basenames, so the route can never read
 * arbitrary files (README is uppercase and therefore unreachable — it is the
 * source of the index page instead). Chrome is brand-aware via brand_title(),
 * so a reseller host shows the reseller's brand; page content is written
 * brand-neutral. Deliberately crawlable (no noindex, unlike integrations
 * docs): these pages are meant to be found.
 */
class HelpCenterController extends Controller
{
    /**
     * D1-3: contextual help — dashboard route pattern => users/ doc slug.
     * Consumed by the topbar help dropdown; first matching pattern wins.
     */
    private const CONTEXT_MAP = [
        'dashboard' => 'dashboard-overview',
        'stores.orders' => 'orders',
        'stores.menu' => 'menu-items',
        'stores.qrDesigner' => 'qr-codes',
        'qr.designer' => 'qr-codes',
        'pos.*' => 'pos',
        'kds.*' => 'kds',
        'subscription*' => 'plans-billing',
        'theme.customizer.*' => 'themes',
        'staff.index' => 'staff',
        'inventory.*' => 'inventory',
        'stores.coupons.*' => 'coupons',
        'analytics.*' => 'analytics',
        'stores.whatsappSettings' => 'whatsapp',
        'stores.dispatch.*' => 'delivery',
        'stores.deliveryInsights.*' => 'delivery',
        'branches.*' => 'branches',
        'wallet.*' => 'wallet',
        'customers.index' => 'customers',
        'reservations.*' => 'reservations',
        'stores.giftcards.*' => 'gift-cards',
        'stores.foodsafety.*' => 'food-safety',
        'stores.directory.*' => 'directory',
        'stores.disputes.*' => 'disputes',
        'stores.catering.*' => 'catering',
        'stores.feedback.*' => 'reviews-feedback',
        'stores.books.*' => 'accounting',
        'stores.accounting.*' => 'accounting',
        'stores.phoneagent.*' => 'phone-agent',
        'stores.aggregators.*' => 'apps-integrations',
        'settings.integrations' => 'apps-integrations',
        'stores.automations.*' => 'marketing-automation',
        // rev/multi-vertical: non-restaurant dashboard surfaces (routes/verticals/lane-*.php).
        'retail.brands.*' => 'retail-products',
        'retail.categories.*' => 'retail-products',
        'retail.products.*' => 'retail-products',
        'retail.fitments.*' => 'car-parts',
        'pharmacy.compliance.*' => 'pharmacy',
        'laundry.services.*' => 'laundry-services',
        'stores.service_board.*' => 'laundry-services',
        'stores.fulfillment.*' => 'fulfillment-methods',
        'stores.orders.board*' => 'fulfillment-methods',
        'stores.orders.shipment.*' => 'fulfillment-methods',
    ];

    /** The users/ doc slug matching the current dashboard route, if any. */
    public static function contextSlug(): ?string
    {
        foreach (self::CONTEXT_MAP as $pattern => $slug) {
            if (request()->routeIs($pattern) && is_file(resource_path('docs/users/'.$slug.'.md'))) {
                return $slug;
            }
        }

        return null;
    }

    // D1-1 owner pillar (docs/users) + D3-7 shopper pillar (docs/shoppers).
    // Each = [docs subdir, URL base for rewritten cross-links, index route].
    private const PILLARS = [
        'users' => ['users', '/help-center', 'help.index'],
        'shoppers' => ['shoppers', '/fusion/help', 'mk.help.index'],
        'resellers' => ['resellers', '/reseller/help', 'reseller.help.index'],
    ];

    public function index(): \Illuminate\Http\Response
    {
        return $this->pillarIndex('users');
    }

    public function show(string $slug): \Illuminate\Http\Response
    {
        return $this->pillarShow('users', $slug);
    }

    // D2-1: raw markdown for AI agents — /help-center/{slug}.md, or the HTML
    // route with `Accept: text/markdown`. ~90% fewer tokens than parsing HTML.
    public function raw(string $slug): \Illuminate\Http\Response
    {
        return $this->pillarRaw('users', $slug);
    }

    // D3-7: shopper pillar, served under the marketplace surface (brand-neutral).
    public function shopperIndex(): \Illuminate\Http\Response
    {
        return $this->pillarIndex('shoppers');
    }

    public function shopperShow(string $slug): \Illuminate\Http\Response
    {
        return $this->pillarShow('shoppers', $slug);
    }

    public function shopperRaw(string $slug): \Illuminate\Http\Response
    {
        return $this->pillarRaw('shoppers', $slug);
    }

    // D1-8: reseller docs pillar, served inside the reseller panel.
    public function resellerIndex(): \Illuminate\Http\Response
    {
        return $this->pillarIndex('resellers');
    }

    public function resellerShow(string $slug): \Illuminate\Http\Response
    {
        return $this->pillarShow('resellers', $slug);
    }

    public function resellerRaw(string $slug): \Illuminate\Http\Response
    {
        return $this->pillarRaw('resellers', $slug);
    }

    /**
     * D5-1 (generalized): resolve a doc file to its localized variant with a
     * per-page English fallback. When the viewer's locale is an active,
     * non-English language AND docs/{dir}/{locale}/{name} exists, serve it;
     * otherwise the English source (docs/{dir}/{name}). The locale is used only
     * when it is a strict language-code shape, is not 'en', and is one of
     * config('laravellocalization.supportedLocales') or an active language row
     * (get_active_languages()). Both the English source and the translated candidate must live
     * strictly inside docs/{dir} (realpath fence), so a crafted locale or name
     * can never read outside the docs tree. RTL presentation is handled in the
     * shell, not here.
     */
    private function localizedPath(string $dir, string $name): string
    {
        // defence in depth: every caller already validated the slug; a name that is not a plain `slug.md` is never joined to a path
        abort_unless(preg_match('/^[A-Za-z0-9-]+\.md$/', $name) === 1, Response::HTTP_NOT_FOUND);

        $en = $this->contained($dir, HelpPillarPaths::root($dir).'/'.$name);
        abort_if($en === null, Response::HTTP_NOT_FOUND); // the English source is missing or resolves outside the docs folder (a symlink)
        $locale = app()->getLocale();

        if (! preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?\z/', $locale) || $locale === 'en') {
            return $en;
        }

        // active = a configured locale OR an active language row (the config list shrinks to en/ar when URL language codes are off)
        $supported = array_keys((array) config('laravellocalization.supportedLocales', []));
        if (function_exists('get_active_languages')) {
            $supported = array_merge($supported, array_map('strval', array_keys(get_active_languages())));
        }
        if (! in_array($locale, $supported, true)) {
            return $en;
        }

        // the translated file, if there is one and it is inside the docs folder too; otherwise the English page
        return $this->contained($dir, HelpPillarPaths::root($dir).'/'.$locale.'/'.$name) ?? $en;
    }

    /** The real path of `$path` when it is an existing file strictly inside docs/{dir}, else null (both the English and the translated path go through this). */
    private function contained(string $dir, string $path): ?string
    {
        $base = realpath(HelpPillarPaths::root($dir));
        $real = is_file($path) ? realpath($path) : false;

        return $base !== false && $real !== false && str_starts_with($real, $base.DIRECTORY_SEPARATOR) ? $real : null;
    }

    private function pillarIndex(string $pillar): \Illuminate\Http\Response
    {
        [$dir, $linkBase] = self::PILLARS[$pillar];
        $html = $this->render($this->localizedPath($dir, 'README.md'), $linkBase);

        // D6-3: Ask-AI box on the index, only when the assistant flag is on (dark).
        if (DocsAssistantService::enabled()) {
            $html = $this->askWidget(e(url('/help-center/ask'))).$html;
        }

        return $this->shell(brand_title().' — Help Center', $html, $pillar);
    }

    /** D6-3: the Ask-AI box markup + a tiny fetch handler that renders sources. */
    private function askWidget(string $askUrl): string
    {
        $nonce = e(csp_nonce());

        return <<<W
<div style="background:#f7f7f9;border:1px solid #e5e5e5;border-radius:8px;padding:1rem;margin-bottom:1.5rem">
  <strong>Ask the guides</strong>
  <div style="display:flex;gap:.5rem;margin-top:.5rem">
    <input id="hc-ask-q" type="text" placeholder="Ask a question…" style="flex:1;padding:.5em" aria-label="Ask a question">
    <button id="hc-ask-b" style="padding:.5em 1em">Ask</button>
  </div>
  <div id="hc-ask-a" style="margin-top:.75rem"></div>
</div>
<script nonce="{$nonce}">
(function(){
  var q=document.getElementById('hc-ask-q'),b=document.getElementById('hc-ask-b'),a=document.getElementById('hc-ask-a');
  function ask(){
    if(!q.value.trim())return; b.disabled=true; a.textContent='Thinking…';
    fetch('{$askUrl}',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({q:q.value})})
      .then(function(r){return r.json()}).then(function(d){
        a.innerHTML='';
        var p=document.createElement('p'); p.textContent=d.answer||''; a.appendChild(p);
        if(d.sources&&d.sources.length){
          var s=document.createElement('div'); s.style.fontSize='.85em'; s.style.color='#555';
          s.appendChild(document.createTextNode('Sources: '));
          d.sources.forEach(function(x,i){var l=document.createElement('a');l.href=x.url;l.textContent=x.title;if(i)s.appendChild(document.createTextNode(', '));s.appendChild(l);});
          a.appendChild(s);
        }
      }).catch(function(){a.textContent='Sorry, something went wrong.';}).finally(function(){b.disabled=false;});
  }
  b.addEventListener('click',ask); q.addEventListener('keydown',function(e){if(e.key==='Enter')ask();});
})();
</script>
W;
    }

    private function pillarShow(string $pillar, string $slug): \Illuminate\Http\Response
    {
        [$dir, $linkBase] = self::PILLARS[$pillar];

        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), Response::HTTP_NOT_FOUND);

        // Existence is checked against the ENGLISH source (the canonical slug set);
        // the ar/ variant is an optional overlay resolved by localizedPath().
        $enPath = HelpPillarPaths::root($dir).'/'.$slug.'.md';
        // basename check defeats case-insensitive filesystems (macOS would
        // otherwise serve README.md — the index source — as .../readme).
        abort_unless(
            is_file($enPath) && in_array($slug.'.md', scandir(dirname($enPath)), true),
            Response::HTTP_NOT_FOUND
        );

        // D2-1: content negotiation — an agent sending `Accept: text/markdown`
        // gets the raw source instead of the rendered HTML shell.
        if (str_contains((string) request()->header('Accept'), 'text/markdown')) {
            return $this->pillarRaw($pillar, $slug);
        }

        // D5-1: serve the Arabic variant when the viewer is in Arabic.
        $html = $this->render($this->localizedPath($dir, $slug.'.md'), $linkBase);

        // Page <title> from the first H1 the renderer produced.
        $title = preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $m)
            ? trim(strip_tags($m[1]))
            : Str::headline($slug);

        return $this->shell($title.' — '.brand_title(), $html, $pillar, $slug);
    }

    /**
     * D1-5 / D6-1: keyword search over a pillar's docs (same scoring shape as the
     * dev MCP corpus), logging the query and result count so zero-result searches
     * surface as a docs-gap signal.
     */
    public function search(Request $request, string $pillar = 'users'): \Illuminate\Http\Response
    {
        abort_unless(isset(self::PILLARS[$pillar]), Response::HTTP_NOT_FOUND);
        [$dir, $linkBase] = self::PILLARS[$pillar];
        $q = trim((string) $request->query('q', ''));

        $results = $q === '' ? [] : $this->searchPillar($dir, $q);

        \Log::channel(config('logging.default'))->info('[docs-search]', [
            'pillar' => $pillar, 'q' => mb_substr($q, 0, 120), 'results' => count($results),
        ]);
        if ($q !== '') {
            DocsEvent::record($results === [] ? 'search_zero' : 'search', $pillar, null, $q); // D6-2
        }

        $items = '';
        foreach ($results as $r) {
            $href = e($linkBase.'/'.$r['slug']);
            $items .= '<li><a href="'.$href.'"><strong>'.e($r['title']).'</strong></a>'
                .' — <span style="color:#555">'.e($r['snippet']).'</span></li>';
        }
        $safeQ = e($q);
        $count = count($results);
        $body = '<h1>Search</h1>'
            .'<form method="get" action=""><input type="search" name="q" value="'.$safeQ
            .'" placeholder="Search the guides" style="width:70%;padding:.5em"> <button>Search</button></form>';
        if ($q !== '') {
            $body .= '<p>'.$count.' result'.($count === 1 ? '' : 's').' for “'.$safeQ.'”.</p>';
            $body .= $count ? '<ul>'.$items.'</ul>' : '<p>No pages matched. Try different words.</p>';
        }

        return $this->shell(($q !== '' ? "“{$q}” — " : '').brand_title().' Help Center', $body, $pillar);
    }

    /** @return list<array{slug:string,title:string,snippet:string,score:int}> */
    private function searchPillar(string $dir, string $query): array
    {
        $terms = array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/i', mb_strtolower($query)) ?: [],
            fn ($t) => mb_strlen($t) >= 2
        )));
        if ($terms === []) {
            return [];
        }

        $scored = [];
        foreach (glob(HelpPillarPaths::root($dir).'/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');
            if ($slug === 'README' || ! preg_match('/^[a-z0-9-]+$/', $slug)) {
                continue;
            }
            $md = (string) file_get_contents($file);
            if (str_starts_with($md, "---\n") && ($end = strpos($md, "\n---\n", 4)) !== false) {
                $md = substr($md, $end + 5);
            }
            $title = preg_match('/^#\s+(.+)$/m', $md, $m) ? trim($m[1]) : Str::headline($slug);
            $body = mb_strtolower(strip_tags($md));
            $titleL = mb_strtolower($title);

            $score = 0;
            $hitTerms = 0;
            foreach ($terms as $term) {
                $h = (str_contains($titleL, $term) ? 3 : 0) + min(substr_count($body, $term), 5);
                if ($h > 0) {
                    $hitTerms++;
                    $score += $h;
                }
            }
            if ($score > 0) {
                $pos = mb_strpos($body, $terms[0]);
                $snippet = trim(mb_substr($md, max(0, $pos - 40), 160));
                $scored[] = ['slug' => $slug, 'title' => $title, 'snippet' => $snippet, 'score' => $hitTerms * 100 + $score];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, 10);
    }

    /**
     * D6-3: docs-grounded AI assistant (ships dark). 404 while the flag is off so
     * the endpoint doesn't exist for anyone; when on, answers from the docs only.
     */
    public function ask(Request $request, string $pillar = 'users'): JsonResponse
    {
        abort_unless(DocsAssistantService::enabled(), Response::HTTP_NOT_FOUND);
        abort_unless(isset(self::PILLARS[$pillar]), Response::HTTP_NOT_FOUND);

        $data = $request->validate(['q' => 'required|string|max:500']);
        $result = app(DocsAssistantService::class)->ask($data['q'], $pillar);

        \Log::channel(config('logging.default'))->info('[docs-assistant]', [
            'pillar' => $pillar, 'q' => mb_substr($data['q'], 0, 120), 'sources' => count($result['sources']),
        ]);
        DocsEvent::record('assistant', $pillar, null, $data['q']); // D6-2

        return response()->json($result);
    }

    /**
     * D6-1: was-this-helpful signal (+ optional comment). Public, CSRF-exempt (the
     * pages are cacheable and unauthenticated); logged as the docs-quality feed.
     */
    public function feedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pillar' => 'required|string|max:20',
            'slug' => 'required|string|max:80',
            'helpful' => 'required|boolean',
            'comment' => 'nullable|string|max:1000',
        ]);

        \Log::channel(config('logging.default'))->info('[docs-helpful]', [
            'pillar' => $data['pillar'],
            'slug' => $data['slug'],
            'helpful' => (bool) $data['helpful'],
            'comment' => $data['comment'] ?? null,
        ]);
        DocsEvent::record(  // D6-2
            $data['helpful'] ? 'helpful' : 'unhelpful',
            $data['pillar'],
            $data['slug'],
            $data['comment'] ?? null
        );

        return response()->json(['ok' => true]);
    }

    /** D2-1: serve the raw markdown source as text/markdown (front-matter kept). */
    private function pillarRaw(string $pillar, string $slug): \Illuminate\Http\Response
    {
        [$dir] = self::PILLARS[$pillar];

        abort_unless(preg_match('/^[a-z0-9-]+$/', $slug), Response::HTTP_NOT_FOUND);

        $enPath = HelpPillarPaths::root($dir).'/'.$slug.'.md';
        abort_unless(
            is_file($enPath) && in_array($slug.'.md', scandir(dirname($enPath)), true),
            Response::HTTP_NOT_FOUND
        );

        // D5-1: raw markdown also honours the Arabic variant.
        return response((string) file_get_contents($this->localizedPath($dir, $slug.'.md')), 200)
            ->header('Content-Type', 'text/markdown; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300');
    }

    /** Strip optional YAML front-matter, render markdown safely, rewrite doc links. */
    private function render(string $path, string $linkBase): string
    {
        $md = (string) file_get_contents($path);

        // Front-matter block (docs-platform D4-4 schema) is metadata, not content.
        if (str_starts_with($md, "---\n") && ($end = strpos($md, "\n---\n", 4)) !== false) {
            $md = substr($md, $end + 5);
        }

        // D2-5: never pass raw HTML or unsafe link schemes through the docs pipeline.
        $html = Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        // Relative cross-references: `coupons.md` / `coupons.md#anchor` → {base}/coupons (a locale folder without its own sibling page links `../coupons.md`, the English page).
        $html = preg_replace('/href="(?:\.\.\/)?([a-z0-9-]+)\.md(#[^"]*)?"/', 'href="'.$linkBase.'/$1$2"', $html);

        // Cross-pillar developer links leave the owner surface for the dev hub
        // (any depth: ../dev from users/, ../../dev from the ar/ overlay).
        return preg_replace('/href="(?:\.\.\/)+dev\/[^"]*"/', 'href="/developers"', $html);
    }

    /**
     * Wrap rendered doc HTML in a page shell. The public owner Help Center
     * (`users` pillar) uses the marketing site chrome — real nav, footer, design
     * system, and a guide sidebar — so it looks like the rest of the site and
     * links back into it. The shopper/reseller pillars keep the lightweight
     * standalone shell: they render inside other contexts (the Fusion surface and
     * the panel-gated reseller portal) where the public landing nav does not fit.
     */
    private function shell(string $title, string $html, string $pillar = 'users', ?string $slug = null): \Illuminate\Http\Response
    {
        if ($pillar === 'users') {
            return $this->landingShell($title, $html, $pillar, $slug);
        }

        return $this->legacyShell($title, $html, $pillar, $slug);
    }

    /**
     * The owner Help Center rendered inside the marketing landing layout
     * (templates/{theme}/layouts/landing): shared header nav + footer + design
     * tokens, plus a docs-specific hero, a grouped guide sidebar, and the
     * was-this-helpful widget. $settings/$activeTheme are supplied to every view
     * by the global `*` composer (ViewServiceProvider), so the chrome is fully
     * populated without threading them through here.
     */
    private function landingShell(string $title, string $html, string $pillar, ?string $slug): \Illuminate\Http\Response
    {
        [$dir, $linkBase, $indexRoute] = self::PILLARS[$pillar];

        return response()
            ->view('marketing.docs.page', [
                // active_theme() is normally shared to every view by the global `*`
                // composer, but that composer is off when APP_INSTALLED=false (the
                // test env), so the landing layout's $activeTheme would be undefined.
                // Passing it here also propagates into the extended layout.
                'activeTheme' => active_theme(),
                'docTitle' => $title,
                'brand' => brand_title(),
                'indexUrl' => route($indexRoute),
                'searchAction' => url($linkBase.'/search'),
                'bodyHtml' => $html,
                'widgetHtml' => $this->helpfulWidget($pillar, $slug),
                'navGroups' => $this->pillarNav($dir, $linkBase, $slug),
                'activeSlug' => $slug,
            ])
            ->header('Cache-Control', 'public, max-age=300');
    }

    /** Lightweight standalone shell for the shopper/reseller pillars. */
    private function legacyShell(string $title, string $html, string $pillar, ?string $slug): \Illuminate\Http\Response
    {
        $safeTitle = e($title);
        $brand = e(brand_title());
        $home = e(route(self::PILLARS[$pillar][2]));
        $searchAction = e(url(self::PILLARS[$pillar][1].'/search'));
        $widget = $this->helpfulWidget($pillar, $slug);

        // D5-2: RTL-aware viewer. dir on <html> per the current locale; CSS uses
        // logical properties so it mirrors correctly, and code blocks stay LTR.
        $lang = e(app()->getLocale());
        $nonce = e(csp_nonce());
        $dir = function_exists('is_rtl') && is_rtl() ? 'rtl' : 'ltr';

        $body = <<<HTML
<!doctype html>
<html lang="{$lang}" dir="{$dir}"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$safeTitle}</title>
<style nonce="{$nonce}">
  body{max-width:760px;margin:0 auto;padding:0 1rem 3rem;font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1a1a1a}
  header{display:flex;gap:.75rem;align-items:baseline;justify-content:space-between;padding:1rem 0;border-bottom:1px solid #e5e5e5;margin-bottom:1.5rem}
  header a{font-weight:600;text-decoration:none}
  header form{margin:0}
  h1,h2{line-height:1.25} code{background:#f2f2f2;padding:.1em .3em;border-radius:4px}
  a{color:#0b5fff} ol,ul{padding-inline-start:1.3rem}
  img{max-width:100%;height:auto;display:block;margin:1.25rem 0;border:1px solid #e5e5e5;border-radius:8px}
  table{border-collapse:collapse} td,th{border:1px solid #ddd;padding:.35em .6em;text-align:start}
  #hc-helpful button{cursor:pointer;background:#f2f2f2;border:1px solid #ddd;border-radius:6px;padding:.2em .6em}
  pre,code,kbd,samp{direction:ltr;unicode-bidi:isolate;text-align:left}
</style>
</head><body>
<header>
  <a href="{$home}">{$brand} Help Center</a>
  <span><a href="/changelog" style="font-size:.85em">What's new</a>
  <form method="get" action="{$searchAction}" style="display:inline"><input type="search" name="q" placeholder="Search" aria-label="Search help"></form></span>
</header>
{$html}
{$widget}
</body></html>
HTML;

        return response($body, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300');
    }

    /** D6-1: was-this-helpful widget markup (content pages only). */
    private function helpfulWidget(string $pillar, ?string $slug): string
    {
        if ($slug === null) {
            return '';
        }
        $feedbackUrl = e(route('help.feedback'));
        $safeSlug = e($slug);
        $safePillar = e($pillar);
        $nonce = e(csp_nonce());
        $tHelpful = e(___('Was this helpful?'));
        $tYes = e(___('Yes'));
        $tNo = e(___('No'));
        $tThanks = e(___('Thanks for the feedback.'));

        return <<<W
<div id="hc-helpful" class="docs-helpful">
  <span>{$tHelpful}</span>
  <button type="button" data-v="1">👍 {$tYes}</button>
  <button type="button" data-v="0">👎 {$tNo}</button>
  <span id="hc-thanks" hidden>{$tThanks}</span>
</div>
<script nonce="{$nonce}">
(function(){
  var box=document.getElementById('hc-helpful');
  box.querySelectorAll('button').forEach(function(b){
    b.addEventListener('click',function(){
      fetch('{$feedbackUrl}',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({pillar:'{$safePillar}',slug:'{$safeSlug}',helpful:b.dataset.v==='1'})}).catch(function(){});
      box.querySelectorAll('button').forEach(function(x){x.hidden=true});
      document.getElementById('hc-thanks').hidden=false;
    });
  });
})();
</script>
W;
    }

    /**
     * Sidebar navigation for a pillar: every guide grouped by its front-matter
     * `group`, groups ordered by a curated sequence (unknown groups after, the
     * generic "Users" bucket last), items alphabetical by title.
     *
     * @return list<array{group:string, items:list<array{title:string,url:string,active:bool}>}>
     */
    private function pillarNav(string $dir, string $linkBase, ?string $activeSlug): array
    {
        static $order = [
            'Getting started', 'Sharing your menu', 'Taking orders', 'Front of house',
            'Operations', 'Your team', 'Customers and marketing', 'Insight',
            'Account and billing', 'Extending your account',
        ];

        $groups = [];
        foreach (glob(HelpPillarPaths::root($dir).'/*.md') ?: [] as $file) {
            $slug = basename($file, '.md');
            if ($slug === 'README' || ! preg_match('/^[a-z0-9-]+$/', $slug)) {
                continue;
            }
            // Read the localized overlay's front-matter when present, so the sidebar
            // shows Arabic guide titles for an Arabic reader (slug set is English).
            $head = (string) file_get_contents($this->localizedPath($dir, $slug.'.md'), false, null, 0, 600);
            $title = preg_match('/^title:\s*"?(.*?)"?\s*$/m', $head, $m)
                ? trim($m[1])
                : (preg_match('/^#\s+(.+)$/m', $head, $mm) ? trim($mm[1]) : Str::headline($slug));
            $group = preg_match('/^group:\s*"?(.*?)"?\s*$/m', $head, $g) ? trim($g[1]) : 'Users';

            $groups[$group][] = [
                'title' => $title,
                'url' => $linkBase.'/'.$slug,
                'active' => $slug === $activeSlug,
            ];
        }

        uksort($groups, function ($a, $b) use ($order) {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);
            $ia = $ia === false ? ($a === 'Users' ? PHP_INT_MAX : count($order) + 1) : $ia;
            $ib = $ib === false ? ($b === 'Users' ? PHP_INT_MAX : count($order) + 1) : $ib;

            return $ia === $ib ? strcmp($a, $b) : $ia <=> $ib;
        });

        $out = [];
        foreach ($groups as $group => $items) {
            usort($items, fn ($a, $b) => strcmp($a['title'], $b['title']));
            $out[] = ['group' => $group, 'items' => $items];
        }

        return $out;
    }
}
