<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Console\Commands;

use App\Models\StoreOption;
use Illuminate\Console\Command;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use RuntimeException;

/**
 * BL-037d: eight synthetic demo stores, one per reference store shape, so a
 * theme can be screenshot-compared against a menu of a KNOWN size instead of
 * against whatever the showcase seeder happens to produce.
 *
 * The content is the showcase data file of the theme (velora / crave / crumb /
 * scoop), reshaped to the shape: the first N top-level categories (cloned when
 * the file has fewer) and exactly M items (trimmed or cloned). Every store is
 * then built by ThemeShowcaseSeeder, so a parity store carries the same depth
 * as a showcase one — photos, galleries, option groups, add-ons, reviews.
 *
 *   php artisan juwri:theme-parity-seed
 *
 * Local/testing only: the command refuses to run in production. It is
 * idempotent — a second run wipes and rebuilds the same eight stores.
 *
 * @phpstan-type PoolEntry array{item: array<string, mixed>, copy: int, source: int}
 * @phpstan-type KeptChild array{category: array<string, mixed>, items: list<PoolEntry>}
 * @phpstan-type KeptCategory array{category: array<string, mixed>, own: list<PoolEntry>, children: list<KeptChild>}
 */
class ThemeParitySeed extends Command
{
    protected $signature = 'juwri:theme-parity-seed {--showcase : keep every showcase extra (stories, banners, offers) instead of the per-reference lean shape}';

    protected $description = 'Seed the eight synthetic theme parity demo stores (one per reference store shape)';

    /**
     * The reference shapes: slug => template, top-level categories, total items
     * and the storefront's default language.
     *
     * @var array<string, array{template: string, categories: int, items: int, language: string, banners: bool, splash: bool}>
     */
    private const SHAPES = [
        'parity-velora-a' => ['template' => 'velora', 'categories' => 6, 'items' => 56, 'language' => 'ar', 'banners' => true, 'splash' => false],
        'parity-velora-b' => ['template' => 'velora', 'categories' => 7, 'items' => 56, 'language' => 'ar', 'banners' => true, 'splash' => false],
        'parity-crave-a' => ['template' => 'crave', 'categories' => 6, 'items' => 56, 'language' => 'ar', 'banners' => false, 'splash' => false],
        'parity-crave-b' => ['template' => 'crave', 'categories' => 6, 'items' => 42, 'language' => 'ar', 'banners' => false, 'splash' => true],
        'parity-crumb-a' => ['template' => 'crumb', 'categories' => 5, 'items' => 12, 'language' => 'ar', 'banners' => false, 'splash' => true],
        'parity-crumb-b' => ['template' => 'crumb', 'categories' => 5, 'items' => 12, 'language' => 'ar', 'banners' => false, 'splash' => true],
        'parity-scoop-a' => ['template' => 'scoop', 'categories' => 7, 'items' => 56, 'language' => 'ar', 'banners' => false, 'splash' => true],
        'parity-scoop-b' => ['template' => 'scoop', 'categories' => 5, 'items' => 33, 'language' => 'ar', 'banners' => false, 'splash' => true],
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('juwri:theme-parity-seed refuses to run in production — it writes demo stores.');

            return self::FAILURE;
        }

        $seeder = new ThemeShowcaseSeeder;
        $owner = $seeder->owner();

        // Demo stores are install-time fixture data, not owner growth: the
        // edition's store limits do not apply to them (the same bypass the
        // db:seed run gets).
        StoreLimitGuard::bypass(function () use ($seeder, $owner): void {
            foreach (self::SHAPES as $slug => $shape) {
                $spec = $this->loadSpec($shape['template']);
                $spec = $this->reshape($spec, $shape['categories'], $shape['items']);
                $spec['store']['title'] = $this->storeTitle($slug, $shape['template']);

                // Lean: the circle rail (stories) and the offers strip are off for every reference
                // (none of the live pages has them); hero banners only where the live page has a hero
                // carousel (velora); the intro splash only where the live page opens on one.
                $features = $this->option('showcase')
                    ? []
                    : ['banners' => $shape['banners'], 'stories' => false, 'offers' => false, 'splash' => $shape['splash']];
                $seeder->buildProStore($owner, $shape['template'], $spec, $slug, $features);

                $this->language($slug, $shape['language']);
                if ($shape['template'] === 'velora' && ! $this->option('showcase')) {
                    $this->wordmarkCover($slug, $spec['store']['title']);
                }

                $this->line(sprintf(
                    '  %-15s %-7s %2d categories  %2d items',
                    $slug,
                    $shape['template'],
                    $shape['categories'],
                    $shape['items'],
                ));
            }
        });

