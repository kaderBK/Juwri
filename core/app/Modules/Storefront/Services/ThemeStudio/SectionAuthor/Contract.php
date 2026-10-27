<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor;

use App\Http\Controllers\DeveloperDocsController;
use Juwri\Modules\Storefront\Storefront\ThemeLinter;

/**
 * Theme Studio phase E1: the section-library contract handed to the model
 * (and enforced again by SectionGates). Everything machine-derived comes from
 * the engine's own sources of truth so the prompt can never drift from what
 * the gates accept:
 *
 *  - filters   ThemeLinter's closed whitelist (standard + platform filters)
 *  - tags      the standard Liquid tags + DeveloperDocsController::tagReference()
 *  - drops     DeveloperDocsController::dropReference() (reflection over Drops)
 *
 * The hook list, token list and rules are the library's written contract
 * (spec §2 "Every section MUST", tools/liquid-library/build.mjs).
 */
class Contract
{
    /** Hard CSS budget per section (spec §2 rule 6). */
    public const CSS_MAX_BYTES = 6144;

    /** Standard Liquid tags a library section may use. */
    public const STANDARD_TAGS = [
        'if', 'elsif', 'else', 'unless', 'case', 'when', 'for', 'break', 'continue', 'cycle', 'assign',
        'capture', 'increment', 'decrement', 'liquid', 'comment', 'raw', 'render', 'echo',
    ];

    /** The commerce runtime hooks a section may wire (commerce-runtime v2). */
    public const HOOKS = [
        'data-mb-open="{{ item.id }}"' => 'opens the item sheet / deep link — every item card MUST carry it',
        'data-mb-add="{{ item.id }}"' => 'add-to-cart button; ONLY inside {% if flags.allow_order %}',
        'data-mb-item="{{ item.id }}"' => 'marks a card as part of the searchable/filterable catalogue',
        'data-mb-name="{{ item.name | escape }}"' => 'item name for the runtime search',
        'data-mb-price="{{ item.price }}"' => 'machine-readable price (visible prices use | money)',
        'data-mb-cat="{{ category.id }}"' => 'category anchor / filter target',
        'data-mb-filter="<key>"' => 'dietary / attribute filter chip',
        'data-mb-search' => 'search input',
        'data-mb-open-cart' => 'opens the cart',
        'data-mb-cart-count' => 'live cart item count',
        'data-mb-cart-total' => 'live cart total',
        'data-mb-go="<view>"' => 'in-page router: go to a view (home, menu, cart, account, favs)',
        'data-mb-back' => 'in-page router: back',
        'data-mb-tab="<view>"' => 'tab-bar entry',
        'data-mb-fav="{{ item.id }}"' => 'favourite toggle',
        'data-mb-share' => 'native share of the store',
        'data-mb-set-lang="<code>"' => 'switch storefront language',
    ];

    /** Design tokens the shell emits; sections use them via var(--x, fallback). */
    public const TOKENS = [
        '--tc-primary', '--tc-primary-700', '--tc-primary-rgb', '--tc-on-primary', '--tc-accent', '--tc-on-accent',
        '--tc-accent-rgb', '--tc-background', '--tc-background-rgb', '--tc-surface', '--tc-text', '--tc-text-rgb',
        '--tc-text-muted', '--tc-success', '--tc-danger', '--tr-card', '--tr-control', '--tr-pill',
        '--ts-gap-1', '--ts-gap-2', '--ts-gap-3', '--ts-gap-4', '--ts-gap-5', '--tf-heading', '--tf-body',
    ];

