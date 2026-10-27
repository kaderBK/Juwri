<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Models;

use App\Services\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Services\Builder\BuilderRender;
use Juwri\Modules\Content\Services\Builder\MergeTags;

/**
 * Theme Studio phase A: an owner-authored Builder.js block for ONE store.
 *
 * `html` is the sanitized draft, `published_html` the sanitized snapshot the
 * storefront renders (both went through Html::cleanPage on write; the render
 * path re-runs it at echo through BuilderRender::page). Every read that can
 * reach a visitor is scoped by store_id — a block never renders for another store.
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property string|null $design_json
 * @property string|null $html
 * @property string|null $published_html
 * @property string|null $locale
 * @property string|null $placement
 * @property string $status
 * @property int|null $updated_by
 */
class StoreCustomBlock extends Model
{
    use BelongsToAccount;

    protected $table = 'store_custom_blocks';

    protected $guarded = ['id'];

    /** Blade-theme anchor points a block can be placed at (null = Studio section only). */
    public const PLACEMENTS = ['top', 'after-hero', 'before-menu', 'after-menu', 'footer'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    /** Blocks a plan without an explicit `custom_blocks_limit` may keep. */
    public const DEFAULT_LIMIT = 5;

    /** Feature key gating authoring (dashboard) and rendering (storefront). */
    public const FEATURE = 'custom_sections';

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /**
     * @param  Builder<StoreCustomBlock>  $query
     * @return Builder<StoreCustomBlock>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)->whereNotNull('published_html');
    }

    /**
     * The owner's block allowance: the plan's `custom_blocks_limit` (Entitlements
     * chain + add-ons); a plan that never set it gets DEFAULT_LIMIT. NULL = unlimited
     * (the 999 sentinel).
     */
    public static function limitFor(User $owner): ?int
    {
        $raw = Entitlements::planValue($owner, 'custom_blocks_limit');
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }

        return Entitlements::limit($owner, 'custom_blocks');
    }

    /**
     * The store's published blocks visible in a locale (a block with a locale shows
     * ONLY in that locale), optionally for one placement, in creation order.
     *
     * @return Collection<int, StoreCustomBlock>
     */
    public static function visibleFor(int $storeId, ?string $locale, ?string $placement = null): Collection
    {
        $query = self::query()->published()->where('store_id', $storeId);
        if ($placement !== null) {
            $query->where('placement', $placement);
        }
        $locale = (string) $locale;
        $query->where(function (Builder $q) use ($locale) {
            $q->whereNull('locale')->orWhere('locale', '');
            if ($locale !== '') {
                $q->orWhere('locale', $locale);
            }
        });

        return $query->orderBy('id')->get();
    }

    /**
     * Merge-tag values for a store's blocks: the shared Post tokens plus the
     * store's phone / address (raw — MergeTags escapes). `store.phone_url` is a
     * ready `tel:` link for buttons (Builder.js would prefix a bare `tel:` href
     * with the template dir, but leaves a `{{token}}` alone).
     *
     * @return array<string, string>
     */
    public static function tokens(Store $store): array
    {
        $phone = trim((string) ($store->phone ?? ''));
        $dial = (string) preg_replace('/[^0-9+]/', '', $phone);

        return array_merge(MergeTags::forPost($store), [
            'store.phone' => $phone,
            'store.phone_url' => $dial !== '' ? 'tel:'.$dial : '#',
            'store.address' => trim((string) ($store->address ?? '')),
        ]);
    }

    /**
     * The visitor-facing HTML of this block through the ONE builder render
     * pipeline (cleanPage at echo → nonce'd <style>, escaped merge tags, curated
     * forms wired to builder.form.submit).
     */
    public function renderFor(Store $store, ?string $pageUrl = null): string
    {
        if ((int) $this->store_id !== (int) $store->id) {
            return ''; // never render another store's block
        }

        $formCtx = [
            'surface' => 'block',
            'id' => $this->id,
            'to' => (string) (optional($store->user)->email ?? ''),
            'label' => (string) ($store->title ?? ''),
            'url' => $pageUrl ?? url('/'.$store->slug),
        ];

        return BuilderRender::page((string) $this->published_html, self::tokens($store), $formCtx);
    }
}
