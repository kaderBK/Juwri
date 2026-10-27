<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Services;

use App\Models\LoyaltyProgram;
use App\Models\StoreOption;
use App\Services\Directory\OpeningHoursService;
use App\Services\Directory\StoreRatingService;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\GiftCardService;
use App\Support\BioLinkOutbound;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;
use Juwri\Modules\Menu\Models\Menu;
use Throwable;

/**
 * BIOLINK-RUN Lane A: the one engine every lane speaks to. SPEC §3 is the LOCKED
 * public contract — Lane B stubs these signatures, Lane C renders their output.
 * Signatures and return shapes here do NOT change.
 *
 * Owns: settings resolution, block resolution (with legacy dual-read + per-locale
 * content), routing mode, preset entitlement, view/click recording (bio_clicks
 * only — NEVER store_page_views, G1), and insights aggregation.
 */
class BioLinkService
{
    /** Merged onto stored `bio_settings` (SPEC §3 defaults). */
    public const DEFAULTS = [
        'enabled' => false,
        'preset' => 'classic_flat',
        'mode' => 'menu_first',
        'sections' => [
            'hours' => true,
            'socials' => true,
            'capture' => false,
        ],
        'branch_pages' => false,
    ];

    /** The 6 basic-tier presets (SPEC §6); everything else is pro. */
    public const BASIC_PRESETS = [
        'classic_flat', 'classic_dark', 'glass_gradient',
        'midnight_glass', 'menu_card', 'majlis_gold',
    ];

    public const MODES = ['menu_first', 'bio_first', 'bio_only'];

    /**
     * The 38 presets → their layout family (SPEC §6). Drives presets() before
     * Lane C's json files exist; json overlays this when merged.
     */
    public const PRESET_FAMILIES = [
        // basic (first 6)
        'classic_flat' => 'list', 'classic_dark' => 'list', 'glass_gradient' => 'list',
        'midnight_glass' => 'list', 'menu_card' => 'menu_card', 'majlis_gold' => 'list',
        // pro
        'retro_diner' => 'list', 'swiss_grid' => 'swiss', 'sticker_pop' => 'list',
        'bento_hero' => 'bento', 'hairline_luxe' => 'list', 'street_brutalist' => 'brutalist',
        'neon_arcade' => 'list', 'broadsheet' => 'broadsheet', 'pastel_zen' => 'list',
        'harbour' => 'list', 'zellige_arch' => 'list', 'y2k_chrome' => 'list',
        'kraft_paper' => 'list', 'soft_gradient' => 'list', 'retro_outline' => 'list',
        'photo_hero' => 'photo_hero', 'creator_store' => 'store_rows', 'story_card' => 'story_card',
        'social_grid' => 'social_grid', 'bento_site' => 'bento', 'support_cards' => 'store_rows',
        'stats_promo' => 'list', 'chat_landing' => 'chat', 'vip_gold' => 'list',
        'neon_minimal' => 'list', 'honey_hive' => 'list', 'cover_card' => 'photo_hero',
        'type_lite' => 'type_lite', 'video_hero' => 'photo_hero', 'countdown_drop' => 'countdown',
        'newsletter_first' => 'list', 'wave_pastel' => 'list',
    ];

    /** Translatable config text keys (SPEC §3 i18n). */
    public const I18N_TEXT_KEYS = ['text', 'title', 'subtitle', 'schedule_label', 'price_label'];

    /**
     * BIOLINK-TPL T1-1: the ONLY block types a starter archetype may seed. The
     * four section-typed families (hours/map/whatsapp/socials) are configured via
     * the archetype's `sections` map and are NEVER seeded as blocks.
     */
    public const SEEDABLE_BLOCK_TYPES = [
        'link', 'menu', 'menu_item', 'featured_dish', 'announcement',
        'delivery_apps', 'reservation', 'reviews', 'video', 'capture',
    ];

    /** Types that exist as SECTION toggles only — never seeded as blocks (T1-1). */
    public const SECTION_BLOCK_TYPES = ['hours', 'map', 'whatsapp', 'socials'];

    /** The 6 starter archetypes (SPEC T1-1). Unknown preset → restaurant_essential. */
    public const ARCHETYPES = [
        'restaurant_essential', 'delivery_late_night', 'cafe',
        'fine_dining', 'social_grid', 'minimal',
    ];

    /**
     * bio_settings + defaults merged. Stored value is a JSON post_option.
     *
     * @return array{enabled:bool,preset:string,mode:string,sections:array<string,bool>,branch_pages:bool}
     */
    public function resolveSettings(int $storeId): array
    {
        $stored = store_options($storeId, 'bio_settings', null);

        if (is_object($stored)) {
            $stored = json_decode(json_encode($stored), true);
        }

        if (! is_array($stored)) {
            $stored = [];
        }

        // Deep-merge so a stored partial (e.g. only `enabled`) keeps default
        // sections. array_replace_recursive lets a stored `sections` override
        // individual keys without dropping the rest.
        $merged = array_replace_recursive(self::DEFAULTS, $stored);

        $merged['enabled'] = (bool) ($merged['enabled'] ?? false);
        $merged['branch_pages'] = (bool) ($merged['branch_pages'] ?? false);
        if (! in_array($merged['mode'] ?? '', self::MODES, true)) {
            $merged['mode'] = 'menu_first';
        }

        return $merged;
    }

    /**
     * Ordered, render-ready blocks for a bio page. See SPEC §3 for the row shape.
     *
     * @return array<int, array{id:int,type:string,label:?string,url:?string,config:array<string,mixed>,active:bool,scheduled:bool,clicks:int}>
     */
    public function blocksFor(int $storeId, ?int $branchId = null, bool $includeHidden = false, ?string $source = null): array
    {
        $rows = BioBlock::query()
            ->where('store_id', $storeId)
            ->when($branchId !== null, function ($q) use ($branchId) {
                // Branch page: branch-scoped blocks AND store-wide (null branch)
                // blocks; A7 resolves same-type override precedence.
                $q->where(function ($qq) use ($branchId) {
                    $qq->where('branch_id', $branchId)->orWhereNull('branch_id');
                });
            }, function ($q) {
                $q->whereNull('branch_id');
            })
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        // G4 dual-read: only while the store has NOT been migrated AND has no
        // real blocks do we synthesize the legacy link1..10 fields (read-only).
        if ($rows->isEmpty() && ! $this->isMigrated($storeId)) {
            return $this->legacyLinkBlocks($storeId);
        }

        // A7 branch override: on a branch page a branch-scoped block REPLACES a
        // store-wide block of the same type (hours/map/whatsapp etc. become the
        // branch's). Applied on the models so contract row order stays final.
        if ($branchId !== null) {
            $branchTypes = $rows->where('branch_id', $branchId)->pluck('type')->all();
            $rows = $rows->reject(
                fn ($b) => $b->branch_id === null && in_array($b->type, $branchTypes, true)
            )->values();
        }

        // A7 scheduled visibility resolves in the STORE's timezone, never the
        // server clock (a window opening 11:00 is live by 11:05 store-local).
        $now = now(store_tz($storeId));
        $out = [];

        foreach ($rows as $block) {
            $scheduled = $this->hasScheduleWindow($block);

            if (! $includeHidden) {
                if (! $block->active) {
                    continue;
                }
                if (! $block->isScheduledVisible($now)) {
                    continue;
                }
                // bio-t15 A12: the cross-cutting `config.schedule` window/day-of-week
                // gate (SPEC §2 A12) — additive to the DB visible_from/visible_until
                // columns above (a block must clear BOTH). Same store-local `$now`.
                if (! $this->configScheduleVisible(is_array($block->config) ? $block->config : [], $now)) {
                    continue;
                }
                // bio-t15 A11/SPEC §8: `tipjar` is PRO — a non-entitled store's
                // public page never renders it, no matter how the row got
                // created. The builder (editorBlocks(), a separate read path)
                // still shows it so Lane C's C7 can render a lock badge.
                if ($block->type === 'tipjar' && ! $this->bioProAllowed($storeId)) {
                    continue;
                }
            }

            $out[] = $this->renderRow($block, $scheduled);
        }

        // T1-5: resolve menu_item / featured_dish item_id → name/price/image/deep
        // link (batched item lookup, slug resolved once).
        $out = $this->resolveItemBlocks($out, $storeId);

        // T2-6: a reviews block with source=live pulls the store's live rating/count
        // from StoreRatingService (cached); manual keeps the owner-typed fields.
        $out = $this->resolveReviewBlocks($out, $storeId);

        // bio-t15 A1: an `hours` block resolves its week + open-now status
        // server-side (SSR — no client clock), auto-sourced from the store's real
        // hours data when config.source='auto', else the owner-typed week.
        $out = $this->resolveHoursBlocks($out, $storeId);

        // bio-t15 A6: a `giftcard` block in mode='auto' surfaces the store's
        // existing gift-card purchase page when that feature is live; else it
        // is left url-less so the blade degrades to nothing (never rebuilds the
        // gift-card feature itself).
        $out = $this->resolveGiftcardBlocks($out, $storeId);

        // bio-t15 A7: a `loyalty` block in mode='auto' surfaces the storefront
        // when an active loyalty program exists for this store (the closest
        // existing seam — see LEDGER "Contract changes": no anonymous single-URL
        // enroll/wallet-save page exists on this platform).
        $out = $this->resolveLoyaltyBlocks($out, $storeId);

        // T1-6: stamp src=bio on links that point at THIS store's storefront so the
        // storefront's own StoreView source split attributes the traffic to the bio
        // page. Owner-entered external links + other stores + qr scans are untouched.
        $out = $this->attributeStorefrontUrls($out, $storeId);

        // bio-t15 D3 (gap-fix): auto-tag OUTBOUND links (links that leave the bio page)
        // with utm_source=bio&utm_medium=<source>&utm_campaign=<slug>. Only the public
        // render passes $source; the builder/read paths pass null and are never tagged.
        $out = $this->applyUtmTags($out, $storeId, $source);

        // bio-t15 A13: `config.pinned` (SPEC §2 A13, ANY block type) sorts a block
        // to the top of its flow — stable within the pinned group (a straight
        // stable-partition rather than usort, so the group order is unambiguous
        // regardless of sort-algorithm details).
        $out = $this->sortPinnedFirst($out);

        return $out;
    }

