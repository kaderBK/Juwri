<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor;

use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Illuminate\Support\Str;

/**
 * Theme Studio phase E1: the `ai_section_author` shape of E2eStubAiService —
 * a deterministic, gate-passing section derived from the request, so feature
 * tests, E2E journeys and `studio:eval-sections` (default) never call a real
 * provider.
 *
 * The section: a heading, owner-typed highlight cards (blocks) and a rail of
 * up to four catalogue items wired to the commerce runtime (data-mb-open, and
 * data-mb-add only under flags.allow_order), en + ar strings.
 *
 * `[[stub-flaw]]` in the request makes the FIRST answer break three gates
 * (physical CSS, an unescaped setting, hard-coded text); the answer after a
 * GATE REPORT is clean — so the repair loop is exercised deterministically.
 */
class StubSectionAuthor
{
    public const FLAW_MARKER = '[[stub-flaw]]';

    /**
     * @param  array<mixed>  $messages
     * @return array<string,mixed>
     */
    public static function respond(array $messages): array
    {
        $request = [];
        $repairing = false;
        foreach ($messages as $m) {
            $content = is_string($m['content'] ?? null) ? $m['content'] : '';
            if (($m['role'] ?? '') === 'user' && preg_match('/<<<REQUEST\n(.*)\nREQUEST/s', $content, $mm)) {
                $request = json_decode($mm[1], true) ?: [];
            }
            if (($m['role'] ?? '') === 'user' && str_contains($content, 'GATE REPORT')) {
                $repairing = true;
            }
        }
        $text = (string) ($request['request'] ?? 'Highlights');
        $flawed = str_contains($text, self::FLAW_MARKER) && ! $repairing;

        $type = self::type(is_string($request['type'] ?? null) ? $request['type'] : '', $text);
        $name = Str::headline(str_replace('-', ' ', $type));
        $verticals = array_values(array_filter((array) ($request['verticals'] ?? []), 'is_string')) ?: ['all'];

        $c = 'mbs-'.$type;
        $headingOut = $flawed ? '{{ section.settings.heading }}' : '{{ heading | escape }}';
        $emptyText = $flawed ? 'Nothing here yet' : "{{ 'Nothing here yet' | t }}";

        $liquid = <<<LIQUID
            {%- comment -%}
              {$name} (AI-authored, stub): a heading, owner-typed highlight cards
              (blocks) and a rail of up to four {{ vocab.items }} from the catalogue.
            {%- endcomment -%}
            {%- liquid
              assign heading = section.settings.heading | strip
              if heading == ''
                assign heading = '{$name}' | t
              endif
              assign max_items = section.settings.limit | plus: 0 | at_least: 0 | at_most: 8
              assign cards = 0
              for block in section.blocks
                assign bt = block.settings.title | strip
                if bt != ''
                  assign cards = cards | plus: 1
                endif
              endfor
              assign item_count = menu.items | size
            -%}
            {%- if cards > 0 or item_count > 0 -%}
            <section class="{$c}" aria-labelledby="{$c}-{{ section.id | escape }}">
              <h2 class="{$c}__title" id="{$c}-{{ section.id | escape }}">{$headingOut}</h2>
              {%- if cards > 0 -%}
              <ul class="{$c}__cards" role="list">
                {%- for block in section.blocks -%}
                  {%- assign bt = block.settings.title | strip -%}
                  {%- if bt != '' -%}
                  <li class="{$c}__card">
                    <h3 class="{$c}__card-title">{{ bt | escape }}</h3>
                    {%- assign body = block.settings.text | strip -%}
                    {%- if body != '' -%}<p class="{$c}__card-text">{{ body | escape }}</p>{%- endif -%}
                  </li>
                  {%- endif -%}
                {%- endfor -%}
              </ul>
              {%- endif -%}
              {%- if item_count > 0 and max_items > 0 -%}
              <ul class="{$c}__items" role="list" aria-label="{{ vocab.items | escape }}">
                {%- for item in menu.items limit: max_items -%}
                <li class="{$c}__item">
                  <a class="{$c}__link" href="?item={{ item.id }}" data-mb-open="{{ item.id }}">
                    {%- if item.image -%}{%- assign src = item.image | media_url: 'menu' -%}<img class="{$c}__img" src="{{ src }}" alt="{{ item.name | escape }}" width="160" height="120" loading="lazy">{%- endif -%}
                    <span class="{$c}__name">{{ item.name | escape }}</span>
                  </a>
                  <span class="{$c}__price">{{ item.price | money }}</span>
                  {%- if flags.allow_order and item.is_sold_out == false -%}
                  <button type="button" class="{$c}__add" data-mb-add="{{ item.id }}" aria-label="{{ 'Add' | t }}: {{ item.name | escape }}">+</button>
                  {%- endif -%}
                </li>
                {%- endfor -%}
              </ul>
              {%- else -%}
              <p class="{$c}__empty">{$emptyText}</p>
              {%- endif -%}
            </section>
            {%- endif -%}
            LIQUID;

        $startProp = $flawed ? 'margin-left' : 'margin-inline-start';
        $css = <<<CSS
            .{$c} { max-inline-size: 1200px; margin-inline: auto; padding: var(--ts-gap-3, 16px); color: var(--tc-text, #141414); }
            .{$c}__title { margin: 0 0 var(--ts-gap-2, 8px); font-family: var(--tf-heading); font-size: 1.25rem; }
            .{$c}__cards { display: grid; gap: var(--ts-gap-2, 8px); grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); margin: 0 0 var(--ts-gap-3, 16px); padding: 0; list-style: none; }
            .{$c}__card { padding: var(--ts-gap-3, 16px); border-radius: var(--tr-card, 12px); background: var(--tc-surface, #f6f6f6); }
            .{$c}__card-title { margin: 0; font-size: 1rem; }
            .{$c}__card-text { {$startProp}: 0; margin-block: 4px 0; color: var(--tc-text-muted, #5b5b5b); }
            .{$c}__items { display: flex; gap: var(--ts-gap-2, 8px); margin: 0; padding: 0 0 4px; list-style: none; overflow-x: auto; scroll-snap-type: x mandatory; }
            .{$c}__item { position: relative; flex: 0 0 min(46%, 180px); scroll-snap-align: start; }
            .{$c}__link { display: block; color: inherit; text-decoration: none; }
            .{$c}__img { display: block; inline-size: 100%; block-size: auto; aspect-ratio: 4 / 3; object-fit: cover; border-radius: var(--tr-card, 12px); }
            .{$c}__name { display: block; margin-block-start: 6px; font-weight: 600; }
            .{$c}__price { color: var(--tc-text-muted, #5b5b5b); }
            .{$c}__add { position: absolute; inset-block-start: 6px; inset-inline-end: 6px; min-inline-size: 44px; min-block-size: 44px; border: 0; border-radius: var(--tr-pill, 999px); background: var(--tc-primary, #1f6feb); color: var(--tc-on-primary, #fff); font-size: 1.25rem; cursor: pointer; }
            .{$c}__empty { margin: 0; color: var(--tc-text-muted, #5b5b5b); }
            CSS;

        return [
            'type' => $type,
            'liquid' => $liquid,
            'css' => $css,
            'schema' => [
                'name' => $name,
                'group' => 'content',
                'verticals' => $verticals,
                'settings' => [
                    ['id' => 'heading', 'type' => 'text', 'label' => 'Heading (blank = automatic)', 'default' => ''],
                    ['id' => 'limit', 'type' => 'range', 'label' => 'Items to show', 'min' => 0, 'max' => 8, 'step' => 1, 'default' => 4],
                ],
                'blocks' => [[
                    'type' => 'card', 'name' => 'Highlight', 'limit' => 6,
                    'settings' => [
                        ['id' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => ''],
                        ['id' => 'text', 'type' => 'textarea', 'label' => 'Text', 'default' => ''],
                    ],
                ]],
                'presets' => [['name' => $name, 'blocks' => [
                    ['type' => 'card', 'settings' => ['title' => 'Fresh every day', 'text' => 'Made to order.']],
                    ['type' => 'card', 'settings' => ['title' => 'Fast pickup', 'text' => 'Ready in minutes.']],
                ]]],
            ],
            'locales' => [
                'en' => [$name => $name, 'Nothing here yet' => 'Nothing here yet', 'Highlight' => 'Highlight', 'Add' => 'Add'],
                'ar' => [$name => 'قسم '.$name, 'Nothing here yet' => 'لا يوجد شيء بعد', 'Highlight' => 'ميزة', 'Add' => 'أضف'],
            ],
            'fixture_notes' => 'Renders the heading, the preset highlight cards and up to four catalogue items on every demo store; '
                .'the add button appears only when ordering is on. An empty store (no items, no cards) renders nothing.',
        ];
    }

    /** A new library type from the hint or the request's first words. */
    protected static function type(string $hint, string $text): string
    {
        $base = Str::slug($hint !== '' ? $hint : implode(' ', array_slice(
            array_values(array_filter(preg_split('/\s+/', (string) preg_replace('/\[\[.*?\]\]|[^a-z0-9\s]/i', ' ', strtolower($text))) ?: [], fn ($w) => strlen($w) > 2)),
            0, 3
        )));
        $base = substr($base !== '' ? $base : 'ai-highlights', 0, 32);
        if (strlen($base) < 3) {
            $base = 'ai-'.$base.'-section';
        }
        $library = SectionLibrary::forVersion('1');
        if ($library && $library->sectionSource($base) !== null) {
            $base .= '-ai';
        }

        return trim($base, '-');
    }
}
