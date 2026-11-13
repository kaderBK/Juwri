<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use App\Models\Concerns\TranslatesForStorefront;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Juwri\Core\Models\Store;

/**
 * A choice group of an item ("Size": Small, Large) or of the store's library (`is_global`, `menu_id` null).
 *
 * P1-06: a `modifier_groups` row of kind `variant`; each option is a {@see Modifier} row. The legacy positional
 * lists stay readable and writable under their old names (owner editor, themes, POS, API, imports all use them):
 *  - `options`        list of option labels (the storefront visitor's language when translated);
 *  - `option_prices`  list of major-unit prices aligned to `options` (from `modifiers.price_minor`), null when no
 *                     option has a price;
 *  - `option_images`  list of image file names aligned to `options`, null when no option has one.
 * Writes are kept until the group is saved, then become modifier rows (same position = same index). Option-label
 * translations stay in the group's `translations.<lang>.options` (mirrored onto each modifier's translations).
 *
 * @property-read MenuItemVariantOptionPivot $pivot  Present when loaded via Menu::attachedVariantOptions().
 * @property mixed $options
 * @property array<int, float>|null $option_prices
 * @property array<int, string|null>|null $option_images
 */
class MenuVariantOption extends ModifierGroup
{
    use HasFactory;
    use TranslatesForStorefront;

    private const LIST_KEYS = ['options', 'option_prices', 'option_images'];

    /** @var array<string, string> */
    protected $casts = [
        'translations' => 'object',
        'is_global' => 'boolean',
        'required' => 'boolean',
        'min_select' => 'integer',
        'max_select' => 'integer',
    ];

    /** @var list<string> legacy JSON shape */
    protected $appends = ['options', 'option_prices', 'option_images'];

    /** @var list<string> */
    protected $hidden = ['modifiers'];

    /** @var list<string> the options are part of every group read */
    protected $with = ['modifiers'];

    /** @var array<string, array<int, mixed>|null> list writes not yet turned into modifier rows */
    private array $pendingLists = [];

