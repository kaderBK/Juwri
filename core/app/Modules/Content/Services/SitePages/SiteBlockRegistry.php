<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\SitePages;

/**
 * HOME-PAGES HP-12: single source of truth for platform Site Pages block types.
 * Same contract as ResellerPages\BlockRegistry (schema drives builder forms,
 * render partials and validation) but a SEPARATE class with its OWN vocabulary —
 * never merged (reseller blocks stay reseller-flavored; see rev/home-pages/PLAN.md).
 *
 * Vocabulary = the legacy landing partial library (rev/home-pages/SECTIONS.md).
 * Render convention: resources/views/site/blocks/{type}.blade.php — a thin
 * wrapper that resolves per-locale content then @includes the UNTOUCHED legacy
 * partial. HP-20..22 fill in the remaining ~18 entries.
 *
 * Field spec (identical to the reseller engine):
 *   settings.*  → UNIVERSAL (flat): align/style/toggles/limits/urls — NEVER colors
 *                 (colors come from sites.theme tokens, HP-50).
 *   content.*   → localized when 'localized' => true (stored {locale: value}),
 *                 otherwise flat (urls, ids). Resolution ONLY via
 *                 ResellerPages\LocalizedContent.
 *
 * Versioning (schema-rot fence, PLAN risk #3): every def carries 'version'.
 * validate() stamps the def version onto each stored block; the renderer skips
 * blocks whose stored version is NEWER than the def (never renders fields it
 * does not understand); older stored versions render best-effort (unknown
 * fields were already stripped on save).
 */
class SiteBlockRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            // Example/reference entry (HP-12). Copy overrides are OPTIONAL —
            // empty content falls back to the partial's ___() defaults, which is
            // exactly how LegacyHomeBridge reproduces today's page.
            'hero' => [
                'key' => 'hero',
                'label' => 'Hero',
                'icon' => 'ti ti-layout-navbar',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [
                    ['name' => 'headline', 'type' => 'text', 'localized' => true, 'maxlen' => 160],
                    ['name' => 'subline', 'type' => 'textarea', 'localized' => true, 'maxlen' => 400],
                    ['name' => 'cta_label', 'type' => 'text', 'localized' => true, 'maxlen' => 60],
                    ['name' => 'cta_url', 'type' => 'url', 'localized' => false, 'maxlen' => 500],
                ],
            ],
            'cta_banner' => [
                'key' => 'cta_banner',
                'label' => 'CTA Banner',
                'icon' => 'ti ti-speakerphone',
                'max' => null,
                'version' => 1,
                'settings' => [
                    ['name' => 'style', 'type' => 'select', 'options' => ['solid', 'gradient'], 'default' => 'solid'],
                ],
                'content' => [
                    ['name' => 'headline', 'type' => 'text', 'localized' => true, 'maxlen' => 160],
                    ['name' => 'subline', 'type' => 'textarea', 'localized' => true, 'maxlen' => 400],
                    ['name' => 'cta_label', 'type' => 'text', 'localized' => true, 'maxlen' => 60],
                    ['name' => 'cta_url', 'type' => 'url', 'localized' => false, 'maxlen' => 500],
                ],
            ],
            'pricing_grid' => [
                'key' => 'pricing_grid',
                'label' => 'Pricing Grid',
                'icon' => 'ti ti-currency-dollar',
                'max' => 1,
                'version' => 1,
                'settings' => [
                    // Live platform plans (Plan status=1) — NOT ResellerPlan.
                    ['name' => 'source', 'type' => 'select', 'options' => ['platform_plans'], 'default' => 'platform_plans'],
                ],
                'content' => [
                    ['name' => 'heading', 'type' => 'text', 'localized' => true, 'maxlen' => 160],
                ],
            ],
            'faq_accordion' => [
                'key' => 'faq_accordion',
                'label' => 'FAQ',
                'icon' => 'ti ti-help',
                'max' => 1,
                'version' => 1,
                'settings' => [
                    ['name' => 'limit', 'type' => 'select', 'options' => ['5', '10', '20'], 'default' => '10'],
                ],
                'content' => [
                    ['name' => 'heading', 'type' => 'text', 'localized' => true, 'maxlen' => 160],
                ],
            ],
            'testimonials_wall' => [
                'key' => 'testimonials_wall',
                'label' => 'Testimonials',
                'icon' => 'ti ti-quote',
                'max' => 1,
                'version' => 1,
                'settings' => [
                    ['name' => 'limit', 'type' => 'select', 'options' => ['5', '10', '20'], 'default' => '10'],
                ],
                'content' => [
                    ['name' => 'heading', 'type' => 'text', 'localized' => true, 'maxlen' => 160],
                ],
            ],
            'blog_teasers' => [
                'key' => 'blog_teasers',
                'label' => 'Blog Teasers',
                'icon' => 'ti ti-news',
                'max' => 1,
                'version' => 1,
                'settings' => [
                    ['name' => 'limit', 'type' => 'select', 'options' => ['3', '6'], 'default' => '3'],
                ],
                'content' => [
                    ['name' => 'heading', 'type' => 'text', 'localized' => true, 'maxlen' => 160],
                ],
            ],
            'logo_marquee' => [
                'key' => 'logo_marquee',
                'label' => 'Restaurant Logo Marquee',
                'icon' => 'ti ti-photo',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'menu_studio' => [
                'key' => 'menu_studio',
                'label' => 'Menu Studio Composer',
                'icon' => 'ti ti-wand',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'section_order_journey' => [
                'key' => 'section_order_journey',
                'label' => 'Order Journey',
                'icon' => 'ti ti-route',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'section_product_fields' => [
                'key' => 'section_product_fields',
                'label' => 'Product Fields',
                'icon' => 'ti ti-forms',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'section_product_tour' => [
                'key' => 'section_product_tour',
                'label' => 'Product Tour',
                'icon' => 'ti ti-presentation',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'section_ai_suite' => [
                'key' => 'section_ai_suite',
                'label' => 'AI Suite',
                'icon' => 'ti ti-sparkles',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'section_payments_tax' => [
                'key' => 'section_payments_tax',
                'label' => 'Payments & Tax',
                'icon' => 'ti ti-credit-card',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'section_integrations' => [
                'key' => 'section_integrations',
                'label' => 'Integrations',
                'icon' => 'ti ti-plug',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'bio_pages' => [
                'key' => 'bio_pages',
                'label' => 'Bio Pages',
                'icon' => 'ti ti-link',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'section_audiences' => [
                'key' => 'section_audiences',
                'label' => 'Audiences',
                'icon' => 'ti ti-users',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'section_features_tabbed' => [
                'key' => 'section_features_tabbed',
                'label' => 'Features (Tabbed)',
                'icon' => 'ti ti-layout-list',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'section_app_screens' => [
                'key' => 'section_app_screens',
                'label' => 'App Screens',
                'icon' => 'ti ti-device-mobile',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'feature_icon_grid' => [
                'key' => 'feature_icon_grid',
                'label' => 'Feature Icon Grid',
                'icon' => 'ti ti-grid-dots',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'features_overview' => [
                'key' => 'features_overview',
                'label' => 'Features Overview',
                'icon' => 'ti ti-layout-grid',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'showcase' => [
                'key' => 'showcase',
                'label' => 'Before/After Showcase',
                'icon' => 'ti ti-photo-scan',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'features_timed' => [
                'key' => 'features_timed',
                'label' => 'Features (Timed Tabs)',
                'icon' => 'ti ti-clock',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'three_steps' => [
                'key' => 'three_steps',
                'label' => 'Three Steps',
                'icon' => 'ti ti-list-numbers',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'for_developers' => [
                'key' => 'for_developers',
                'label' => 'For Developers',
                'icon' => 'ti ti-code',
                'max' => 1,
                'version' => 1,
                'settings' => [],
                'content' => [],
            ],
            'richtext' => [
                'key' => 'richtext',
                'label' => 'Rich Text',
                'icon' => 'ti ti-text-caption',
                'max' => null,
                'version' => 1,
                'settings' => [],
                'content' => [
                    ['name' => 'body', 'type' => 'richtext', 'localized' => true, 'maxlen' => 20000],
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function types(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, mixed>|null */
    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /**
     * Forward-compatible sanitizer: drops unknown block types and unknown fields,
     * keeps only registry-declared settings/content keys, stamps the current def
     * version. Returns null for a junk block (unknown type) so the caller can
     * filter it out. Old published JSON must never 500 — renderers additionally
     * skip unknown types defensively.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    public static function validate(array $block): ?array
    {
        $type = $block['type'] ?? null;
        $def = is_string($type) ? self::get($type) : null;
        if (! $def) {
            return null;
        }

        $settingNames = array_column($def['settings'], 'name');
        $contentNames = array_column($def['content'], 'name');

        $out = [
            'id' => self::safeId($block['id'] ?? null),
            'type' => $type,
            'enabled' => (bool) ($block['enabled'] ?? true),
            'version' => (int) $def['version'],
            'settings' => [],
            'content' => [],
        ];

        foreach (($block['settings'] ?? []) as $k => $v) {
            if (in_array($k, $settingNames, true)) {
                $out['settings'][$k] = is_array($v) ? $v : (string) $v;
            }
        }
        foreach (($block['content'] ?? []) as $k => $v) {
            if (in_array($k, $contentNames, true)) {
                $out['content'][$k] = $v; // localized map or flat string, kept as-is
            }
        }

        return $out;
    }

    /**
     * Validate a whole blocks document; drop junk blocks, keep order. Also
     * enforces per-type 'max' (first N kept in document order).
     *
     * @param  mixed  $doc
     * @return array{version: int, blocks: array<int, array<string, mixed>>}
     */
    public static function validateDocument($doc): array
    {
        $blocks = is_array($doc) ? ($doc['blocks'] ?? []) : [];
        $clean = [];
        $counts = [];
        foreach ($blocks as $block) {
            if (! is_array($block) || ($v = self::validate($block)) === null) {
                continue;
            }
            $max = self::get($v['type'])['max'] ?? null;
            $counts[$v['type']] = ($counts[$v['type']] ?? 0) + 1;
            if ($max !== null && $counts[$v['type']] > $max) {
                continue;
            }
            $clean[] = $v;
        }

        return ['version' => 1, 'blocks' => $clean];
    }

    /**
     * Renderable check for the public renderer: known type AND stored version
     * not newer than the current def (schema-rot fence).
     *
     * @param  array<string, mixed>  $block
     */
    public static function renderable(array $block): bool
    {
        $type = $block['type'] ?? null;
        $def = is_string($type) ? self::get($type) : null;
        if (! $def) {
            return false;
        }

        return (int) ($block['version'] ?? 1) <= (int) $def['version'];
    }

    private static function safeId(mixed $id): string
    {
        if (is_string($id) && preg_match('/^blk_[a-f0-9]{4,8}$/', $id)) {
            return $id;
        }

        return 'blk_'.substr(bin2hex(random_bytes(4)), 0, 6);
    }
}
