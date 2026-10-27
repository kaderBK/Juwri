<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Admin;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\TemplateRollout;
use App\Services\StorefrontThemeService;
use App\Support\SearchTerm;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\TenantFill;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Storefront\Models\TemplateMigration;
use Juwri\Modules\Storefront\Models\TemplateRolloutStore;
use Juwri\Modules\Storefront\Models\ThemeTemplateMeta;
use Validator;

/**
 * Superadmin management of the restaurant menu templates (store_templates).
 * Template code lives on disk; this edits the post_template_meta overlay
 * (name/description/image/featured/enabled), the fallback template used when
 * a restaurant's template was deleted, and bulk reassignment between templates.
 */
class MenuTemplateController extends Controller
{
    /**
     * SE run §1: the 11 selectable story entry-point slugs, in concept order.
     * 'inherit' is a STORE-level customizer value only — the platform default
     * must name a concrete style, so it is not offered here. The code fallback
     * when no option row exists is 'ribbon' (SPEC §2).
     *
     * @var array<string,string> slug => human label
     */
    private const STORIES_ENTRY_STYLES = [
        'none' => 'No entry point',
        'ribbon' => 'Gilded bookmark ribbon',
        'orb' => 'Glass story orb',
        'toolbar' => 'Toolbar story button',
        'capsule' => 'Title capsule',
        'tassel' => 'Leather page tassel',
        'seal' => 'Wax seal',
        'dogear' => 'Dog-ear page curl',
        'filmstrip' => 'Filmstrip tray',
        'medallion' => 'Spine medallion',
        'sheet' => 'Bottom-sheet peek',
    ];

    public function __construct(private StorefrontThemeService $templates) {}

    /**
     * CC run: one preview tile per §1 slug — asset-relative paths into the
     * shared previews dir (lane A's SVGs). Single source for the admin card
     * grid; the customizer reads the SAME paths from theme.json
     * `option_previews`, so the two pickers can never drift apart.
     *
     * @return array<string,string> slug => asset-relative path
     */
    private static function storiesEntryPreviews(): array
    {
        $out = [];
        foreach (array_keys(self::STORIES_ENTRY_STYLES) as $slug) {
            $out[$slug] = 'global/stories-entries/previews/'.$slug.'.svg';
        }

        return $out;
    }

    public function index()
    {
        $templates = $this->templates->all();
        $usage = $this->templates->usageCounts();
        $fallback = $this->templates->fallbackSlug();
        $default = $this->templates->defaultSlug();

        /* SE run §6: the platform-wide story entry default the resolution chain
           falls back to when a store leaves its customizer on 'inherit'. Raw
           option value (false when never set) so the view can show the code
           fallback 'ribbon' as the current value. */
        $storiesEntryDefault = PlatformSettings::get('stories_entry_default', false);
        $storiesEntryStyles = self::STORIES_ENTRY_STYLES;
        $storiesEntryPreviews = self::storiesEntryPreviews();

        /* Newest store — its public page backs the "Preview" links so the admin
           can eyeball any template against real data. The ?preview_template
           override that makes it render a chosen template is Lane B's part
           (User\StoreController@publicView); until that merges the link simply
           shows the store on its own template. */
        $previewStore = Store::query()->orderByDesc('id')->value('slug');

        /* Recent admin actions on templates (update/toggle/sort/fallback/default/
           bulk/reassign) — an audit trail so several admins can see who changed what. */
        $activity = ActivityLog::with('actor')
            ->whereLike('action', 'admin.menu_template.%')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        /* Recent store→theme migrations with their undo state — the revertable
           audit trail sitting beside the read-only activity feed. */
        $migrations = TemplateMigration::with('actor')
            ->orderByDesc('id')
            ->limit(12)
            ->get();

        /* Templates still referenced by restaurants but deleted from disk —
           these currently render the fallback. Surface them so the admin can
           reassign their restaurants for good. */
        $missing = array_diff_key($usage, $templates);

        /* Disabled templates that restaurants still use — they keep rendering
           (grandfathered), but the admin should see them and be able to migrate. */
        $disabledInUse = [];
        foreach ($templates as $slug => $tpl) {
            if (! $tpl['enabled'] && ! empty($usage[$slug])) {
                $disabledInUse[$slug] = ['name' => $tpl['name'], 'count' => $usage[$slug]];
            }
        }

        return view('admin.menu_templates.index', compact('templates', 'usage', 'fallback', 'default', 'previewStore', 'activity', 'migrations', 'missing', 'disabledInUse', 'storiesEntryDefault', 'storiesEntryStyles', 'storiesEntryPreviews'));
    }