    /**
     * Stable-partition: every row with `config.pinned` truthy first (in their
     * original relative order), then everything else (also in original order).
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array<string,mixed>>
     */
    protected function sortPinnedFirst(array $rows): array
    {
        $pinned = [];
        $rest = [];
        foreach ($rows as $row) {
            if (! empty($row['config']['pinned'] ?? false)) {
                $pinned[] = $row;
            } else {
                $rest[] = $row;
            }
        }

        return array_merge($pinned, $rest);
    }

    /** 'menu_first' | 'bio_first' | 'bio_only'. */
    public function resolveMode(int $storeId): string
    {
        return $this->resolveSettings($storeId)['mode'];
    }

    /**
     * Plan-tier check: basic presets always allowed; pro presets only when the
     * store's plan grants the `all` preset tier (A2 wires the entitlement source).
     */
    public function presetAllowed(int $storeId, string $preset): bool
    {
        if (in_array($preset, self::BASIC_PRESETS, true)) {
            return true;
        }

        return $this->presetTierForStore($storeId) === 'all';
    }

    /**
     * G1: a bio PAGE VIEW = a bio_clicks upsert at block_id=0. NEVER StoreView —
     * store_page_views feeds the scan-limit counter and must not be inflated by bio.
     */
    public function recordView(int $storeId, ?int $branchId, string $source, string $device, string $country = '--', string $ref = ''): void
    {
        $this->upsertCount($storeId, BioClick::VIEW_SENTINEL, $source, $device, $country, $ref);
    }

    /**
     * A block tap. Writes both the slim bio_clicks counter and the block's
     * denormalised clicks_cached (G8; bio:aggregate reconciles the two).
     */
    public function recordClick(int $storeId, int $blockId, string $source, string $device, string $country = '--', string $ref = ''): void
    {
        $this->upsertCount($storeId, $blockId, $source, $device, $country, $ref);

        if ($blockId > 0) {
            BioBlock::where('id', $blockId)->where('store_id', $storeId)->increment('clicks_cached');
        }
    }

    /**
     * Insights over a range. Views = block_id=0 rows; ctr = clicks/views. Sourced
     * from bio_clicks ONLY, never store_page_views (G1). A11 finalises range parsing.
     *
     * @return array{views:int,clicks:int,ctr:float,qr_scans:int,per_block:array<int,array{block_id:int,clicks:int,label:string|null,type:string|null}>,sources:array<int,array{source:string,count:int}>,devices:array<int,array{device:string,count:int}>}
     */
    public function statsFor(int $storeId, string $range): array
    {
        $since = $this->rangeStart($range);

        $base = BioClick::query()->where('store_id', $storeId)
            ->when($since !== null, fn ($q) => $q->where('day', '>=', $since->toDateString()));

        $views = (int) (clone $base)->where('block_id', BioClick::VIEW_SENTINEL)->sum('count');
        $clicks = (int) (clone $base)->where('block_id', '!=', BioClick::VIEW_SENTINEL)->sum('count');

        // QR-scan KPI (additive to the LOCKED shape; Lane B's insights merges it):
        // bio page views whose classified source is a QR scan — from bio_clicks,
        // NEVER store_page_views (G1).
        $qrScans = (int) (clone $base)->where('block_id', BioClick::VIEW_SENTINEL)
            ->where('source', 'qr')->sum('count');

        $perBlockRows = (clone $base)->where('block_id', '!=', BioClick::VIEW_SENTINEL)
            ->select('block_id', DB::raw('SUM(count) as c'))
            ->groupBy('block_id')->orderByDesc('c')->get();
        // Attach each block's label/type so the leaderboard names the row instead of a
        // generic "Block". A deleted block leaves null (the view falls back gracefully).
        $blockMeta = BioBlock::query()->where('store_id', $storeId)
            ->whereIn('id', $perBlockRows->pluck('block_id')->all())
            ->get(['id', 'label', 'type'])->keyBy('id');
        $perBlock = $perBlockRows->map(function ($r) use ($blockMeta) {
            $meta = $blockMeta->get((int) $r->block_id);
            $label = $meta !== null ? trim((string) $meta->label) : '';

            return [
                'block_id' => (int) $r->block_id,
                'clicks' => (int) $r->getAttribute('c'),
                'label' => $label !== '' ? $label : null,
                'type' => $meta !== null ? (string) $meta->type : null,
            ];
        })->all();

        // Sources / devices = page-traffic split (view rows), which G5 stamps the
        // same way it stamps clicks.
        $sources = (clone $base)->where('block_id', BioClick::VIEW_SENTINEL)
            ->select('source', DB::raw('SUM(count) as c'))
            ->groupBy('source')->orderByDesc('c')->get()
            ->map(fn ($r) => ['source' => (string) $r->source, 'count' => (int) $r->getAttribute('c')])->all();

        $devices = (clone $base)->where('block_id', BioClick::VIEW_SENTINEL)
            ->select('device', DB::raw('SUM(count) as c'))
            ->groupBy('device')->orderByDesc('c')->get()
            ->map(fn ($r) => ['device' => (string) $r->device, 'count' => (int) $r->getAttribute('c')])->all();

        return [
            'views' => $views,
            'clicks' => $clicks,
            'ctr' => $views > 0 ? round($clicks / $views, 4) : 0.0,
            'qr_scans' => $qrScans,
            'per_block' => $perBlock,
            'sources' => $sources,
            'devices' => $devices,
        ];
    }

    // ── Builder writes (Lane B dashboard contract) ──────────────────────────