    /**
     * The rules every generated section must follow (mirrors spec §2 "Every
     * section MUST" + the library CSS build contract). Each line is enforced
     * by a gate in SectionGates.
     *
     * @return list<string>
     */
    public static function rules(string $type = '<type>'): array
    {
        return [
            "Root element: exactly one <section class=\"mbs-{$type}\" …> with aria-labelledby (pointing at its heading) or aria-label. Every class you use starts with mbs-{$type} (BEM children mbs-{$type}__x, modifiers mbs-{$type}--x).",
            'No <script>, <style>, <link>, <iframe>, <object>, <embed>, <form action>, inline on*= handlers or javascript: URLs. No http(s):// URLs anywhere (liquid or css).',
            'Every visible fixed string goes through the t filter: {{ \'Book now\' | t }} (English key; supply the en + ar values in locales). Store nouns come from vocab.* (vocab.item, vocab.items, vocab.category …). No bare English text between tags or in alt/aria-label/placeholder/title attributes.',
            'Every merchant setting output is escaped: {{ section.settings.heading | escape }} / {{ block.settings.text | escape }} (select/checkbox/range/color values excepted).',
            'Prices render with | money (never a raw {{ item.price }}). Images: <img> with width, height, alt (alt from the item/setting name), loading="lazy", src via | media_url.',
            'Order/cart controls (data-mb-add, quantity, checkout CTAs) ONLY inside {% if flags.allow_order %}. Feature-backed UI ONLY when features.<name> is true (or via {% widget %}, which gates itself).',
            'Every item card carries data-mb-open="{{ item.id }}"; an add button carries data-mb-add="{{ item.id }}". Do NOT emit {% commerce %} (the theme does).',
            'Accessible: one heading (h2 by default), focusable controls are <a href> or <button type="button"> with visible text or aria-label, min 44px targets, lists use <ul role="list">.',
            'Render safely on an EMPTY store (no items, no hours, no settings): output nothing or a tidy empty state — never an error. Guard loops/sizes.',
            "CSS: every selector starts with .mbs-{$type}; LOGICAL properties only (margin-inline-start, padding-inline, inset-inline-start, text-align: start … never left/right, margin-left, float:left, translateX); colours ONLY as var(--tc-*, <fallback>); no url(), @import, @font-face, 100vh or other at-rules except @media/@supports/@container and @keyframes named mbs-{$type}-*; total ≤ ".self::CSS_MAX_BYTES.' bytes. Mobile-first, no horizontal overflow at 360px.',
            'Schema: {"name", "group", "verticals": [...], "settings": [{id,type,label,default}…], "blocks": [...], "presets": [{"name": …}]}. Setting types: text, textarea, richtext, checkbox, select (options [{value,label}]), range (min,max,step), number, color_token, image_picker, url, category, item_list.',
        ];
    }

    /** @return list<string> */
    public static function filters(): array
    {
        return (new class extends ThemeLinter
        {
            /** @return list<string> */
            public function exposed(): array
            {
                return array_values($this->allowedFilters());
            }
        })->exposed();
    }

    /** @return list<string> */
    public static function tags(): array
    {
        $custom = array_map(fn (array $t) => (string) $t['name'], DeveloperDocsController::tagReference());

        return array_values(array_unique(array_merge(self::STANDARD_TAGS, $custom)));
    }

    /**
     * Compact "Drop: prop, prop" reference, plus the root variables.
     *
     * @return array<string,string>
     */
    public static function drops(): array
    {
        $out = [];
        foreach (DeveloperDocsController::dropReference() as $drop => $props) {
            $out[$drop] = implode(', ', array_map(fn (array $p) => (string) $p['name'], $props));
        }

        return $out;
    }

    /** Root variables a section sees (ThemeContext::build). */
    public const ROOTS = [
        'restaurant' => 'StoreDrop (the store)', 'options' => 'OptionsDrop', 'settings' => 'theme settings',
        'menu' => 'MenuDrop (menu.categories, menu.items)', 'banners' => 'list<BannerDrop>', 'branches' => 'list<BranchDrop>',
        'localization' => 'LocalizationDrop', 'flags' => 'FlagsDrop (allow_order, delivery, takeaway …)',
        'vocab' => 'VocabDrop (item, items, category, add_to_cart …)', 'hours' => 'HoursDrop', 'stories' => 'list<StoryDrop>',
        'offers' => 'list<ItemDrop> on offer', 'social' => 'SocialDrop', 'announcement' => 'AnnouncementDrop|nil',
        'features' => 'FeaturesDrop (reservations, waitlist, loyalty, gift_cards, catering …)',
        'section' => 'SectionDrop (section.id, section.settings.*, section.blocks)',
    ];
}