    public function edit(string $slug)
    {
        $templates = $this->templates->all();
        abort_unless(isset($templates[$slug]), 404);

        $template = $templates[$slug];
        $meta = ThemeTemplateMeta::firstOrNew(['slug' => $slug]);

        return view('admin.menu_templates.edit', compact('template', 'meta', 'slug'));
    }

    public function update(Request $request, string $slug)
    {
        abort_unless(array_key_exists($slug, $this->templates->discover()), 404);

        $validator = Validator::make($request->all(), [
            'display_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'preview_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'featured' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        if ($validator->fails()) {
            app_alert_error(implode('<br>', $validator->errors()->all()));

            return back();
        }

        $meta = ThemeTemplateMeta::firstOrNew(['slug' => $slug]);

        if ($request->hasFile('preview_image')) {
            $meta->preview_image = image_upload(
                $request->file('preview_image'),
                'storage/template_previews/',
                null,
                null,
                $meta->preview_image
            );
        }

        $meta->display_name = $request->display_name;
        $meta->description = $request->description;
        $meta->featured = (bool) $request->featured;
        $meta->enabled = (bool) $request->enabled;
        $meta->sort_order = (int) ($request->sort_order ?? 0);
        $meta->save();

        log_activity('admin.menu_template.update', $meta, ['slug' => $slug]);

        app_alert_success(___('Updated Successfully'));

        return redirect()->route('admin.menu-templates.index');
    }

    public function toggle(string $slug)
    {
        abort_unless(array_key_exists($slug, $this->templates->discover()), 404);

        $meta = ThemeTemplateMeta::firstOrNew(['slug' => $slug]);
        $meta->enabled = ! ($meta->exists ? $meta->enabled : true);
        $meta->save();

        log_activity('admin.menu_template.toggle', $meta, ['slug' => $slug, 'enabled' => $meta->enabled]);

        app_alert_success($meta->enabled ? ___('Template enabled') : ___('Template disabled'));

        return back();
    }

    /**
     * Nudge one template up/down in the admin display order. Never-saved rows
     * all share sort_order 0, so a single swap can't produce a stable ladder;
     * instead we rewrite the WHOLE visible sequence to sort_order = (i+1)*10.
     * Only sort_order is touched — a fresh firstOrNew() row defaults
     * enabled=true, so setting enabled here would silently re-enable a disabled
     * template. Edges are a graceful no-op.
     */
    public function sort(Request $request, string $slug): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        if ($validator->fails()) {
            return back();
        }

        // all() is already ordered by sort_order asc — its keys are the current
        // display order the admin sees.
        $order = array_keys($this->templates->all());
        $pos = array_search($slug, $order, true);
        abort_if($pos === false, 404);

        $swapWith = $request->direction === 'up' ? $pos - 1 : $pos + 1;
        if ($swapWith < 0 || $swapWith >= count($order)) {
            return back(); // already at an edge — nothing to move
        }

        [$order[$pos], $order[$swapWith]] = [$order[$swapWith], $order[$pos]];

        foreach ($order as $i => $s) {
            $meta = ThemeTemplateMeta::firstOrNew(['slug' => $s]);
            $meta->sort_order = ($i + 1) * 10;
            $meta->save();
        }

        log_activity('admin.menu_template.sort', null, ['slug' => $slug, 'direction' => $request->direction]);

        app_alert_success(___('Order updated'));

        return back();
    }

    public function fallback(Request $request)
    {
        $enabled = array_keys($this->templates->available());

        $validator = Validator::make($request->all(), [
            'fallback_store_theme' => ['required', 'string', Rule::in($enabled)],
        ]);

        if ($validator->fails()) {
            app_alert_error(___('Fallback must be an enabled template'));

            return back();
        }

        PlatformSettings::set('fallback_store_theme', $request->fallback_store_theme);

        log_activity('admin.menu_template.fallback', null, ['slug' => $request->fallback_store_theme]);

        app_alert_success(___('Fallback template saved'));

        return back();
    }

    /**
     * Set the default template new restaurants are seeded onto. Sibling of the
     * fallback but a different job (see StorefrontThemeService::defaultSlug):
     * fallback rescues broken existing stores, default seeds new signups.
     */
    public function defaultTemplate(Request $request): RedirectResponse
    {
        $enabled = array_keys($this->templates->available());

        $validator = Validator::make($request->all(), [
            'default_post_template' => ['required', 'string', Rule::in($enabled)],
        ]);

        if ($validator->fails()) {
            app_alert_error(___('Default must be an enabled template'));

            return back();
        }

        PlatformSettings::set('default_post_template', $request->default_post_template);

        log_activity('admin.menu_template.default', null, ['slug' => $request->default_post_template]);

        app_alert_success(___('Default template saved'));

        return back();
    }

    /**
     * SE run §6: set the platform-wide default story entry style. Sibling of
     * defaultTemplate()/fallback() but a different job — it chooses the entry
     * POINT (a §1 slug), not a template. It is the second link of the §2
     * resolution chain: a store's customizer 'inherit' (or unset) resolves to
     * this Option; a missing Option row resolves to the code fallback 'ribbon'.
     * Only the 11 concrete slugs are accepted — 'inherit' is a store-level value.
     */
    public function storiesEntryDefault(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'stories_entry_default' => ['required', 'string', Rule::in(array_keys(self::STORIES_ENTRY_STYLES))],
        ]);

