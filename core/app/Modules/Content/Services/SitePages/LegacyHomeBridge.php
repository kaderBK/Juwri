<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\SitePages;

use App\Models\Site;
use App\Models\SitePage;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

/**
 * HOME-PAGES HP-30: composes a DRAFT home SitePage that reproduces today's
 * hardcoded legacy home 1:1 from blocks.
 *
 * Design (deliberate deviation from the original PLAN wording): block content
 * is left EMPTY — every wrapper falls back to its partial's ___() defaults, so
 * the bridged page renders through the SAME live lang pipeline as the legacy
 * home (translations keep flowing; nothing is frozen at seed time). The ONLY
 * seeded copy is the SEO map: the default locale gets the admin-configured
 * site_title/meta_description VERBATIM (rev/seo R9 — the ranking Arabic
 * strings must be byte-identical), mirroring index.blade.php:8-14 exactly.
 *
 * Never publishes. Never touches published_blocks or status. Idempotent:
 * re-running refreshes an untouched draft; a HAND-EDITED draft is refused
 * unless $force (protects admin work from a stray re-seed).
 */
class LegacyHomeBridge
{
    /**
     * Today's section order — rev/home-pages/SECTIONS.md is the source of
     * truth; this list must match home/index.blade.php's include sequence.
     */
    public const LEGACY_ORDER = [
        'hero',
        'menu_studio',
        'logo_marquee',
        // Lead block (2026-08-29 reorder) — mirrors home/index.blade.php.
        'feature_icon_grid',
        'features_timed',
        'three_steps',
        'testimonials_wall',
        'features_overview',
        'showcase',
        'section_order_journey',
        'section_product_fields',
        'section_product_tour',
        'section_ai_suite',
        'section_payments_tax',
        'section_integrations',
        'bio_pages',
        'section_audiences',
        'pricing_grid',
        'for_developers',
        'faq_accordion',
        'blog_teasers',
    ];

    /**
     * @return array{page: SitePage, action: string} action: created|refreshed|skipped
     */
    public function seed(Site $site, bool $force = false): array
    {
        $doc = $this->composeDocument();

        $page = SitePage::forSite($site->id)->where('type', 'home')->orderBy('id')->first();

        if (! $page) {
            $page = SitePage::create([
                'site_id' => $site->id,
                'type' => 'home',
                'status' => 'draft',
                'blocks' => $doc,
                'seo' => $this->composeSeo(),
            ]);

            return ['page' => $page, 'action' => 'created'];
        }

        // Idempotence fence: identical draft → no-op; hand-edited draft → refuse
        // without --force so a re-seed never silently clobbers admin work.
        if ($page->blocks == $doc && ! $force) {
            return ['page' => $page, 'action' => 'skipped'];
        }
        if (! empty($page->blocks['blocks']) && $page->blocks != $doc && ! $force) {
            return ['page' => $page, 'action' => 'refused'];
        }

        $page->update([
            'blocks' => $doc,
            'seo' => $page->seo ?: $this->composeSeo(),
        ]);

        return ['page' => $page, 'action' => 'refreshed'];
    }

    /**
     * @return array{version: int, blocks: array<int, array<string, mixed>>}
     */
    public function composeDocument(): array
    {
        $blocks = [];
        foreach (self::LEGACY_ORDER as $type) {
            $blocks[] = [
                'id' => 'blk_'.substr(md5('bridge:'.$type), 0, 6), // deterministic → idempotent
                'type' => $type,
                'enabled' => true,
                'version' => 1,
                'settings' => [],
                'content' => [], // empty → wrapper falls back to ___() defaults
            ];
        }

        return SiteBlockRegistry::validateDocument(['blocks' => $blocks]);
    }

    /**
     * SEO map mirroring index.blade.php:8-14 exactly, per locale:
     *   - default locale → the admin-configured Options strings VERBATIM
     *     (rev/seo R9 deploy gate — the ranking Arabic strings);
     *   - every other locale → its translated ___() marketing strings
     *     (existing lang keys only — nothing auto-registers).
     * Without the non-default entries, LocalizedContent's fallback would serve
     * the default-locale (Arabic) title to every locale — a legacy mismatch.
     *
     * @return array<string, mixed>
     */
    private function composeSeo(): array
    {
        $default = (string) LaravelLocalization::getDefaultLocale();
        $title = (string) (config('settings.site_title') ?? '');
        $description = (string) (config('settings.meta_description') ?? '');

        $seo = ['meta_title' => [], 'meta_description' => []];
        $original = app()->getLocale();

        foreach (array_keys(LaravelLocalization::getSupportedLocales()) as $locale) {
            if ($locale === $default) {
                if ($title !== '') {
                    $seo['meta_title'][$locale] = $title;
                }
                if ($description !== '') {
                    $seo['meta_description'][$locale] = $description;
                }

                continue;
            }

            app()->setLocale($locale);
            $seo['meta_title'][$locale] = ___('Free QR menu with WhatsApp ordering').' | '.seo_brand();
            $seo['meta_description'][$locale] = ___('Turn your paper menu into a digital ordering menu with a QR code for every restaurant and send orders straight to WhatsApp. Try it free.');
        }

        app()->setLocale($original);

        return $seo;
    }
}
