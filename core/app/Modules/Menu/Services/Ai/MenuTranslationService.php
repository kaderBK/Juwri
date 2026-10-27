<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Ai;

use App\Models\MenuCategory;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Language;
use Juwri\Modules\Ai\Contracts\Translator;
use Juwri\Modules\Ai\Models\TranslationMemory;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;

/**
 * Fills MISSING or STALE menu translations for a restaurant's supported languages.
 * Writes into the existing `translations` object shape, with review metadata:
 *
 *   {"ar": {"title": "...", "description": "...",
 *           "_machine": true, "_src_hash": "<sha1 of source>", "_lowconf": false}}
 *
 * Rules:
 *  - Missing translation  -> fill.
 *  - Machine translation whose "_src_hash" no longer matches the current source
 *    (owner edited the base text) -> re-translate (#2 stale-on-edit).
 *  - Owner-edited translation (no _machine flag) -> never touched.
 *  - Batches all pending records for a language into as few AI calls as possible (#1).
 *  - Applies the restaurant glossary (#3) and tone (#7); flags low-confidence (#5).
 */
class MenuTranslationService
{
    public function __construct(protected Translator $translator) {}

    /**
     * @param  callable|null  $onProgress  fn(int $done, int $total, string $lang): void
     * @return array{languages: array<string>, categories: int, items: int, skipped: bool}
     */
    public function translateRestaurant(int $storeId, ?int $userId = null, ?callable $onProgress = null): array
    {
        $default = store_options($storeId, 'default_language');
        $codes = store_options($storeId, 'menu_languages');
        $codes = is_array($codes) ? $codes : (array) $codes;
        $targets = array_values(array_filter($codes, fn ($c) => $c && $c !== $default));

        if (empty($targets)) {
            return ['languages' => [], 'categories' => 0, 'items' => 0, 'skipped' => true];
        }

        $names = $this->languageNames(array_merge($targets, array_filter([$default])));
        $variants = $this->settingJson($storeId, 'translation_variants'); // code => label override
        $sourceName = $default ? ($names[$default] ?? $default) : null;

        $baseOpts = [
            'sourceLang' => $sourceName,
            'glossary' => $this->loadGlossary($storeId),
            'tone' => store_options($storeId, 'translation_tone') ?: null,
            'userId' => $userId,
            'storeId' => $storeId,
        ];

        // Load once, reuse across languages.
        $categories = MenuCategory::where('store_id', $storeId)->get();
        $items = Menu::where('store_id', $storeId)->get();
        $itemIds = $items->pluck('id')->all();
        // #4 broaden coverage: variant options + extras (title only).
        $variantOptions = MenuVariantOption::whereIn('menu_id', $itemIds)->get();
        $extras = MenuExtra::whereIn('menu_id', $itemIds)->get();

        $catChanged = [];
        $itemChanged = [];
        $voChanged = [];
        $exChanged = [];
        $total = count($targets);
        $done = 0;

        foreach ($targets as $code) {
            $langName = $variants[$code] ?? ($names[$code] ?? $code);

            $records = [];
            $meta = []; // key => [model, hash, fields]

            foreach ($categories as $cat) {
                $fields = $this->categoryFields($cat);
                if (! $fields) {
                    continue;
                }
                $hash = $this->hash($fields);
                if ($this->needs($cat->translations, $code, $hash)) {
                    $key = 'c'.$cat->id;
                    $records[$key] = $fields;
                    $meta[$key] = ['model' => $cat, 'hash' => $hash];
                }
            }

            foreach ($items as $item) {
                $fields = $this->itemFields($item);
                if (! $fields) {
                    continue;
                }
                $hash = $this->hash($fields);
                if ($this->needs($item->translations, $code, $hash)) {
                    $key = 'i'.$item->id;
                    $records[$key] = $fields;
                    $meta[$key] = ['model' => $item, 'hash' => $hash];
                }
            }

            foreach ($variantOptions as $vo) {
                $fields = $this->titleFields($vo);
                if (! $fields) {
                    continue;
                }
                $hash = $this->hash($fields);
                if ($this->needs($vo->translations, $code, $hash)) {
                    $key = 'v'.$vo->id;
                    $records[$key] = $fields;
                    $meta[$key] = ['model' => $vo, 'hash' => $hash];
                }
            }

            foreach ($extras as $ex) {
                $fields = $this->titleFields($ex);
                if (! $fields) {
                    continue;
                }
                $hash = $this->hash($fields);
                if ($this->needs($ex->translations, $code, $hash)) {
                    $key = 'e'.$ex->id;
                    $records[$key] = $fields;
                    $meta[$key] = ['model' => $ex, 'hash' => $hash];
                }
            }

            if (! empty($records)) {
                // #9 reuse owner-approved memory for this language before calling AI.
                $this->translator->useMemory(fn ($src, $lang) => TranslationMemory::lookup($storeId, $code, $src));
                try {
                    $translated = $this->translator->translateBatch($records, $langName, $baseOpts);
                } catch (\Throwable $e) {
                    Log::warning("[AI translate] batch {$storeId} -> {$code} failed: ".$e->getMessage());
                    $translated = [];
                }

                foreach ($translated as $key => $rec) {
                    if (! isset($meta[$key]) || empty($rec['title'])) {
                        continue;
                    }
                    $m = $meta[$key];
                    $entry = ['title' => $rec['title'], '_machine' => true, '_src_hash' => $m['hash']];
                    if (isset($rec['description'])) {
                        $entry['description'] = $rec['description'];
                    }
                    if (! empty($rec['_lowconf'])) {
                        $entry['_lowconf'] = true;
                    }

                    $model = $m['model'];
                    $translations = $this->asObject($model->translations);
                    $translations->{$code} = (object) $entry;
                    $model->translations = $translations;
                    if ($model instanceof MenuCategory) {
                        $catChanged[$model->id] = $model;
                    } elseif ($model instanceof MenuVariantOption) {
                        $voChanged[$model->id] = $model;
                    } elseif ($model instanceof MenuExtra) {
                        $exChanged[$model->id] = $model;
                    } else {
                        $itemChanged[$model->id] = $model;
                    }
                }
            }

            $done++;
            if ($onProgress) {
                $onProgress($done, $total, $code);
            }
        }

        foreach (array_merge($catChanged, $itemChanged, $voChanged, $exChanged) as $model) {
            $model->save();
        }

        return [
            'languages' => $targets,
            'categories' => count($catChanged),
            'items' => count($itemChanged),
            'options' => count($voChanged),
            'extras' => count($exChanged),
            'skipped' => false,
        ];
    }

