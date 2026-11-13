<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Juwri\Core\Legal\LegalDocuments;

/**
 * Small helpers for Blade views that render on every request. They live in a class because a closure literal
 * (`fn (...)` / `function (...)`) inside a compiled view keeps about 250 bytes in the worker on every include on PHP 8.4,
 * whether or not it runs (P6-36/P6-36b soak): a view rendered per request with a closure grows a long-lived Octane worker.
 * Do not add closure literals to views in the hot list of Tests\Feature\Views\NoViewClosuresTest.
 */
final class ViewHelpers
{
    /** Asset URL with a cache-busting version (file mtime, else the app version). */
    public static function assetV(string $p): string
    {
        return asset($p).'?v='.(is_file(public_path($p)) ? filemtime(public_path($p)) : config('appinfo.version', '1'));
    }

    /** @param  array<int, array{0: mixed, 1: mixed}>  $points */
    public static function polyline(array $points): string
    {
        $out = [];
        foreach ($points as $p) {
            $out[] = $p[0].','.$p[1];
        }

        return implode(' ', $out);
    }

    /**
     * Names of chart series, comma separated, empty names dropped.
     *
     * @param  iterable<mixed>  $series
     */
    public static function seriesLabel(iterable $series): string
    {
        $names = [];
        foreach ($series as $s) {
            $name = (string) ($s['name'] ?? '');
            if ($name) {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /** @param  array<int, array<string, mixed>>  $pending */
    public static function legalDocumentLabels(array $pending): string
    {
        $labels = [];
        foreach ($pending as $p) {
            $labels[] = LegalDocuments::label($p['document']);
        }

        return implode(', ', $labels);
    }

    /** @param  array<int, array<string, mixed>>  $pending */
    public static function firstUpcoming(array $pending): mixed
    {
        foreach ($pending as $p) {
            if ($p['upcoming']) {
                return $p;
            }
        }

        return null;
    }

    /** Path without the /{locale} prefix, trailing slash and query string. */
    public static function barePath(?string $p): string
    {
        return rtrim((string) preg_replace('#^/[a-z]{2}(?=/|$)#', '', (string) parse_url((string) $p, PHP_URL_PATH)), '/') ?: '/';
    }

    public static function translatedValue(string $code, mixed $translations, string $name, mixed $value): mixed
    {
        $t = is_object($translations) ? ($translations->{$code} ?? null) : (is_array($translations) ? ($translations[$code] ?? null) : null);
        $t = is_array($t) ? (object) $t : $t;

        return (is_object($t) && ! empty($t->{$name})) ? $t->{$name} : $value;
    }

    /** @return array<int, string> */
    public static function cardModifiers(mixed $variant): array
    {
        $mods = [];
        foreach (array_filter(preg_split('/\s+/', (string) $variant) ?: []) as $m) {
            $mods[] = 'ui-card--'.$m;
        }

        return $mods;
    }

    /** @param  iterable<int, array<string, mixed>>  $flashes
     *  @return array<int, array{type: mixed, message: mixed}> */
    public static function flashPayload(iterable $flashes): array
    {
        $out = [];
        foreach ($flashes as $f) {
            $out[] = ['type' => $f['type'], 'message' => $f['message']];
        }

        return $out;
    }

    public static function trendText(mixed $value): string
    {
        if ((float) $value > 0) {
            return '+'.abs((float) $value).'%';
        }
        if ((float) $value < 0) {
            return '-'.abs((float) $value).'%';
        }

        return '0%';
    }

    public static function trendState(mixed $value): string
    {
        if ((float) $value > 0) {
            return 'up';
        }

        return (float) $value < 0 ? 'down' : 'flat';
    }

    /** @param  array<int, array<string, mixed>>  $items */
    public static function maxQty(array $items): int|float
    {
        $qtys = [];
        foreach ($items as $i) {
            $qtys[] = $i['qty'];
        }

        return max($qtys ?: [1]) ?: 1;
    }

    public static function maxLanguageCount(mixed $langs): int
    {
        if (! is_countable($langs) || ! count($langs)) {
            return 1;
        }
        $counts = [];
        foreach (is_array($langs) ? $langs : (method_exists($langs, 'all') ? $langs->all() : []) as $l) {
            $counts[] = (int) ($l->count ?? 0);
        }

        return max($counts) ?: 1;
    }

    /**
     * Sum of order prices (null counts as 0).
     *
     * @param  iterable<mixed>  $orders
     */
    public static function sumPrices(iterable $orders): int|float
    {
        $sum = 0;
        foreach ($orders as $o) {
            $sum += $o->price ?? 0;
        }

        return $sum;
    }

    /**
     * Stores whose public menu redirects to an external URL.
     *
     * @param  iterable<mixed>  $stores
     * @return Collection<array-key, mixed>
     */
    public static function externalRedirectStores(iterable $stores): Collection
    {
        $out = [];
        foreach ($stores as $k => $r) {
            if ($r && ExternalRedirect::menuActive($r)) {
                $out[$k] = $r;
            }
        }

        return new Collection($out);
    }

    /**
     * Index of the first undone onboarding step (false when none, as Collection::search).
     *
     * @param  array<array-key, array<string, mixed>>  $steps
     */
    public static function firstUndone(array $steps): int|string|false
    {
        foreach ($steps as $k => $s) {
            if (! $s['done']) {
                return $k;
            }
        }

        return false;
    }

    public static function addressLabel(mixed $a): string
    {
        return trim(implode(', ', array_filter([$a->line, $a->city, $a->state, $a->zip, $a->country])));
    }

    /**
     * Models of a collection whose id has a positive count (a `filter()->values()` without a closure).
     *
     * @param  iterable<mixed>  $models
     * @param  array<array-key, int>  $counts
     * @return Collection<int, mixed>
     */
    public static function withItems(iterable $models, array $counts): Collection
    {
        $out = [];
        foreach ($models as $m) {
            if (($counts[$m->id] ?? 0) > 0) {
                $out[] = $m;
            }
        }

        return new Collection($out);
    }

    /**
     * Titles of an order line's extras, comma separated, empty ones dropped.
     *
     * @param  iterable<mixed>  $itemExtras
     */
    public static function extraTitles(iterable $itemExtras): string
    {
        $titles = [];
        foreach ($itemExtras as $x) {
            $title = $x->extra->title ?? '';
            if ($title) {
                $titles[] = $title;
            }
        }

        return implode(', ', $titles);
    }

    /**
     * Label of a variant whose options are the legacy `{variantOptionId: choiceIndex}` pivot shape.
     * Falls back to "Option".
     */
    public static function variantLabelLegacy(object $menu, object $variant): string
    {
        $parts = self::legacyVariantParts($menu, $variant);

        return ! empty($parts) ? implode(' · ', $parts) : ___('Option');
    }

    /** As variantLabelLegacy, but falls back to the variant's own name before "Option". */
    public static function variantLabelNamed(object $menu, object $variant): string
    {
        $parts = self::legacyVariantParts($menu, $variant);

        return ! empty($parts) ? implode(' · ', $parts) : (($variant->name ?? null) ?: ___('Option'));
    }

    /**
     * Label of a variant that stores either the matrix AXES shape `{"Size":"M"}` or the legacy pivot shape.
     */
    public static function variantLabelAxes(object $menu, object $variant): string
    {
        $parts = [];
        foreach ((array) ($variant->options ?? []) as $key => $val) {
            if (is_string($key) && ! ctype_digit((string) $key) && (is_string($val) || is_numeric($val))) {
                if ($val !== '') {
                    $parts[] = (string) $val;
                }
            } else {
                $vo = $menu->variantOptions->firstWhere('id', (int) $key);
                if ($vo) {
                    $choices = (array) $vo->options;
                    if (isset($choices[$val])) {
                        $parts[] = $choices[$val];
                    }
                }
            }
        }

        return ! empty($parts) ? implode(' · ', $parts) : ___('Option');
    }

    /** As variantLabelLegacy, but a plain-text (non-JSON) options value is the label itself, and non-numeric keys are skipped. */
    public static function variantLabelRaw(object $menu, object $variant): string
    {
        $raw = $variant->options ?? null;
        if (is_string($raw) && trim($raw) !== '' && ! str_starts_with(trim($raw), '{')) {
            return trim($raw);
        }
        $parts = [];
        foreach ((array) $raw as $optId => $idx) {
            if (! is_numeric($optId)) {
                continue;
            }
            $vo = $menu->variantOptions->firstWhere('id', (int) $optId);
            if ($vo) {
                $choices = (array) $vo->options;
                if (isset($choices[$idx])) {
                    $parts[] = $choices[$idx];
                }
            }
        }

        return ! empty($parts) ? implode(' · ', $parts) : ___('Option');
    }

    /** @return array<int, mixed> */
    private static function legacyVariantParts(object $menu, object $variant): array
    {
        $parts = [];
        foreach ((array) ($variant->options ?? []) as $optId => $idx) {
            $vo = $menu->variantOptions->firstWhere('id', (int) $optId);
            if ($vo) {
                $choices = (array) $vo->options;
                if (isset($choices[$idx])) {
                    $parts[] = $choices[$idx];
                }
            }
        }

        return $parts;
    }

    /** @return array<mixed> */
    public static function attrsOf(mixed $m): array
    {
        $raw = $m->attributes ?? null;

        return is_array($raw) ? $raw : [];
    }

    public static function brandOf(mixed $m): ?string
    {
        $rel = $m->brand->name ?? null;
        if ($rel) {
            return $rel;
        }
        $attrs = self::attrsOf($m);

        return $attrs['brand'] ?? null;
    }

    /** @return array{type: string, text: string}|null */
    public static function stockBadge(bool $trackInventory, ?int $stockQty): ?array
    {
        if (! $trackInventory || $stockQty === null) {
            return null;
        }
        if ($stockQty <= 0) {
            return ['type' => 'out', 'text' => ___('Out of stock')];
        }
        if ($stockQty <= 5) {
            return ['type' => 'low', 'text' => ___(':count left', ['count' => $stockQty])];
        }

        return ['type' => 'in', 'text' => ___('In stock')];
    }

    public static function formatDuration(?int $hours): ?string
    {
        if (! $hours) {
            return null;
        }

        return ___(':hours h turnaround', ['hours' => $hours]);
    }

    /** Allergy chips for the storefront item JSON: id, title and an image URL (absolute or under storage/allergies). */
    /** @return Collection<int, mixed> */
    public static function allergyList(mixed $allergies): Collection
    {
        return $allergies->map(fn ($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'img' => Str::of($a->image)->isUrl() ? $a->image : media_url('storage/allergies/', $a->image, null),
        ])->values();
    }

    /** As allergyList, with the image resolved through asset(). */
    /** @return Collection<int, mixed> */
    public static function allergyListAsset(mixed $allergies): Collection
    {
        return $allergies->map(fn ($a) => [
            'id' => $a->id,
            'title' => $a->title,
            'img' => Str::of($a->image)->isUrl() ? $a->image : asset('storage/allergies/'.$a->image),
        ])->values();
    }

    /** Allergy chips without an image. */
    /** @return Collection<int, mixed> */
    public static function allergyListPlain(mixed $allergies): Collection
    {
        return $allergies->map(fn ($a) => ['id' => $a->id, 'title' => $a->title])->values();
    }

    /** Gallery image URLs of a menu item (media_url under storage/menu/). */
    /** @return Collection<int, mixed> */
    public static function galleryUrls(mixed $images, mixed $storeId): Collection
    {
        return collect((array) $images)->map(fn ($f) => media_url('storage/menu/', $f, $storeId))->values();
    }

    /** Gallery image URLs of a menu item via asset(). */
    /** @return Collection<int, mixed> */
    public static function galleryAssets(mixed $images): Collection
    {
        return collect((array) $images)->map(fn ($f) => asset('storage/menu/'.$f))->values();
    }

    /**
     * Global option groups of a menu item for the storefront item JSON.
     *
     * @param  iterable<mixed>  $groups
     * @return Collection<int, mixed>
     */
    public static function optionGroups(iterable $groups): Collection
    {
        return (new Collection($groups))->map(fn ($g) => [
            'id' => $g->id,
            'title' => $g->title,
            'required' => (bool) $g->required,
            'min' => (int) ($g->min_select ?? 0),
            'max' => $g->max_select !== null ? (int) $g->max_select : null,
            'options' => collect((array) $g->options)->values()->map(fn ($label, $i) => [
                'i' => $i, 'label' => $label, 'price' => (float) ($g->option_prices[$i] ?? 0),
            ])->values(),
        ])->values();
    }

    /**
     * First six written reviews of a menu item for the storefront item JSON.
     *
     * @param  iterable<mixed>  $reviews
     * @return Collection<int, mixed>
     */
    public static function reviewList(iterable $reviews): Collection
    {
        return (new Collection($reviews))->take(6)->map(fn ($review) => [
            'name' => $review->reviewer_name ?: ___('Guest'),
            'stars' => (int) $review->stars,
            'body' => (string) ($review->body ?? ''),
        ])->values();
    }

    /**
     * The store's menu languages normalised to {code, name} objects.
     *
     * @param  iterable<mixed>  $languages
     * @return Collection<int, mixed>
     */
    public static function menuLanguages(iterable $languages): Collection
    {
        return (new Collection($languages))
            ->map(fn ($lang) => (object) ['code' => $lang->code, 'name' => $lang->name ?? $lang->code])
            ->values();
    }

    /** First item that has a real image, else the first item. */
    public static function firstWithImage(mixed $items): mixed
    {
        return $items->first(fn ($m) => $m->image && $m->image !== 'default.png') ?? $items->first();
    }

    /** The first $n items that have a real image. */
    /** @return Collection<int, mixed> */
    public static function imageItems(mixed $items, int $n): Collection
    {
        return $items->filter(fn ($m) => $m->image && $m->image !== 'default.png')->take($n)->values();
    }

    /** Fulfillment modes of a store type as {value, label, needsAddress, needsSlot} rows for the storefront. */
    /** @return Collection<int, mixed> */
    public static function fulfillmentModes(mixed $fulfillment, mixed $storeType): Collection
    {
        return (new Collection($fulfillment->modesFor($storeType)))->map(fn ($mode) => [
            'value' => $mode,
            'label' => $fulfillment->label($mode),
            'needsAddress' => $fulfillment->needsAddress($mode),
            'needsSlot' => $fulfillment->needsSlot($mode),
        ])->values();
    }

    /**
     * Menu banners that are active (a missing `active` counts as active).
     *
     * @param  iterable<mixed>  $banners
     * @return Collection<int, mixed>
     */
    public static function activeBanners(iterable $banners): Collection
    {
        return (new Collection($banners))->filter(fn ($b) => ($b->active ?? 1))->values();
    }

    /** Items whose `$flag` attribute is set (non-empty), optionally only the first $limit. */
    /** @return Collection<int, mixed> */
    public static function flagged(mixed $items, string $flag, ?int $limit = null): Collection
    {
        $out = $items->filter(fn ($m) => ! empty($m->{$flag}));

        return $limit === null ? $out : $out->take($limit);
    }

    /**
     * First locale row whose code differs from the current locale (null when none).
     *
     * @param  iterable<mixed>  $locales
     */
    public static function otherLocale(iterable $locales, mixed $current): mixed
    {
        return (new Collection($locales))->first(fn ($l) => $l['code'] !== $current);
    }

    /** @param  array<int, mixed>  $sections
     * @return array<int, mixed> */
    public static function sectionIds(array $sections): array
    {
        return array_map(fn ($s) => is_array($s) ? $s['id'] : $s, $sections);
    }

    /**
     * @param  iterable<mixed>  $badges
     */
    public static function hasSoldOutBadge(iterable $badges): bool
    {
        return (new Collection($badges))->contains(fn ($b) => ($b['kind'] ?? '') === 'sold_out');
    }

    // ---- P6-36e batch 1: storefront kit / aisle / basket / brew / book ----

    /** First existing view of `store_templates.{theme}.sections.{id}` then the shared kit section, else null. */
    public static function themeView(string $theme, string $id): ?string
    {
        foreach (['store_templates.'.$theme.'.sections.'.$id, 'store_templates._kit.sections.'.$id] as $name) {
            if (view()->exists($name)) {
                return $name;
            }
        }

        return null;
    }

    /** The shared kit section only (book orders home): its name when the view exists, else null. */
    public static function kitView(string $id): ?string
    {
        return view()->exists('store_templates._kit.sections.'.$id) ? 'store_templates._kit.sections.'.$id : null;
    }

    /** Kiosk screen: the theme's section, else the kit's (the kit name even when neither exists). */
    public static function kioskView(string $theme, string $name): string
    {
        return self::themeView($theme, $name) ?? 'store_templates._kit.sections.'.$name;
    }

    public static function kitAsset(string $path, string $version): string
    {
        return asset('assets/'.$path).'?v='.$version;
    }

    public static function bookLocation(string $key): string
    {
        return $key === 'at_home' ? 'at_home' : 'venue';
    }

    /**
     * @param  array<int, mixed>  $actions
     * @param  array<string, mixed>  $more
     * @return array<string, mixed>
     */
    public static function stateCard(string $tone, string $icon, string $tag, mixed $title, string $body, array $actions = [], array $more = []): array
    {
        return ['tone' => $tone, 'icon' => $icon, 'tag' => $tag, 'title' => $title, 'body' => $body, 'actions' => $actions] + $more;
    }

    /**
     * First category whose id equals `$id` as a string.
     *
     * @param  iterable<mixed>  $categories
     */
    public static function categoryById(iterable $categories, mixed $id): mixed
    {
        foreach ($categories as $c) {
            if ((string) $c['id'] === (string) $id) {
                return $c;
            }
        }

        return null;
    }

    /**
     * First category whose id is identical to `$id` (a missing id is null).
     *
     * @param  iterable<mixed>  $categories
     */
    public static function categoryByStrictId(iterable $categories, mixed $id): mixed
    {
        foreach ($categories as $c) {
            if (($c['id'] ?? null) === $id) {
                return $c;
            }
        }

        return null;
    }

    /**
     * First category that lists the item id.
     *
     * @param  iterable<mixed>  $categories
     */
    public static function categoryWithItem(iterable $categories, mixed $itemId): mixed
    {
        foreach ($categories as $c) {
            if (in_array($itemId, $c['item_ids'], true)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * @param  iterable<mixed>  $categories
     * @return array<mixed>
     */
    public static function populatedCategories(iterable $categories): array
    {
        $out = [];
        foreach ($categories as $c) {
            if ($c['count'] > 0) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @param  iterable<mixed>  $options
     */
    public static function selectedCount(iterable $options): int
    {
        $n = 0;
        foreach ($options as $o) {
            if (! empty($o['selected'])) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * True when every option label is at most `$max` characters.
     *
     * @param  iterable<mixed>  $options
     */
    public static function labelsFit(iterable $options, int $max): bool
    {
        foreach ($options as $o) {
            if (! (mb_strlen($o['label']) <= $max)) {
                return false;
            }
        }

        return true;
    }

    /**
     * First option that is selected or whose id is in `$picked`.
     *
     * @param  iterable<mixed>  $options
     * @param  array<mixed>  $picked
     */
    public static function firstPicked(iterable $options, array $picked): mixed
    {
        foreach ($options as $o) {
            if (! empty($o['selected']) || in_array($o['id'], $picked, true)) {
                return $o;
            }
        }

        return null;
    }

    /**
     * First option that is selected.
     *
     * @param  iterable<mixed>  $options
     */
    public static function firstSelectedOption(iterable $options): mixed
    {
        foreach ($options as $o) {
            if (! empty($o['selected'])) {
                return $o;
            }
        }

        return null;
    }

    /**
     * Kiosk order types: only the ones the kiosk allows.
     *
     * @param  array<mixed>  $types
     * @param  array<mixed>  $allowed
     * @return array<mixed>
     */
    public static function kioskOrderTypes(array $types, array $allowed): array
    {
        $out = [];
        foreach ($types as $t) {
            if (in_array($t['key'], $allowed, true)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * Cafe flow: pickup, then dine in, then delivery; others last. Stable like usort on PHP 8.
     *
     * @param  array<mixed>  $types
     * @return array<mixed>
     */
    public static function cafeOrderTypes(array $types): array
    {
        $rank = ['takeaway' => 0, 'pickup' => 0, 'dine_in' => 1, 'delivery' => 2];
        usort($types, fn ($a, $b) => ($rank[$a['key']] ?? 3) <=> ($rank[$b['key']] ?? 3));

        return $types;
    }

    /**
     * @param  iterable<mixed>  $rows
     */
    public static function hasKey(iterable $rows, string $key, string $field = 'key'): bool
    {
        foreach ($rows as $r) {
            if ($r[$field] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Badge label by kind.
     *
     * @param  iterable<mixed>  $badges
     * @return Collection<int|string, mixed>
     */
    public static function badgeLabels(iterable $badges): Collection
    {
        $out = [];
        foreach ($badges as $b) {
            $out[$b['kind']] = $b['label'];
        }

        return collect($out);
    }

    /**
     * @param  iterable<mixed>  $badges
     */
    public static function badgeOfKind(iterable $badges, string $kind): mixed
    {
        foreach ($badges as $b) {
            if (($b['kind'] ?? '') === $kind) {
                return $b;
            }
        }

        return null;
    }

    /**
     * @param  iterable<mixed>  $badges
     */
    public static function badgeNotOfKind(iterable $badges, string $kind): mixed
    {
        foreach ($badges as $b) {
            if (($b['kind'] ?? '') !== $kind) {
                return $b;
            }
        }

        return null;
    }

    /**
     * Wishlist item id => variant id.
     *
     * @param  iterable<mixed>  $wishlist
     * @return Collection<int|string, mixed>
     */
    public static function wishlistVariants(iterable $wishlist): Collection
    {
        $out = [];
        foreach ($wishlist as $w) {
            $out[$w['item_id']] = $w['variant_id'] ?? null;
        }

        return collect($out);
    }

    /**
     * Checkout fields shown under "Contact": all kiosk gets is the name.
     *
     * @param  iterable<mixed>  $fields
     * @param  array<mixed>  $contact
     * @return Collection<int|string, mixed>
     */
    public static function contactFields(iterable $fields, bool $kiosk, array $contact): Collection
    {
        return collect($fields)->filter(fn ($f) => $kiosk ? $f['id'] === 'name' : in_array($f['id'], $contact, true));
    }

    /**
     * Checkout fields outside the contact group (the address block).
     *
     * @param  iterable<mixed>  $fields
     * @param  array<mixed>  $contact
     * @return Collection<int|string, mixed>
     */
    public static function addressFields(iterable $fields, array $contact): Collection
    {
        return collect($fields)->reject(fn ($f) => in_array($f['id'], $contact, true));
    }

    /**
     * @param  iterable<mixed>  $methods
     */
    public static function onlineSelected(iterable $methods): bool
    {
        foreach ($methods as $m) {
            if ($m['id'] === 'online' && ! empty($m['selected'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Key of the delivery day in the "selected" state, or false.
     *
     * @param  iterable<mixed>  $days
     */
    public static function selectedDayKey(iterable $days): int|string|false
    {
        foreach ($days as $k => $d) {
            if (($d['state'] ?? '') === 'selected') {
                return $k;
            }
        }

        return false;
    }

    /**
     * @param  iterable<mixed>  $rows
     */
    public static function firstOfKind(iterable $rows, string $field, string $value): mixed
    {
        foreach ($rows as $r) {
            if (($r[$field] ?? '') === $value) {
                return $r;
            }
        }

        return null;
    }

    /**
     * "label value · label value" for nutrition facts.
     *
     * @param  iterable<mixed>  $nutrition
     */
    public static function nutritionFacts(iterable $nutrition): string
    {
        $out = [];
        foreach ($nutrition as $n) {
            $out[] = $n['label'].' '.$n['value'];
        }

        return implode(' · ', $out);
    }

    /**
     * Rows whose url is set (true) or empty (false).
     *
     * @param  iterable<mixed>  $actions
     * @return Collection<int|string, mixed>
     */
    public static function withUrl(iterable $actions, bool $has): Collection
    {
        return collect($actions)->filter(fn ($a) => $has ? ! empty($a['url']) : empty($a['url']));
    }

    /**
     * Actions whose icon is (or is not) one of `$icons`.
     *
     * @param  iterable<mixed>  $actions
     * @param  array<mixed>  $icons
     * @return Collection<int|string, mixed>
     */
    public static function actionsByIcon(iterable $actions, array $icons, bool $in): Collection
    {
        $c = collect($actions);

        return $in
            ? $c->filter(fn ($a) => in_array($a['icon'] ?? '', $icons, true))
            : $c->reject(fn ($a) => in_array($a['icon'] ?? '', $icons, true));
    }

    /**
     * True when no quote row is a total and no subtotal carries the total label.
     *
     * @param  iterable<mixed>  $rows
     */
    public static function quoteNeedsTotal(iterable $rows, mixed $totalLabel): bool
    {
        $hasTotal = false;
        $hasSub = false;
        foreach ($rows as $r) {
            if ($r['kind'] === 'total') {
                $hasTotal = true;
            }
            if ($r['kind'] === 'subtotal' && $r['label'] === $totalLabel) {
                $hasSub = true;
            }
        }

        return ! $hasTotal && ! $hasSub;
    }

    /**
     * First sold-out catalogue item.
     *
     * @param  iterable<mixed>  $items
     */
    public static function firstSoldOut(iterable $items): mixed
    {
        foreach ($items as $i) {
            if (! empty($i['sold_out'])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * First in-stock item among the ids, looked up in the catalogue.
     *
     * @param  iterable<mixed>  $ids
     * @param  array<mixed>  $items
     */
    public static function firstAvailable(iterable $ids, array $items): mixed
    {
        foreach ($ids as $id) {
            $i = $items[$id] ?? null;
            if ($i && empty($i['sold_out'])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Image src of the first catalogue item (by id) that has one.
     *
     * @param  iterable<mixed>  $ids
     * @param  array<mixed>  $items
     */
    public static function firstItemImage(iterable $ids, array $items): mixed
    {
        foreach ($ids as $id) {
            $src = $items[$id]['image']['src'] ?? null;
            if ($src !== null) {
                return $src;
            }
        }

        return null;
    }

    // ---- P6-36e batch 2: marketing JSON-LD, admin option lists, bio blocks, owner views ----

    /**
     * @return array<mixed>
     */
    private static function question(mixed $name, mixed $text): array
    {
        return ['@type' => 'Question', 'name' => $name, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $text]];
    }

    /**
     * FAQ rows with q/a keys; the answer loses its HTML.
     *
     * @param  iterable<mixed>  $faqs
     * @return array<mixed>
     */
    public static function faqLdStripped(iterable $faqs): array
    {
        $out = [];
        foreach ($faqs as $f) {
            $out[] = self::question((string) ($f['q'] ?? ''), trim(strip_tags((string) ($f['a'] ?? ''))));
        }

        return $out;
    }

    /**
     * FAQ rows with q/a keys, taken as they are.
     *
     * @param  iterable<mixed>  $faqs
     * @return array<mixed>
     */
    public static function faqLdPlain(iterable $faqs): array
    {
        $out = [];
        foreach ($faqs as $f) {
            $out[] = self::question($f['q'] ?? '', $f['a'] ?? '');
        }

        return $out;
    }

    /**
     * FAQ rows with q/a keys that must exist.
     *
     * @param  iterable<mixed>  $faqs
     * @return array<mixed>
     */
    public static function faqLdRequired(iterable $faqs): array
    {
        $out = [];
        foreach ($faqs as $f) {
            $out[] = self::question($f['q'], $f['a']);
        }

        return $out;
    }

    /**
     * FAQ pairs [question, answer].
     *
     * @param  iterable<mixed>  $faqs
     * @return array<mixed>
     */
    public static function faqLdPairs(iterable $faqs): array
    {
        $out = [];
        foreach ($faqs as $f) {
            $out[] = self::question($f[0], $f[1]);
        }

        return $out;
    }

    /**
     * FAQ models (faq_title / faq_content).
     *
     * @param  iterable<mixed>  $faqs
     * @return array<mixed>
     */
    public static function faqLdModels(iterable $faqs): array
    {
        $out = [];
        foreach ($faqs as $f) {
            $out[] = self::question($f->faq_title, trim(strip_tags($f->faq_content)));
        }

        return $out;
    }

    /**
     * FAQ models that may carry question/answer instead.
     *
     * @param  iterable<mixed>  $faqs
     * @return array<mixed>
     */
    public static function faqLdModelsFallback(iterable $faqs): array
    {
        $out = [];
        foreach ($faqs as $f) {
            $out[] = self::question($f->faq_title ?? $f->question ?? '', trim(strip_tags($f->faq_content ?? $f->answer ?? '')));
        }

        return $out;
    }

    /**
     * Select options from model properties: `$keyProp => $valueProp`.
     *
     * @param  iterable<mixed>  $models
     * @return array<mixed>
     */
    public static function propOptions(iterable $models, string $keyProp, string $valueProp, bool $keyAsString = false): array
    {
        $out = [];
        foreach ($models as $m) {
            $out[$keyAsString ? (string) $m->{$keyProp} : $m->{$keyProp}] = $m->{$valueProp};
        }

        return $out;
    }

    /**
     * Template options `folder => name (folder)` from arrays; `$onlyEnabled` keeps the enabled ones and translates the name.
     *
     * @param  iterable<mixed>  $templates
     * @return array<mixed>
     */
    public static function templateFolderOptions(iterable $templates, bool $onlyEnabled = false): array
    {
        $out = [];
        foreach ($templates as $t) {
            if ($onlyEnabled) {
                if ($t['enabled']) {
                    $out[$t['folder']] = ___($t['name']).' ('.$t['folder'].')';
                }
            } else {
                $out[$t['folder']] = $t['name'].' ('.$t['folder'].')';
            }
        }

        return $out;
    }

    /**
     * `slug => name`, optionally `name (slug)`.
     *
     * @param  iterable<mixed>  $templates
     * @return array<mixed>
     */
    public static function templateSlugOptions(iterable $templates, bool $withSlug): array
    {
        $out = [];
        foreach ($templates as $slug => $t) {
            $out[$slug] = $withSlug ? $t['name'].' ('.$slug.')' : $t['name'];
        }

        return $out;
    }

    /**
     * Values mapped to their own translated, capitalised label.
     *
     * @param  iterable<mixed>  $values
     * @return array<mixed>
     */
    public static function ucfirstOptions(iterable $values, bool $translate): array
    {
        $out = [];
        foreach ($values as $v) {
            $out[$v] = $translate ? ___(ucfirst($v)) : ucfirst($v);
        }

        return $out;
    }

    /**
     * Minutes mapped to ":count min".
     *
     * @param  iterable<mixed>  $minutes
     * @return array<mixed>
     */
    public static function minuteOptions(iterable $minutes): array
    {
        $out = [];
        foreach ($minutes as $m) {
            $out[$m] = ___(':count min', ['count' => $m]);
        }

        return $out;
    }

    /**
     * Trimmed, truthy strings (Collection::filter drops '' and '0'), re-indexed.
     *
     * @return array<mixed>
     */
    public static function cleanList(mixed $values): array
    {
        $out = [];
        foreach ((array) $values as $v) {
            $v = trim((string) $v);
            if ($v) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * Trimmed strings that are not empty ('0' stays), re-indexed.
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    public static function trimmedNonEmpty(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $v = trim($v);
            if ($v !== '') {
                $out[] = $v;
            }
        }

        return $out;
    }

    /**
     * Trimmed tags from an array or comma list, empties dropped (keys kept, as Collection::filter).
     *
     * @return Collection<int|string, mixed>
     */
    public static function tagCollection(mixed $tags): Collection
    {
        return collect(is_array($tags) ? $tags : explode(',', (string) $tags))
            ->map(fn ($tag) => trim((string) $tag))
            ->filter();
    }

    /**
     * Locales other than the default.
     *
     * @param  iterable<mixed>  $locales
     * @return array<mixed>
     */
    public static function otherLocales(iterable $locales, string $default): array
    {
        $out = [];
        foreach ($locales as $l) {
            if ($l !== $default) {
                $out[] = $l;
            }
        }

        return $out;
    }

    public static function toneOf(?string $state): string
    {
        return match ($state) {
            'ok', 'success' => 'success',
            'warn', 'pending' => 'warning',
            null, '' => 'neutral',
            default => 'danger',
        };
    }

    public static function adminLogoUrl(mixed $file): string
    {
        return asset('storage/logo/'.$file);
    }

    public static function storeLangUrl(mixed $code): string
    {
        return route('store.lang', $code);
    }

    public static function editorAsset(string $rel): string
    {
        return asset($rel).'?v='.(@filemtime(public_path($rel)) ?: '0');
    }

    // bio blocks: keep only rows that have the fields the block draws
    /**
     * @param  array<mixed>  $apps
     * @return array<mixed>
     */
    public static function namedApps(array $apps): array
    {
        $out = [];
        foreach ($apps as $a) {
            if (is_array($a) && trim((string) ($a['name'] ?? '')) !== '') {
                $out[] = $a;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $links
     * @return array<mixed>
     */
    public static function linksWithUrl(array $links): array
    {
        $out = [];
        foreach ($links as $l) {
            if (is_array($l) && trim((string) ($l['url'] ?? '')) !== '') {
                $out[] = $l;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $items
     * @return array<mixed>
     */
    public static function faqWithBoth(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            if (is_array($i) && trim((string) ($i['q'] ?? '')) !== '' && trim((string) ($i['a'] ?? '')) !== '') {
                $out[] = $i;
            }
        }

        return $out;
    }

    /**
     * "09:00–17:00, ..." for a day's open/close pairs.
     *
     * @param  array<mixed>  $pairs
     */
    public static function hoursPairs(array $pairs): string
    {
        $out = [];
        foreach ($pairs as $p) {
            $out[] = trim((string) ($p[0] ?? '')).'–'.trim((string) ($p[1] ?? ''));
        }

        return implode(', ', $out);
    }

    public static function phoneDigits(string $raw): string
    {
        return preg_replace('/[^0-9+]/', '', whatsapp_latin_digits(trim($raw))) ?? '';
    }

    /** Media URL for a bio block file, or the value when it already is a URL. */
    public static function bioMediaUrl(mixed $m, ?int $storeId): string
    {
        if (is_string($m)) {
            $m = trim($m);

            return ($m === '' || Str::startsWith($m, ['http://', 'https://', '/'])) ? $m : media_url('storage/bio/', $m, $storeId);
        }
        if (is_array($m)) {
            if (! empty($m['url'])) {
                return (string) $m['url'];
            }
            if (! empty($m['file'])) {
                return media_url((string) ($m['path'] ?? 'storage/bio/'), (string) $m['file'], $storeId);
            }
        }

        return '';
    }

    // order hub
    /**
     * @param  iterable<mixed>  $rows
     */
    public static function allOk(iterable $rows): bool
    {
        foreach ($rows as $r) {
            if (! $r['ok']) {
                return false;
            }
        }

        return true;
    }

    /** Total quantity across the carts' items. */
    public static function cartQuantity(mixed $cart): int|float
    {
        $n = 0;
        foreach (is_iterable($cart) ? $cart : [] as $s) {
            foreach ($s['items'] as $i) {
                $n += $i['quantity'] ?? 0;
            }
        }

        return $n;
    }

    /**
     * @param  iterable<mixed>  $stores
     * @return Collection<int, mixed>
     */
    public static function topRatedStores(iterable $stores, int $n = 8): Collection
    {
        return (new Collection($stores))->filter(fn ($s) => $s->hub_rating)->sortByDesc('hub_rating')->take($n)->values();
    }
}
