<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\SitePages;

use App\Models\SitePage;
use Juwri\Core\Support\Html;
use Juwri\Modules\Content\Services\SitePages\SiteBlockRegistry;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;

/**
 * HOME-PAGES HP-44: AI translation for site-page block documents. Walks the
 * SiteBlockRegistry schema (never the reseller registry) and delegates the
 * actual translation to the shared StringMapTranslator::translateMap (public,
 * provider-chained, char-capped) — the reseller leaf service is REUSED, not
 * modified (KICKOFF rule 2).
 */
class SiteTranslator
{
    public function __construct(private StringMapTranslator $translate) {}

    /**
     * @return array{version: int, blocks: array<int, array<string, mixed>>}
     *
     * @throws AiProviderFailure
     */
    public function translatePage(SitePage $page, string $source, string $target, string $mode = 'missing'): array
    {
        $doc = ($page->blocks && isset($page->blocks['blocks'])) ? $page->blocks : ['version' => 1, 'blocks' => []];
        $blocks = $doc['blocks'];

        $strings = [];
        $richKeys = [];
        foreach ($blocks as $i => $block) {
            $def = SiteBlockRegistry::get($block['type'] ?? '');
            if (! $def) {
                continue;
            }
            foreach ($def['content'] as $f) {
                if (empty($f['localized'])) {
                    continue;
                }
                $name = $f['name'];
                $srcVal = $block['content'][$name][$source] ?? '';
                if (trim((string) $srcVal) === '') {
                    continue;
                }
                if ($mode === 'missing' && trim((string) ($block['content'][$name][$target] ?? '')) !== '') {
                    continue; // manual target edit wins
                }
                $key = "b{$i}.{$name}";
                $strings[$key] = (string) $srcVal;
                if (($f['type'] ?? '') === 'richtext') {
                    $richKeys[$key] = true;
                }
            }
        }

        $translated = $this->translate->translateMap($strings, $source, $target);

        foreach ($translated as $key => $val) {
            [$bi, $name] = explode('.', $key, 2);
            $bi = (int) substr($bi, 1);
            if (! isset($blocks[$bi])) {
                continue;
            }
            if (! isset($blocks[$bi]['content'][$name]) || ! is_array($blocks[$bi]['content'][$name])) {
                $blocks[$bi]['content'][$name] = [];
            }
            $blocks[$bi]['content'][$name][$target] = isset($richKeys[$key])
                ? Html::clean((string) $val, 'richtext')
                : (string) $val;
        }

        return ['version' => 1, 'blocks' => $blocks];
    }
}