    protected static function booted(): void
    {
        static::addGlobalScope('variant_kind', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('kind'), self::KIND_VARIANT);
        });
        // P3-09: the store's substitution group is a choice the picker acts on, not a priced choice of an item: it is
        // never read, attached or required as one of an item's groups.
        static::addGlobalScope('not_substitution', function (Builder $query): void {
            $column = $query->getModel()->qualifyColumn('purpose');
            $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '!=', self::PURPOSE_SUBSTITUTION));
        });
        static::creating(function (self $group): void {
            $group->setAttribute('kind', self::KIND_VARIANT);
        });
        static::saved(function (self $group): void {
            $group->syncModifierRows();
        });
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** Items this option group is attached to (library many-to-many). */
    public function menus()
    {
        return $this->belongsToMany(Menu::class, 'item_modifier_group', 'modifier_group_id', 'menu_id')
            ->wherePivotNull('modifier_id')
            ->withPivot(['position', 'required', 'min_select', 'max_select', 'active']);
    }

    /** The group title in the storefront visitor's language. */
    public function getTitleAttribute($value): mixed
    {
        return $this->storefrontTranslation('title') ?? $value;
    }

    /** The option labels in the storefront visitor's language. */
    public function getOptionsAttribute(): mixed
    {
        return $this->storefrontTranslation('options') ?? $this->baseList('options');
    }

    public function setOptionsAttribute(mixed $value): void
    {
        $this->pendingLists['options'] = self::toList($value);
    }

    /** @return array<int, float|int|string|null>|null */
    public function getOptionPricesAttribute(): ?array
    {
        return $this->baseList('option_prices');
    }

    public function setOptionPricesAttribute(mixed $value): void
    {
        $this->pendingLists['option_prices'] = self::toList($value);
    }

    /** @return array<int, string|null>|null */
    public function getOptionImagesAttribute(): ?array
    {
        return $this->baseList('option_images');
    }

    public function setOptionImagesAttribute(mixed $value): void
    {
        $this->pendingLists['option_images'] = self::toList($value);
    }

    /** `getOriginal('options' | 'option_prices' | 'option_images')`: the saved base-language list. */
    public function getOriginal($key = null, $default = null)
    {
        if (is_string($key) && in_array($key, self::LIST_KEYS, true)) {
            return $this->storedList($key) ?? $default;
        }

        return parent::getOriginal($key, $default);
    }

    /**
     * A copy carries its option rows (written when the copy is saved).
     *
     * @param  array<int, string>|null  $except
     */
    public function replicate(?array $except = null)
    {
        /** @var static $copy */
        $copy = parent::replicate($except);
        foreach (self::LIST_KEYS as $key) {
            $copy->pendingLists[$key] = $this->baseList($key);
        }

        return $copy;
    }

    /**
     * One positional list in the base language: the unsaved write, else built from the modifier rows.
     *
     * @return array<int, mixed>|null
     */
    public function baseList(string $key): ?array
    {
        if (array_key_exists($key, $this->pendingLists)) {
            return $this->pendingLists[$key];
        }

        return $this->storedList($key);
    }

    /**
     * The saved (untranslated) list — what `getOriginal('options')` gave for the old JSON column.
     *
     * @return array<int, mixed>|null
     */
    public function storedList(string $key): ?array
    {
        $rows = $this->getKey() === null ? $this->modifiers()->getRelated()->newCollection() : $this->modifiers;

        return match ($key) {
            'options' => $rows->map(fn (Modifier $m) => (string) $m->title)->values()->all(),
            'option_prices' => $rows->contains(fn (Modifier $m) => $m->price_minor !== null)
                ? $rows->map(fn (Modifier $m) => $m->price_minor === null ? 0.0 : (float) $m->price)->values()->all()
                : null,
            'option_images' => $rows->contains(fn (Modifier $m) => $m->image !== null && $m->image !== '')
                ? $rows->map(fn (Modifier $m) => $m->image ?: null)->values()->all()
                : null,
            default => null,
        };
    }

    /** Turn pending list writes (and changed option translations) into the group's modifier rows. */
    public function syncModifierRows(): void
    {
        $mirror = $this->wasChanged(['menu_id', 'store_id', 'account_id', 'is_global']);
        if ($this->pendingLists === [] && ! $this->wasChanged('translations') && ! $mirror) {
            return;
        }

        $pending = $this->pendingLists;
        $this->pendingLists = [];
        /** @var Collection<int, Modifier> $rows */
        $rows = $this->modifiers()->get()->values();
        $labels = array_key_exists('options', $pending)
            ? array_values((array) $pending['options'])
            : $rows->map(fn (Modifier $m) => (string) $m->title)->all();
        $translations = $this->optionTranslations();

        foreach ($labels as $i => $label) {
            $row = $rows->get($i) ?? new Modifier(['price_minor' => null]);
            $row->fill([
                'modifier_group_id' => $this->id,
                'menu_id' => $this->menu_id,
                'store_id' => $this->store_id,
                'account_id' => $this->account_id,
                'kind' => self::KIND_VARIANT,
                'is_global' => (bool) $this->is_global,
                'title' => is_scalar($label) ? (string) $label : json_encode($label),
                'position' => $i,
                'translations' => $translations[$i] ?? null,
            ]);
            if (array_key_exists('option_prices', $pending)) {
                $price = ((array) $pending['option_prices'])[$i] ?? null;
                $row->price = ($price === null || $price === '' || ! is_numeric($price)) ? null : $price;
            }
            if (array_key_exists('option_images', $pending)) {
                $image = ((array) $pending['option_images'])[$i] ?? null;
                $row->image = ($image === null || $image === '') ? null : (string) $image;
            }
            $row->save();
        }
        foreach ($rows->slice(count($labels)) as $extra) {
            $extra->delete();
        }

        $this->setRelation('modifiers', $this->modifiers()->get());
    }

    /**
     * Per option index: `{<lang>: {title: <label>}}` from the group's `translations.<lang>.options`.
     *
     * @return array<int, array<string, array{title: string}>>
     */
    private function optionTranslations(): array
    {
        $out = [];
        foreach ((array) ($this->translations ?? []) as $lang => $entry) {
            $labels = is_object($entry) ? ($entry->options ?? null) : (is_array($entry) ? ($entry['options'] ?? null) : null);
            foreach (array_values((array) ($labels ?? [])) as $i => $label) {
                if (is_scalar($label) && (string) $label !== '') {
                    $out[$i][(string) $lang] = ['title' => (string) $label];
                }
            }
        }

        return $out;
    }

    /** @return array<int, mixed>|null a JSON string / object / array as a list */
    private static function toList(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : ($value === '' ? [] : [$value]);
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        return array_values((array) $value);
    }
}