        if ($validator->fails()) {
            app_alert_error(___('Choose a valid story entry style'));

            return back();
        }

        PlatformSettings::set('stories_entry_default', $request->stories_entry_default);

        log_activity('admin.menu_template.stories_entry_default', null, ['slug' => $request->stories_entry_default]);

        app_alert_success(___('Stories entry default saved'));

        return back();
    }

    /**
     * Bulk action on selected templates: enable / disable / feature / unfeature.
     */
    public function bulk(Request $request)
    {
        $known = array_keys($this->templates->discover());

        $validator = Validator::make($request->all(), [
            'bulk_action' => ['required', Rule::in(['enable', 'disable', 'feature', 'unfeature'])],
            'slugs' => ['required', 'array', 'min:1'],
            'slugs.*' => ['string', Rule::in($known)],
        ]);

        if ($validator->fails()) {
            app_alert_error(___('Select at least one template and a valid action'));

            return back();
        }

        $slugs = array_values(array_unique($request->slugs));

        /* Never leave the platform with zero enabled templates — the picker
           (and new-restaurant validation) would have nothing to offer. */
        if ($request->bulk_action === 'disable') {
            $remaining = array_diff(array_keys($this->templates->available()), $slugs);
            if (empty($remaining)) {
                app_alert_error(___('At least one template must stay enabled'));

                return back();
            }
        }

        foreach ($slugs as $slug) {
            $meta = ThemeTemplateMeta::firstOrNew(['slug' => $slug]);
            match ($request->bulk_action) {
                'enable' => $meta->enabled = true,
                'disable' => $meta->enabled = false,
                'feature' => $meta->featured = true,
                'unfeature' => $meta->featured = false,
            };
            $meta->save();
        }

        log_activity('admin.menu_template.bulk', null, [
            'action' => $request->bulk_action,
            'slugs' => $slugs,
        ]);

        app_alert_success(count($slugs).' '.___('templates updated'));

        return back();
    }

    /**
     * Restaurants using one template + the bulk reassign form.
     * Works for deleted templates too (the slug only lives in store_options).
     */
    public function templateStores(Request $request, string $slug)
    {
        $templates = $this->templates->all();
        $usage = $this->templates->usageCounts();
        abort_unless(isset($templates[$slug]) || isset($usage[$slug]), 404);

        $storeIds = collect(StoreSettings::storeIdsWhere('store_template', fn ($v) => $v === $slug));

        // True count on this template — reassign-all moves ALL of these, so the
        // label/confirm must use this, not the (possibly search-filtered) paginator.
        $total = $storeIds->count();

        $q = trim((string) $request->query('q', ''));

        $stores = Store::with('user')
            ->whereIn('id', $storeIds)
            ->when(SearchTerm::clean($q) !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    SearchTerm::apply($sub, ['title', 'slug'], $q);
                    $sub->orWhereHas('user', fn ($u) => SearchTerm::apply($u, ['email'], $q));
                });
            })
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        $template = $templates[$slug] ?? ['folder' => $slug, 'name' => $slug, 'enabled' => false];
        $isMissing = ! isset($templates[$slug]);
        $targets = array_filter($this->templates->available(), fn ($t) => $t['folder'] !== $slug);

        return view('admin.menu_templates.template_stores', compact('template', 'slug', 'stores', 'targets', 'isMissing', 'q', 'total'));
    }

    /**
     * Bulk: move every restaurant on template X to template Y.
     */
    public function reassign(Request $request)
    {
        $enabled = array_keys($this->templates->available());

        $validator = Validator::make($request->all(), [
            'from' => ['required', 'string', 'max:60', 'different:to'],
            'to' => ['required', 'string', Rule::in($enabled)],
            'post_ids' => ['nullable', 'array'],
            'post_ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            app_alert_error(implode('<br>', $validator->errors()->all()));

            return back();
        }

        // Optional per-row selection: when present, only those restaurants move
        // and we land back on the same template page (the admin is mid-triage);
        // absent means "move them all" and back to the index.
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('post_ids', []))));
        $partial = ! empty($ids);

        // Snapshot the affected stores' current theme BEFORE the move so the
        // batch can be undone precisely.
        $moves = [];
        foreach (StoreSettings::valuesFor('store_template', $partial ? $ids : null) as $storeId => $value) {
            if ($value === (string) $request->from) {
                $moves[] = ['store_id' => $storeId, 'from' => $value];
            }
        }

        // Move + audit as one unit: if the migration record can't be written the
        // theme change rolls back, so we never re-theme stores without an undo trail.
        $count = DB::transaction(function () use ($request, $moves) {
            $moved = 0; // P1-07: writes go through StoreSettings (one per store, same count as the old bulk UPDATE)
            foreach ($moves as $move) {
                StoreOption::updateStoreOption($move['store_id'], 'store_template', $request->to);
                $moved++;
            }
            $this->recordMigration('reassign', $request->to, $moves);

            return $moved;
        });

        log_activity('admin.menu_template.reassign', null, [
            'from' => $request->from,
            'to' => $request->to,
            'stores' => $count,
            'partial' => $partial,
        ]);

        app_alert_success($count.' '.___('restaurants moved to').' '.$request->to);

        if ($partial) {
            // Keep the admin on the same (possibly search-filtered) view they were
            // triaging — carry the search term back so it isn't reset by the move.
            return redirect()->route('admin.menu-templates.restaurants', array_filter([
                'slug' => $request->from,
                'q' => trim((string) $request->input('q', '')),
            ]));
        }

        return redirect()->route('admin.menu-templates.index');
    }

    /**
     * Store-first switcher: search any store and see/change the template it
     * renders. The template-scoped restaurants() page above is theme-first
     * ("who uses X?"); this is the inverse ("what does store Y use?").
     */
    public function stores(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $available = $this->templates->available();

        // The current theme is read for the page's stores only, after pagination.
        $stores = Store::query()
            ->with('user')
            ->when(SearchTerm::clean($q) !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    SearchTerm::apply($sub, ['stores.title', 'stores.slug'], $q);
                    $sub->orWhereHas('user', fn ($u) => SearchTerm::apply($u, ['email'], $q));
                });
            })
            ->orderByDesc('stores.id')
            ->select('stores.*')
            ->paginate(30)
            ->withQueryString();

        // P1-07: the current theme comes from stores.settings (no join on a key/value table any more).
        $current = StoreSettings::valuesFor('store_template', $stores->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all());
        $stores->getCollection()->each(fn ($store) => $store->setAttribute('current_template', $current[(int) $store->id] ?? null));

        return view('admin.menu_templates.stores', compact('stores', 'available', 'q'));
    }

    /**
     * Switch a single store to a chosen (enabled) template. Render choice only —
     * the store keeps all its data; just the theme it renders under changes.
     */
    public function switchStore(Request $request): RedirectResponse
    {
        $enabled = array_keys($this->templates->available());

        $validator = Validator::make($request->all(), [
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'to' => ['required', 'string', Rule::in($enabled)],
        ]);

        if ($validator->fails()) {
            app_alert_error(implode('<br>', $validator->errors()->all()));

            return back();
        }

        $storeId = (int) $request->store_id;
        $from = store_options($storeId, 'store_template');

        DB::transaction(function () use ($storeId, $request, $from) {
            StoreOption::updateStoreOption($storeId, 'store_template', $request->to);
            $this->recordMigration('switch', $request->to, [['store_id' => $storeId, 'from' => $from ?: null]]);
        });

        log_activity('admin.menu_template.reassign', null, [
            'from' => $from ?: null,
            'to' => $request->to,
            'restaurants' => 1,
            'partial' => true,
            'store' => $storeId,
        ]);

        app_alert_success(___('Store template updated'));

        return redirect()->route('admin.menu-templates.stores', array_filter([
            'q' => trim((string) $request->input('q', '')),
        ]));
    }

    /**
     * Cross-template migration: move every store on any of the selected source
     * templates onto one target. One mass UPDATE — the target is dropped from
     * the source set so a template can't be migrated onto itself.
     */
    public function bulkReassign(Request $request): RedirectResponse
    {
        $enabled = array_keys($this->templates->available());

        $validator = Validator::make($request->all(), [
            'slugs' => ['required', 'array', 'min:1'],
            'slugs.*' => ['string', 'max:60'],
            'to' => ['required', 'string', Rule::in($enabled)],
        ]);

        if ($validator->fails()) {
            app_alert_error(implode('<br>', $validator->errors()->all()));

            return back();
        }

        $slugs = array_values(array_unique(array_filter($request->slugs, fn ($s) => $s !== $request->to)));

        if (empty($slugs)) {
            app_alert_error(___('Choose source templates different from the target.'));

            return back();
        }

        // Each store may come off a different source template — capture its own
        // pre-move theme so undo restores every store to where it actually was.
        $moves = [];
        foreach (StoreSettings::valuesFor('store_template') as $storeId => $value) {
            if (in_array($value, $slugs, true)) {
                $moves[] = ['store_id' => $storeId, 'from' => $value];
            }
        }

        $count = DB::transaction(function () use ($request, $moves) {
            $moved = 0; // P1-07: writes go through StoreSettings (one per store, same count as the old bulk UPDATE)
            foreach ($moves as $move) {
                StoreOption::updateStoreOption($move['store_id'], 'store_template', $request->to);
                $moved++;
            }
            $this->recordMigration('bulk_reassign', $request->to, $moves);

            return $moved;
        });

        log_activity('admin.menu_template.bulk_reassign', null, [
            'from' => $slugs,
            'to' => $request->to,
            'stores' => $count,
        ]);

        app_alert_success($count.' '.___('restaurants moved to').' '.$request->to);

        return redirect()->route('admin.menu-templates.index');
    }

    /**
     * Record a migration batch (each store's pre-move theme) so it can be
     * reverted later. No-op for an empty move set.
     *
     * @param  array<int, array{store_id: int, from: string|null}>  $moves
     */
    private function recordMigration(string $kind, string $to, array $moves): void
    {
        if (empty($moves)) {
            return;
        }

        TemplateMigration::create([
            'actor_user_id' => auth()->id(),
            'kind' => $kind,
            'to_template' => $to,
            'moves' => array_values($moves),
            'count' => count($moves),
        ]);
    }

    /**
     * Revert a migration: restore each store to its recorded pre-move template.
     * Only stores still on the migrated-to theme are touched, so a store the
     * admin re-themed after this batch is never clobbered. Idempotent via
     * undone_at.
     */
    public function undoMigration(TemplateMigration $migration): RedirectResponse
    {
        // Atomically claim the undo: a concurrent double-submit loses this
        // conditional write and bails, instead of both passing an isUndone()
        // read and re-running the restore.
        $claimed = TemplateMigration::query()
            ->whereKey($migration->getKey())
            ->whereNull('undone_at')
            ->update(['undone_at' => now()]);

        if (! $claimed) {
            app_alert_error(___('This migration was already reverted.'));

            return back();
        }

        $restored = 0;
        foreach ($migration->moves as $move) {
            $storeId = (int) ($move['store_id'] ?? 0);
            $from = $move['from'] ?? null;
            if ($storeId === 0 || $from === null || $from === '') {
                continue;
            }
            if (store_options($storeId, 'store_template') === $migration->to_template) {
                StoreOption::updateStoreOption($storeId, 'store_template', $from);
                $restored++;
            }
        }

        log_activity('admin.menu_template.undo', $migration, [
            'to' => $migration->to_template,
            'restored' => $restored,
        ]);

        app_alert_success($restored.' '.___('stores restored'));

        return back();
    }

    /**
     * Build the store query for a segment from the request filters (plan,
     * created-date range, verified flag, current template). Shared by the
     * preview and the assign so both see exactly the same set.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Juwri\Core\Models\Store>
     */
    private function segmentQuery(Request $request): Builder
    {
        $query = Store::query();

        if ($request->filled('plan')) {
            $plan = $request->input('plan');
            $query->whereHas('user', fn ($u) => $u->where('group_id', $plan));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
        if ($request->filled('verified') && in_array($request->input('verified'), ['0', '1'], true)) {
            $query->where('is_verified', (int) $request->input('verified'));
        }
        if ($request->filled('current_template')) {
            $wanted = (string) $request->input('current_template');
            $ids = StoreSettings::storeIdsWhere('store_template', fn ($v) => $v === $wanted);
            $query->whereIn('id', $ids);
        }

        return $query;
    }

    /** Segment builder: filter stores by plan / date / verified / current theme, preview the match. */
    public function segments(Request $request): View
    {
        $plans = app(PlanCatalog::class)->plans(false, ['id', 'name']);
        $available = $this->templates->available();
        $all = $this->templates->all();

        $hasFilters = $request->hasAny(['plan', 'date_from', 'date_to', 'verified', 'current_template']);
        $matches = null;
        $matchCount = 0;

        if ($hasFilters) {
            $base = $this->segmentQuery($request);
            $matchCount = (clone $base)->count();
            $matches = $base->with('user')->orderByDesc('id')->limit(50)->get();
        }

        return view('admin.menu_templates.segments', compact('plans', 'available', 'all', 'matches', 'matchCount', 'hasFilters', 'request'));
    }

    /**
     * Assign a template to every store in the segment. At least one filter is
     * required so the whole platform can't be re-themed by an empty segment.
     * Recorded as a migration batch so it is undoable.
     */
    public function segmentAssign(Request $request): RedirectResponse
    {
        $enabled = array_keys($this->templates->available());

        $validator = Validator::make($request->all(), [
            'to' => ['required', 'string', Rule::in($enabled)],
            'plan' => ['nullable', 'string', 'max:60'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'verified' => ['nullable', 'in:0,1'],
            'current_template' => ['nullable', 'string', 'max:60'],
        ]);

        if ($validator->fails()) {
            app_alert_error(implode('<br>', $validator->errors()->all()));

            return back();
        }

        if (! $request->hasAny(['plan', 'date_from', 'date_to', 'verified', 'current_template'])) {
            app_alert_error(___('Add at least one segment filter before assigning.'));

            return back();
        }

        $storeIds = $this->segmentQuery($request)->pluck('id')->all();
        if (empty($storeIds)) {
            app_alert_error(___('No stores match this segment.'));

            return back();
        }

        // Current theme per store (one query), then a single bulk UPDATE for the
        // stores that already have an option row and per-row upserts only for the
        // few that don't — keeps a large segment from N round-trips.
        $currentMap = collect(StoreSettings::valuesFor('store_template', array_map('intval', (array) $storeIds)));

        $moves = [];
        $existingToMove = [];
        $missing = [];
        foreach ($storeIds as $pid) {
            if ($currentMap->has($pid)) {
                $from = $currentMap[$pid];
                if ($from === $request->to) {
                    continue;
                }
                $moves[] = ['store_id' => (int) $pid, 'from' => $from];
                $existingToMove[] = $pid;
            } else {
                $moves[] = ['store_id' => (int) $pid, 'from' => null];
                $missing[] = $pid;
            }
        }

        // All theme writes + the undo record commit together or not at all — a
        // broad segment failing part-way never leaves stores stranded off-audit.
        DB::transaction(function () use ($existingToMove, $missing, $moves, $request) {
            if (! empty($existingToMove)) {
                foreach ($existingToMove as $pid) { // P1-07: writes go through StoreSettings
                    StoreOption::updateStoreOption((int) $pid, 'store_template', $request->to);
                }
            }
            foreach ($missing as $pid) {
                StoreOption::updateStoreOption((int) $pid, 'store_template', $request->to);
            }

            $this->recordMigration('segment', $request->to, $moves);
        });

        log_activity('admin.menu_template.segment', null, [
            'to' => $request->to,
            'restaurants' => count($moves),
        ]);

        app_alert_success(count($moves).' '.___('stores assigned to').' '.$request->to);

        return redirect()->route('admin.menu-templates.segments');
    }

    /** A/B rollouts: create form (segment + split %) + list with live orders-per-arm results. */
    public function rollouts(Request $request): View
    {
        $plans = app(PlanCatalog::class)->plans(false, ['id', 'name']);
        $available = $this->templates->available();
        $all = $this->templates->all();

        $rollouts = TemplateRollout::with('actor')->orderByDesc('id')->limit(20)->get();
        $results = [];
        foreach ($rollouts as $rollout) {
            $results[$rollout->id] = $this->rolloutResults($rollout);
        }

        return view('admin.menu_templates.rollouts', compact('plans', 'available', 'all', 'rollouts', 'results', 'request'));
    }

    /**
     * Orders-per-store for each arm since the rollout started, plus the variant's
     * lift over control. Orders are joined by store_id (= store post id).
     *
     * @return array{control: array{stores:int,orders:int,per_store:float}, variant: array{stores:int,orders:int,per_store:float}, lift: float|null}
     */
    private function rolloutResults(TemplateRollout $rollout): array
    {
        $byArm = $rollout->stores()->get(['store_id', 'arm'])->groupBy('arm');

        $arms = [];
        foreach (['control', 'variant'] as $arm) {
            $ids = $byArm->get($arm, collect())->pluck('store_id')->all();
            $stores = count($ids);
            $orders = $stores === 0 ? 0 : Order::query()
                ->whereIn('store_id', $ids)
                ->where('created_at', '>=', $rollout->started_at)
                ->count();
            $arms[$arm] = [
                'stores' => $stores,
                'orders' => $orders,
                'per_store' => $stores > 0 ? round($orders / $stores, 2) : 0.0,
            ];
        }

        $control = $arms['control']['per_store'];
        $variant = $arms['variant']['per_store'];

        return [
            'control' => $arms['control'],
            'variant' => $arms['variant'],
            'lift' => $control > 0 ? round((($variant - $control) / $control) * 100, 1) : null,
        ];
    }

    /**
     * Start a rollout: split the segment into a variant arm (gets the target
     * theme) and a control arm (unchanged) by a deterministic per-rollout hash,
     * record every store's arm + prior theme, and log the variant theme changes
     * as an undoable migration.
     */
    public function startRollout(Request $request): RedirectResponse
    {
        $enabled = array_keys($this->templates->available());

        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:120'],
            'to' => ['required', 'string', Rule::in($enabled)],
            'percentage' => ['required', 'integer', 'min:1', 'max:99'],
            'plan' => ['nullable', 'string', 'max:60'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'verified' => ['nullable', 'in:0,1'],
            'current_template' => ['nullable', 'string', 'max:60'],
        ]);

        if ($validator->fails()) {
            app_alert_error(implode('<br>', $validator->errors()->all()));

            return back();
        }

        if (! $request->hasAny(['plan', 'date_from', 'date_to', 'verified', 'current_template'])) {
            app_alert_error(___('Add at least one segment filter before starting a rollout.'));

            return back();
        }

        $storeIds = $this->segmentQuery($request)->pluck('id')->all();
        if (empty($storeIds)) {
            app_alert_error(___('No stores match this segment.'));

            return back();
        }

        // The whole experiment — rollout row, per-store arm assignments, the
        // variant theme changes, and their undo record — commits atomically. A
        // failure part-way rolls the entire rollout back rather than leaving
        // half-themed stores or arm rows pointing at a phantom rollout.
        $result = DB::transaction(function () use ($request, $storeIds) {
            $rollout = TemplateRollout::create([
                'actor_user_id' => auth()->id(),
                'name' => $request->input('name'),
                'target_template' => $request->to,
                'segment' => $request->only(['plan', 'date_from', 'date_to', 'verified', 'current_template']),
                'percentage' => (int) $request->percentage,
                'status' => 'running',
                'started_at' => now(),
            ]);

            $currentMap = collect(StoreSettings::valuesFor('store_template', array_map('intval', (array) $storeIds)));

            $armRows = [];
            $variantMoves = [];
            $variantExisting = [];
            $variantMissing = [];
            foreach ($storeIds as $pid) {
                $from = $currentMap[$pid] ?? null;
                // Deterministic per-rollout split — a store always lands in the same arm.
                $isVariant = (crc32($pid.'|'.$rollout->id) % 100) < (int) $request->percentage;
                $armRows[] = [
                    'rollout_id' => $rollout->id,
                    'store_id' => (int) $pid,
                    'arm' => $isVariant ? 'variant' : 'control',
                    'from_template' => $from,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if ($isVariant && $from !== $request->to) {
                    $variantMoves[] = ['store_id' => (int) $pid, 'from' => $from];
                    if ($currentMap->has($pid)) {
                        $variantExisting[] = $pid;
                    } else {
                        $variantMissing[] = $pid;
                    }
                }
            }

            if (! empty($variantExisting)) {
                foreach ($variantExisting as $pid) { // P1-07: writes go through StoreSettings
                    StoreOption::updateStoreOption((int) $pid, 'store_template', $request->to);
                }
            }
            foreach ($variantMissing as $pid) {
                StoreOption::updateStoreOption((int) $pid, 'store_template', $request->to);
            }

            foreach (array_chunk($armRows, 500) as $chunk) {
                TemplateRolloutStore::insert(array_map(fn ($r) => TenantFill::row(DB::connection(), 'template_rollout_stores', $r), $chunk));
            }

            $this->recordMigration('rollout', $request->to, $variantMoves);

            return [$rollout, count($variantMoves)];
        });

        [$rollout, $variantCount] = $result;

        log_activity('admin.menu_template.rollout', $rollout, [
            'to' => $request->to,
            'percentage' => (int) $request->percentage,
            'variant' => $variantCount,
        ]);

        app_alert_success(___('Rollout started'));

        return redirect()->route('admin.menu-templates.rollouts');
    }

    /**
     * Stop a rollout. Variant stores keep the target theme (the experiment ran);
     * reverting is available via the recorded migration.
     */
    public function stopRollout(TemplateRollout $rollout): RedirectResponse
    {
        if (! $rollout->isRunning()) {
            app_alert_error(___('This rollout is already stopped.'));

            return back();
        }

        $rollout->status = 'stopped';
        $rollout->stopped_at = now();
        $rollout->save();

        log_activity('admin.menu_template.rollout_stop', $rollout, ['id' => $rollout->id]);

        app_alert_success(___('Rollout stopped.'));

        return back();
    }
}
