<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio;

use App\Services\Verticals\VerticalRegistry;

/**
 * Theme Studio B2: vertical-aware starting layouts ("recipes") that ship with
 * the section library — `resources/liquid-library/<libver>/recipes/<vertical>/<name>.json`:
 *
 *   {name, description, vertical, priority, tokens{colors.*, layout.*}, font_pair,
 *    translations{ar:{name, description}},
 *    layout{sections, order, theme{settings{}}}}
 *
 * Used by the Studio starter themes (their templates/index.json is a recipe
 * layout), by "new theme" in the editor (phase C) and as the safe skeleton the
 * AI theme builder starts from (phase D2 step 2: vertical → candidates,
 * priority → ranking).
 *
 * Recipe ids are `<vertical>/<name>`, both slug-shaped and regex-guarded, so
 * an id can never carry a path segment.
 */
class Recipes
{
    public const ID_PATTERN = '/^([a-z0-9][a-z0-9-]{0,39})\/([a-z0-9][a-z0-9-]{0,39})$/';

    public const PRIORITIES = ['fast_ordering', 'brand_story', 'browse', 'bookings'];

    /**
     * Store type → recipe verticals to offer, best first. Bakeries are food
     * stores without a store type of their own, so they share the food group
     * (cafés have had a store type since Phase 3); specialty goods (car
     * parts, pharmacy) keep their own catalogue sections first and fall back
     * to the general shop. Grocery and services have no recipe yet and borrow
     * the closest ones (retail, laundry).
     */
    public const CANDIDATES = [
        'restaurant' => ['restaurant', 'cafe', 'bakery'],
        'cafe' => ['cafe', 'bakery', 'restaurant'],
        'bakery' => ['bakery', 'cafe', 'restaurant'],
        'retail' => ['retail', 'clothing', 'cosmetic'],
        'cosmetic' => ['cosmetic', 'clothing', 'retail'],
        'clothing' => ['clothing', 'cosmetic', 'retail'],
        'carparts' => ['carparts', 'retail'],
        'pharmacy' => ['pharmacy', 'retail'],
        'grocery' => ['retail', 'cosmetic'],
        'laundry' => ['laundry'],
        'services' => ['laundry'],
        'hotel' => ['hotel'],
    ];

    /** Vertical group → the store type whose candidates it falls back to. */
    public const GROUP_FALLBACK = [
        'food' => 'restaurant',
        'goods' => 'retail',
        'services' => 'laundry',
        'stays' => 'hotel',
    ];

    /** Recipe vertical → the DemoStorefront dataset that exercises it. */
    public const DEMO_DATASET = [
        'restaurant' => 'restaurant',
        'cafe' => 'restaurant',
        'bakery' => 'restaurant',
        'retail' => 'retail',
        'cosmetic' => 'retail',
        'clothing' => 'retail',
        'carparts' => 'carparts',
        'pharmacy' => 'pharmacy',
        'laundry' => 'laundry',
        'hotel' => 'hotel',
        // P3-00 store types without a recipe of their own preview on the closest dataset.
        'grocery' => 'retail',
        'services' => 'laundry',
    ];

    public const DEFAULT_LIBRARY = '1';

    /** @var array<string,array<string,array<string,mixed>>> library version → id → recipe */
    protected static array $memo = [];

    /** \Juwri\Core\Support\RequestEpoch::id() the memo belongs to — a fresh app (next test, worker restart) starts clean. */
    protected static ?int $memoApp = null;

    /** Absolute recipes dir for a library version. */
    public static function path(string $library = self::DEFAULT_LIBRARY): string
    {
        return resource_path('liquid-library/'.$library.'/recipes');
    }