    /**
     * Persist the store's bio_settings (merged onto the resolved current value so
     * a partial write never drops keys). Lane B's settings page calls this.
     *
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(int $storeId, array $settings): void
    {
        $merged = array_replace_recursive($this->resolveSettings($storeId), $settings);
        StoreOption::updateStoreOption($storeId, 'bio_settings', json_encode($merged));
        StoreOption::flushOptionMemo();
    }

    /**
     * Create a block for a store/branch and return its editor row. G4: the FIRST
     * builder insert migrates the legacy link1..10 first (so the other links do
     * not vanish the moment bio_blocks becomes non-empty), then appends the new
     * block. `$data` is the validated payload from Lane B (type/label/url/config
     * incl. `_i18n`/active/visible_from/visible_until).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function createBlock(int $storeId, ?int $branchId, array $data): array
    {
        if (! $this->isMigrated($storeId)) {
            $this->migrateLegacy($storeId);
        }

        $block = BioBlock::create([
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'type' => (string) ($data['type'] ?? 'link'),
            'label' => $data['label'] ?? null,
            'url' => $data['url'] ?? null,
            'config' => is_array($data['config'] ?? null) ? $data['config'] : [],
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'visible_from' => $data['visible_from'] ?? null,
            'visible_until' => $data['visible_until'] ?? null,
            'position' => $this->nextPosition($storeId, $branchId),
        ]);

        return $this->editorRow($block);
    }

    /**
     * Update an existing block (tenant-scoped to the store). Only the editable
     * fields are touched; branch scope and position are not moved here.
     *
     * @param  array<string,mixed>  $data
     */
    public function updateBlock(int $storeId, int $id, array $data): void
    {
        $block = BioBlock::where('id', $id)->where('store_id', $storeId)->first();
        if (! $block) {
            return;
        }

        foreach (['type', 'label', 'url', 'visible_from', 'visible_until'] as $field) {
            if (array_key_exists($field, $data)) {
                $block->{$field} = $data[$field];
            }
        }
        if (array_key_exists('config', $data)) {
            $block->config = is_array($data['config']) ? $data['config'] : [];
        }
        if (array_key_exists('active', $data)) {
            $block->active = (bool) $data['active'];
        }

        $block->save();
    }

    /** Flip a block's active flag (tenant-scoped). */
    public function toggleBlock(int $storeId, int $id): void
    {
        $block = BioBlock::where('id', $id)->where('store_id', $storeId)->first();
        if ($block) {
            $block->active = ! $block->active;
            $block->save();
        }
    }

    /** Delete a block (tenant-scoped). */
    public function deleteBlock(int $storeId, int $id): void
    {
        BioBlock::where('id', $id)->where('store_id', $storeId)->delete();
    }

    /**
     * Reorder blocks: `$orderedIds` is the new position order. Only ids that
     * belong to the store are moved; unknown/foreign ids are ignored.
     *
     * @param  array<int,int>  $orderedIds
     */
    public function reorderBlocks(int $storeId, array $orderedIds): void
    {
        $position = 0;
        foreach ($orderedIds as $id) {
            BioBlock::where('id', (int) $id)->where('store_id', $storeId)->update(['position' => $position]);
            $position++;
        }
    }

    /**
     * RAW editor rows for the builder — config KEEPS `_i18n` and text keys are
     * NOT locale-resolved, so the per-locale editor round-trips overrides. Shows
     * ALL blocks (active + inactive + scheduled) at the exact scope: store blocks
     * when `$branchId` is null, otherwise that branch's own blocks. This is the
     * edit contract; blocksFor() stays the strip-`_i18n` render contract.
     *
     * @return array<int, array<string,mixed>>
     */
    public function editorBlocks(int $storeId, ?int $branchId = null): array
    {
        return BioBlock::query()
            ->where('store_id', $storeId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn ($b) => $this->editorRow($b))->all();
    }

