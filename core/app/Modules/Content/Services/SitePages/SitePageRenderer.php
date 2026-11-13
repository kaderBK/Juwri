<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\SitePages;

use App\Models\SitePage;
use Juwri\Core\Support\LocalizedContent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

/**
 * HOME-PAGES HP-13: renders a published SitePage. Reads ONLY published_blocks
 * (the publish snapshot) — never the draft; preview paths (HP-43) pass the
 * draft document explicitly. Locale resolution is delegated to the reseller
 * engine's LocalizedContent (shared leaf service — never re-implemented).
 *
 * Defensive by contract (PLAN risk #3): skips disabled blocks, unknown types
 * and stored versions newer than the current registry def. Old published JSON
 * must never 500.
 */
class SitePageRenderer
{
    /**
     * HP-16: blocks-HTML fragment cache TTL. The key embeds the page's
     * updated_at timestamp, so every save/publish/unpublish naturally starts a
     * fresh key (old entries just expire) — the short TTL exists only to keep
     * DATA-BOUND blocks (pricing/faq/testimonials, which query their own
     * models) at most this stale. The auth-aware layout renders per request.
     */
    private const HTML_TTL = 300;

    public function render(SitePage $page): View
    {
        $locale = get_lang();
        $stamp = optional($page->updated_at)->getTimestamp() ?: 0;

        $html = Cache::flexible(
            "sitepage:html:{$page->id}:{$locale}:{$stamp}",
            [self::HTML_TTL, self::HTML_TTL * 2],
            fn () => $this->blocksHtml($page->published_blocks, $locale)
        );

        return $this->view($page, $html, $locale);
    }

    /**
     * Render an explicit blocks document against the live layout — the HP-43
     * draft-preview path. NEVER cached (drafts change on every autosave).
     *
     * @param  array<string, mixed>|null  $doc
     */
    public function renderDocument(SitePage $page, ?array $doc): View
    {
        $locale = get_lang();

        return $this->view($page, $this->blocksHtml($doc, $locale), $locale);
    }

    private function view(SitePage $page, string $blocksHtml, string $locale): View
    {
        return view('site.page', [
            'sitePage' => $page,
            'siteBlocksHtml' => $blocksHtml,
            'siteSeo' => $this->resolveSeo($page, $locale),
        ]);
    }

    /**
     * Render the enabled, renderable blocks of a document to HTML via their
     * site/blocks/{type} wrappers (missing wrappers skipped — last line of
     * defense behind SiteBlockRegistry::renderable()).
     *
     * @param  array<string, mixed>|null  $doc
     */
    private function blocksHtml(?array $doc, string $locale): string
    {
        $html = '';
        foreach (($doc['blocks'] ?? []) as $block) {
            if (! is_array($block) || ($block['enabled'] ?? true) === false) {
                continue;
            }
            if (! SiteBlockRegistry::renderable($block)) {
                continue;
            }
            $viewName = 'site.blocks.'.$block['type'];
            if (! view()->exists($viewName)) {
                continue;
            }
            $html .= view($viewName, [
                'block' => [
                    'id' => (string) ($block['id'] ?? ''),
                    'type' => (string) $block['type'],
                    'settings' => (array) ($block['settings'] ?? []),
                    // Per-locale maps resolved to flat strings for the wrappers;
                    // empty string means "use the partial's ___() default".
                    'content' => $this->resolveContent($block, $locale),
                ],
            ])->render();
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, string>
     */
    private function resolveContent(array $block, string $locale): array
    {
        $out = [];
        foreach (($block['content'] ?? []) as $name => $field) {
            $out[$name] = LocalizedContent::resolve($field, $locale);
        }

        return $out;
    }

    /**
     * SEO fields for the layout head. The default-locale VERBATIM contract
     * (rev/seo R9) holds because LegacyHomeBridge seeds seo.meta_title/
     * meta_description default-locale entries byte-identically from Options,
     * and resolve() returns the exact stored string for that locale.
     *
     * @return array{title: string, description: string, og_image: string, robots: string}
     */
    private function resolveSeo(SitePage $page, string $locale): array
    {
        $seo = (array) ($page->seo ?? []);

        return [
            'title' => LocalizedContent::resolve($seo['meta_title'] ?? null, $locale),
            'description' => LocalizedContent::resolve($seo['meta_description'] ?? null, $locale),
            'og_image' => is_string($seo['og_image'] ?? null) ? $seo['og_image'] : '',
            'robots' => is_string($seo['robots'] ?? null) ? $seo['robots'] : '',
        ];
    }
}