    /**
     * Every recipe, keyed by id, sorted by id. Malformed files are skipped.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(string $library = self::DEFAULT_LIBRARY): array
    {
        $appId = \Juwri\Core\Support\RequestEpoch::id();
        if (static::$memoApp !== $appId) {
            static::$memoApp = $appId;
            static::$memo = [];
        }
        if (isset(static::$memo[$library])) {
            return static::$memo[$library];
        }

        $out = [];
        if (preg_match('/^[0-9]{1,4}$/', $library)) {
            foreach (glob(static::path($library).'/*/*.json') ?: [] as $file) {
                $id = basename(dirname($file)).'/'.basename($file, '.json');
                $recipe = static::load($id, $file);
                if ($recipe !== null) {
                    $out[$id] = $recipe;
                }
            }
        }
        ksort($out);

        return static::$memo[$library] = $out;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $id, string $library = self::DEFAULT_LIBRARY): ?array
    {
        if (! preg_match(self::ID_PATTERN, $id)) {
            return null;
        }

        return static::all($library)[$id] ?? null;
    }

    /**
     * Candidate recipes for a store type, best first: its own vertical, then
     * the related ones (CANDIDATES), a vertical group's fallback for store
     * types without a list of their own, and the registry default for unknown
     * types. Within one vertical, recipes matching $priority come first.
     *
     * @return list<array<string,mixed>>
     */
    public static function forVertical(string $storeType, ?string $priority = null, string $library = self::DEFAULT_LIBRARY): array
    {
        $verticals = static::candidateVerticals($storeType);
        $rank = array_flip($verticals);

        $list = array_values(array_filter(
            static::all($library),
            static fn (array $r) => isset($rank[$r['vertical']])
        ));

        usort($list, static function (array $a, array $b) use ($rank, $priority): int {
            return [$rank[$a['vertical']], $priority !== null && $a['priority'] !== $priority, $a['id']]
                <=> [$rank[$b['vertical']], $priority !== null && $b['priority'] !== $priority, $b['id']];
        });

        return $list;
    }

    /**
     * The recipe's layout as a normalized layout document (LayoutDocument
     * shape), or null for an unknown id.
     *
     * @return array<string,mixed>|null
     */
    public static function layout(string $id, string $library = self::DEFAULT_LIBRARY): ?array
    {
        $recipe = static::find($id, $library);

        return $recipe === null ? null : LayoutDocument::normalize($recipe['layout']);
    }

    /**
     * Store-level token values for a recipe (the `colors.*` / `layout.*`
     * roles plus `fonts.pair`), in the flat shape theme_settings stores.
     *
     * @return array<string,string>|null
     */
    public static function tokens(string $id, string $library = self::DEFAULT_LIBRARY): ?array
    {
        $recipe = static::find($id, $library);
        if ($recipe === null) {
            return null;
        }

        $tokens = $recipe['tokens'];
        if ($recipe['font_pair'] !== null) {
            $tokens['fonts.pair'] = $recipe['font_pair'];
        }

        return $tokens;
    }

    /** @return list<string> */
    public static function candidateVerticals(string $storeType): array
    {
        $storeType = strtolower(trim($storeType));
        if (isset(self::CANDIDATES[$storeType])) {
            return self::CANDIDATES[$storeType];
        }

        $registry = app(VerticalRegistry::class);
        if ($storeType !== '' && $registry->exists($storeType)) {
            $group = (string) ($registry->config($storeType)['group'] ?? '');
            if (isset(self::GROUP_FALLBACK[$group])) {
                return array_values(array_unique(array_merge(
                    [$storeType],
                    self::CANDIDATES[self::GROUP_FALLBACK[$group]],
                )));
            }
        }

        return self::CANDIDATES[$registry->default()] ?? self::CANDIDATES['restaurant'];
    }

    /** Test seam: forget loaded recipes after a fixture writes one. */
    public static function flush(): void
    {
        static::$memo = [];
    }

    /** @return array<string,mixed>|null */
    protected static function load(string $id, string $file): ?array
    {
        if (! preg_match(self::ID_PATTERN, $id, $m)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data) || ! is_array($data['layout'] ?? null) || ! is_array($data['layout']['sections'] ?? null)) {
            return null;
        }
        if (($data['vertical'] ?? $m[1]) !== $m[1]) {
            return null; // the file must live under its own vertical
        }

        $priority = (string) ($data['priority'] ?? 'browse');
        $fontPair = isset($data['font_pair']) && is_string($data['font_pair']) ? $data['font_pair'] : null;

        return [
            'id' => $id,
            'vertical' => $m[1],
            'name' => (string) ($data['name'] ?? $m[2]),
            'description' => (string) ($data['description'] ?? ''),
            'priority' => in_array($priority, self::PRIORITIES, true) ? $priority : 'browse',
            'tokens' => array_map('strval', array_filter(
                is_array($data['tokens'] ?? null) ? $data['tokens'] : [],
                static fn ($v, $k) => is_string($k) && is_scalar($v),
                ARRAY_FILTER_USE_BOTH,
            )),
            'font_pair' => $fontPair,
            'translations' => is_array($data['translations'] ?? null) ? $data['translations'] : [],
            'layout' => $data['layout'],
        ];
    }
}