    /**
     * The preset catalog for the picker (slug, name, family, tier + any json
     * tokens). Reads Lane C's `bio/presets/*.json` when present and overlays the
     * built-in 38-preset catalog so the picker works before Lane C merges.
     *
     * @return array<int, array<string,mixed>>
     */
    public function presets(): array
    {
        $catalog = [];
        foreach (self::PRESET_FAMILIES as $slug => $family) {
            $catalog[$slug] = [
                'slug' => $slug,
                'name' => ucwords(str_replace('_', ' ', $slug)),
                'family' => $family,
                'tier' => in_array($slug, self::BASIC_PRESETS, true) ? 'basic' : 'pro',
            ];
        }

        $dir = resource_path('views/bio/presets');
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.json') ?: [] as $file) {
                $json = json_decode((string) file_get_contents($file), true);
                if (is_array($json) && ! empty($json['slug'])) {
                    $catalog[$json['slug']] = array_merge($catalog[$json['slug']] ?? [], $json);
                }
            }
        }

        return array_values($catalog);
    }

    /**
     * BIOLINK-TPL T1-2: seed a store's MAIN bio page from a preset's starter
     * archetype. Frozen signature (SPEC T1-2) — Lane B's AI apply path and Lane C's
     * chooser both call this.
     *
     * - Resolves preset → archetype (`starter` key on the preset json); an unknown
     *   or missing archetype falls back to `restaurant_essential`.
     * - Wraps everything in one transaction. `$replace` clears the store's existing
     *   MAIN-PAGE blocks (branch pages untouched) BEFORE seeding.
     * - Seeds with `branch_id = NULL`, honours `blockLimit()` (999 = unlimited),
     *   trimming the lowest-priority entries first.
     * - Smart pre-fill from real store data (menu URL, gift-card page URL); a block
     *   whose `requires` data is missing is seeded `active = 0` (hidden) — never
     *   with fake placeholder data.
     * - Persists `bio_settings.preset` + the archetype `sections` map via
     *   saveSettings(). NEVER touches `enabled` / `mode` (no auto-publish).
     *
     * @return int number of blocks created
     */
    public function applyStarter(int $storeId, string $presetSlug, bool $replace = false): int
    {
        $store = Store::find($storeId);
        if (! $store) {
            return 0;
        }

        $def = $this->loadArchetype($this->archetypeForPreset($presetSlug));
        $facts = $this->starterFacts($store);

        // Highest priority first (so a limit trims the least-important entries).
        $entries = collect($def['blocks'])
            ->sortByDesc(fn ($b) => (int) ($b['priority'] ?? 0))
            ->values()
            ->all();

        $limit = $this->blockLimit($storeId); // 999 = unlimited sentinel

        return DB::transaction(function () use ($storeId, $def, $entries, $replace, $limit, $facts, $presetSlug) {
            // G4: a store still on legacy link1..10 is migrated before we seed, so
            // adding starter blocks never makes the legacy links silently vanish
            // (unless we are replacing, which clears them by intent).
            if (! $this->isMigrated($storeId)) {
                if ($replace) {
                    $this->markMigrated($storeId);
                } else {
                    $this->migrateLegacy($storeId);
                }
            }

            if ($replace) {
                BioBlock::where('store_id', $storeId)->whereNull('branch_id')->delete();
            }

            $existing = BioBlock::where('store_id', $storeId)->whereNull('branch_id')->count();
            $capacity = $limit >= 999 ? PHP_INT_MAX : max(0, $limit - $existing);
            if ($capacity < count($entries)) {
                $entries = array_slice($entries, 0, $capacity);
            }

            $basePos = (int) BioBlock::where('store_id', $storeId)->whereNull('branch_id')->max('position');
            $created = 0;

            foreach ($entries as $i => $entry) {
                BioBlock::create($this->buildStarterBlock($entry, $facts, $basePos + $i + 1));
                $created++;
            }

            // Preset + sections; leaves enabled/mode exactly as they were.
            $this->saveSettings($storeId, [
                'preset' => $presetSlug,
                'sections' => $def['sections'],
            ]);

            return $created;
        });
    }

    /**
     * Map a preset slug to its starter archetype via the preset json's `starter`
     * key. Unknown preset / unknown archetype → restaurant_essential (T1-1/T1-2).
     */
    public function archetypeForPreset(string $presetSlug): string
    {
        $file = resource_path('views/bio/presets/'.basename($presetSlug).'.json');
        if (is_file($file)) {
            $json = json_decode((string) file_get_contents($file), true);
            $key = is_array($json) ? ($json['starter'] ?? null) : null;
            if (is_string($key) && in_array($key, self::ARCHETYPES, true)) {
                return $key;
            }
        }

        return 'restaurant_essential';
    }

    /**
     * Load an archetype definition (bio/starters/{key}.json). A missing/broken file
     * falls back to restaurant_essential so applyStarter always has a definition.
     *
     * @return array{key:string,sections:array<string,bool>,blocks:array<int,array<string,mixed>>}
     */
    protected function loadArchetype(string $key): array
    {
        foreach ([$key, 'restaurant_essential'] as $candidate) {
            $file = resource_path('views/bio/starters/'.basename($candidate).'.json');
            if (is_file($file)) {
                $json = json_decode((string) file_get_contents($file), true);
                if (is_array($json) && is_array($json['blocks'] ?? null)) {
                    $json['sections'] = is_array($json['sections'] ?? null) ? $json['sections'] : [];

                    return $json;
                }
            }
        }

        return ['key' => 'restaurant_essential', 'sections' => [], 'blocks' => []];
    }

    /**
     * Store-derived facts an archetype entry uses for pre-fill + requires gating.
     * Resolved once per applyStarter so a 5-block seed hits each source at most once.
     *
     * @return array<string,mixed>
     */
    protected function starterFacts(Store $store): array
    {
        $giftCards = false;
        try {
            $giftCards = GiftCardService::enabled($store->id);
        } catch (Throwable $e) {
            $giftCards = false;
        }

        $reviews = 0;
        try {
            $reviews = (int) StoreRatingService::forStore($store->id)['count'];
        } catch (Throwable $e) {
            $reviews = 0;
        }

        // The honest "can a diner book here" signal is the SAME per-store flag the
        // public booking page requires (enable_reservations), not the plan
        // entitlement — a store entitled but not switched on cannot take bookings.
        $reservations = (bool) store_options($store->id, 'enable_reservations');

        return [
            'store_id' => $store->id,
            // Plain storefront menu URL — the render layer (T1-6) appends `src=bio`,
            // so it is NOT baked in here (avoids a double src param).
            'menu_url' => route('publicView', $store->slug),
            'giftcard_url' => $giftCards ? route('store.giftcards.buy', $store->slug) : '',
            'has_menu_items' => Menu::where('store_id', $store->id)->exists(),
            'has_reservations' => $reservations,
            'gift_cards' => $giftCards,
            'has_reviews' => $reviews > 0,
        ];
    }

    /**
     * Build one BioBlock::create() payload from an archetype entry: translate the
     * user-facing text, apply the requires→active rule and the prefill URL, and
     * strip the internal `prefill` marker so it never persists in config.
     *
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    protected function buildStarterBlock(array $entry, array $facts, int $position): array
    {
        $type = (string) ($entry['type'] ?? 'link');
        $config = is_array($entry['config'] ?? null) ? $entry['config'] : [];

        $prefill = $config['prefill'] ?? null;
        unset($config['prefill']); // internal marker, never stored on the block

        $url = null;
        if ($prefill === 'menu') {
            $url = (string) ($facts['menu_url'] ?? '');
        } elseif ($prefill === 'giftcard') {
            $url = ($facts['giftcard_url'] ?? '') !== '' ? (string) $facts['giftcard_url'] : null;
        }

        // Translate the block's own user-facing content (label + known text keys).
        $label = isset($entry['label']) && $entry['label'] !== '' ? ___((string) $entry['label']) : null;
        foreach (self::I18N_TEXT_KEYS as $key) {
            if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '') {
                $config[$key] = ___($config[$key]);
            }
        }

        // Starter blocks are seeded ACTIVE (visible) so choosing a design shows its
        // full layout in the builder, ready to edit — the owner turns off or fills
        // in any block they don't want, rather than hunting for hidden ones. (The
        // `requires` metadata is kept on the archetype for future use, e.g. ordering
        // or smart defaults, but no longer auto-hides.)
        $active = true;

        return [
            'store_id' => (int) ($facts['store_id'] ?? 0),
            'branch_id' => null,
            'type' => $type,
            'label' => $label,
            'url' => $url,
            'config' => $config,
            'active' => $active,
            'position' => $position,
        ];
    }

    /** Next position for a new block at a store/branch scope. */
    protected function nextPosition(int $storeId, ?int $branchId): int
    {
        $max = (int) BioBlock::where('store_id', $storeId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId), fn ($q) => $q->whereNull('branch_id'))
            ->max('position');

        return $max + 1;
    }

    /**
     * Raw editor row for one block — config INCLUDES `_i18n`, nothing resolved.
     *
     * @return array<string,mixed>
     */
    protected function editorRow(BioBlock $b): array
    {
        return [
            'id' => (int) $b->id,
            'type' => (string) $b->type,
            'label' => $b->label,
            'url' => $b->url,
            'config' => is_array($b->config) ? $b->config : [],
            'active' => (bool) $b->active,
            'position' => (int) $b->position,
            'branch_id' => $b->branch_id ? (int) $b->branch_id : null,
            'visible_from' => optional($b->visible_from)->format('Y-m-d H:i:s'),
            'visible_until' => optional($b->visible_until)->format('Y-m-d H:i:s'),
            'clicks' => (int) $b->clicks_cached,
            'scheduled' => $this->hasScheduleWindow($b),
        ];
    }

    /**
     * bio-t15 A12: does this block carry ANY schedule restriction — the DB
     * visible_from/visible_until columns OR the cross-cutting `config.schedule`
     * window/day-of-week gate (SPEC §2 A12)? Drives the "scheduled" flag both
     * the render contract and the builder's editor rows expose, so Lane C's
     * "greyed scheduled" UI (C4) needs no extra config-poking of its own.
     */
    protected function hasScheduleWindow(BioBlock $b): bool
    {
        if ($b->visible_from !== null || $b->visible_until !== null) {
            return true;
        }

        $config = is_array($b->config) ? $b->config : [];
        $sched = is_array($config['schedule'] ?? null) ? $config['schedule'] : [];

        return ($sched['start_at'] ?? null) !== null
            || ($sched['end_at'] ?? null) !== null
            || ! empty($sched['days']);
    }

    /**
     * bio-t15 A12: is a block's `config.schedule` window open right now (SPEC §2
     * A12)? `$now` is the caller's already store-local Carbon instant. No
     * `schedule` key (or an empty one) = unrestricted = always visible.
     *
     * `days` (0=Sun..6=Sat, PHP/Carbon's own `dayOfWeek` numbering) restricts to
     * specific weekdays. `start_at`/`end_at` accept EITHER a full date(time)
     * string (an absolute one-time window, evaluated as store-local wall-clock —
     * exactly like `BioBlock::isScheduledVisible()`, never an instant-with-
     * timezone comparison) OR a bare "HH:MM" (a DAILY recurring time-of-day
     * window, e.g. a 17:00–19:00 happy hour, with overnight support). A
     * malformed bound is ignored rather than hiding the block outright.
     *
     * @param  array<string,mixed>  $config
     */
    protected function configScheduleVisible(array $config, Carbon $now): bool
    {
        $sched = is_array($config['schedule'] ?? null) ? $config['schedule'] : null;
        if (! $sched) {
            return true;
        }

        $days = is_array($sched['days'] ?? null) ? array_map('intval', $sched['days']) : null;
        if ($days !== null && $days !== [] && ! in_array((int) $now->dayOfWeek, $days, true)) {
            return false;
        }

        $start = $sched['start_at'] ?? null;
        $end = $sched['end_at'] ?? null;
        if ($start === null && $end === null) {
            return true;
        }

        // HH:MM on either bound = a daily recurring time-of-day window.
        if ($this->isClockString($start) || $this->isClockString($end)) {
            $cur = $now->format('H:i');
            if ($start !== null && $end !== null) {
                $overnight = $end < $start;

                return $overnight ? ($cur >= $start || $cur <= $end) : ($cur >= $start && $cur <= $end);
            }
            if ($start !== null) {
                return $cur >= $start;
            }

            return $cur <= $end;
        }

        // Otherwise an absolute date(time) window — wall-clock string compare,
        // same idiom as BioBlock::isScheduledVisible() (never an instant/tz compare).
        $nowStr = $now->format('Y-m-d H:i:s');
        try {
            if ($start !== null && $nowStr < Carbon::parse($start)->format('Y-m-d H:i:s')) {
                return false;
            }
            if ($end !== null) {
                $endC = Carbon::parse($end);
                // A bare date (no time component) means "through the END of that day"
                // (gap-fix): `end_at: 2026-08-30` parses to 00:00:00, which otherwise
                // hides a seasonal block from the first second of its intended last day.
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $end))) {
                    $endC = $endC->endOfDay();
                }
                if ($nowStr > $endC->format('Y-m-d H:i:s')) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            return true; // malformed bound — never hide a block over bad data
        }

        return true;
    }

    /** "HH:MM" (00:00–23:59), else false — including null/empty. */
    protected function isClockString(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value) === 1;
    }

    // ── internals ───────────────────────────────────────────────────────────

    /**
     * Portable atomic upsert-with-increment on bio_clicks. Single statement on
     * both sqlite (ON CONFLICT) and MySQL (ON DUPLICATE KEY), so it is atomic
     * without lockForUpdate (a no-op on sqlite). Table name is built from the
     * connection prefix — never hardcoded (prefix trap).
     */
    protected function upsertCount(int $storeId, int $blockId, string $source, string $device, string $country = '--', string $ref = ''): void
    {
        $source = in_array($source, BioClick::SOURCES, true) ? $source : 'other';
        $device = in_array($device, BioClick::DEVICES, true) ? $device : 'other';
        // bio-t15 F0 (Lane D grain wire): coarse country + host-only referrer joined the
        // unique grain in 2026_08_26_120000. Sentinels ('--','') keep any not-yet-enriched
        // caller collapsing onto one row; length-guard to the column widths.
        $country = $country !== '' ? mb_substr($country, 0, 2) : '--';
        $ref = mb_substr($ref, 0, 190);
        $day = now()->toDateString();

        // Query-builder upsert: ON DUPLICATE KEY (MySQL) / ON CONFLICT (PostgreSQL, sqlite) on the
        // (block_id, day, source, device, country, ref) grain; the builder adds the table prefix.
        // P1-04 slice 5: the tenant key rides along (J29-1); raw writes bypass the BelongsToAccount fill.
        $accountId = TenantFill::accountForStore(DB::connection(), (int) $storeId);
        DB::table('bio_clicks')->upsert(
            [[
                'account_id' => $accountId, 'store_id' => $storeId, 'block_id' => $blockId, 'day' => $day, 'source' => $source,
                'device' => $device, 'country' => $country, 'ref' => $ref, 'count' => 1,
            ]],
            ['block_id', 'day', 'source', 'device', 'country', 'ref'],
            ['count' => DB::raw(DB::connection()->getQueryGrammar()->wrap('bio_clicks.count').' + 1')], // qualified: PostgreSQL reports a bare "count" as ambiguous
        );
    }

    /**
     * The store's enabled locale codes — the SAME set the `/{locale}/` storefront
     * switcher uses (`get_active_languages()`). Lane B's builder shows a locale
     * switcher only when this has more than one entry; the first/current app
     * locale is the base (writes `label`/`config`), the rest write
     * `config._i18n[locale]` (SPEC §3 i18n).
     *
     * @return array<int,string>
     */
    public function enabledLocales(int $storeId): array
    {
        return array_map('strval', array_keys(get_active_languages()));
    }

    /** Has the store been cut over off the legacy link1..10 fields (G4)? */
    public function isMigrated(int $storeId): bool
    {
        return (bool) store_options($storeId, 'bio_migrated', false);
    }

    /** Mark the store migrated (first builder save + bio:migrate-legacy). Idempotent. */
    public function markMigrated(int $storeId): void
    {
        StoreOption::updateStoreOption($storeId, 'bio_migrated', '1');
        StoreOption::flushOptionMemo();
    }

    /**
     * G4 dual-read: synthesize the legacy `link1..10` / `link_label1..10` post
     * columns as READ-ONLY `link` blocks. Only reached while the store is not
     * migrated AND has no real bio_blocks (blocksFor gate), so a brand-new store
     * with no legacy links renders nothing. The synthetic id = the 1-based link
     * slot; the moment the builder is touched these become real rows
     * (migrateLegacy), so click tracking on them pre-migration is best-effort.
     *
     * @return array<int, array{id:int,type:string,label:?string,url:?string,config:array<string,mixed>,active:bool,scheduled:bool,clicks:int}>
     */
    protected function legacyLinkBlocks(int $storeId): array
    {
        $store = Store::find($storeId);
        if (! $store) {
            return [];
        }

        $out = [];
        foreach ($this->legacyLinkRows($store) as $i => $row) {
            $out[] = [
                'id' => $i,
                'type' => 'link',
                'label' => $row['label'],
                'url' => $row['url'],
                'config' => ['legacy' => true],
                'active' => true,
                'scheduled' => false,
                'clicks' => 0,
            ];
        }

        return $out;
    }

    /**
     * G4 cutover: migrate a store's legacy `link1..10` into real `link`
     * bio_blocks and set `bio_migrated`. Idempotent — a migrated store is a
     * no-op. Called by `bio:migrate-legacy` AND by the builder before its first
     * insert (so adding one block never makes the other legacy links vanish).
     *
     * @return int number of link blocks created (0 if already migrated / none)
     */
    public function migrateLegacy(int $storeId): int
    {
        if ($this->isMigrated($storeId)) {
            return 0;
        }

        $store = Store::find($storeId);
        if (! $store) {
            return 0;
        }

        // Only create rows if the store has none yet — a store that already has
        // real blocks is migrated by definition; just flip the flag.
        $created = 0;
        if (BioBlock::where('store_id', $storeId)->count() === 0) {
            foreach ($this->legacyLinkRows($store) as $i => $row) {
                BioBlock::create([
                    'store_id' => $storeId,
                    'branch_id' => null,
                    'type' => 'link',
                    'label' => $row['label'],
                    'url' => $row['url'],
                    'config' => [],
                    'position' => $i,
                    'active' => true,
                ]);
                $created++;
            }
        }

        $this->markMigrated($storeId);

        return $created;
    }

    /**
     * How many legacy link blocks WOULD migrate for this store (dry-run helper).
     * Zero when already migrated.
     */
    public function legacyLinkCount(int $storeId): int
    {
        if ($this->isMigrated($storeId)) {
            return 0;
        }

        $store = Store::find($storeId);

        return $store ? count($this->legacyLinkRows($store)) : 0;
    }

    /**
     * Extract non-empty legacy link slots from a Post, keyed by 1-based slot.
     *
     * @return array<int, array{label:?string,url:string}>
     */
    protected function legacyLinkRows(Store $store): array
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $url = trim((string) ($store->{'link'.$i} ?? ''));
            if ($url === '') {
                continue;
            }
            $label = trim((string) ($store->{'link_label'.$i} ?? ''));
            $rows[$i] = ['label' => $label !== '' ? $label : $url, 'url' => $url];
        }

        return $rows;
    }

    /**
     * Build one render-contract row from a BioBlock, resolving i18n (A10).
     *
     * @return array<string, mixed>
     */
    protected function renderRow(BioBlock $block, bool $scheduled): array
    {
        $config = is_array($block->config) ? $block->config : [];
        [$label, $config] = $this->resolveLocaleContent($block->label, $config);

        return [
            'id' => (int) $block->id,
            'type' => (string) $block->type,
            'label' => $label,
            'url' => $block->url,
            'config' => $config,
            'active' => (bool) $block->active,
            'scheduled' => $scheduled,
            'clicks' => (int) $block->clicks_cached,
        ];
    }

    /**
     * BIOLINK-TPL T1-5: resolve `config.item_id` on menu_item / featured_dish rows
     * to the live menu item — localized name, store-formatted price, image and the
     * storefront deep link (`?item=ID`). Owner-typed label/price/image WIN (resolved
     * data only FILLS empties); a missing/deleted item leaves the row's fallback
     * text untouched and never sets a broken deep link. Items are looked up in ONE
     * query, scoped to the store (cross-tenant item ids resolve to nothing).
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array<string,mixed>>
     */
    protected function resolveItemBlocks(array $rows, int $storeId): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (in_array($row['type'] ?? '', ['menu_item', 'featured_dish'], true)) {
                $id = (int) (($row['config']['item_id'] ?? 0));
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }
        if (! $ids) {
            return $rows;
        }

        $items = Menu::where('store_id', $storeId)
            ->whereIn('id', array_keys($ids))
            ->get()
            ->keyBy('id');
        $slug = (string) Store::where('id', $storeId)->value('slug');

        foreach ($rows as &$row) {
            if (! in_array($row['type'] ?? '', ['menu_item', 'featured_dish'], true)) {
                continue;
            }
            $id = (int) (($row['config']['item_id'] ?? 0));
            if ($id <= 0) {
                continue; // manual mode — owner typed the fields, no item to resolve
            }

            $item = $items->get($id);
            if (! $item) {
                // Deleted/foreign item: keep the owner's fallback text, never point
                // the card at a broken ?item= link (the blade's own guards hide an
                // otherwise-empty card).
                continue;
            }

            $name = $this->localizedItemName($item);
            $price = $this->itemPriceLabel($item, $storeId);
            $deepLink = $slug !== '' ? route('publicView', $slug).'?item='.$id : null;
            $config = is_array($row['config'] ?? null) ? $row['config'] : [];

            if ($row['type'] === 'menu_item') {
                if (($row['label'] ?? '') === '' || $row['label'] === null) {
                    $row['label'] = $name;
                }
                if (($config['price_label'] ?? '') === '' && $price !== '') {
                    $config['price_label'] = $price;
                }
            } else { // featured_dish
                if (($config['title'] ?? '') === '') {
                    $config['title'] = $name;
                }
                if (($config['price_label'] ?? '') === '' && $price !== '') {
                    $config['price_label'] = $price;
                }
                $img = $this->itemImageUrl($item, $storeId);
                if (($config['image'] ?? '') === '' && $img !== '') {
                    $config['image'] = $img;
                }
            }

            if (($row['url'] ?? '') === '' || $row['url'] === null) {
                $row['url'] = $deepLink;
            }

            $row['config'] = $config;
        }
        unset($row);

        return $rows;
    }

    /** Menu item name resolved to the current app locale (menu's own translations). */
    protected function localizedItemName(Menu $item): string
    {
        $base = (string) ($item->getRawOriginal('name') ?? '');
        $locale = app()->getLocale();
        $tr = $item->translations;
        if (is_object($tr) && ! empty($tr->{$locale}->title ?? null)) {
            return (string) $tr->{$locale}->title;
        }

        return $base;
    }

    /**
     * BIOLINK-TPL T2-6: resolve `reviews` blocks with `config.source = 'live'` to
     * the store's live rating/count (StoreRatingService, cached). Live is
     * authoritative — it OVERWRITES stale manual numbers, and empties them when the
     * store has no ratings (the blade's own guard then hides the card). `manual`
     * (or unset) reviews keep the owner-typed rating/count exactly as they are.
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array<string,mixed>>
     */
    protected function resolveReviewBlocks(array $rows, int $storeId): array
    {
        $needsLive = false;
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === 'reviews' && (($row['config']['source'] ?? '') === 'live')) {
                $needsLive = true;
                break;
            }
        }
        if (! $needsLive) {
            return $rows;
        }

        try {
            $rating = StoreRatingService::forStore($storeId);
        } catch (Throwable $e) {
            $rating = ['avg' => null, 'count' => 0];
        }
        $avg = $rating['avg'] ?? null;
        $count = (int) $rating['count'];

        foreach ($rows as &$row) {
            if (($row['type'] ?? '') !== 'reviews' || (($row['config']['source'] ?? '') !== 'live')) {
                continue;
            }
            $config = is_array($row['config'] ?? null) ? $row['config'] : [];
            $config['rating'] = ($avg !== null && (float) $avg > 0) ? (string) $avg : '';
            $config['count'] = $count > 0 ? (string) $count : '';
            $row['config'] = $config;
        }
        unset($row);

        return $rows;
    }

    /** The 7 week day keys, Monday-first (SPEC §2 A1 `week` shape; matches Branch::DAYS). */
    public const HOURS_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * bio-t15 A1: resolve every `hours` block's `week` (day => [[start,end],...] pairs)
     * and open-now status, server-side, in the store's own timezone (no client clock —
     * CSP + SSR). `source:'auto'` reads the platform's existing structured-hours seam
     * (`OpeningHoursService::weeklyModelForPost` — an active branch's `hours` json, else
     * a best-effort parse of the store's free-text `timing` field); an unresolvable auto
     * source leaves the week empty (the blade degrades to nothing, never a guessed
     * "closed all week"). `source:'manual'`/unset keeps the owner-typed `week` exactly
     * as stored. `config.tz` may override the store's own timezone; an invalid value
     * falls back to `store_tz()`.
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array<string,mixed>>
     */
    protected function resolveHoursBlocks(array $rows, int $storeId): array
    {
        // Lazily resolved at most once per render; `false` = "not computed yet"
        // so a genuinely-null result (no auto data anywhere) is only computed once.
        $autoWeek = false;
        $store = null;

        foreach ($rows as &$row) {
            if (($row['type'] ?? '') !== 'hours') {
                continue;
            }
            $config = is_array($row['config'] ?? null) ? $row['config'] : [];

            $tz = $this->validTimezone((string) ($config['tz'] ?? '')) ?: store_tz($storeId);

            // `$week` stays NULL when no data could be resolved at all (distinct from
            // an empty-but-known week, e.g. every day genuinely closed) so the status
            // computation below can report "unknown" rather than a guessed "closed".
            if (($config['source'] ?? '') === 'auto') {
                if ($autoWeek === false) {
                    $store ??= Store::find($storeId);
                    $autoWeek = $store ? $this->autoHoursWeek($store) : null;
                }
                $week = $autoWeek;
            } else {
                $week = $this->sanitizeWeek(is_array($config['week'] ?? null) ? $config['week'] : []);
            }

            // The RENDER contract always carries a fully day-keyed week (blade
            // iterates HOURS_DAYS directly), even when nothing resolved.
            $config['week'] = $week ?? array_fill_keys(self::HOURS_DAYS, []);

            try {
                $now = now($tz);
            } catch (Throwable $e) {
                $now = now();
            }
            $config['_status'] = $this->weekOpenStatus($week, $now);

            $row['config'] = $config;
        }
        unset($row);

        return $rows;
    }

    /**
     * `OpeningHoursService`'s single-range-per-day weekly model converted to the
     * SPEC §2 A1 display shape (a day may hold multiple shifts once entered manually,
     * but the auto source only ever contributes one range per day). Null (no source
     * data at all — no branch hours, no parseable `timing` text) stays null so the
     * caller can distinguish "no data" from "every day closed".
     *
     * @return array<string, array<int, array{0:string,1:string}>>|null
     */
    protected function autoHoursWeek(Store $store): ?array
    {
        try {
            $model = OpeningHoursService::weeklyModelForPost($store);
        } catch (Throwable $e) {
            return null;
        }
        if ($model === null) {
            return null;
        }

        $week = [];
        foreach (self::HOURS_DAYS as $day) {
            $d = $model[$day] ?? null;
            $week[$day] = (is_array($d) && empty($d['closed']) && isset($d['open'], $d['close']))
                ? [[$d['open'], $d['close']]]
                : [];
        }

        return $week;
    }

    /**
     * Whitelist a manually-typed week to the 7 known day keys, each an array of
     * [start,end] "HH:MM" pairs; malformed days/pairs are dropped rather than
     * invalidating the whole week (mirrors OpeningHoursService::fromHoursJson's
     * per-day tolerance).
     *
     * @param  array<string,mixed>  $raw
     * @return array<string, array<int, array{0:string,1:string}>>
     */
    protected function sanitizeWeek(array $raw): array
    {
        $week = [];
        foreach (self::HOURS_DAYS as $day) {
            $pairs = [];
            foreach ((array) ($raw[$day] ?? []) as $pair) {
                if (! is_array($pair) || count($pair) < 2) {
                    continue;
                }
                $start = $this->validClock((string) $pair[0]);
                $end = $this->validClock((string) $pair[1]);
                if ($start !== null && $end !== null) {
                    $pairs[] = [$start, $end];
                }
            }
            $week[$day] = $pairs;
        }

        return $week;
    }

    /** "HH:MM" (00:00–23:59) only, else null. */
    protected function validClock(string $value): ?string
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value) === 1 ? $value : null;
    }

    /** A real IANA timezone identifier, else null (defensive — never throws building a DateTimeZone). */
    protected function validTimezone(string $tz): ?string
    {
        if ($tz === '') {
            return null;
        }
        try {
            new \DateTimeZone($tz);

            return $tz;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Open-now status for a resolved week, evaluated at `$now` (already in the
     * target timezone). Supports multiple shifts per day and a shift that runs past
     * midnight (close < start) by also checking whether YESTERDAY's overnight shift
     * bleeds into the current moment — the same overnight rule Branch::isOpenNow()
     * and OpeningHoursService::isOpenAt() use, extended to multi-shift days.
     *
     * @param  array<string, array<int, array{0:string,1:string}>>|null  $week  null = no
     *                                                                          data resolved at all (auto source with nothing to read)
     * @return array{open:?bool, today:string}
     */
    protected function weekOpenStatus(?array $week, Carbon $now): array
    {
        $idx = $now->dayOfWeekIso - 1; // 0 = Monday
        $today = self::HOURS_DAYS[$idx];
        $yesterday = self::HOURS_DAYS[($idx + 6) % 7];
        $cur = $now->format('H:i');

        // No week data resolved at all → unknown, never a guessed "closed" badge.
        if ($week === null) {
            return ['open' => null, 'today' => $today];
        }

        foreach ((array) ($week[$yesterday] ?? []) as $pair) {
            [$start, $end] = $pair;
            if ($end < $start && $cur <= $end) {
                return ['open' => true, 'today' => $today];
            }
        }

        foreach ((array) ($week[$today] ?? []) as $pair) {
            [$start, $end] = $pair;
            $overnight = $end < $start;
            $isOpen = $overnight ? ($cur >= $start || $cur <= $end) : ($cur >= $start && $cur <= $end);
            if ($isOpen) {
                return ['open' => true, 'today' => $today];
            }
        }

        return ['open' => false, 'today' => $today];
    }

    /**
     * bio-t15 A6: resolve every `giftcard` block's `mode:'auto'` destination — the
     * store's existing gift-card purchase page when `GiftCardService::enabled()`
     * is true for this store, else left unset (never a fake/broken link — the
     * blade degrades to nothing). `mode:'url'` (or unset) is untouched, the owner's
     * own `url` stands as typed.
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array<string,mixed>>
     */
    protected function resolveGiftcardBlocks(array $rows, int $storeId): array
    {
        $needsAuto = false;
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === 'giftcard' && (($row['config']['mode'] ?? '') === 'auto')) {
                $needsAuto = true;
                break;
            }
        }
        if (! $needsAuto) {
            return $rows;
        }

        $autoUrl = null;
        try {
            if (GiftCardService::enabled($storeId)) {
                $slug = (string) Store::where('id', $storeId)->value('slug');
                if ($slug !== '') {
                    $autoUrl = route('store.giftcards.buy', $slug);
                }
            }
        } catch (Throwable $e) {
            $autoUrl = null;
        }

        foreach ($rows as &$row) {
            if (($row['type'] ?? '') !== 'giftcard' || (($row['config']['mode'] ?? '') !== 'auto')) {
                continue;
            }
            $config = is_array($row['config'] ?? null) ? $row['config'] : [];
            if ($autoUrl !== null) {
                $config['url'] = $autoUrl;
            } else {
                unset($config['url']);
            }
            $row['config'] = $config;
        }
        unset($row);

        return $rows;
    }

    /**
     * bio-t15 A7: resolve every `loyalty` block's `mode:'auto'` destination.
     *
     * No anonymous single-URL "enroll" or wallet-save destination exists on this
     * platform today (LEDGER "Contract changes" — the loyalty balance/redeem
     * widgets are auth:customer JS panels embedded in the storefront account/
     * checkout pages; the Google Wallet save-link mint also needs an authed
     * customer + client fetch, which this CSP-strict SSR page cannot do). The
     * best available reuse of the existing seam is the store's own storefront,
     * and only when an active program actually exists — else the block hides
     * rather than sending a diner to a dead end. `mode:'url'` is untouched.
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array<string,mixed>>
     */
    protected function resolveLoyaltyBlocks(array $rows, int $storeId): array
    {
        $needsAuto = false;
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === 'loyalty' && (($row['config']['mode'] ?? '') === 'auto')) {
                $needsAuto = true;
                break;
            }
        }
        if (! $needsAuto) {
            return $rows;
        }

        $autoUrl = null;
        try {
            if (FeatureAccess::storeAllows($storeId, 'loyalty')
                && LoyaltyProgram::where('store_id', $storeId)->where('active', true)->exists()) {
                $slug = (string) Store::where('id', $storeId)->value('slug');
                if ($slug !== '') {
                    $autoUrl = route('publicView', $slug);
                }
            }
        } catch (Throwable $e) {
            $autoUrl = null;
        }

        foreach ($rows as &$row) {
            if (($row['type'] ?? '') !== 'loyalty' || (($row['config']['mode'] ?? '') !== 'auto')) {
                continue;
            }
            $config = is_array($row['config'] ?? null) ? $row['config'] : [];
            if ($autoUrl !== null) {
                $config['url'] = $autoUrl;
            } else {
                unset($config['url']);
            }
            $row['config'] = $config;
        }
        unset($row);

        return $rows;
    }

    /**
     * BIOLINK-TPL T2-6: the `source` a NEW reviews block should default to — `live`
     * when the store already has ratings, else `manual`. Lane C's builder calls this
     * so a fresh block auto-uses live data where it exists.
     */
    public function reviewDefaultSource(int $storeId): string
    {
        try {
            return ((int) StoreRatingService::forStore($storeId)['count']) > 0 ? 'live' : 'manual';
        } catch (Throwable $e) {
            return 'manual';
        }
    }

    /** Store-formatted price label for an item (currency sign + position), or ''. */
    protected function itemPriceLabel(Menu $item, int $storeId): string
    {
        $price = (float) ($item->price ?? 0);

        return $price > 0 ? store_price_symbol($price, $storeId) : '';
    }

    /** Absolute image URL for an item (its own menu image), or '' when default/none. */
    protected function itemImageUrl(Menu $item, int $storeId): string
    {
        $file = trim((string) ($item->image ?? ''));
        if ($file === '' || $file === 'default.png') {
            return '';
        }

        return media_url('storage/menu/', $file, $storeId);
    }

    /**
     * BIOLINK-TPL T1-6: append `src=bio` to every row URL (top-level + config.url)
     * that points at THIS store's own storefront, so the storefront's StoreView
     * source split buckets the traffic as coming from the bio page. Guard rails:
     * only OUR storefront origin+path is touched (owner external links, other
     * stores, and any `?qr-id=` scan URL are left exactly as they are), and the
     * append is idempotent (an existing `src=` is never stacked).
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array<string,mixed>>
     */
    protected function attributeStorefrontUrls(array $rows, int $storeId): array
    {
        $slug = (string) Store::where('id', $storeId)->value('slug');
        if ($slug === '') {
            return $rows;
        }
        $base = route('publicView', $slug);

        foreach ($rows as &$row) {
            if (isset($row['url']) && is_string($row['url']) && $row['url'] !== '') {
                $row['url'] = $this->withBioSource($row['url'], $base);
            }
            if (isset($row['config']) && is_array($row['config'])
                && isset($row['config']['url']) && is_string($row['config']['url']) && $row['config']['url'] !== '') {
                $row['config']['url'] = $this->withBioSource($row['config']['url'], $base);
            }
        }
        unset($row);

        return $rows;
    }

    /** Stamp src=bio on a URL iff it is this store's storefront (T1-6 guards). */
    /**
     * bio-t15 D3 (gap-fix): stamp UTM params on every OUTBOUND link so the merchant's
     * analytics attribute the visit to their bio page. Same-store storefront links are
     * skipped (attributeStorefrontUrls already stamped ?src=bio on them — those don't
     * "leave"); BioLinkOutbound::withUtm() additionally no-ops on tel:/mailto:/sms:/
     * wa.me and never double-appends. Walks the same url/config.url the storefront pass
     * does, plus the nested delivery (config.apps[].url) and socials (config.links[].url)
     * lists the flat pass can't see. Only called with a non-null $source (public render).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function applyUtmTags(array $rows, int $storeId, ?string $source): array
    {
        if ($source === null || $source === '') {
            return $rows;
        }
        $slug = (string) Store::where('id', $storeId)->value('slug');
        if ($slug === '') {
            return $rows;
        }
        $base = route('publicView', $slug);
        // Any link on our OWN host (the storefront, the auto-resolved gift-card/loyalty
        // pages, etc.) never "leaves" the site, so it must not be UTM-tagged — that would
        // just pollute the merchant's own attribution. isSameStoreStorefront only knows the
        // publicView base; the host check catches every other internal route too.
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $tag = function (string $url) use ($source, $slug, $base, $appHost): string {
            if ($url === '' || $this->isSameStoreStorefront($url, $base)) {
                return $url;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && $appHost !== '' && $host === $appHost) {
                return $url;
            }

            return BioLinkOutbound::withUtm($url, $source, $slug);
        };

        foreach ($rows as &$row) {
            if (isset($row['url']) && is_string($row['url'])) {
                $row['url'] = $tag($row['url']);
            }
            if (isset($row['config']) && is_array($row['config'])) {
                if (isset($row['config']['url']) && is_string($row['config']['url'])) {
                    $row['config']['url'] = $tag($row['config']['url']);
                }
                foreach (['apps', 'links', 'socials'] as $listKey) {
                    if (isset($row['config'][$listKey]) && is_array($row['config'][$listKey])) {
                        foreach ($row['config'][$listKey] as &$entry) {
                            if (is_array($entry) && isset($entry['url']) && is_string($entry['url'])) {
                                $entry['url'] = $tag($entry['url']);
                            }
                        }
                        unset($entry);
                    }
                }
            }
        }
        unset($row);

        return $rows;
    }

    protected function withBioSource(string $url, string $base): string
    {
        if (! $this->isSameStoreStorefront($url, $base)) {
            return $url; // owner external link / another store — never touched
        }
        if (str_contains($url, 'qr-id=')) {
            return $url; // a qr scan URL owns its own attribution (never clobber it)
        }
        if (preg_match('/[?&]src=/', $url) === 1) {
            return $url; // idempotent — do not stack a second src param
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'src=bio';
    }

    /**
     * Is $url on THIS store's storefront origin+path? A strict prefix match with a
     * boundary check so `/{slug}` never matches a sibling store `/{slug}-2`.
     */
    protected function isSameStoreStorefront(string $url, string $base): bool
    {
        if (! str_starts_with($url, $base)) {
            return false;
        }
        $rest = substr($url, strlen($base));

        return $rest === '' || $rest[0] === '?' || $rest[0] === '#' || $rest[0] === '/';
    }

    /**
     * Resolve label + config text keys to the CURRENT app locale from the
     * `config._i18n` override map, then strip `_i18n` so the view contract is
     * byte-identical (SPEC §3 i18n). Mirrors the SHAPE of the codebase's existing
     * owner-content translation idiom (Menu::translations = {locale:{field:val}}),
     * stored inside the block's own config json rather than a dedicated column.
     *
     * @param  array<string,mixed>  $config
     * @return array{0:?string,1:array<string,mixed>}
     */
    protected function resolveLocaleContent(?string $label, array $config): array
    {
        $overrides = [];
        if (isset($config['_i18n']) && is_array($config['_i18n'])) {
            $locale = app()->getLocale();
            $overrides = is_array($config['_i18n'][$locale] ?? null) ? $config['_i18n'][$locale] : [];
        }
        unset($config['_i18n']);

        if (isset($overrides['label']) && $overrides['label'] !== '') {
            $label = (string) $overrides['label'];
        }

        foreach (self::I18N_TEXT_KEYS as $key) {
            if (isset($overrides[$key]) && $overrides[$key] !== '' && array_key_exists($key, $config)) {
                $config[$key] = (string) $overrides[$key];
            }
        }

        return [$label, $config];
    }

    /**
     * The store's biolink preset tier: 'basic' | 'all'. Driven by the plan
     * entitlement `bio_presets_all` (Lane B B6 grants it in the plan matrix).
     * Dark by default → 'basic', so a plan downgrade re-locks the pro presets
     * and G3 falls the public render back to classic_flat.
     */
    protected function presetTierForStore(int $storeId): string
    {
        return $this->ownerHas($storeId, 'bio_presets_all') ? 'all' : 'basic';
    }

    /**
     * bio_only routing is only available when the store's plan carries the legacy
     * `allow_onlybio` flag (SPEC §5 — this is what finally gives that flag meaning).
     */
    public function bioOnlyAllowed(int $storeId): bool
    {
        return $this->ownerHas($storeId, 'allow_onlybio');
    }

    /**
     * bio-t15 A11/SPEC §8: is `bio_pro` allowed for this store? Gates the `tipjar`
     * block. Uses the SAME gate as Lane B's premium-appearance surface —
     * FeatureAccess::storeAllows('bio_pro') — so both PRO surfaces of the one key
     * agree (gap-fix): bio_pro ships as a GLOBAL dark kill-switch (plan_gated=false,
     * default OFF; see the ship_bio_pro migration), so the authoritative gate is the
     * kill-switch, not a raw per-owner entitlement that would bypass it. Dark by
     * default, so a store never gets tipjar for free by accident.
     */
    public function bioProAllowed(int $storeId): bool
    {
        return FeatureAccess::storeAllows($storeId, 'bio_pro');
    }

    /**
     * Server-side block cap for this store (999 = unlimited sentinel). Read via
     * effective_limit('bio_blocks') against the store owner; Lane B enforces it
     * on block create.
     */
    public function blockLimit(int $storeId): int
    {
        $owner = $this->ownerFor($storeId);

        return $owner ? effective_limit('bio_blocks', $owner) : 0;
    }

    /** Resolve the store owner (fk-less: post.user_id → User). */
    protected function ownerFor(int $storeId): ?User
    {
        $ownerId = (int) Store::where('id', $storeId)->value('user_id');

        return $ownerId ? User::find($ownerId) : null;
    }

    /** Does the store owner hold this plan entitlement? Defensive: false on any error. */
    protected function ownerHas(int $storeId, string $key): bool
    {
        try {
            $owner = $this->ownerFor($storeId);

            return $owner instanceof User && Entitlements::has($owner, $key);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Parse a range chip into an inclusive start day, or null for all-time. */
    protected function rangeStart(string $range): ?Carbon
    {
        return match ($range) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => null,
        };
    }
}