    /** @return array<string,string>|null single 'title' field from a model's raw title. */
    protected function titleFields($model): ?array
    {
        $title = trim((string) $model->getRawOriginal('title'));

        return $title === '' ? null : ['title' => $title];
    }

    /** @return array<string,string>|null */
    protected function categoryFields(MenuCategory $cat): ?array
    {
        $name = trim((string) $cat->getRawOriginal('name'));
        if ($name === '') {
            return null;
        }

        return ['title' => $name];
    }

    /** @return array<string,string>|null */
    protected function itemFields(Menu $item): ?array
    {
        $name = trim((string) $item->getRawOriginal('name'));
        if ($name === '') {
            return null;
        }
        $fields = ['title' => $name];
        $desc = trim((string) $item->getRawOriginal('description'));
        if ($desc !== '') {
            $fields['description'] = $desc;
        }

        return $fields;
    }

    protected function hash(array $fields): string
    {
        return sha1(implode('|', array_values($fields)));
    }

    /** Does this language need (re)translation: missing, or stale machine output? */
    protected function needs($translations, string $code, string $hash): bool
    {
        $t = $this->asObject($translations);
        $entry = $t->{$code} ?? null;

        if (! $entry || trim((string) ($entry->title ?? '')) === '') {
            return true; // missing
        }
        // Stale: previously machine-translated, but the source changed.
        if (! empty($entry->_machine) && (($entry->_src_hash ?? null) !== $hash)) {
            return true;
        }

        return false; // owner-edited, or up-to-date machine
    }

    protected function asObject($translations): object
    {
        if (is_object($translations)) {
            return $translations;
        }
        if (is_array($translations)) {
            return (object) $translations;
        }

        return new \stdClass;
    }

    /** @return array<string,string> */
    protected function languageNames(array $codes): array
    {
        $codes = array_values(array_unique(array_filter($codes)));
        if (empty($codes)) {
            return [];
        }

        return Language::whereIn('code', $codes)->pluck('name', 'code')->toArray();
    }

    /**
     * Load the glossary. Accepts a JSON array (advanced) or a plain textarea:
     * one term per line, "term" (keep as-is) or "term = translation" (fixed).
     *
     * @return array<int,array{term:string,translation?:string}>
     */
    protected function loadGlossary(int $storeId): array
    {
        $raw = store_options($storeId, 'translation_glossary');
        if (is_array($raw) || is_object($raw)) {
            return $this->settingJson($storeId, 'translation_glossary');
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        if (str_starts_with($raw, '[')) { // JSON
            $d = json_decode($raw, true);

            return is_array($d) ? $d : [];
        }
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_contains($line, '=')) {
                [$term, $trans] = array_map('trim', explode('=', $line, 2));
                if ($term !== '') {
                    $out[] = $trans !== '' ? ['term' => $term, 'translation' => $trans] : ['term' => $term];
                }
            } else {
                $out[] = ['term' => $line];
            }
        }

        return $out;
    }

    /** Decode a JSON post-option to an array (safe). */
    protected function settingJson(int $storeId, string $key): array
    {
        $raw = store_options($storeId, $key);
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw)) {
            return json_decode(json_encode($raw), true) ?: [];
        }
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);

            return is_array($d) ? $d : [];
        }

        return [];
    }
}
