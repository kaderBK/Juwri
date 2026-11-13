<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Services\Ai;

use Illuminate\Support\Collection;
use Juwri\Modules\Ai\Contracts\Translator;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Throwable;

/**
 * BIOLINK-TEMPLATES-RUN (Lane B · T2-2): bulk "Translate page" for the bio
 * builder.
 *
 * For every block and every ENABLED locale that is not the base, fills the
 * translatable fields (block label + the config text keys) into
 * `config._i18n[locale][field]` — the exact override map BioLinkService::blocksFor
 * resolves at render (SPEC §3 i18n). It never overwrites a locale field the owner
 * already filled (skip non-empty), and it reuses TranslatorService::translateBatch
 * (dedupe + chunk + 30-day cache) with the metering tag `bio_translate`, so spend
 * is metered through the same seam (BYOK bypasses caps but still meters).
 *
 * Slow (one AI call per chunk per target locale) — always run from
 * TranslateBioBlocksJob on the `heavy` queue, never inline in a web request.
 */
class BioBlockTranslationService
{
    /** AiService metering tag for the translate calls (SPEC T2-2). */
    public const FEATURE = 'bio_translate';

    public function __construct(
        protected Translator $translator,
        protected BioLinkService $bio,
    ) {}

    /**
     * Translate every block of a store into each non-base enabled locale.
     *
     * @param  callable(int $done, int $total, string $locale):void|null  $progress
     * @return array{locales:array<int,string>,blocks:int}
     */
    public function translateStore(int $storeId, ?int $userId = null, ?callable $progress = null): array
    {
        $locales = $this->targetLocales($storeId);
        $blocks = BioBlock::where('store_id', $storeId)->get();

        $total = count($locales);
        $done = 0;
        foreach ($locales as $locale) {
            if ($blocks->isNotEmpty()) {
                $this->translateLocale($storeId, $blocks, $locale, $userId);
            }
            $done++;
            if ($progress) {
                $progress($done, $total, $locale);
            }
        }

        return ['locales' => array_values($locales), 'blocks' => $blocks->count()];
    }

    /**
     * The enabled locales to translate INTO: the store's active language set
     * minus the base (default) locale, whose strings live in the base
     * `label`/`config`.
     *
     * @return array<int,string>
     */
    public function targetLocales(int $storeId): array
    {
        $enabled = array_map('strval', (array) $this->safe(fn () => $this->bio->enabledLocales($storeId), []));
        $base = $this->baseLocale();

        return array_values(array_filter($enabled, fn ($code) => $code !== '' && $code !== $base));
    }

    /**
     * Translate every block's still-untranslated fields into ONE target locale
     * and write the results into `config._i18n[locale]`.
     *
     * @param  Collection<int,BioBlock>  $blocks
     */
    protected function translateLocale(int $storeId, Collection $blocks, string $locale, ?int $userId): void
    {
        // 1. Gather the fields still missing an override in this locale.
        $records = [];
        foreach ($blocks as $block) {
            $fields = $this->missingFields($block, $locale);
            if ($fields) {
                $records[(int) $block->id] = $fields;
            }
        }
        if (! $records) {
            return;
        }

        // 2. One batched AI pass (deduped + chunked), metered as bio_translate.
        $translated = $this->translator->translateBatch($records, $locale, [
            'userId' => $userId,
            'storeId' => $storeId,
            'feature' => self::FEATURE,
        ]);

        // 3. Write each result into config._i18n[locale], never clobbering a
        //    field the owner already filled.
        foreach ($blocks as $block) {
            $rec = $translated[(int) $block->id] ?? null;
            if (! is_array($rec)) {
                continue;
            }
            unset($rec['_lowconf']);
            if (! $rec) {
                continue;
            }
            $this->writeOverrides($block, $locale, $rec);
        }
    }

    /**
     * The translatable fields of a block whose override in $locale is still empty.
     *
     * @return array<string,string>
     */
    protected function missingFields(BioBlock $block, string $locale): array
    {
        $config = is_array($block->config) ? $block->config : [];
        $existing = is_array($config['_i18n'][$locale] ?? null) ? $config['_i18n'][$locale] : [];

        $fields = [];

        $label = trim((string) $block->label);
        if ($label !== '' && trim((string) ($existing['label'] ?? '')) === '') {
            $fields['label'] = $label;
        }

        foreach (BioLinkService::I18N_TEXT_KEYS as $key) {
            $val = trim((string) ($config[$key] ?? ''));
            if ($val !== '' && trim((string) ($existing[$key] ?? '')) === '') {
                $fields[$key] = $val;
            }
        }

        return $fields;
    }

    /**
     * Merge translated fields into the block's `config._i18n[locale]`, skipping
     * any field the owner already filled, then persist.
     *
     * @param  array<string,mixed>  $rec
     */
    protected function writeOverrides(BioBlock $block, string $locale, array $rec): void
    {
        $config = is_array($block->config) ? $block->config : [];
        $i18n = is_array($config['_i18n'] ?? null) ? $config['_i18n'] : [];
        $bucket = is_array($i18n[$locale] ?? null) ? $i18n[$locale] : [];

        $changed = false;
        foreach ($rec as $field => $val) {
            if (! is_string($val) || $val === '') {
                continue;
            }
            // Never overwrite an owner-filled field (skip non-empty).
            if (trim((string) ($bucket[$field] ?? '')) !== '') {
                continue;
            }
            $bucket[$field] = $val;
            $changed = true;
        }

        if ($changed) {
            $i18n[$locale] = $bucket;
            $config['_i18n'] = $i18n;
            $block->config = $config;
            $block->save();
        }
    }

    /** The base/default content locale (whose strings live in label/config). */
    protected function baseLocale(): string
    {
        try {
            if (class_exists(LaravelLocalization::class)) {
                return (string) LaravelLocalization::getDefaultLocale();
            }
        } catch (Throwable $e) {
            // fall through
        }

        return (string) config('app.locale', 'en');
    }

    /**
     * @template T
     *
     * @param  callable():T  $fn
     * @param  T  $default
     * @return T
     */
    private function safe(callable $fn, $default)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return $default;
        }
    }
}