        $this->info('Seeded '.count(self::SHAPES).' theme parity stores.');

        return self::SUCCESS;
    }

    /**
     * The storefront language of a parity store: the shape's language first, the
     * other one second, so the language switcher offers both.
     */
    private function language(string $slug, string $language): void
    {
        $store = Store::where('slug', $slug)->first();

        if (! $store) {
            return;
        }

        $other = $language === 'ar' ? 'en' : 'ar';

        StoreOption::updateStoreOption((int) $store->id, 'menu_languages', json_encode([$language, $other]));
        StoreOption::updateStoreOption((int) $store->id, 'default_language', $language);
    }

    /**
     * Velora has no logo slot on its cover: the cover is one image, and a reference store's wordmark is part of
     * that image. So the parity store gets a synthetic cover with a centred wordmark (a plain SVG, no real brand).
     */
    private function wordmarkCover(string $slug, string $title): void
    {
        $store = Store::where('slug', $slug)->first();
        if (! $store) {
            return;
        }
        $dir = public_path('storage/store/cover');
        @mkdir($dir, 0755, true);
        $file = $slug.'-wordmark.svg';
        $text = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        file_put_contents($dir.'/'.$file, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 500" preserveAspectRatio="xMidYMid slice">'
            .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#1d3a8a"/><stop offset="1" stop-color="#0b1d4d"/></linearGradient></defs>'
            .'<rect width="1200" height="500" fill="url(#g)"/>'
            .'<text x="600" y="270" text-anchor="middle" font-family="Georgia,serif" font-size="84" font-weight="700" fill="#f5d77a">'.$text.'</text></svg>');
        $store->forceFill(['cover_image' => $file])->save();
    }

    /** "parity-velora-a" + "velora" => "Parity Velora A". */
    private function storeTitle(string $slug, string $template): string
    {
        $parts = explode('-', $slug);
        $suffix = strtoupper((string) end($parts));

        return 'Parity '.ucfirst($template).' '.$suffix;
    }

    /**
     * The theme's own showcase data file.
     *
     * @return array<string, mixed>
     */
    private function loadSpec(string $template): array
    {
        $path = database_path("seeders/data/showcase/$template.php");

        if (! is_file($path)) {
            throw new RuntimeException("Missing showcase data file for theme [$template].");
        }

        $spec = include $path;

        if (! is_array($spec)) {
            throw new RuntimeException("Broken showcase data file for theme [$template].");
        }

        return $spec;
    }

    /* ============================ RESHAPING ============================ */

    /**
     * Reshape a theme's spec into the requested shape: the first N top-level
     * categories (cloned, with a suffixed name, when the file has fewer) and
     * exactly M items (trimmed or cloned).
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function reshape(array $spec, int $targetCategories, int $targetItems): array
    {
        $source = is_array($spec['categories'] ?? null) ? array_values($spec['categories']) : [];

        // Every source item, in file order, with the id of the item it was
        // copied from: a second copy of the same item is named " (3)".
        $copies = [];
        $sourceId = 0;
        $categories = [];

        foreach ($source as $category) {
            if (! is_array($category)) {
                continue;
            }

            $categories[] = [
                'category' => $category,
                'own' => $this->entries($this->itemsOf($category), $sourceId),
                'children' => $this->childEntries($category, $sourceId),
            ];
        }

        // Keep the first N top-level categories; clone them (name suffixed, items
        // copied) when the file has fewer than N.
        $kept = [];
        $cloneNumbers = array_fill(0, max(count($categories), 1), 1);
        for ($i = 0; $i < $targetCategories; $i++) {
            $index = count($categories) > 0
                ? ($i < count($categories) ? $i : ($i - count($categories)) % count($categories))
                : 0;

            if (! isset($categories[$index])) {
                continue;
            }

            if ($i < count($categories)) {
                $kept[] = $categories[$index];

                continue;
            }

            $no = ++$cloneNumbers[$index];
            $kept[] = $this->cloneCategory($categories[$index], $no, $copies);
        }

        $kept = $this->fitItems($kept, $targetItems, $copies);

        $spec['categories'] = $this->rebuild($kept);

        return $spec;
    }

    /**
     * Bring the kept categories to exactly the wanted item count: trim the
     * fullest categories first (so every category keeps at least one item and
     * the menu stays balanced), then clone items until the count is reached.
     *
     * @param  list<KeptCategory>  $kept
     * @param  array<int, int>  $copies
     * @return list<KeptCategory>
     */
    private function fitItems(array $kept, int $targetItems, array &$copies): array
    {
        $total = $this->countItems($kept);

        while ($total > $targetItems) {
            $best = -1;
            $bestCount = 0;

            foreach ($kept as $i => $category) {
                $count = $this->countItems([$category]);

                if ($count > $bestCount) {
                    $bestCount = $count;
                    $best = $i;
                }
            }

            // Every category is down to its last item: keep them, the shape is
            // the closest this data can get.
            if ($best === -1 || $bestCount <= 1) {
                break;
            }

            $kept[$best] = $this->dropLastItem($kept[$best]);
            $total--;
        }

        if ($total < $targetItems && $kept !== []) {
            $sources = $this->cloneSources($kept);
            $cursor = 0;

            while ($total < $targetItems && $sources !== []) {
                $source = $sources[$cursor % count($sources)];
                $cursor++;

                $entry = $source['entry'];
                $copy = ($copies[$entry['source']] ?? 1) + 1;
                $copies[$entry['source']] = $copy;

                // The copy lands next to the item it was copied from, so a
                // padded category stays about the same food.
                $new = ['item' => $entry['item'], 'copy' => $copy, 'source' => $entry['source']];
                if ($source['child'] === null) {
                    $kept[$source['category']]['own'][] = $new;
                } else {
                    $kept[$source['category']]['children'][$source['child']]['items'][] = $new;
                }

                $total++;
            }
        }

        return $kept;
    }

    /**
     * The items a store can be padded with, category by category (round robin),
     * so a padded store grows evenly instead of filling up its first category.
     *
     * @param  list<KeptCategory>  $kept
     * @return list<array{entry: PoolEntry, category: int, child: int|null}>
     */
    private function cloneSources(array $kept): array
    {
        $groups = [];

        foreach ($kept as $ci => $category) {
            $entries = [];

            foreach ($category['own'] as $entry) {
                $entries[] = ['entry' => $entry, 'category' => $ci, 'child' => null];
            }

            foreach ($category['children'] as $chi => $child) {
                foreach ($child['items'] as $entry) {
                    $entries[] = ['entry' => $entry, 'category' => $ci, 'child' => $chi];
                }
            }

            $groups[] = $entries;
        }

        $sources = [];
        $deepest = 0;

        foreach ($groups as $entries) {
            $deepest = max($deepest, count($entries));
        }

        for ($i = 0; $i < $deepest; $i++) {
            foreach ($groups as $entries) {
                if (isset($entries[$i])) {
                    $sources[] = $entries[$i];
                }
            }
        }

        return $sources;
    }

    /**
     * Take the last item off the fullest group of one category.
     *
     * @param  KeptCategory  $category
     * @return KeptCategory
     */
    private function dropLastItem(array $category): array
    {
        $groups = [];

        if ($category['own'] !== []) {
            $groups['own'] = count($category['own']);
        }

        foreach ($category['children'] as $i => $child) {
            $groups['child'.$i] = count($child['items']);
        }

        arsort($groups);
        $key = (string) array_key_first($groups);

        if ($key === 'own') {
            array_pop($category['own']);

            return $category;
        }

        $index = (int) substr($key, 5);
        array_pop($category['children'][$index]['items']);

        return $category;
    }

    /**
     * Rebuild the spec's categories from the kept ones, materialising the items
     * (a copy carries its name suffix).
     *
     * @param  list<KeptCategory>  $kept
     * @return list<array<string, mixed>>
     */
    private function rebuild(array $kept): array
    {
        $categories = [];

        foreach ($kept as $category) {
            $out = $category['category'];
            $out['items'] = array_map(fn (array $entry): array => $this->materialise($entry), $category['own']);

            $children = [];

            foreach ($category['children'] as $child) {
                // A child that lost every item disappears instead of showing up
                // as an empty sub-menu.
                if ($child['items'] === []) {
                    continue;
                }

                $childOut = $child['category'];
                $childOut['items'] = array_map(fn (array $entry): array => $this->materialise($entry), $child['items']);
                $children[] = $childOut;
            }

            $out['children'] = $children;
            $categories[] = $out;
        }

        return $categories;
    }

    /**
     * Copy a category (and its children) with a suffixed name and copied items.
     *
     * @param  KeptCategory  $category
     * @param  array<int, int>  $copies
     * @return KeptCategory
     */
    private function cloneCategory(array $category, int $no, array &$copies): array
    {
        $own = [];

        foreach ($category['own'] as $entry) {
            $own[] = $this->cloneEntry($entry, $copies);
        }

        $children = [];

        foreach ($category['children'] as $child) {
            $items = [];

            foreach ($child['items'] as $entry) {
                $items[] = $this->cloneEntry($entry, $copies);
            }

            $children[] = [
                'category' => $this->suffixCategory($child['category'], $no),
                'items' => $items,
            ];
        }

        return [
            'category' => $this->suffixCategory($category['category'], $no),
            'own' => $own,
            'children' => $children,
        ];
    }

    /**
     * @param  PoolEntry  $entry
     * @param  array<int, int>  $copies
     * @return PoolEntry
     */
    private function cloneEntry(array $entry, array &$copies): array
    {
        $copy = ($copies[$entry['source']] ?? 1) + 1;
        $copies[$entry['source']] = $copy;

        return ['item' => $entry['item'], 'copy' => $copy, 'source' => $entry['source']];
    }

    /**
     * A copied item carries the copy number in its name (English and Arabic),
     * so no two items of a store share a name.
     *
     * @param  PoolEntry  $entry
     * @return array<string, mixed>
     */
    private function materialise(array $entry): array
    {
        $item = $entry['item'];

        if ($entry['copy'] < 2) {
            return $item;
        }

        $suffix = ' ('.$entry['copy'].')';
        $name = is_array($item['n'] ?? null) ? $item['n'] : [];

        $item['n'] = [
            'en' => (string) ($name['en'] ?? '').$suffix,
            'ar' => (string) ($name['ar'] ?? '').$suffix,
        ];

        return $item;
    }

    /**
     * A copied category carries the copy number in its name.
     *
     * @param  array<string, mixed>  $category
     * @return array<string, mixed>
     */
    private function suffixCategory(array $category, int $no): array
    {
        $name = is_array($category['n'] ?? null) ? $category['n'] : [];

        $category['n'] = [
            'en' => (string) ($name['en'] ?? '').' '.$no,
            'ar' => (string) ($name['ar'] ?? '').' '.$no,
        ];

        return $category;
    }

    /**
     * @param  list<KeptCategory>  $kept
     */
    private function countItems(array $kept): int
    {
        $total = 0;

        foreach ($kept as $category) {
            $total += count($category['own']);

            foreach ($category['children'] as $child) {
                $total += count($child['items']);
            }
        }

        return $total;
    }

    /**
     * The items of a category as pool entries.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return list<PoolEntry>
     */
    private function entries(array $items, int &$sourceId): array
    {
        $entries = [];

        foreach ($items as $item) {
            $entries[] = ['item' => $item, 'copy' => 1, 'source' => $sourceId];
            $sourceId++;
        }

        return $entries;
    }

    /**
     * The children of a category as pool entries.
     *
     * @param  array<string, mixed>  $category
     * @return list<KeptChild>
     */
    private function childEntries(array $category, int &$sourceId): array
    {
        $children = [];

        foreach ($this->childrenOf($category) as $child) {
            $children[] = [
                'category' => $child,
                'items' => $this->entries($this->itemsOf($child), $sourceId),
            ];
        }

        return $children;
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<int, array<string, mixed>>
     */
    private function itemsOf(array $category): array
    {
        return is_array($category['items'] ?? null) ? array_values($category['items']) : [];
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<int, array<string, mixed>>
     */
    private function childrenOf(array $category): array
    {
        return is_array($category['children'] ?? null) ? array_values($category['children']) : [];
    }
}
