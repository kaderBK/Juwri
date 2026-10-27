<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use App\Models\Concerns\BustsMenuCache;
use App\Models\Concerns\TranslatesForStorefront;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Juwri\Core\Models\Concerns\AppliesTimeWindow;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Modules\Media\Services\StorageService;
use Juwri\Modules\Menu\Models\Concerns\FiresMenuWebhook;
use Juwri\Modules\Menu\Models\Menu;

/**
 * @property string|null $public_id UUIDv7 public id (J25-1)
 */
class MenuCategory extends Model
{
    protected $table = 'categories';

    /* PARITY-RUN T5: category-level availability windows ("Breakfast 07:00-11:00"),
       same column vocabulary and same resolver as the item-level ones. */
    use AppliesTimeWindow;
    use BelongsToAccount;
    use BustsMenuCache;

    use FiresMenuWebhook;
    use HasFactory;
    use HasUuids;
    use TranslatesForStorefront;

    public $timestamps = false;

    /** MCP-expansion P0-4: entity label for the menu.updated webhook. */
    protected string $menuWebhookEntity = 'category';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'translations' => 'object',
    ];

    /** The category name in the storefront visitor's language (its `title` translation). */
    public function getNameAttribute($value): mixed
    {
        return $this->storefrontTranslation('title') ?? $value;
    }

    /**
     * Public URL for the category image (empty string when unset).
     */
    public function getImageUrlAttribute(): string
    {
        if (empty($this->image)) {
            return '';
        }

        $storeId = $this->store_id !== null ? (int) $this->store_id : null;
        if (! StorageService::exists('storage/menu/', (string) $this->image, $storeId)) {
            return '';
        }

        return media_url('storage/menu/', $this->image, $storeId);
    }

    /** @return HasMany<MenuCategory, $this> */
    public function subcategories(): HasMany
    {
        // H12: no chained ->with('menus') — Post::menu_categories() is lean and
        // Post::menu_categories_with_items() eager-loads subcategories.menus with
        // the active filter for the 15 legacy templates that need them.
        return $this->hasMany(self::class, 'parent')->orderBy('position');
    }

    /**
     * This category's items, restricted to the store the category belongs to.
     *
     * The store_id filter is not redundant with category_id: 11 live rows carry a
     * store_id that differs from their category's, and the 15 legacy themes render
     * $category->menus straight into a public page — so an unscoped relation served one
     * store's item from another store's menu.
     *
     * The store is read back off the category row rather than off $this->store_id
     * because a relation resolves its constraints once, against the first model, and
     * then reuses them for every model in an eager load. Baking one category's store id
     * into a query that answers for all of them would filter the rest against the wrong
     * store the moment anything eager-loads categories across stores.
     *
     * An item whose store_id disagrees with its category is now rendered by
     * neither store. That is deliberate — serving it to the wrong tenant is worse than
     * not serving it — but it means the row is only reachable again once the underlying
     * data is repaired. See rev/audit-residue/DATA-REPAIR-PLAN.md.
     *
     * @return HasMany<Menu, $this>
     */
    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class, 'category_id')
            ->whereIn('items.store_id', function ($query) {
                $query->select('store_id')
                    ->from('categories')
                    ->whereColumn('categories.id', 'items.category_id');
            })
            ->orderBy('position');
    }

    /**
     * Deleting a category deletes its items one by one (so each item's own
     * delete hooks run). The category image is library-managed and shared,
     * so no file is removed here.
     */
    protected static function booted(): void
    {
        static::deleting(fn (self $category) => $category->loadMissing('menus')->menus->each->delete());
    }

    /**
     * P1-03 (J25-1): UUIDv7 public id (HasUuids) for URLs/APIs; the bigint id stays the primary key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** P6-24b: a category window / name / order edit changes cached storefront cards. */
    public function menuCacheRestaurantId(): ?int
    {
        return $this->store_id ? (int) $this->store_id : null;
    }
}
