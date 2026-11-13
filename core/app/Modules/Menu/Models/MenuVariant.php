<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Models;

use App\Models\Concerns\BustsMenuCache;
use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Money\Concerns\HasMinorMoney;
use Juwri\Core\Money\MinorMoneyModel;
use Juwri\Modules\Menu\Services\Retail\StockAlerts;

/**
 * @property string|null $public_id P3-02: UUIDv7, the id the storefront view model and API expose
 * @property string|null $name
 * @property string $title Transient, computed label joined from the selected option values (not a column).
 * @property string|null $sku rev/multi-vertical Lane A2
 * @property int|null $stock_qty rev/multi-vertical Lane A2 — null = not stock-tracked
 * @property string|null $barcode rev/multi-vertical Lane A2
 * @property int|null $compare_at_minor P3-08: struck-through "was" price, minor units of currency_code
 * @property int|null $low_stock_threshold P3-08 (API-6): at or below it the stock state is `low`; NULL = the item's
 * @property string|null $image P3-08: the cell's own picture (a colour's photo)
 * @property string|null $gtin P3-08: barcode number for Google merchant listings (GTIN-8/12/13/14)
 *
 * P1-05 money (*_minor + currency_code; major attributes via HasMinorMoney):
 * @property float|null $price
 * @property int|null $price_minor
 * @property string|null $currency_code
 */
class MenuVariant extends Model implements MinorMoneyModel
{
    use BelongsToAccount;
    use BustsMenuCache;
    use HasMinorMoney;
    use HasUuids;

    /** @var array<string, string> P1-05: a number (the old column was uncast) */
    protected array $minorMoney = ['price' => 'price_minor'];

    use HasFactory; // P5

    public $timestamps = false;

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    /** P5: the store this variant belongs to (via its menu). */
    public function menuCacheRestaurantId(): ?int
    {
        $rid = Menu::where('id', $this->menu_id)->value('store_id');

        return $rid ? (int) $rid : null;
    }

    /** @var list<string> */
    protected $guarded = [];

    /** @return list<string> P3-02: the public id (the key stays the bigint id) */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /** @var array<string, string> */
    protected $casts = [
        'options' => 'object',
        'stock_qty' => 'integer',
        'compare_at_minor' => 'integer',
        'low_stock_threshold' => 'integer',
    ];

    /**
     * P3-08: the matrix cell of a variant built on the item's own option groups — {group id: option index} — or null
     * for a variant whose `options` are axis labels ({"Size": "M"}, VariantMatrixGenerator) or empty.
     *
     * @return array<int, int>|null
     */
    public function cell(): ?array
    {
        $options = json_decode((string) json_encode($this->options), true);
        if (! is_array($options) || $options === []) {
            return null;
        }
        $cell = [];
        foreach ($options as $group => $index) {
            if (! is_numeric($group) || ! is_numeric($index)) {
                return null;
            }
            $cell[(int) $group] = (int) $index;
        }
        ksort($cell);

        return $cell;
    }

    /**
     * rev/multi-vertical Lane A2 — one axes combination as a readable label,
     * e.g. "Size: M, Color: Red". Built from the `options` JSON cell the
     * VariantMatrixGenerator writes ({"Size":"M","Color":"Red"}). Falls back to
     * the legacy `title` accessor (joined option-group selections) when this
     * variant wasn't produced by the matrix generator.
     */
    public function getAxesLabelAttribute(): string
    {
        // P3-08: a matrix cell ({group id: option index}) reads as "Colour: Sand, Size: M" from the item's groups.
        $cell = $this->cell();
        if ($cell !== null) {
            $parts = [];
            foreach (MenuVariantOption::query()->whereIn('id', array_keys($cell))->get()->keyBy('id') as $groupId => $group) {
                $labels = array_values((array) $group->baseList('options'));
                $label = $labels[$cell[(int) $groupId]] ?? null;
                if (is_scalar($label)) {
                    $parts[(int) $groupId] = $group->getRawOriginal('title').': '.$label;
                }
            }
            ksort($parts);

            return implode(', ', $parts);
        }

        $options = $this->options;
        if (is_object($options) && get_object_vars($options)) {
            $parts = [];
            foreach (get_object_vars($options) as $axis => $value) {
                $parts[] = $axis.': '.$value;
            }

            return implode(', ', $parts);
        }

        return (string) ($this->title ?? '');
    }

    /**
     * rev/multi-vertical Lane A2 — deduct stock for ONE variant cell on an
     * order line. Exact mirror of {@see Menu::deductStock}: one conditional
     * UPDATE (no race), oversell still deducts the full qty (goes negative,
     * truthful) and is logged rather than silently clamped. A null
     * `stock_qty` means this variant is not stock-tracked — a no-op, so every
     * legacy variant (pizza-size, modifier-driven) is unaffected.
     */
    public static function deductStock(int $variantId, int $qty): bool
    {
        $qty = max(0, $qty);
        $variant = static::find($variantId);
        if (! $variant || $variant->stock_qty === null || $qty === 0) {
            return true;
        }

        $ok = static::whereKey($variantId)
            ->where('stock_qty', '>=', $qty)
            ->decrement('stock_qty', $qty) > 0;

        if (! $ok) {
            static::whereKey($variantId)->decrement('stock_qty', $qty);
            Log::warning('variant_stock.oversell', [
                'menu_variant_id' => $variantId,
                'menu_id' => $variant->menu_id,
                'requested' => $qty,
            ]);
        }

        $storeId = $variant->menuCacheRestaurantId();
        if ($storeId) {
            StorefrontMenuCache::bust($storeId);
        }

        return $ok;
    }

    /**
     * P3-08 (REVIEW, Opus: stock race): take `$qty` of this cell for a CUSTOMER order or refuse — never oversell.
     * One conditional UPDATE (`where stock_qty >= $qty`), which MySQL/InnoDB and PostgreSQL run under the row lock
     * against the latest committed value: of two shoppers racing for the last unit exactly one gets it. A cell that
     * is not stock-tracked (null) always succeeds. Staff / partner channels keep {@see deductStock()}.
     *
     * @return bool false = not enough stock (nothing was taken)
     */
    public static function reserveStock(int $variantId, int $qty): bool
    {
        $qty = max(0, $qty);
        $variant = static::find($variantId);
        if (! $variant || $variant->stock_qty === null || $qty === 0) {
            return true;
        }

        $ok = static::whereKey($variantId)
            ->where('stock_qty', '>=', $qty)
            ->decrement('stock_qty', $qty) > 0;
        if ($ok) {
            StorefrontMenuCache::bust($variant->menuCacheRestaurantId());
        }

        return $ok;
    }

    /** rev/multi-vertical Lane A2 — restore stock after a void/edit; mirror of deductStock. */
    public static function restoreStock(int $variantId, int $qty): void
    {
        $qty = max(0, $qty);
        $variant = static::find($variantId);
        if (! $variant || $variant->stock_qty === null || $qty === 0) {
            return;
        }

        $wasOut = (int) $variant->stock_qty <= 0;
        static::whereKey($variantId)->increment('stock_qty', $qty);
        StorefrontMenuCache::bust($variant->menuCacheRestaurantId());
        if ($wasOut) {
            // P3-08 / N5: the cell is back — the waiting back-in-stock requests go out (after commit).
            StockAlerts::restocked((int) $variant->menu_id, (int) $variant->id);
        }
    }
}
