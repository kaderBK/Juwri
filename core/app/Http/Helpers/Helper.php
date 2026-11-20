<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\StorefrontThemeService;
use App\Services\ThemeCustomizerService;
use GeoIp2\Database\Reader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Notification;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Settings\UserSettings;
use Juwri\Core\Support\BrandContext;
use Juwri\Core\Support\RequestEpoch;
use Juwri\Modules\Content\Models\Adsense;
use Juwri\Modules\Content\Models\Page;
use Juwri\Modules\Content\Services\SitePages\SiteContext;
use Juwri\Modules\Marketing\Models\AbExperiment;
use Juwri\Modules\Marketing\Models\AbImpression;
use Juwri\Modules\Media\Services\StorageService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Payments\Services\StorePaymentGateways;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontContext;
use Juwri\Modules\Storefront\Services\Theme\ThemeAliases;
use Juwri\Modules\Storefront\Services\Theme\ThemeKeys;
use Juwri\Modules\Tables\Models\QrDesign;
use Juwri\Modules\Tables\Services\Qr\QrRenderService;

require_once __DIR__.'/PluginHelper.php';

/**
 * HOME-PAGES HP-51: per-site settings overlay. Resolution: the active Site
 * row's whitelisted `settings` JSON -> config('settings.*') (the Options
 * forever-cache) -> $default. In the 4-separate-installs topology Options stay
 * authoritative; the overlay is the host-consolidation on-ramp (HP-63) and a
 * per-site divergence hook, NOT a replacement for SettingsServiceProvider.
 */
function site_setting(string $key, mixed $default = null): mixed
{
    try {
        $site = SiteContext::current();
        if ($site && is_array($site->settings) && array_key_exists($key, $site->settings)) {
            return $site->settings[$key];
        }
    } catch (Throwable $e) {
        // pre-migration installs — fall through to Options.
    }

    return config('settings.'.$key, $default);
}

/**
 * Whether the app is set up and may serve its database-backed pages.
 *
 * APP_INSTALLED=true (compiled into config('app.installed'), so it survives
 * config:cache) answers yes straight away. Otherwise the app counts as set up
 * once it has an application key and a migrated database. That probe runs at
 * most once per application instance (one boot = one schema check).
 */
function app_is_installed(): bool
{
    if (config('app.installed')) {
        return true;
    }

    $app = app();
    if (! $app->bound('setup.complete')) {
        $app->instance('setup.complete', app_setup_probe());
    }

    return (bool) $app->make('setup.complete');
}

/** An application key is configured and the schema (migrations + settings table) exists. */
function app_setup_probe(): bool
{
    if (blank(config('app.key'))) {
        return false;
    }

    try {
        return Schema::hasTable('migrations')
            && Schema::hasTable('platform_settings');
    } catch (Throwable) {
        return false;
    }
}

/**
 * Write a single key into the .env file, in place.
 *
 * H8: the previous implementation matched `"$key=".env($key)` with str_contains
 * and replaced it with str_replace over the whole file — unanchored, every
 * occurrence. With an empty current value the needle degenerated to the bare
 * token "APP_KEY=", which is a substring of PUSHER_APP_KEY= and
 * VITE_PUSHER_APP_KEY=, so the old web installer (removed in WI-02) wrote the generated
 * application key into all three on every fresh install. Values were also never
 * quoted, so a DB_PASSWORD containing a space made the file unparseable
 * (Dotenv\Exception\InvalidFileException — the app cannot boot) and one
 * containing '#' was silently truncated at the '#'.
 *
 * The match is now line-anchored (/^KEY=.*$/m) and the value is always quoted
 * and escaped, matching GenerateVapidKeys::writeEnv(). The third $quote
 * argument is retained for signature compatibility and ignored — no caller has
 * ever passed it (20 call sites, all two-argument).
 *
 * @param  string|int|bool|null  $value
 * @param  bool  $quote  Deprecated and ignored; values are always quoted.
 */
function set_env(string $key, $value, bool $quote = false): void
{
    $envFile = app()->environmentFilePath();

    if (! file_exists($envFile)) {
        return;
    }

    // Escape for a double-quoted dotenv value. Backslash FIRST, then the quote
    // and the dollar — otherwise the escapes we just inserted get re-escaped.
    // '$' must be escaped because both bare and double-quoted dotenv values
    // interpolate ${VAR}; a password containing "${" would otherwise be rewritten.
    $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], (string) $value);
    $line = $key.'="'.$escaped.'"';

    $contents = (string) file_get_contents($envFile);
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

    if (preg_match($pattern, $contents)) {
        // preg_replace_callback, NOT preg_replace: a value containing "$1" or
        // "\0" (legal in a password) would be read as a backreference and
        // silently mangled. Every anchored match is rewritten — phpdotenv's
        // repository is immutable (first assignment wins), so leaving a stale
        // duplicate behind would be invisible and wrong.
        $contents = preg_replace_callback($pattern, static fn () => $line, $contents);
    } else {
        $contents = rtrim($contents, "\n")."\n".$line."\n";
    }

    file_put_contents($envFile, $contents, LOCK_EX);

    // O9: if config is cached, the just-written .env value is invisible until
    // the cache is rebuilt. Clear it so theme switches / social-key saves
    // (which write via set_env) take effect immediately under config:cache.
    if (app()->configurationIsCached()) {
        try {
            Artisan::call('config:clear');
        } catch (Throwable $e) {
            // best-effort — never let a cache rebuild break the save
        }
    }
}

/**
 * View prefix of the active owner theme (`templates.<name>.`), or its public
 * asset folder (`assets/templates/<name>/`) when `$asset` is true.
 */
function active_theme(bool $asset = false): string
{
    $name = active_theme_name();

    return $asset ? "assets/templates/{$name}/" : "templates.{$name}.";
}

function staff_session(): ?StaffMember
{
    $staffId = session('staff_session_id');
    if (! $staffId) {
        return null;
    }

    $guardStaff = Auth::guard('staff')->user();

    if ($guardStaff instanceof StaffMember && $guardStaff->id === (int) $staffId) {
        return $guardStaff;
    }

    return StaffMember::with(['owner', 'store'])->find($staffId);
}

function is_staff_dashboard(): bool
{
    return staff_session() instanceof StaffMember;
}

function dashboard_actor()
{
    return staff_session() ?? Auth::user();
}

function dashboard_owner(): ?User
{
    $staff = staff_session();

    if ($staff) {
        if ($staff->relationLoaded('owner') && $staff->owner) {
            return $staff->owner;
        }

        return User::find($staff->owner_id);
    }

    $user = Auth::user();

    return $user instanceof User ? $user : null;
}

function dashboard_owner_id(): ?int
{
    return dashboard_owner()?->id;
}

/**
 * DOCS-PLATFORM D1-6: the owner's next onboarding step for the topbar pill, or
 * null when onboarding is dismissed or complete. Memoized per request; the whole
 * thing short-circuits on the dismiss flag so it costs nothing for set-up owners.
 *
 * @return array{label:string,url:string}|null
 */
function onboarding_next_step(): ?array
{
    $owner = function_exists('dashboard_owner') ? dashboard_owner() : null;
    if (! $owner || (function_exists('staff_session') && staff_session())) {
        return null; // owners only, not staff sessions
    }
    // No process-static memo: the topbar @php calls this once per request, and a
    // static would leak across test methods (RefreshDatabase reuses owner ids).
    if (UserOption::getUserOption($owner->id, 'onboarding_dismissed')) {
        return null;
    }

    try {
        // A user's Posts are their restaurants (no type filter — matches the
        // onboarding checklist and dashboard_store_ids()).
        $store = Store::where('user_id', $owner->id)->orderBy('id')->first();
        if (! $store) {
            return ['label' => ___('Add your first store'), 'url' => Route::has('stores.create') ? route('stores.create') : url('restaurants')];
        }
        $logoOk = ! in_array($store->main_image ?? '', ['', 'default.png', null], true);
        if (! $logoOk && Route::has('stores.edit')) {
            return ['label' => ___('Upload your logo'), 'url' => route('stores.edit', $store->id)];
        }
        if (! Menu::where('store_id', $store->id)->exists()) {
            return ['label' => ___('Add your first menu item'), 'url' => Route::has('stores.menu') ? route('stores.menu', $store->id) : url('dashboard')];
        }
    } catch (Throwable $e) {
        return null;
    }

    return null; // core setup done — pill disappears
}

/** @return list<int> */
function dashboard_store_ids(): array
{
    // PERF: per-request memo, keyed by the resolved SUBJECT (staff vs owner id) so
    // an in-request/in-test subject switch (actingAs, impersonation) never reads a
    // prior subject's ids. Held in ONE container binding — app-instance scoped, so
    // it resets per request AND per test (fresh app), avoiding the process-static
    // cross-test leak the codebase deliberately avoids here (see onboarding_next_step).
    // Post::booted() forgets the binding on any store write.
    $staff = staff_session();
    $subject = $staff ? 'staff:'.$staff->id : 'owner:'.(Auth::id() ?? '0');

    $map = app()->bound('__dash_ids_map') ? app('__dash_ids_map') : [];
    if (array_key_exists($subject, $map)) {
        return $map[$subject];
    }

    $ids = $staff
        ? ($staff->store_id ? [(int) $staff->store_id] : [])
        : (($owner = dashboard_owner()) ? $owner->stores()->pluck('id')->map(fn ($id) => (int) $id)->all() : []);

    $map[$subject] = $ids;
    app()->instance('__dash_ids_map', $map);

    return $ids;
}

/**
 * S13: canonical "restaurant (Post) ids owned by a user" resolver — the single
 * source of truth behind the ~22 hand-rolled private `ownerRestaurantIds()`
 * helpers and the ScopesToOwner model trait. Keyed on the raw web-guard user id
 * (the owner), NOT dashboard_owner() — matching the historical controller
 * behavior. Use dashboard_store_ids() when you need the staff-aware scope.
 */
/** @return list<int> */
function owner_store_ids(?int $ownerId = null): array
{
    $ownerId ??= Auth::id();

    return $ownerId
        ? Store::where('user_id', $ownerId)->pluck('id')->map(fn ($id) => (int) $id)->all()
        : [];
}

/**
 * M1: per-dashboard-user notification preferences, stored as a JSON blob in
 * user_options under `dashnotif_prefs` (same table as the `dashnotif_seen`
 * watermark). Shape:
 *
 *   { "stores": { "<storeId>": {
 *        "enabled":  bool,
 *        "branches": { "<branchId>": bool } | null,   // null / absent = every branch on
 *        "events":   { "orders":bool,"reviews":bool,"disputes":bool,"waitlist":bool,"webhooks":bool }
 *   } } }
 *
 * An ABSENT key, an absent store entry, or an absent event/branch entry ALL mean
 * ENABLED — so a user who never opens the prefs page keeps today's behaviour
 * (every store, every branch, every event fires). Defaults live in the readers
 * below, never in the stored blob, so "on" is never persisted redundantly.
 *
 * @return array<string,mixed>
 */
function dashnotif_prefs(?int $userId = null): array
{
    $userId ??= (dashboard_owner()->id ?? Auth::id());
    if (! $userId) {
        return [];
    }

    $raw = UserOption::getUserOption($userId, 'dashnotif_prefs');
    if (! is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * M1: should the given store (optionally a branch, optionally a specific event)
 * fire dashboard notifications for this user? Defaults to ENABLED on ANY missing
 * entry — the store is only silenced when a pref explicitly says `false`.
 *
 * @param  array<string,mixed>  $prefs  from dashnotif_prefs()
 */
function dashnotif_store_enabled(array $prefs, int $storeId, ?int $branchId = null, ?string $event = null): bool
{
    $stores = $prefs['stores'] ?? null;
    if (! is_array($stores)) {
        return true;                                   // no prefs at all → everything on
    }

    // json_decode(assoc) keys stores by string; tolerate an int key too.
    $store = $stores[(string) $storeId] ?? ($stores[$storeId] ?? null);
    if (! is_array($store)) {
        return true;                                   // this store never configured → on
    }

    if (($store['enabled'] ?? true) === false) {
        return false;                                  // whole store muted
    }

    if ($event !== null) {
        $events = $store['events'] ?? null;
        if (is_array($events) && ($events[$event] ?? true) === false) {
            return false;                              // this event muted for the store
        }
    }

    if ($branchId !== null) {
        $branches = $store['branches'] ?? null;        // null = all branches on
        if (is_array($branches) && ($branches[(string) $branchId] ?? ($branches[$branchId] ?? true)) === false) {
            return false;                              // this branch muted
        }
    }

    return true;
}

/**
 * M1 IDOR FENCE: normalise a raw prefs payload (from the M2 UI) down to ONLY the
 * stores this user actually owns/staffs (dashboard_store_ids — subject-aware)
 * and, within each, only real branch ids of THAT store (Branch → store_id). A
 * foreign store id or a branch id that does not belong to the store is silently
 * STRIPPED, so a user can never configure — or probe the existence of — a store
 * or branch that is not theirs. Returns a clean array ready to json_encode.
 *
 * @param  array<string,mixed>  $input
 * @return array<string,mixed>
 */
function dashnotif_sanitize_prefs(array $input): array
{
    $allowedStores = dashboard_store_ids();
    $eventKeys = ['orders', 'reviews', 'disputes', 'waitlist', 'webhooks'];

    $out = ['stores' => []];
    $stores = $input['stores'] ?? null;
    if (! is_array($stores)) {
        return $out;
    }

    foreach ($stores as $sid => $cfg) {
        $sid = (int) $sid;
        if (! in_array($sid, $allowedStores, true)) {
            continue;                                  // FENCE: not this user's store → drop
        }
        if (! is_array($cfg)) {
            continue;
        }

        $clean = ['enabled' => (($cfg['enabled'] ?? true) !== false)];

        $inEvents = is_array($cfg['events'] ?? null) ? $cfg['events'] : [];
        $events = [];
        foreach ($eventKeys as $e) {
            if (array_key_exists($e, $inEvents)) {
                $events[$e] = (bool) $inEvents[$e];
            }
        }
        if ($events !== []) {
            $clean['events'] = $events;
        }

        $inBranches = $cfg['branches'] ?? null;
        if (is_array($inBranches)) {
            $realBranchIds = Branch::where('store_id', $sid)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $branches = [];
            foreach ($inBranches as $bid => $on) {
                if (in_array((int) $bid, $realBranchIds, true)) {   // FENCE: real branch of THIS store only
                    $branches[(string) (int) $bid] = (bool) $on;
                }
            }
            $clean['branches'] = $branches !== [] ? $branches : null;
        }

        $out['stores'][(string) $sid] = $clean;
    }

    return $out;
}

/**
 * M1: fence + persist a raw prefs payload for the given user (default: the
 * web-guard owner). Always writes the SANITISED blob, so a foreign id in the
 * request body can never reach storage. Returns the clean array that was saved.
 *
 * @param  array<string,mixed>  $input
 * @return array<string,mixed>
 */
function dashnotif_save_prefs(array $input, ?int $userId = null): array
{
    $userId ??= (dashboard_owner()->id ?? Auth::id());
    $clean = dashnotif_sanitize_prefs($input);

    if ($userId) {
        UserOption::updateUserOption($userId, 'dashnotif_prefs', json_encode($clean));
    }

    return $clean;
}

function dashboard_stores()
{
    // PERF: same subject-keyed per-request memo as dashboard_store_ids(); the
    // nav + the page both ask for the store collection. Post::booted() busts it on a
    // store write. Callers that ->load() extra relations mutate this shared instance
    // in place, which is fine (idempotent for a single render).
    $staff = staff_session();
    $subject = $staff ? 'staff:'.$staff->id : 'owner:'.(Auth::id() ?? '0');

    $map = app()->bound('__dash_restaurants_map') ? app('__dash_restaurants_map') : [];
    if (array_key_exists($subject, $map)) {
        return $map[$subject];
    }

    $ids = dashboard_store_ids();
    $stores = empty($ids)
        ? collect()
        : Store::whereIn('id', $ids)->latest()->orderByDesc('id')->get(); // id tie-break: same-second stores keep a stable default

    $map[$subject] = $stores;
    app()->instance('__dash_restaurants_map', $map);

    return $stores;
}

function dashboard_first_store(): ?Store
{
    return dashboard_current_store();
}

/**
 * The store the dashboard is currently scoped to: the session-selected one
 * (sidebar switcher) when it still belongs to the actor, else the actor's
 * saved default, else the first. Invalid selections (store deleted or no
 * longer accessible) fall through, so there is always a working fallback.
 */
function dashboard_current_store(): ?Store
{
    $ids = dashboard_store_ids();

    if (empty($ids)) {
        return null;
    }

    $candidates = [(int) session('dashboard_store_id')];

    // Owners can save a login default; staff are bound to one store anyway.
    if (! staff_session() && ($ownerId = dashboard_owner_id())) {
        $candidates[] = (int) UserOption::getUserOption($ownerId, 'default_store_id');
    }

    foreach ($candidates as $candidate) {
        if ($candidate && in_array($candidate, $ids, true)) {
            $store = Store::find($candidate);

            if ($store) {
                return $store;
            }
        }
    }

    return dashboard_stores()->first();
}

/**
 * store_noun() — the store-entity noun helper — moved to VerticalHelper.php
 * (WI-M15-style split, to keep this file under its line ceiling). Same global
 * function, same call sites.
 */

/**
 * The branch the dashboard is scoped to, or null for "all branches".
 * Staff pinned to a branch are always locked to it. A session value of 0
 * means the user explicitly chose "all branches"; an absent key means no
 * choice yet, so the actor's saved default applies. A default that no
 * longer belongs to the current store falls back to all branches.
 */
function dashboard_current_branch(): ?Branch
{
    $store = dashboard_current_store();

    if (! $store) {
        return null;
    }

    $staff = staff_session();

    if ($staff && $staff->branch_id) {
        return Branch::where('id', $staff->branch_id)->where('store_id', $store->id)->first();
    }

    if (session()->has('dashboard_branch_id')) {
        $selected = (int) session('dashboard_branch_id');

        return $selected
            ? Branch::where('id', $selected)->where('store_id', $store->id)->first()
            : null;
    }

    $default = $staff
        ? (int) $staff->default_branch_id
        : (int) UserOption::getUserOption(dashboard_owner_id(), 'default_branch_id');

    if (! $default) {
        return null;
    }

    return Branch::where('id', $default)->where('store_id', $store->id)->first();
}

function dashboard_current_branch_id(): ?int
{
    return dashboard_current_branch()?->id;
}

/**
 * Branch id a dashboard page should filter by: an explicit ?branch_id= query
 * wins (0/empty = all branches), otherwise the sidebar session selection.
 * Pass the page's restaurant id so a session branch belonging to another
 * store is ignored.
 */
function dashboard_request_branch_id(?int $storeId = null): ?int
{
    if (request()->has('branch_id')) {
        return (int) request()->query('branch_id') ?: null;
    }

    $branch = dashboard_current_branch();

    if (! $branch) {
        return null;
    }

    if ($storeId && (int) $branch->store_id !== $storeId) {
        return null;
    }

    return $branch->id;
}

function dashboard_allows(?string $permission = null): bool
{
    $staff = staff_session();

    if (! $staff) {
        return true;
    }

    if ($permission === null) {
        return true;
    }

    return $staff->hasPermission($permission);
}

function dashboard_can_access_store($store): bool
{
    $storeId = $store instanceof Store ? (int) $store->id : (int) $store;

    return in_array($storeId, dashboard_store_ids(), true);
}

function dashboard_can_access_order($order): bool
{
    $orderModel = $order instanceof Order ? $order : Order::find($order);

    return $orderModel ? dashboard_can_access_store($orderModel->store_id) : false;
}

/**
 * Name of the active owner theme: a non-blank `template` session value (theme
 * preview) wins over config('app.theme') (THEME_NAME, config-cache safe).
 * The pre-P1-01 name `lamar` (still in older .env files) reads as `juwri`.
 */
function active_theme_name(): mixed
{
    $preview = session('template');
    $name = trim((string) $preview) ? $preview : config('app.theme');

    return $name === 'lamar' ? 'juwri' : $name; // brand-alias: pre-P1-01 THEME_NAME value (rename-map)
}

/**
 * Browser title for a layout: "<section title> - <site title>", or just the site
 * title when the view defines no `title` section. `$view` is the Blade factory.
 */
function page_title(mixed $view): mixed
{
    $section = $view->yieldContent('title');
    $site = config('settings.site_title');

    return $section ? $section.' - '.$site : $site;
}

/**
 * One store option (`$name` given; `$default` when absent) or all of the store's
 * options as an object.
 *
 * @param  int|string  $storeId
 * @param  string|null  $name
 * @param  mixed  $default
 */
function store_options($storeId, $name = null, $default = null): mixed
{
    if (! empty($name)) {
        return StoreOption::getStoreOption($storeId, $name, $default);
    }
    // P1: memoized bulk read (one store_options SELECT per store per request; also seeds
    // the single-key memo so later store_options($id,$key) calls are memo hits).
    $options = StoreOption::bulkOptions($storeId);

    // Overlay BYOK gateway secrets from the encrypted vault so bulk reads like
    // `store_options($id)->restaurant_stripe_secret_key` resolve after migration.
    $options = GatewaySecretVault::overlay($storeId, $options);
    // P1-07: payment gateway settings (store_payment_gateways), decoded like store_options values.
    foreach (StorePaymentGateways::all((int) $storeId) as $name => $value) {
        $options[$name] = (! StorePaymentGateways::isCredential($name) && is_string($value) && Str::isJson($value))
            ? json_decode($value) : $value;
    }

    return array_to_object($options);
}

/**
 * WA-S2: has the platform switched the WhatsApp hand-off off for everyone?
 *
 * A single admin option (Admin → Settings → Feature Controls). It exists so an
 * outage — a WhatsApp block, a legal hold, a bad number range — can be stopped
 * from one place without touching 1,172 stores. Off by default: an absent option
 * must never mean "killed".
 */
function whatsapp_handoff_killed(): bool
{
    return filter_var(config('settings.whatsapp_handoff_kill'), FILTER_VALIDATE_BOOLEAN);
}

/**
 * Is WhatsApp ordering switched on for this store?
 *
 * WA-C1: the order→WhatsApp hand-off used to be gated on
 * `is_plugin_enabled('quickorder')`, so deactivating that plugin silently
 * emptied `whatsapp_url` for every store that relied on it. The gate now lives
 * in core and asks only about the store's own flag.
 *
 * Reads the core option first and falls back to the legacy `quickorder_enable`
 * key, so no store changes behaviour on deploy. WA-C4 renames the rows and drops
 * the fallback; until then both spellings are honoured.
 */
function whatsapp_ordering_enabled($storeId): bool
{
    // WA-S2: the platform kill-switch outranks every store setting.
    if (whatsapp_handoff_killed()) {
        return false;
    }

    $enabled = store_options($storeId, 'whatsapp_ordering_enable');

    /* StoreOption::getStoreOption() returns FALSE for a missing option, not null —
       so "absent" and "explicitly off" look almost alike. Only a genuinely absent
       key falls through to the legacy flag; a stored '0' stays off. */
    if ($enabled === false || $enabled === null || $enabled === '') {
        $enabled = store_options($storeId, 'quickorder_enable');
    }

    return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
}

/**
 * H15: is this store's storefront checkout served by the unified pipeline
 * (OrderApiService::create) instead of StoreController::submitOrder's inline copy?
 *
 * Platform default is 'legacy'. `storefront_order_pipeline_stores` is a
 * comma-separated allow-list of post ids for canarying one store at a time
 * WITHOUT flipping the platform. An absent option must never mean "unified".
 */
function storefront_unified_pipeline(int $storeId): bool
{
    if ((string) config('settings.storefront_order_pipeline', 'legacy') === 'unified') {
        return true;
    }
    $canary = (string) config('settings.storefront_order_pipeline_stores', '');
    if ($canary === '') {
        return false;
    }

    return in_array((string) $storeId, array_filter(array_map('trim', explode(',', $canary))), true);
}

if (! function_exists('whatsapp_verify_signatures')) {
    /**
     * C1: the ONE reader for the inbound-webhook signature switch.
     *
     * The admin form writes the Option `whatsapp_verify_signatures`
     * (Admin\WhatsAppSettingsController::SETTING_KEYS), which surfaces as
     * config('settings.whatsapp_verify_signatures'). The webhook controller used
     * to read config('whatsapp.verify_signatures') — a key that does not exist in
     * config/whatsapp.php — so the toggle was inert in BOTH directions. Default
     * ON: an unset/blank Option must verify.
     */
    function whatsapp_verify_signatures(): bool
    {
        // P6-69: production always verifies; the platform toggle only works off-production.
        if (app()->environment('production')) {
            return true;
        }
        $v = config('settings.whatsapp_verify_signatures');

        return ! ($v === '0' || $v === 0 || $v === false);
    }
}

/**
 * WA-D3: legacy WhatsApp numbers 2–10 this store still has sitting in post
 * options, which the migration deliberately did NOT place on a branch.
 *
 * We never invent a branch to hold one — only the merchant knows which physical
 * location a number belongs to. This returns them so the dashboard can ask.
 *
 * @return array<int,string> the extra numbers, in slot order
 */
function whatsapp_extra_numbers($storeId): array
{
    $extras = [];

    for ($i = 2; $i <= 10; $i++) {
        $value = store_options($storeId, 'whatsapp_number'.$i);

        /* getStoreOption() returns FALSE for a missing option — see
           whatsapp_ordering_enabled() for the same trap. */
        if ($value === false || blank($value)) {
            continue;
        }

        $extras[] = (string) $value;
    }

    return $extras;
}

/**
 * WA-S1: how the storefront should hand the customer over to WhatsApp after
 * checkout. Store setting, then platform default, then the shipped default.
 *
 * Behaviour used to be hardcoded per theme and inconsistent — some popped a new
 * tab, some navigated in place, one did nothing at all. The themes now ask this.
 *
 * @return array{mode:string,target:string,delay:int,route:string}
 *                                                                 mode   auto | button | none   — open it, offer a button, or do nothing
 *                                                                 target same | new             — same tab or new tab
 *                                                                 delay  milliseconds before the automatic attempt
 *                                                                 route  branch | fixed         — which number the message goes to
 */
function whatsapp_handoff_settings($storeId): array
{
    /* WA-S2: the platform kill-switch wins over everything below it. Returned as
       a normal "do nothing" result so every caller — themes included — needs no
       extra branch to honour it. */
    if (whatsapp_handoff_killed()) {
        return ['mode' => 'none', 'target' => 'new', 'delay' => 0, 'route' => 'branch'];
    }

    /* A stored '0' is a real choice; only an absent option falls through.
       getStoreOption() returns FALSE when absent — see whatsapp_ordering_enabled(). */
    $pick = function (string $key, $platform, $shipped) use ($storeId) {
        $value = store_options($storeId, $key);
        if ($value !== false && filled($value)) {
            return $value;
        }
        $value = config('settings.'.$key);

        return filled($value) ? $value : $shipped;
    };

    $mode = (string) $pick('whatsapp_handoff_mode', null, 'auto');
    $target = (string) $pick('whatsapp_handoff_target', null, 'new');
    $delay = (float) $pick('whatsapp_handoff_delay', null, 0);
    $route = (string) $pick('whatsapp_handoff_route', null, 'branch');

    return [
        'mode' => in_array($mode, ['auto', 'button', 'none'], true) ? $mode : 'auto',
        'target' => in_array($target, ['same', 'new'], true) ? $target : 'new',
        // Seconds in the UI, milliseconds in the browser; clamp to the documented range.
        'delay' => (int) round(max(0, min(5, $delay)) * 1000),
        'route' => in_array($route, ['branch', 'fixed'], true) ? $route : 'branch',
    ];
}

/**
 * WA-T1: the WhatsApp number a storefront should chat to, digits only.
 *
 * Branch first, then the store's own phone. The legacy `whatsapp_number` option is
 * still consulted while it exists, and that read is logged (WA-D4) so Lane E can
 * prove nothing depends on it before deleting it.
 *
 * The 16 keeper themes each open-coded
 * `preg_replace('/\D+/', '', @$postOptions->whatsapp_number ?: $store->phone)`,
 * which is why the branch system never reached the storefront chat button.
 *
 * @param  Store|int|null  $store  the store, or its id
 * @param  Branch|int|null  $branch  the branch in context, if the page knows one
 * @param  bool  $logLegacy  WA-S3: the dashboard's routing preview passes false. A
 *                           logged read is Lane E's evidence that a store still *depends* on the legacy
 *                           option; a merchant looking at their own branches page is not that.
 */
function storefront_whatsapp_number($store, $branch = null, bool $logLegacy = true): string
{
    $storeId = is_object($store) ? ($store->id ?? null) : $store;

    if (! $storeId) {
        return '';
    }

    // 1. The branch the page is showing, or the store's default.
    $branchModel = is_object($branch) ? $branch : null;

    if (! $branchModel) {
        try {
            $query = Branch::where('store_id', $storeId);
            $branchModel = $branch
                ? (clone $query)->where('id', $branch)->first()
                : null;
            $branchModel ??= $query->orderBy('position')->orderBy('id')->first();
        } catch (Throwable $e) {
            $branchModel = null;
        }
    }

    $number = $branchModel->whatsapp ?? null;

    // 2. Legacy store option — logged, because Lane E is waiting on this going quiet.
    if (blank($number)) {
        $legacy = store_options($storeId, 'whatsapp_number');
        if ($legacy !== false && filled($legacy)) {
            if ($logLegacy) {
                whatsapp_log_legacy_read($storeId, 'whatsapp_number');
            }
            $number = $legacy;
        }
    }

    // 3. The store's phone, the fallback every theme already had.
    if (blank($number)) {
        $number = is_object($store) ? ($store->phone ?? null) : Store::where('id', $storeId)->value('phone');
    }

    /* wa.me wants ASCII digits only. whatsapp_normalize_number() also rewrites
       Arabic-Indic numerals, which a plain \D strip would delete entirely. */
    return whatsapp_normalize_number((string) ($number ?? ''));
}

/**
 * PAYMENTS-RUN / WI-06e: the one normaliser for every gateway "sandbox mode"
 * setting ('Yes'/'No', '1'/'0', booleans) — see Settlement::sandboxFlag().
 * A missing, blank or unknown value gets $default (sandbox by default).
 * Lane D: every adapter puts the result through GatewaySandbox::gate(), so with
 * payment sandboxes off (production) a blank setting read as sandbox is refused
 * ("not configured") — it never charges on a provider's test host by accident.
 */
function gateway_is_sandbox($value, bool $default = true): bool
{
    return Settlement::sandboxFlag($value, $default);
}

/**
 * Resolve a restaurant's inventory mode.
 * Returns 'advanced' (ingredient-level) or 'simple' (per-menu-item, default).
 */
function inventory_mode($storeId): string
{
    return store_options($storeId, 'inventory_mode') === 'advanced' ? 'advanced' : 'simple';
}

/**
 * Resolve a restaurant's KDS mode.
 * Returns 'advanced' (stations, item-level, expo, coursing) or 'basic' (order-level, default).
 */
function kds_mode($storeId): string
{
    return store_options($storeId, 'kds_mode') === 'advanced' ? 'advanced' : 'basic';
}

/**
 * One user option (`$name` given: its stored value, or false when absent) or all
 * of the user's options as an object, JSON values decoded.
 */
function user_options(mixed $userId, mixed $name = null): mixed
{
    if (! empty($name)) {
        return UserOption::getUserOption($userId, $name);
    }

    $all = collect(UserSettings::legacyMap((int) $userId))
        ->map(fn ($stored) => Str::isJson($stored) ? json_decode($stored) : $stored);

    return array_to_object($all);
}

/** A plan feature row by id (null when it does not exist). P1-13: plans are Billing's (ee) — PlanCatalog. */
function plan_option(mixed $optionId): ?Model
{
    return app(PlanCatalog::class)->option($optionId);
}

/** Translated label for a plan billing interval; '-' for anything unknown. */
function plan_interval_text($interval): string
{
    return match (is_scalar($interval) ? (string) $interval : '') {
        'MONTHLY' => ___('Monthly'),
        'YEARLY' => ___('Yearly'),
        'LIFETIME' => ___('Lifetime'),
        default => '-',
    };
}

/**
 * Currencies whose minor unit is NOT 2 decimals (ISO 4217).
 *
 * Only the exceptions are listed; everything else resolves to 2 via
 * currency_decimals(). Shared with the storefront JS — each theme passes this
 * map into its CFG so `money()` and `price_format()` cannot drift apart.
 *
 * IQD is ISO-3 but is DELIBERATELY OMITTED: Iraq's fils are obsolete in
 * practice and its 167 stores price in whole dinars, so honouring ISO here
 * would render 25000 IQD as "25000.000". The same pragmatism applies to any
 * currency whose minor unit is legally defined but unused.
 *
 * @return array<string, int>
 */
function currency_decimals_map()
{
    return [
        'BHD' => 3,  // Bahraini dinar   — 1000 fils
        'JOD' => 3,  // Jordanian dinar  — 1000 fils
        'KWD' => 3,  // Kuwaiti dinar    — 1000 fils
        'OMR' => 3,  // Omani rial       — 1000 baisa
        'LYD' => 3,  // Libyan dinar     — 1000 dirham
        'TND' => 3,  // Tunisian dinar   — 1000 millime

        // Zero-decimal currencies (ISO 4217, no minor unit) — mirror
        // GatewayMinorUnits::ZERO_DECIMAL so display/rounding matches what is
        // actually charged. Rendering JPY 5000 as "5000.00" was both wrong and
        // out of step with the charged amount.
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'JPY' => 0, 'KMF' => 0,
        'KRW' => 0, 'MGA' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0,
        'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
    ];
}

/**
 * Saudi Riyal Symbol (U+20C1) rollout — S10: the ONE authoritative ISO-code →
 * display-symbol map.
 *
 * Seeded from the union of the three legacy maps that used to disagree
 * (UserController::currencySymbolForCode, a since-removed legacy importer, the
 * countries seed) plus common currencies. Callers fall back to the ISO code itself for an
 * unknown code (mirroring the old currencySymbolForCode behaviour).
 *
 * Locked (§1): SAR is the new U+20C1 sign; Iran (IRR) and Yemen (YER) keep the
 * generic rial ligature ﷼ (U+FDFC). AED is the new U+20C3 UAE Dirham sign (its
 * MIT-licensed webfont glyph shipped 2026-08-12 — the S12 font gate cleared). OMR
 * keeps ر.ع. until a redistributable U+20C4 font exists (still gated).
 *
 * @return array<string, string>
 */
function currency_symbol_map(): array
{
    return [
        // Majors + widely used.
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'CNY' => '¥',
        'INR' => '₹', 'TRY' => '₺', 'RUB' => '₽', 'KRW' => '₩', 'THB' => '฿',
        'VND' => '₫', 'PHP' => '₱', 'ILS' => '₪', 'UAH' => '₴', 'NGN' => '₦',
        'BRL' => 'R$', 'ZAR' => 'R', 'CHF' => 'CHF', 'PLN' => 'zł', 'CZK' => 'Kč',
        'HUF' => 'Ft', 'RON' => 'lei', 'BGN' => 'лв', 'SEK' => 'kr', 'NOK' => 'kr',
        'DKK' => 'kr', 'ISK' => 'kr',
        // Dollar family (share the glyph).
        'CAD' => '$', 'AUD' => '$', 'NZD' => '$', 'HKD' => '$', 'SGD' => '$', 'MXN' => '$',
        // South + Southeast Asia.
        'IDR' => 'Rp', 'MYR' => 'RM', 'PKR' => '₨', 'LKR' => '₨', 'BDT' => '৳', 'NPR' => '₨',
        // Middle East + North Africa.
        'SAR' => '⃁',   // U+20C1 SAUDI RIYAL SIGN (locked §1)
        'AED' => '⃃',   // U+20C3 UAE DIRHAM SIGN (font gate cleared 2026-08-12)
        'QAR' => 'ر.ق', 'KWD' => 'د.ك', 'BHD' => '.د.ب',
        'OMR' => 'ر.ع.', 'IQD' => 'د.ع', 'JOD' => 'د.ا', 'LBP' => 'ل.ل',
        'LYD' => 'ل.د', 'TND' => 'د.ت', 'SYP' => 'ل.س', 'EGP' => '£', 'MAD' => 'د.م.',
        'DZD' => 'دج',
        'IRR' => '﷼',   // U+FDFC generic rial ligature — Iran keeps it (locked §1)
        'YER' => '﷼',   // U+FDFC generic rial ligature — Yemen keeps it (locked §1)
    ];
}

/**
 * The display symbol for an ISO code, falling back to the code itself when the
 * map has no entry (e.g. 'XXX' → 'XXX'). Sibling of price_symbol_format() but
 * pure — no request/store context.
 */
function currency_symbol_for(string $code): string
{
    $code = strtoupper(trim($code));

    return currency_symbol_map()[$code] ?? $code;
}

/**
 * The customer-facing name of an order type, in the current locale (receipts, kitchen tickets): the raw type key
 * ("takeaway", "on-table") printed as-is left Arabic receipts with an English word in them.
 */
function order_type_label(?string $type): string
{
    return match ($type) {
        'on-table' => ___('Dine in'),
        'takeaway' => ___('Takeaway'),
        'delivery' => ___('Delivery'),
        'room_service' => ___('Room service'),
        default => ucfirst((string) $type),
    };
}

/** A payment method key ("cash", "card") as a receipt line in the current locale; an unknown method prints as written. */
function payment_method_label(?string $method): string
{
    return match ($method) {
        'cash' => ___('Cash'),
        'card' => ___('Card'),
        'gift_card' => ___('Gift card'),
        'store_credit' => ___('Store credit'), // P6-330 Z-report tender lines
        'platform_wallet' => ___('Platform wallet'),
        default => ucfirst((string) $method),
    };
}

/**
 * Saudi Riyal Symbol (U+20C1) rollout — S7: the ONE non-web fallback guard.
 *
 * Non-web surfaces (WinAnsi invoice PDF, plain-text/webfont-less emails, CSV
 * exports, thermal ESC/POS) cannot draw glyphs outside Windows-1252 — the new
 * U+20C1 riyal, ₹, ﷼, د.إ all render as mojibake or tofu there. Return the sign
 * when it is WinAnsi-drawable ($, €, £ …), otherwise the ISO code (SAR, INR …).
 *
 * The drawability test mirrors QrSheetPdfService::hasUnrepresentableGlyphs():
 * iconv to Windows-1252 and see whether any character was dropped.
 *
 * @param  string|null  $sign
 */
function currency_sign_safe($sign, string $code): string
{
    $sign = trim((string) $sign);
    $code = strtoupper(trim($code));

    if ($sign === '') {
        return $code;
    }

    $stripped = @iconv('UTF-8', 'Windows-1252//IGNORE', $sign);
    $drawable = $stripped !== false
        && mb_strlen($stripped, 'Windows-1252') === mb_strlen($sign, 'UTF-8');

    if ($drawable) {
        return $sign;
    }

    return $code !== '' ? $code : $sign;
}

/**
 * price_symbol_format() for non-web surfaces: identical, but the platform sign is
 * passed through currency_sign_safe() so an undrawable glyph becomes the ISO code.
 *
 * @param  int|float|string  $price
 */
function price_symbol_format_safe($price): string
{
    $safe = currency_sign_safe(
        config('settings.currency_sign'),
        (string) config('settings.currency_code', '')
    );

    return currency_pos_effective() === 1
        ? $safe.price_format($price)
        : price_format($price).$safe;
}

/**
 * Decimal places for a currency code, defaulting to the current store's.
 *
 * StoreController@publicView overrides settings.currency_code per request with
 * the store's own currency, so this resolves per-store on a storefront render
 * and to the platform currency everywhere else (plans, wallet, admin).
 *
 * @param  string|null  $code
 * @return int
 */
function currency_decimals($code = null)
{
    $code = strtoupper((string) ($code ?? config('settings.currency_code', 'USD')));

    return currency_decimals_map()[$code] ?? 2;
}

/**
 * Saudi Riyal Symbol (U+20C1) rollout — S4: normalize a stored currency sign.
 *
 * Guards against wrong riyal codepoints reaching storage from copy-paste of bad
 * sources: U+20C0 is the Kyrgyz SOM sign (a common look-alike error) and U+E900
 * is the private-use codepoint the pre-2025 riyal font used. Both are rewritten
 * to the canonical U+20C1 SAUDI RIYAL SIGN.
 *
 * The rewrite fires ONLY when the currency code is SAR (locked decision §1): a
 * KGS store may legitimately use U+20C0, and no other currency owns U+E900, so
 * the guard is scoped to Saudi Arabia and leaves every other sign untouched.
 *
 * @param  string|null  $sign
 * @param  string|null  $code
 */
function normalize_currency_sign($sign, $code): string
{
    $sign = (string) $sign;

    if (strtoupper((string) $code) === 'SAR') {
        $sign = str_replace(["\u{20C0}", "\u{E900}"], "\u{20C1}", $sign);
    }

    return $sign;
}

/**
 * Price decimal format.
 *
 * `menu.price` is a DOUBLE, so raw values carry float artifacts —
 * 1.7999999523162842 was rendering verbatim on menu cards. This was a no-op
 * (`return $price`) since the initial commit, which is why prices only looked
 * right where a theme's JS reformatted them.
 *
 * Decimals follow the currency, not a hardcoded 2: a 3-decimal currency keeps
 * its fils (0.275 OMR stays "0.275" instead of rounding to "0.28"). The themes'
 * `money()` reads the same map from CFG, so the server-rendered card and the
 * JS-rendered modal agree.
 *
 * No thousands separator, deliberately — `toFixed()` never emits one, and
 * re-introducing it here would reopen the card/modal mismatch from the other
 * side.
 */
function price_format(mixed $amount): string
{
    return number_format((float) $amount, currency_decimals(), '.', '');
}

/**
 * The EFFECTIVE currency-symbol position for the current render: 1 = before the
 * number, 0 = after.
 *
 * RTL typography places the currency AFTER the number, so on an RTL display
 * locale (ar, ur, fa, he, …) the symbol is forced after regardless of the stored
 * `currency_pos` — UNLESS the owner turned the auto rule off (`currency_rtl_auto`
 * = '0'), in which case the stored flag wins even in RTL. LTR always honors the
 * stored flag. Pass a store id off the storefront-config path (push/SMS/POS); omit
 * it inside a storefront render where StoreController primes config('settings.*').
 */
function currency_pos_effective(int|string|null $storeId = null): int
{
    // UAE Dirham (U+20C3) is ALWAYS sign-first (before the number), regardless of
    // the stored currency_pos or locale — its own convention, kept as a deliberate
    // per-currency exception so no AED store needs setup and every surface (PHP
    // price helpers + the CFG.currencyPos the theme JS reads) inherits the one rule.
    $dirhamSign = $storeId !== null
        ? (store_options($storeId, 'currency_sign') ?: config('settings.currency_sign'))
        : config('settings.currency_sign');
    if (currency_sign_is_dirham(is_string($dirhamSign) ? $dirhamSign : null)) {
        return 1;
    }

    if ($storeId !== null) {
        $flag = store_currency_pos($storeId);
        $raw = store_options($storeId, 'currency_rtl_auto');
        // A stored '0' (auto OFF) must survive; only unset falls back (store_options
        // returns false for an unset key, and '0' is falsy — same trap as currency_pos).
        $rtlAuto = ($raw === false || $raw === null || $raw === '')
            ? (string) config('settings.currency_rtl_auto', '1')
            : (string) $raw;
    } else {
        $flag = (int) config('settings.currency_pos', 1);
        $rtlAuto = (string) config('settings.currency_rtl_auto', '1');
    }

    if (is_rtl() && $rtlAuto !== '0') {
        // Arabic/RTL convention: the currency sign sits AFTER the number, which is a
        // NUMBER-FIRST string (pos 0). The digits are an LTR run; in an RTL paragraph
        // the first string element is laid out rightmost, so a number-first string
        // ("3.29 ⃁") puts the digits on the right and the sign to their LEFT = visually
        // after. (A sign-first string renders the sign rightmost = visually BEFORE the
        // number — wrong; verified live on /retail-demo against the approved Northgate
        // "3.29 ر.س".) USD is the exception: money_ltr() force-isolates it LTR so it
        // always reads "$5.00", which needs the sign-first string.
        $code = $storeId !== null
            ? (store_options($storeId, 'currency_code') ?: config('settings.currency_code'))
            : config('settings.currency_code');

        return strtoupper((string) $code) === 'USD' ? 1 : 0;
    }

    return $flag === 1 ? 1 : 0;
}

/**
 * The ONE predicate for the UAE Dirham sign (U+20C3). Both the spacing rule and
 * the always-sign-first position rule key off this, so the definition of "is the
 * dirham" lives in exactly one place.
 */
function currency_sign_is_dirham(?string $sign): bool
{
    return $sign !== null && str_contains($sign, "\u{20C3}");
}

/**
 * UAE Dirham spacing — the new U+20C3 UAE DIRHAM SIGN glyph carries no
 * side-bearing, so "20.80⃃" collides the sign into the last digit and reads as
 * one blurred token. A single space between the sign and the number restores
 * legibility. Locked to U+20C3 alone: the Saudi Riyal U+20C1 and every other
 * sign stay gap-less, so this predicate is the single source of truth for both
 * the PHP price helpers and the CFG the theme JS reads. Callers insert the
 * returned string on the number-facing side of the sign (position-aware).
 */
function currency_symbol_gap(?string $sign): string
{
    // Both the UAE Dirham (U+20C3) and the Saudi Riyal (U+20C1) glyphs carry no
    // side-bearing, so "20.80⃃" / "12.50⃁" collide the sign into the last digit and
    // read as one blurred token. A single space on the number-facing side restores
    // legibility (matches the approved Northgate "12.50 ر.س" spacing). Every other
    // sign — "$", "€", "£" — stays gap-less. Single source of truth for both the PHP
    // price helpers and the CFG the theme JS reads.
    $sign = (string) $sign;

    return (currency_sign_is_dirham($sign) || str_contains($sign, "\u{20C1}")) ? ' ' : '';
}

/** price_format() with the store currency sign on its effective side (see currency_pos_effective()). */
function price_symbol_format($price): string
{
    $raw = config('settings.currency_sign');
    $sign = cur_isolate($raw);
    $gap = currency_symbol_gap($raw);

    // money_ltr keeps the whole unit rendering in its logical order (USD only) so
    // an isolated "$" does not bidi-flip to the wrong side of the number on an RTL
    // page; it preserves the STORED position (before stays before, after stays
    // after) and no-ops for every non-USD currency.
    return money_ltr(currency_pos_effective() === 1
        ? $sign.$gap.price_format($price)
        : price_format($price).$gap.$sign);
}

/**
 * PARITY-RUN T5: the happy-hour price cell for the LEGACY server-rendered
 * templates, whose Blade partials print `$menu->price` directly (the modern
 * theme-pack renders the same thing in JS). Returns escaped HTML: an optional
 * struck-through pre-schedule price, the (already server-resolved) current
 * price, and an optional "Happy Hour" badge. `original_price` / `schedule_label`
 * are the additive fields MenuScheduleService::decorate() sets; both are absent
 * when no schedule is live, so this degrades to exactly the old output. The
 * caller must pass the DECORATED item (see each partial's `$total_menus` lookup)
 * — an undecorated item simply shows its price with no strike or badge.
 */
function hh_price_cell($menu)
{
    $html = '';
    if (! empty($menu->original_price)) {
        $html .= '<del class="hh-was" style="opacity:.55;font-weight:400;margin-inline-end:.35em">'
               .e(price_symbol_format($menu->original_price)).'</del> ';
    }
    $html .= e(price_symbol_format($menu->price));
    if (! empty($menu->schedule_label)) {
        $html .= ' <span class="hh-badge" style="display:inline-block;padding:.1em .5em;border-radius:999px;'
               .'background:#f59e0b;color:#1b1b1b;font-size:.68em;font-weight:700;vertical-align:middle">'
               .e($menu->schedule_label).'</span>';
    }

    return $html;
}

/** price_format() with the ISO currency code on its effective side, space-separated. */
function price_code_format($price): string
{
    $code = cur_isolate(config('settings.currency_code'));

    return currency_pos_effective() === 1
        ? $code.' '.price_format($price)
        : price_format($price).' '.$code;
}

/** Country name for an ISO code from the countries table (null when unknown). */
function get_country_name(mixed $code): ?string
{
    return Country::query()->where('code', $code)->value('name');
}

/**
 * Every row of the countries table.
 *
 * @return Illuminate\Database\Eloquent\Collection<int, Country>
 */
function countries(): Illuminate\Database\Eloquent\Collection
{
    return Country::query()->get();
}

/**
 * A country's name in the reader's language (PHP intl / CLDR) — the countries table only stores the English name, which an
 * Arabic owner would otherwise read in a picker. Falls back to the stored name when intl is missing or the code is unknown.
 */
function country_display_name(mixed $code, ?string $fallback = null, ?string $locale = null): string
{
    $code = strtoupper(trim((string) $code));
    $fallback = $fallback ?? $code;

    if ($code === '' || ! class_exists(Locale::class) || ! preg_match('/^[A-Z]{2}$/', $code)) {
        return $fallback;
    }

    $name = Locale::getDisplayRegion('-'.$code, $locale ?? get_lang());

    return ($name === false || $name === $code || $name === '-'.$code) ? $fallback : $name;
}

/**
 * A language's name in the reader's language (PHP intl / CLDR); falls back to the stored name for unknown codes.
 */
function language_display_name(mixed $code, ?string $fallback = null, ?string $locale = null): string
{
    $code = trim((string) $code);
    $fallback = $fallback ?? $code;

    if ($code === '' || ! class_exists(Locale::class) || ! preg_match('/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{2,8})*$/', $code)) {
        return $fallback;
    }

    $name = Locale::getDisplayName(str_replace('-', '_', $code), $locale ?? get_lang());

    return ($name === false || strcasecmp($name, $code) === 0) ? $fallback : $name;
}

/** URL prefix of the admin panel (APP_ADMIN via config, default "admin"). */
function admin_url(): string
{
    // P6: config('app.admin') so the admin path prefix survives config:cache
    // (the old APP_ADMIN env read returned null once .env stops being parsed).
    return (string) (config('app.admin') ?: 'admin');
}

if (! function_exists('storefront')) {
    /**
     * P9: the per-request storefront engagement context (loyalty / waitlist /
     * reservation / customer token). Themes call e.g.
     * `storefront()->loyaltyProgram($store)` instead of running the query inline in
     * their blade @php header. Reads are memoized app-instance-bound, so repeated
     * reads in partials are free and a fresh test boot starts clean.
     */
    function storefront(): StorefrontContext
    {
        return new StorefrontContext;
    }
}

/** Whether the current request path contains the admin prefix followed by "/". */
function is_admin_url(): bool
{
    return str_contains(request()->path(), admin_url().'/');
}

/** Queue an admin-panel notification (dispatched once the transaction commits). */
function create_notification(string $title, string $type, ?string $link = null): void
{
    CreateNotificationJob::dispatch($title, $type, $link)->afterCommit();
}

/**
 * Real social proof for the landing "trusted by" marquee: a stable daily
 * sample of ACTIVE restaurants that have a real (non-default) logo. Replaces
 * the template's fake Apple/Google/Amazon placeholder logos with genuine
 * customers (their logos are already public on their menu pages). Cached.
 *
 * @return Collection<int, Store>
 */
function marquee_stores(int $limit = 14)
{
    return Cache::flexible('landing:marquee_stores:'.$limit, [21600, 43200], function () use ($limit) {
        try {
            return Store::query()
                ->publiclyListed() // P6-48
                ->whereNotNull('main_image')->where('main_image', '<>', '')
                ->where('main_image', 'not like', '%default%')
                ->whereHas('user', fn ($u) => $u->where('status', 1))
                ->inRandomOrder()
                ->limit($limit)
                ->get(['id', 'slug', 'title', 'main_image']);
        } catch (Throwable $e) {
            return collect();
        }
    });
}

/** Active-restaurant count for honest landing copy (cached, never a live-exact promise). */
function active_store_count(): int
{
    return (int) Cache::flexible('landing:active_store_count', [21600, 43200], function () {
        try {
            return Store::query()->publiclyListed() // P6-48b: same public-listing fence as the marquee
                ->whereHas('user', fn ($u) => $u->where('status', 1))->count();
        } catch (Throwable $e) {
            return 0;
        }
    });
}

/**
 * Hero integration logo: return the URL for an official partner/AI logo
 * (assets/.../integrations/<key>.svg|png|webp) if that file is present, else
 * null so the badge falls back to its inline icon. Drop a brand-kit logo in
 * and it lights up — no code change. Cached per request.
 */
function integration_logo(?string $key): ?string
{
    if (! $key) {
        return null;
    }
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $dir = '/assets/site/integrations/';
    $root = dirname(base_path());
    foreach (['svg', 'png', 'webp'] as $ext) {
        if (is_file($root.$dir.$key.'.'.$ext)) {
            return $cache[$key] = $dir.$key.'.'.$ext;
        }
    }

    return $cache[$key] = null;
}

/**
 * Link that switches the site to `$locale`: the localized URL of the current page
 * when locale prefixes are on (settings.include_language_code), otherwise the
 * `localize` route that stores the choice.
 */
function lang_url(mixed $locale): mixed
{
    // rev/seo R2b: forceDefaultLocation=false — the default locale lives at the
    // UNPREFIXED URL (hideDefaultLocaleInURL), so pickers/hreflang must link "/"
    // not "/ar" (which would cost every visitor a 302 hop).
    return config('settings.include_language_code')
        ? LaravelLocalization::getLocalizedURL($locale, null, [], false)
        : route('localize', $locale);
}

/** The application's current locale code. */
function get_lang(): string
{
    return app()->getLocale();
}

/** The Language row for the current locale (never null; see the fallbacks below). */
function current_language(): mixed
{
    // P1: current_language() is called many times per render (7× in the storefront
    // layout alone, 72 sites across views). Memoize the row per locale, bound to the
    // app instance so a fresh test/app boot starts clean and a Language write flushes
    // it (Juwri\Core\Models\Language::booted()).
    $locale = get_lang();
    $memo = app()->bound('memo.current_language') ? app('memo.current_language') : [];
    if (array_key_exists($locale, $memo)) {
        return $memo[$locale];
    }
    // Never return null: views read current_language()->name / ->direction, so a
    // locale without a row (or a test DB with no languages seeded) would 500 the
    // page. Fall back to the default language row, then any row, then a synthetic
    // English instance — always a usable object.
    $lang = Language::where('code', $locale)->first()
        ?? Language::where('code', 'en')->first()
        ?? Language::where('active', 1)->first()
        ?? Language::query()->first()
        ?? new Language(['code' => $locale ?: 'en', 'name' => 'English', 'direction' => 'ltr']);
    $memo[$locale] = $lang;
    app()->instance('memo.current_language', $memo);

    return $memo[$locale];
}

/**
 * I3: is a locale right-to-left? Single source of truth replacing ~10 divergent
 * hardcoded RTL lists across admin/reseller/driver/staff/order-hub/marketplace
 * that disagreed on which locales are RTL (some flipped fa/he LTR).
 *
 * Resolution: (1) the `languages` table `direction` for $code (or the current
 * locale, reusing current_language()'s memo); (2) a canonical RTL fallback set
 * when no Language row exists — so he/fa/ps/… stay RTL even where they are not
 * configured rows (a table-only helper would silently flip the staff app's
 * current 4-item list LTR). Memoized per code, request-scoped (Helper is hot).
 */
function is_rtl(?string $code = null): bool
{
    $code = strtolower((string) ($code ?: get_lang()));

    $memo = app()->bound('memo.is_rtl') ? app('memo.is_rtl') : [];
    if (array_key_exists($code, $memo)) {
        return $memo[$code];
    }

    // The old per-view lists were pure array checks (no DB). This helper reads
    // the languages table, so it must never 500 a page (or a test without that
    // table) over RTL detection — fall back to the canonical set on any error.
    // Read the row for THIS exact code — not current_language(), which now falls
    // back to a default row when the active locale has none, and would report the
    // fallback's direction (e.g. an unseeded `ar` → the en fallback → ltr) instead
    // of letting the canonical set below keep `ar` RTL.
    $row = null;
    try {
        $row = Language::where('code', $code)->first();
    } catch (Throwable $e) {
        $row = null;
    }

    if ($row && ! empty($row->direction)) {
        $rtl = strtolower((string) $row->direction) === 'rtl';
    } else {
        $rtl = in_array($code, ['ar', 'fa', 'he', 'ur', 'ps', 'sd', 'dv', 'yi', 'ckb', 'arc'], true);
    }

    $memo[$code] = $rtl;
    app()->instance('memo.is_rtl', $memo);

    return $rtl;
}

/**
 * Active languages keyed by code, in the shape mcamara/laravel-localization wants
 * for supportedLocales (name, script, native, regional), with per-locale overrides
 * from config('laravellocalization.localesMetadata'). Memoized per app instance and
 * flushed by any Language write (Language::booted()).
 *
 * @return array<string, array<string, string>>
 */
function get_active_languages(): array
{
    if (app()->bound('memo.active_languages')) {
        return app('memo.active_languages');
    }

    // rev/seo R2a: regional also feeds og:locale + hreflang.
    $overrides = config('laravellocalization.localesMetadata', []);
    $active = Language::query()->where('active', 1)->get()->mapWithKeys(fn (Language $row) => [
        $row->code => array_merge(
            ['name' => $row->name, 'script' => 'Latn', 'native' => $row->name, 'regional' => $row->code.'_'.strtoupper($row->code)],
            $overrides[$row->code] ?? [],
        ),
    ])->all();

    app()->instance('memo.active_languages', $active);

    return $active;
}

/**
 * Whether ___() / ___n() may write missing keys into lang/<locale>/lang.php.
 *
 * Only as an explicit local opt-in (config app.lang_auto_register, env
 * LANG_AUTO_REGISTER=true). Never in production (a live request must not rewrite
 * deployed files) and never under the test runner (parallel suites would race on
 * the same files and every run would dirty tracked lang files).
 */
function lang_auto_register_enabled(): bool
{
    return (bool) config('app.lang_auto_register', false)
        && ! app()->isProduction()
        && ! app()->runningUnitTests();
}

/**
 * Add `$slug => $value` to each locale's lang.php that lacks it. A file that does
 * not return an array is left untouched, so a bad read never overwrites a good file.
 */
function lang_register_missing_key(string $slug, string $value, ?string $langRoot = null): void
{
    foreach (File::directories($langRoot ?? base_path('lang')) as $localeDir) {
        $file = $localeDir.'/lang.php';
        $lines = File::exists($file) ? include $file : [];
        if (! is_array($lines) || array_key_exists($slug, $lines)) {
            continue;
        }
        $lines[$slug] = $value;
        File::put($file, "<?php\n\nreturn ".var_export($lines, true).";\n");
    }
}

/**
 * Translate a source string through lang/<locale>/lang.php, keyed by its slug
 * ("Add item" → lang.add_item). A missing key returns the source text with its
 * `:placeholders` filled (and, with the local opt-in, is added to the lang files).
 *
 * @param  array<string, mixed>  $replace
 * @return array|string|null
 */
function ___(mixed $text, array $replace = []): mixed
{
    $slug = Str::slug($text, '_');

    if (Lang::has("lang.{$slug}")) {
        return trans("lang.{$slug}", $replace, get_lang());
    }

    if (lang_auto_register_enabled()) {
        lang_register_missing_key($slug, $text);
    }

    if ($replace === []) {
        return $text;
    }

    // Placeholders are filled in array order.
    $tokens = array_map(fn ($placeholder) => ':'.$placeholder, array_keys($replace));

    return str_replace($tokens, array_values($replace), $text);
}

/**
 * Pluralized translation — the trans_choice sibling of ___().
 *
 * ___() has no plural path, so themes concatenate a count with a singular noun
 * ("0 item(s)") or pick between two hand-passed forms in JS, which is wrong for
 * Arabic (6 plural forms) and Russian (3). ___n() resolves the correct form for
 * $count under the current locale's CLDR plural rules.
 *
 * $key is the SINGULAR noun (e.g. 'item'). A missing key auto-registers a sane
 * English default `{1} :count item|[2,*] :count items` into every lang file —
 * exactly like ___(), dev-only, never rewriting on a production request — which
 * translators then replace with the locale's own plural branches.
 *
 * @param  string  $key  Singular English source noun
 * @param  int  $count  The count driving form selection
 * @param  array<string, mixed>  $replace  Extra placeholder replacements (`:count` is provided)
 */
function ___n(string $key, int $count, array $replace = []): string
{
    // Namespaced with an `n_` prefix so the pluralized entry never collides with
    // a flat ___('item') / ___('items') key already registered by a theme (those
    // are singular strings with no `|` branches, which would defeat trans_choice).
    $slug = 'n_'.Str::slug($key, '_');
    $langKey = 'lang.'.$slug;
    $replace = $replace + ['count' => $count];

    if (Lang::has($langKey)) {
        return trans_choice($langKey, $count, $replace, get_lang());
    }

    // English default: singular for exactly 1, plural otherwise.
    $default = '{1} :count '.$key.'|[2,*] :count '.Str::plural($key);

    if (lang_auto_register_enabled()) {
        lang_register_missing_key($slug, $default);
    }

    // Resolve the default line for this count/locale (also the production path
    // for a not-yet-committed key). getSelector()->choose applies CLDR rules.
    $line = app('translator')->getSelector()->choose($default, $count, get_lang());
    foreach ($replace as $ph => $val) {
        $line = str_replace(':'.$ph, (string) $val, $line);
    }

    return $line;
}

/**
 * Whether the demo restrictions apply to this request: DEMO_MODE is on (config,
 * config-cache safe) and the signed-in user is not account #1.
 */
function demo_mode(): bool
{
    if ((int) Auth::id() === 1) {
        return false;
    }

    return filter_var(config('app.demo_mode'), FILTER_VALIDATE_BOOLEAN);
}

/**
 * Visitor location record for `$ip` (default: the request IP).
 *
 * PRIVACY (AL-42 follow-up): the IP is never sent to a third party and never
 * cached. The country comes from visitor_country() (trusted-proxy CF header or a
 * LOCAL GeoLite2 database; null when geo is off) and its name from the countries
 * table. City, postal code, coordinates and timezone are not resolved and carry
 * the 'Other' / 'Unknown' sentinels callers already handle.
 *
 * @return array{ip: mixed, location: array<string, mixed>}
 */
function user_ip_lookup(mixed $ip = null): array
{
    $code = visitor_country();

    $name = null;
    if ($code) {
        try {
            $name = DB::table('countries')->where('code', $code)->value('name');
        } catch (Throwable $e) {
            // geo-absent — keep the 'Other' sentinel
        }
    }

    return [
        'ip' => $ip ?: request()->ip(),
        'location' => [
            'country' => $name ?: 'Other',
            'country_code' => $code ?: 'Other',
            'timezone' => 'Other',
            'city' => 'Other',
            'postal_code' => 'Unknown',
            'latitude' => 'Unknown',
            'longitude' => 'Unknown',
        ],
    ];
}

/** Visitor location plus `system.os` / `system.browser`, as a nested object. */
function user_ip_info(): mixed
{
    $visitor = user_ip_lookup();
    $visitor['system'] = ['os' => user_os_info(), 'browser' => user_browser_info()];

    return array_to_object($visitor);
}

/**
 * Operating system named by the request's User-Agent, or 'Other'. Labels are
 * checked in priority order (a Windows version beats "Linux", Android beats
 * Linux, Ubuntu beats Linux, iOS devices beat "Mac OS X"); the first label with a
 * marker in the agent wins.
 */
function user_os_info(): string
{
    $agent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    $labels = [
        'Windows XP' => ['windows xp', 'windows nt 5.1'],
        'Windows Server 2003/XP x64' => ['windows nt 5.2'],
        'Windows Vista' => ['windows nt 6.0'],
        'Windows 7' => ['windows nt 6.1'],
        'Windows 8' => ['windows nt 6.2'],
        'Windows 8.1' => ['windows nt 6.3'],
        'Windows 10' => ['windows nt 10'],
        'Mobile' => ['webos'],
        'BlackBerry' => ['blackberry'],
        'Android' => ['android'],
        'iPad' => ['ipad'],
        'iPod' => ['ipod'],
        'iPhone' => ['iphone'],
        'Ubuntu' => ['ubuntu'],
        'Linux' => ['linux'],
        'Mac OS 9' => ['mac_powerpc'],
        'Mac OS X' => ['macintosh', 'mac os x'],
    ];

    return user_agent_label($agent, $labels);
}

/**
 * Browser family named by the request's User-Agent, or 'Other'. Any mobile agent
 * reports 'Handheld Browser'; Opera, Edge and Chrome are checked before Safari
 * because their agents also contain "Safari".
 */
function user_browser_info(): string
{
    $agent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    $labels = [
        'Handheld Browser' => ['mobile'],
        'Opera' => ['opera'],
        'Edge' => ['edge'],
        'Chrome' => ['chrome'],
        'Safari' => ['safari'],
        'Firefox' => ['firefox'],
        'Internet Explorer' => ['msie'],
    ];

    return user_agent_label($agent, $labels);
}

/**
 * First label whose markers occur in the (lower-cased) agent string.
 *
 * @param  array<string, list<string>>  $labels
 */
function user_agent_label(string $agent, array $labels): string
{
    foreach ($labels as $label => $markers) {
        foreach ($markers as $marker) {
            if (str_contains($agent, $marker)) {
                return $label;
            }
        }
    }

    return 'Other';
}

/**
 * O5: whether the `schedule:run` cron is still firing. The scheduler stamps
 * `schedule_last_run` every minute (see Console\Kernel::schedule); a stale stamp
 * (>5 min) means the cron died — nothing else notices, since a dead cron also
 * can't run the in-app alert evaluators. /healthz is the authoritative detector.
 */
function scheduler_alive(): bool
{
    return (int) Cache::get('schedule_last_run', 0) > time() - 300;
}

/** O5: seconds since the scheduler last ran, or null when it never has. */
function scheduler_last_run_age(): ?int
{
    $last = (int) Cache::get('schedule_last_run', 0);

    return $last > 0 ? time() - $last : null;
}

/**
 * Dispatch a queued job so it actually runs even when no queue worker exists
 * (typical shared hosting / local dev): with a live worker it queues normally,
 * otherwise it runs after the HTTP response is sent (dispatchAfterResponse).
 */
function dispatch_job_reliably($job): void
{
    if (queue_worker_alive()) {
        dispatch($job);
    } else {
        dispatch($job)->afterResponse();
    }
}

/**
 * Canonical public menu URL with the scan-tracking qr-id (and optional table /
 * source label). Single source of truth for the wire format — the QR image
 * (server-side) and every "Copy link" button must emit the exact same URL.
 */
function store_public_qr_url($store, ?string $tableKey = null, ?string $src = null): string
{
    $slug = is_object($store) ? $store->slug : (string) $store;

    $url = reseller_store_url(route('publicView', $slug), $store).'?qr-id='.urlencode(app_xor_encrypt($slug, 'qr-scan')); // R4b: reseller host
    if ($src !== null && $src !== '') {
        $url .= '&src='.urlencode(mb_substr($src, 0, 64));
    }
    if ($tableKey !== null && $tableKey !== '') {
        $url .= '&table='.urlencode($tableKey);
        /* B3 (dark flag hotel_charge_room_token_required): bind a room's QR to the
           room with a signed token so a diner can only charge-to-room the room they
           physically scanned. Only for rooms, only when the store opted in — zero
           cost otherwise. */
        if (is_object($store) && app(RoomCharge::class)->tokenRequired((int) $store->id)) {
            $room = StoreTable::where('store_id', $store->id)
                ->where('area_type', 'room')->where('key', $tableKey)->first();
            if ($room) {
                $url .= '&rt='.urlencode(app(RoomCharge::class)->mintToken($room));
            }
        }
    }

    return $url;
}

/**
 * Store an uploaded image (or raw image bytes) and return the saved file name.
 *
 * Delegates to StorageService which transparently handles S3 or local-disk
 * storage based on the active BYOK credentials for the owning restaurant.
 *
 * @param  UploadedFile|string  $file  An uploaded file, or raw image bytes (AI image jobs) — StorageService re-encodes both
 * @param  string  $path  Relative public path e.g. "storage/store/cover/"
 * @param  string|null  $size  Resize spec e.g. "500x500", "300"
 * @param  string|null  $filename  Override basename (without extension)
 * @param  string|null  $oldfilename  Old filename to delete
 * @param  int|null  $storeId  Restaurant ID for BYOK vault lookup
 * @return string Saved filename
 */
function image_upload($file, $path, $size = null, $filename = null, $oldfilename = null, $storeId = null)
{
    return StorageService::upload($file, $path, $size, $filename, $oldfilename, $storeId);
}

/**
 * Remove file from local disk or S3.
 *
 * For backwards-compatible calls that pass a full local path (e.g. "storage/menu/file.jpg"),
 * we split the path into directory + filename and delegate to StorageService::delete().
 *
 * @param  string  $path  Full relative path including filename (legacy) OR just the path prefix
 * @param  string|null  $filename  If provided, $path is treated as the directory prefix only
 * @param  int|null  $storeId  Restaurant ID for BYOK vault lookup
 * @return bool
 */
function remove_file($path, $filename = null, $storeId = null)
{
    if ($filename !== null) {
        return StorageService::delete($path, $filename, $storeId);
    }

    // Legacy call: $path = full path including filename (e.g. "storage/menu/abc.jpg")
    $dir = dirname($path).'/';
    $file = basename($path);

    return StorageService::delete($dir, $file, $storeId);
}

/**
 * Resolve the public URL for a stored media file.
 *
 * Use this in Blade templates instead of asset('storage/...') so that
 * CDN / S3 URLs are returned when the restaurant has S3 credentials.
 *
 * @param  string  $path  Relative storage sub-path e.g. "storage/store/cover/"
 */
function media_url(string $path, string $filename, ?int $storeId = null): string
{
    return StorageService::url($path, $filename, $storeId);
}

/** Whether a stored media file exists (S3 storage is assumed present). Same semantics as StorageService::exists. */
function media_exists(string $path, string $filename, ?int $storeId = null): bool
{
    return StorageService::exists($path, $filename, $storeId);
}

/**
 * P6-308: ['src', 'srcset'] of width-capped WebP variants for a card / cart thumb, or null to keep the original.
 *
 * @return array{src: string, srcset: string}|null
 */
function media_card_variants(string $path, string $filename, ?int $storeId = null): ?array
{
    return StorageService::widthVariants($path, $filename, $storeId);
}

/**
 * Public URL for a small cached thumbnail of a stored image (see
 * StorageService::thumbUrl). Use for tiny display slots — e.g. the 44px
 * marquee logos — so a 150 KB upload isn't shipped to render at 44px.
 */
function media_thumb_url(string $path, string $filename, ?int $storeId = null, int $height = 88): string
{
    return StorageService::thumbUrl($path, $filename, $storeId, $height);
}

/**
 * Locale-aware storefront screenshot for a first-party theme slug, as shipped in
 * resources for the home theme gallery/marquee. Returns a public URL (with an
 * mtime cache-bust) or null when no capture exists — the Theme Store card then
 * falls back to its generated SVG hero. Arabic pages get the RTL capture and
 * fall back to English; assets serve from the repo root (docroot), so the path
 * is checked one level above base_path(). Mirrors themes.blade.php::$psThemeShot.
 */
function theme_showcase_shot(?string $slug): ?string
{
    $slug = (string) $slug;
    if ($slug === '' || ! preg_match('/^[a-z0-9-]+$/', $slug)) {
        return null;
    }

    $locale = get_lang() === 'ar' ? 'ar' : 'en';
    foreach (array_unique([$locale, 'en']) as $dir) {
        $rel = "/assets/site/themes/$dir/$slug.webp";
        $file = dirname(base_path()).$rel;
        if (is_file($file)) {
            return $rel.'?v='.filemtime($file);
        }
    }

    return null;
}

/**
 * Localized date text. `$value` is a Unix timestamp (all digits, 10+ long) or
 * anything Carbon can parse; unparseable input is returned unchanged.
 */
function date_formating(mixed $value, mixed $pattern = 'd, M Y'): mixed
{
    try {
        // P10-4: Carbon's own localization (translatedFormat) for month/day names.
        Carbon::setLocale(get_lang());

        // Cast before ctype_digit(): a Carbon/DateTime/null there is a PHP 8.2
        // deprecation; non-scalars go to Carbon::parse() instead.
        $digits = is_scalar($value) ? (string) $value : '';
        $moment = ($digits !== '' && ctype_digit($digits) && strlen($digits) >= 10)
            ? Carbon::createFromTimestamp($digits)
            : Carbon::parse($value);

        return $moment->translatedFormat($pattern);
    } catch (Exception $e) {
        return $value;
    }
}

/** Cut text to `$limit` characters with a trailing "...". */
function text_shorting(mixed $text, mixed $limit): string
{
    return Str::limit((string) $text, (int) $limit);
}

/** Compact count: 1500 → "1.5K", 2500000 → "2.5M" (up to "B"); larger numbers come back unchanged. */
function format_number_count(mixed $count): mixed
{
    foreach (['', 'K', 'M', 'B'] as $power => $unit) {
        $scaled = $count / 1000 ** $power;
        if ($scaled < 1000) {
            return round($scaled, 1).$unit;
        }
    }

    return $count;
}

/** Deep-convert an array/collection to stdClass objects (an empty one becomes `{}`). */
function array_to_object(mixed $value): mixed
{
    if (is_countable($value) && count($value) === 0) {
        return new stdClass;
    }

    return json_decode(json_encode($value));
}

/** The reCAPTCHA widget wrapped for a form, or null when reCAPTCHA is off. */
function display_captcha(): ?string
{
    return config('settings.recaptcha_mode')
        ? '<div class="margin-bottom-15">'.app('captcha')->display().'</div>'
        : null;
}

/**
 * Validation rules for the reCAPTCHA response (none when reCAPTCHA is off).
 *
 * @return array<string, string>
 */
function validate_recaptcha(): array
{
    return config('settings.recaptcha_mode') ? ['g-recaptcha-response' => 'required|captcha'] : [];
}

/**
 * The active ad in a slot, or null. Guests always see ads; a signed-in user only
 * when their plan includes the `advertisements` entitlement (P6-2).
 */
function ads(mixed $slot): ?Adsense
{
    $viewer = auth()->user();
    if ($viewer !== null && ! ($viewer instanceof User && Entitlements::has($viewer, 'advertisements'))) {
        return null;
    }

    return Adsense::query()->where('slug', $slot)->where('status', 1)->first();
}

/**
 * Symmetric XOR obfuscation used for short public tokens (QR ids, etc.). The key
 * stream is built from SHA-1 blocks of key + stream so far (2 bytes per block, 8
 * for inputs over 100 bytes). Applying it twice returns the input; existing
 * tokens depend on this exact stream, so it must not change.
 */
function app_xor(string $data, mixed $key = ''): string
{
    $length = strlen($data);
    $blockBytes = $length > 100 ? 8 : 2;

    $stream = '';
    while (strlen($stream) < $length) {
        $stream .= substr(sha1($key.$stream, true), 0, $blockBytes);
    }

    return $data ^ $stream;
}

/** app_xor() then base64: the public token form. */
function app_xor_encrypt(mixed $data, mixed $key = ''): string
{
    return base64_encode(app_xor((string) $data, $key));
}

/**
 * Reverse of app_xor_encrypt(). Callers pass raw request input (e.g. ?qr-id=…);
 * anything that is not a string (?qr-id[]=x) is never a valid token and yields ''.
 */
function app_xor_decrypt(mixed $token, mixed $key = ''): string
{
    return is_string($token) ? app_xor((string) base64_decode($token), $key) : '';
}

/**
 * Echo an admin on/off switch row. A hidden "0" input precedes the checkbox with
 * the same name so an unticked switch still posts a value. `$label` and `$hint`
 * are output as given (they may carry markup). Returns '' so it can sit inside
 * an echo tag.
 */
function app_switch(mixed $label, mixed $name, mixed $checked = false, mixed $hint = ''): string
{
    $rows = [
        '<div class="mb-3 nx-switch-row">',
        '<input type="hidden" name="'.$name.'" value="0">',
        '<div class="form-check form-switch form-switch-lg mb-0">',
        '<input class="form-check-input" type="checkbox" role="switch" name="'.$name.'" id="'.$name.'" value="1" '.($checked ? 'checked' : '').'>',
        '<label class="form-check-label fw-medium" for="'.$name.'">'.$label.'</label>',
        '</div>',
    ];
    if (! empty($hint)) {
        $rows[] = '<small class="text-body d-block mt-1">'.$hint.'</small>';
    }
    $rows[] = '</div>';

    echo implode("\n", $rows);

    return '';
}

/** Flash a toast for the next page: message plus type (success, error, info, warning). */
function app_alert(mixed $text, mixed $type = 'success'): void
{
    foreach (['app_alert_message' => $text, 'app_alert_type' => $type] as $flashKey => $flashValue) {
        session()->flash($flashKey, $flashValue);
    }
}

/** Flash a success toast. */
function app_alert_success(mixed $text): void
{
    app_alert($text, 'success');
}

/** Flash an error toast. */
function app_alert_error(mixed $text): void
{
    app_alert($text, 'error');
}

/** Flash an info toast. */
function app_alert_info(mixed $text): void
{
    app_alert($text, 'info');
}

/**
 * #79: Log an owner or system action to activity_logs.
 * Never log secrets or PII beyond what is passed in $meta.
 *
 * @param  string  $action  e.g. 'menu.create'
 * @param  object|null  $subject  Eloquent model (optional)
 * @param  array  $meta  Extra context (no secrets)
 * @param  int|null  $storeId  Explicit restaurant scope
 */
function log_activity(string $action, $subject = null, array $meta = [], ?int $storeId = null): void
{
    try {
        $user = auth()->user();
        // P6-229: during "log in as" the actor is the merchant, but the hands are the admin's: stamp both.
        $impersonatorId = request()->hasSession() ? (int) request()->session()->get('quick_admin_user_id') : 0;
        if ($impersonatorId > 0 && $impersonatorId !== (int) $user?->id) {
            $meta['impersonator_id'] = $impersonatorId;
            $meta['impersonated_user_id'] = $user?->id;
        }
        ActivityLog::create([
            'actor_user_id' => $user?->id,
            'store_id' => $storeId,
            'action' => $action,
            // P1-08: a model's morph name (its pre-move class name), so stored values stay stable
            'subject_type' => $subject instanceof Model ? $subject->getMorphClass() : ($subject ? get_class($subject) : null),
            'subject_id' => $subject?->id,
            'meta' => ! empty($meta) ? $meta : null,
            'ip' => request()->ip(),
        ]);
    } catch (Throwable $e) {
        // Never let audit logging break the main flow
    }
}

/**
 * P0-3: scrub a raw CSS blob before echoing it into a storefront <style>.
 * Strips tag breakouts, external @import, IE expression()/behavior, XBL
 * bindings, and dangerous url() schemes. Defense-in-depth alongside the
 * per-tenant custom_code_enabled gate and CSP.
 */
function sanitize_css(?string $css): string
{
    if ($css === null || trim($css) === '') {
        return '';
    }
    $css = preg_replace('/<\/?\s*(style|script)[^>]*>/i', '', $css);
    $css = preg_replace('/@import\b[^;]*;?/i', '', $css);
    $css = preg_replace('/expression\s*\(/i', '', $css);
    $css = preg_replace('/behavior\s*:/i', '', $css);
    $css = preg_replace('/-moz-binding\s*:/i', '', $css);
    $css = preg_replace('/(url\s*\(\s*[\'"]?\s*)(javascript|data|vbscript)\s*:/i', '$1', $css);

    return (string) $css;
}

/**
 * P0-6: true when APP_DEBUG is on in production — a secret/stack-trace leak
 * risk. Surfaced as a boot-time warning (AppServiceProvider).
 */
function app_debug_in_production(): bool
{
    return (bool) config('app.debug') && app()->environment('production');
}

/**
 * #84: Centralized feature gate.
 *
 * Checks two layers:
 *  1. Global kill-switch from config('settings.*') — admin can disable platform-wide.
 *  2. Per-plan flag from $user->plan()->settings->* — falls back to auth()->user() if omitted.
 *
 * Usage: plan_feature('allow_ordering')  /  plan_feature('table_qr', $user)
 *
 * @param  string  $key  Plan settings key (e.g. 'allow_ordering', 'table_qr')
 * @param  User|null  $user
 */
function plan_feature(string $key, $user = null): bool
{
    // 1. Global kill-switch — P1 (feature-platform): ONE veto implementation,
    //    shared with Entitlements::has (which re-checks it defensively).
    if (Entitlements::vetoed($key)) {
        return false;
    }

    // 2. Per-plan flag — P2 (plans rebuild): delegates to the Entitlements
    //    service (snapshot → rows → legacy blob → P1 overrides).
    try {
        $u = $user ?? auth()->user();
        if (! $u) {
            return false;
        }

        return Entitlements::has($u, $key);
    } catch (Throwable $e) {
        return false;
    }
}

if (! function_exists('qr_svg')) {
    /**
     * GAPS-RUN-2: inline SVG QR for a URL. Returns '' on failure.
     *
     * PARITY-RUN T10: this no longer talks to BaconQrCode's SVG back-end
     * directly — every QR the platform emits now goes through QrRenderService,
     * which owns styling, the logo overlay and the raster twin. The signature
     * gained an OPTIONAL error-correction level (H whenever a logo is composited
     * over the code); the three legacy callers pass nothing and keep the plain
     * black-on-white code they always had.
     *
     * @param  string|null  $ecLevel  L|M|Q|H — null keeps the historical default.
     */
    function qr_svg(string $url, int $size = 180, ?string $ecLevel = null): string
    {
        try {
            $settings = QrDesign::plain([
                'size' => $size,
                'ec' => $ecLevel ?: 'L',
            ]);

            return app(QrRenderService::class)->svg($url, $settings);
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (! function_exists('store_qr_settings')) {
    /**
     * PARITY-RUN T10: the QR design a store's codes should use.
     *
     * Falls back to the plain design when the store has never opened the QR
     * Designer, when the platform kill-switch is off, or when the owner's plan
     * does not include the suite. Subject is the STORE OWNER (FeatureAccess::
     * storeAllows), never the requester — a QR is rendered on owner screens,
     * print sheets and receipts alike, and an anonymous surface must degrade to
     * the plain design rather than refuse.
     */
    function store_qr_settings(?int $storeId, array $overrides = []): array
    {
        try {
            if (! $storeId || ! FeatureAccess::storeAllows($storeId, 'qr_designer')) {
                return QrDesign::plain($overrides);
            }

            $design = QrDesign::forStore($storeId);

            return $design
                ? QrDesign::normalize($overrides + $design->settings())
                : QrDesign::plain($overrides);
        } catch (Throwable $e) {
            return QrDesign::plain($overrides);
        }
    }
}

if (! function_exists('branded_qr_svg')) {
    /**
     * PARITY-RUN T10: inline SVG QR wearing the store's design.
     *
     * Use this on every owner/print surface. Never gate the CALL — the helper
     * degrades to the plain design on its own, so a lapsed plan loses the
     * branding, not the QR.
     */
    function branded_qr_svg(?int $storeId, string $url, int $size = 180, array $overrides = []): string
    {
        try {
            $settings = store_qr_settings($storeId, $overrides + ['size' => $size]);

            return app(QrRenderService::class)->svg($url, $settings);
        } catch (Throwable $e) {
            return qr_svg($url, $size);
        }
    }
}

if (! function_exists('order_source_label')) {
    /**
     * GAPS-RUN-2 B9: human label for an order's channel (orders.source).
     */
    function order_source_label(?string $source): string
    {
        return [
            'qr' => ___('QR'),
            'pos' => ___('POS'),
            'kiosk' => ___('Kiosk'),
            'customer_app' => ___('App'),
            'agent' => ___('Phone agent'),
            'api' => ___('API'), 'staff_pwa' => ___('staff app'),
            'kdb' => ___('Web'),
        ][$source] ?? ucfirst((string) ($source ?: ___('Web')));
    }
}

if (! function_exists('feature_enabled')) {
    /**
     * GAPS-RUN-2 B0: superadmin global kill-switch state for a feature key.
     * Reads the stored admin_allow_<key> Option, falling back to the
     * FeatureRegistry default. Plan-independent — this is the platform veto only.
     */
    function feature_enabled(string $key): bool
    {
        return FeatureRegistry::isEnabled($key);
    }
}

/**
 * Platform P3: read a PUBLISHED theme-customizer value for a restaurant's active
 * template. Keys are flat dot-strings from theme.json (e.g. 'colors.primary').
 * Returns $default when unset — themes that were never customized just get defaults.
 *
 * Usage in a theme: style="--brand: {{ theme_setting($store->id, 'colors.primary', '#10b981') }}"
 */
function theme_setting($storeId, string $key, $default = null)
{
    try {
        // Per-request memo: the helper is called many times per render, so the DB read
        // is cached for the life of the request.
        //
        // The memo lives ON the request object rather than in a static keyed by
        // spl_object_id(request()). Object ids are RECYCLED: once a Request is
        // garbage-collected the next allocation can be handed the same id, and a fresh
        // request would then read the previous one's cached values -- including a
        // cached miss. That produced a sporadic CI failure in ThemeJsonCustomizerTest
        // ("null is identical to 'Published Title'") that passed locally, because GC
        // timing differs per machine, and under a long-lived worker (Octane, a queue
        // worker rendering views) it could serve one restaurant's settings into another
        // request. Stored here the memo simply dies with the request it belongs to.
        $__request = request();
        $__cache = $__request->attributes->get('__theme_setting_memo', []);
        if (! array_key_exists($storeId, $__cache)) {
            $__cache[$storeId] = null;
            $template = store_options($storeId)->store_template ?? '';
            // P3-12: a store saved on an old theme slug that is aliased to a kit theme reads the kit theme's values
            // (the alias preset, then its own saved values on top). An admin's ?preview_template= shows the old theme as it was.
            $__aliased = $template !== '' && ! $__request->filled('preview_template')
                ? ThemeAliases::target($template) : null;
            if ($__aliased !== null) {
                $__aliasOwner = (int) (Store::where('id', $storeId)->value('user_id') ?? 0);
                $__aliasDraft = $__request->boolean('preview_theme') && auth()->check() && $__aliasOwner > 0 && (int) auth()->id() === $__aliasOwner;
                $__aliasValues = ThemeAliases::values((int) $storeId, $template, $__aliasDraft);
                $template = $__aliased['theme'];
            }
            // Customizer v2: remember the template alongside the values so a
            // legacy key can be resolved to its canonical id below.
            $__templates = $__request->attributes->get('__theme_setting_template', []);
            $__templates[$storeId] = $template;
            $__request->attributes->set('__theme_setting_template', $__templates);
            if ($__aliased !== null) {
                $__cache[$storeId] = $__aliasValues;
            } elseif ($template !== '') {
                $ts = ThemeSetting::where('store_id', $storeId)->where('template', $template)->first();
                if ($ts && is_array($ts->settings)) {
                    $values = $ts->settings;
                    // Owner draft preview: merge draft over published — EXACTLY the
                    // ownership + ?preview_theme=1 gate used in store_templates/layout.blade.php.
                    // A public visitor never satisfies this, so it always gets published values.
                    $ownerId = (int) (Store::where('id', $storeId)->value('user_id') ?? 0);
                    $isPreview = request()->boolean('preview_theme')
                        && auth()->check()
                        && $ownerId > 0
                        && (int) auth()->id() === $ownerId;
                    if ($isPreview && is_array($ts->draft)) {
                        $values = array_merge($values, $ts->draft);
                    }
                    $__cache[$storeId] = $values;
                }
            }
            $__request->attributes->set('__theme_setting_memo', $__cache);
        }
        $values = $__cache[$storeId];
        if (! is_array($values)) {
            return $default;
        }
        // Customizer v2: settings are stored under canonical ids only. A theme (or
        // an export, or an old integration) that still asks for a legacy id gets
        // the canonical value — the alias map is the compatibility net for one
        // release, see config/theme-aliases.php.
        if (! array_key_exists($key, $values)) {
            $__template = (string) (($__request->attributes->get('__theme_setting_template', [])[$storeId]) ?? '');
            if ($__template !== '') {
                $canonical = ThemeKeys::canonical($__template, $key);
                if ($canonical !== $key && array_key_exists($canonical, $values)) {
                    return $values[$canonical] ?? $default;
                }
            }
        }
        $resolved = $values[$key] ?? $default;

        // Customizer v2 · WI-L1 — a localized text override is stored as a
        // {locale: string} map. Resolve it to the active locale, falling back
        // exact → store default language → any value present → the theme default.
        // A legacy plain-string override is returned unchanged, so nothing that
        // predates per-locale texts breaks. Splash v2: the splash text fields
        // localize through the same map shape.
        if (is_array($resolved) && (str_starts_with($key, 'texts.')
            || in_array($key, ['splash.title', 'splash.subtitle', 'splash.cta_label'], true))) {
            $locale = get_lang();
            if (array_key_exists($locale, $resolved)) {
                return $resolved[$locale] !== '' && $resolved[$locale] !== null ? $resolved[$locale] : $default;
            }
            $storeDefault = (string) (store_options($storeId)->default_language ?? '');
            if ($storeDefault !== '' && ! empty($resolved[$storeDefault])) {
                return $resolved[$storeDefault];
            }
            foreach ($resolved as $v) {
                if ($v !== '' && $v !== null) {
                    return $v;
                }
            }

            return $default;
        }

        // ?? (not array_key_exists) so a null "clear override" draft tombstone
        // falls back to the theme default during owner preview.
        return $resolved;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Customizer v2 — a restaurant's visible theme sections, in order.
 *
 * Section visibility used to live in TWO places that never met: the customizer
 * wrote `sections.<id>` into theme_settings (which no first-party theme read),
 * while the themes read `store_options.menu_sections` (which the customizer never
 * wrote). This is the single read path: a customizer override wins, the legacy
 * menu_sections row is the fallback, and the theme's schema supplies the default.
 * `sections._order` (WI-L2) reorders the result; unlisted ids keep schema order.
 *
 * @return array<int,array{id:string,label:string,visible:bool}>
 */
function theme_sections($storeId, ?string $template = null): array
{
    try {
        $template = $template ?: (string) (store_options($storeId)->store_template ?? '');
        if ($template === '') {
            return [];
        }
        $schema = ThemeCustomizerService::schemaFor($template);
        $sections = $schema['sections'] ?? [];
        if ($sections === []) {
            return [];
        }

        // Legacy per-store visibility (the dashboard's Menu Sections page).
        $legacy = [];
        $saved = store_options($storeId)->menu_sections ?? null;
        $saved = is_string($saved) ? json_decode($saved, true) : json_decode(json_encode($saved), true);
        if (is_array($saved)) {
            foreach ($saved as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $legacy[(string) $row['id']] = isset($row['visible']) ? (bool) $row['visible'] : true;
                }
            }
        }

        $out = [];
        foreach ($sections as $section) {
            $id = (string) $section['id'];
            $override = theme_setting($storeId, 'sections.'.$id, null);
            $visible = $override !== null
                ? (bool) $override
                : ($legacy[$id] ?? (bool) ($section['default'] ?? true));
            $out[$id] = ['id' => $id, 'label' => (string) ($section['label'] ?? $id), 'visible' => $visible];
        }

        $order = theme_setting($storeId, 'sections._order', null);
        if (is_array($order)) {
            $ordered = [];
            foreach ($order as $id) {
                if (isset($out[(string) $id])) {
                    $ordered[(string) $id] = $out[(string) $id];
                    unset($out[(string) $id]);
                }
            }
            $out = $ordered + $out;
        }

        return array_values($out);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Convenience for theme blades: [sectionId => bool] visibility map.
 * Replaces the hand-rolled `$sectionVisible` block that each theme copied.
 */
function theme_section_visible($storeId, ?string $template = null): array
{
    $map = [];
    foreach (theme_sections($storeId, $template) as $section) {
        $map[$section['id']] = $section['visible'];
    }

    return $map;
}

/**
 * Resolve a restaurant's saved menu template to one that still exists on disk.
 * Deleted/missing templates fall back to the admin-configured fallback
 * (Settings → Menu Templates); disabled-but-present templates keep rendering.
 */
/**
 * P4-16: the legacy `TOTAL_MENUS` page global (store_templates/layout): nothing reads it, so it carries a slim map of the fields a
 * script could want instead of every raw menu row, and nothing on a very large catalogue (3.7 MB for 2,000 items).
 *
 * @param  iterable<int|string, Menu>  $menus
 */
function storefront_menu_stub(iterable $menus): object
{
    $out = [];
    if (count(is_countable($menus) ? $menus : iterator_to_array($menus)) > (int) config('perf.storefront_home_page_threshold', 200)) {
        return (object) $out;
    }
    foreach ($menus as $key => $m) {
        $out[$key] = ['id' => $m->id, 'category_id' => $m->category_id, 'name' => $m->name, 'price' => $m->price, 'image' => $m->image, 'is_sold_out' => (bool) $m->is_sold_out];
    }

    return (object) $out;
}

function resolve_store_theme(?string $requested, ?string $storeType = null): string
{
    try {
        return app(StorefrontThemeService::class)->resolve($requested, $storeType);
    } catch (Throwable $e) {
        return StorefrontThemeService::LAST_RESORT;
    }
}

/**
 * #64: A/B Testing — get (or assign) the current visitor's variant for an experiment.
 *
 * Assignment is deterministic per session. Logs an impression row on first call.
 * Returns the variant string (e.g. 'A' / 'B' / 'control' / 'variant1') or '' if experiment not found/inactive.
 *
 * Usage in Blade: @if(ab_variant('hero_cta') === 'B') ... @endif
 */
/**
 * #67: Effective plan limit = plan limit + sum of active purchased add-ons of that type.
 * 999 is the app-wide "unlimited" sentinel: a plan limit of 999 means unlimited and
 * short-circuits (add-ons are moot). For a bounded plan the add-on boost is added WITHOUT
 * clamping — a scan pack must be able to raise a 500 limit to 10,500, not silently cap at
 * 999 (which enforcement would then read as "unlimited"). The only guard is that a bounded
 * sum must never land exactly on the 999 sentinel and flip to unlimited.
 * Types: 'restaurant', 'scan', 'category', 'menu'. (whatsapp_messages has its own path in WhatsAppBilling.)
 */
/**
 * P6-2b (feature-platform): the legacy post_template gate — replaces the ~255
 * direct `@$plan->settings-><key>` blade reads across the 16 legacy templates.
 * Resolves through Entitlements (snapshot → rows → blob → overrides → add-ons),
 * so grandfathered subscribers finally see their SNAPSHOT tier (the blob reads
 * ignored it) and admin overrides/boolean add-ons reach the storefront.
 * Memoized per (owner, key) — a template render hits the same keys dozens of
 * times on the hot public path.
 */
function store_plan_allows(Store $store, string $key): bool
{
    // Memo lives in the container → scoped to one request (and one test),
    // never leaking across requests/tests like a PHP static would.
    if (! app()->bound('store_plan_allows.memo')) {
        app()->instance('store_plan_allows.memo', new ArrayObject);
    }
    $memo = app('store_plan_allows.memo');
    $mk = $store->user_id.':'.$key;
    if ($memo->offsetExists($mk)) {
        return $memo[$mk];
    }

    try {
        $owner = $store->user;

        return $memo[$mk] = $owner ? Entitlements::has($owner, $key) : false;
    } catch (Throwable $e) {
        return $memo[$mk] = false;
    }
}

function effective_limit(string $type, $user = null): int
{
    $user = $user ?? auth()->user();
    if (! $user) {
        return 0;
    }

    /* P2 (plans rebuild): delegate to the Entitlements service. Transition
       contract (kickoff rule #7): unlimited still RETURNS 999 so unaudited
       `< 999` comparisons keep working until the Retire phase; legacy raw
       values above 999 also stay "unlimited" exactly as before. */
    $raw = null;
    try {
        $raw = $user->plan()->settings->{$type.'_limit'} ?? null;
    } catch (Throwable $e) {
        // plan unreadable → fall through to the service (may still have a snapshot)
    }
    if ($raw !== null && (int) $raw > 999
        // R14a (G6): a reseller plan cap on this key (an edition lane) wins over the legacy >999 shortcut.
        && (Entitlements::planTrace($user, $type.'_limit')['lane'] ?? null) !== 'reseller_plan') {
        return 999; // legacy >999 collision semantics preserved during transition
    }

    $effective = Entitlements::limit($user, $type);
    if ($effective === null) {
        return 999; // unlimited, sentinel contract until Retire
    }

    /* P0-3 (feature-platform): a bounded total must never REACH OR PASS the 999
       sentinel. The old guard only handled === 999; add-on-boosted sums > 999
       (e.g. 500 + packs = 1100) flipped every `$limit < 999` call site
       (StoreController restaurant create/clone, ~40 template blades) to unlimited.
       'scan' is exempt from the hard clamp: scan packs legitimately exceed 999
       (+10,000 per pack) and every scan enforcement site compares `!= 999`. */
    if ($type === 'scan') {
        return $effective === 999 ? 998 : $effective;
    }

    /* WI-H16 (Variant A): the clamp below silently discards any bounded base +
       purchased add-on capacity above 998. Removing the clamp is NOT the fix —
       a bounded total that reaches 999 collides with the unlimited sentinel and
       flips every `< 999` call site to unlimited (see the comment above); that
       is Variant B (retire the sentinel), a separate run pack. Here we keep the
       clamp but STOP swallowing the shortfall: record it so support and the
       merchant-facing Add-ons page can surface the paid-but-ungranted capacity.
       Return value is unchanged. `scan` already returned above, so this only
       fires for the truly-truncated count keys, never for unlimited (999). */
    if ($effective > 998) {
        Log::warning('effective_limit clamped purchased add-on capacity', [
            'user_id' => $user->id,
            'type' => $type,
            'effective' => $effective, // pre-clamp: what the merchant paid for
            'granted' => 998,          // what enforcement actually allows
        ]);
    }

    return min($effective, 998);
}

function ab_variant(string $experimentName): string
{
    // Per unit of work: the variant is the visitor's (session-derived), so a process-wide memo would hand one visitor's
    // variant to the next request of a long-lived worker (Octane).
    static $cache = [];
    static $stamp = null;
    $now = RequestEpoch::id();
    if ($stamp !== $now) {
        $cache = [];
        $stamp = $now;
    }
    if (isset($cache[$experimentName])) {
        return $cache[$experimentName];
    }

    try {
        $exp = AbExperiment::where('name', $experimentName)
            ->where('status', 'active')
            ->first();

        if (! $exp || empty($exp->variants)) {
            return $cache[$experimentName] = '';
        }

        $sessionKey = hash('sha256', session()->getId());

        // Deterministic assignment: sha256 of (session+name) mod variant count
        $idx = crc32($sessionKey.$experimentName) % count($exp->variants);
        $variant = $exp->variants[abs($idx)];

        // Record impression (ignore duplicate key constraint)
        try {
            AbImpression::firstOrCreate(
                ['experiment_id' => $exp->id, 'session_id' => $sessionKey],
                ['variant' => $variant]
            );
        } catch (Throwable $e) {
            // Duplicate — already recorded
        }

        return $cache[$experimentName] = $variant;
    } catch (Throwable $e) {
        return $cache[$experimentName] = '';
    }
}

/**
 * #64: Mark the current visitor as converted for an experiment.
 * Call this on signup / purchase / other goal completion.
 */
function ab_convert(string $experimentName): void
{
    try {
        $exp = AbExperiment::where('name', $experimentName)->first();
        if (! $exp) {
            return;
        }
        $sessionKey = hash('sha256', session()->getId());
        AbImpression::where('experiment_id', $exp->id)
            ->where('session_id', $sessionKey)
            ->whereNull('converted_at')
            ->update(['converted_at' => now()]);
    } catch (Throwable $e) {
        // Never let analytics break the main flow
    }
}

/**
 * RP4 white-label brand helpers. On a reseller host (resolved by domain) these return
 * the reseller's brand; everywhere else they fall back to the platform settings. Use
 * these instead of hardcoded config('settings.site_title'|'site_logo') on any
 * CUSTOMER-FACING surface so a reseller's diners never see the platform brand.
 */
/** P4 (4.6): the memoized per-request brand context (host OR user.reseller_id). */
function brand_context(): BrandContext
{
    return BrandContext::current();
}

function active_brand_reseller(): ?Model
{
    return brand_context()->reseller;
}

function brand_title(): string
{
    return brand_context()->name();
}

function brand_logo(): ?string
{
    return brand_context()->logo();
}

/** P4 (4.7): brand-aware favicon (reseller logo, else platform favicon). */
function brand_favicon(): ?string
{
    return brand_context()->favicon();
}

/**
 * DEC-5: scrub platform identity out of the shared $settings object on a reseller
 * (white-label) host, so no page can leak the platform's socials or contact
 * details in a footer / JSON-LD sameAs. A reseller has no social fields of its
 * own (only a support email), so the platform socials + contact are blanked and
 * the contact email maps to the reseller's. No-op on a platform host.
 *
 * Called from BOTH the frontend view composer (live) and LandingChrome (the test
 * runner path, where the composer is skipped) so the two never diverge.
 */
function brand_scrub_settings(object $settings): object
{
    $reseller = active_brand_reseller();
    if (! $reseller) {
        return $settings;
    }

    $settings->invoice_admin_email = $reseller->getAttribute('support_email') ?: null;
    foreach (['facebook_link', 'twitter_link', 'instagram_link', 'linkedin_link', 'youtube_link', 'invoice_admin_phone', 'invoice_admin_address'] as $key) {
        $settings->{$key} = null;
    }

    return $settings;
}

/**
 * D2-4 (docs-platform): brand-aware MCP serverInfo name. Platform hosts keep
 * the historical literal names (pinned by the Mcp*Test suites); an active
 * reseller host advertises the reseller's own brand instead of the platform's,
 * so MCP clients connecting via a white-label domain never see the platform name.
 */
function mcp_server_name(string $suffix): string
{
    $brand = brand_context();

    return $brand->isReseller()
        ? Str::slug($brand->shortName()).'-'.$suffix
        : 'juwri-'.$suffix;
}

/**
 * Built-in gradient artworks available for the animated logo tile
 * (the same set the landing-page pricing cards use).
 *
 * @return array<string,string> preset key => public URL
 */
function brand_tile_presets(): array
{
    $presets = [];
    foreach (['1', '2', '3'] as $n) {
        $presets[$n] = '/assets/brand/tiles/'.$n.'.webp';
    }

    return $presets;
}

/**
 * Resolve the animated logo-tile background chosen in
 * Settings → Logo & Favicon → Logo background effect.
 *
 * Returns null when the effect is switched off, so callers can skip the tile
 * entirely instead of rendering an empty frame around the logo.
 */
function brand_tile_url(): ?string
{
    $style = (string) (config('settings.brand_tile_style') ?? '1');

    if ($style === 'off') {
        return null;
    }

    if ($style === 'custom') {
        $file = (string) (config('settings.brand_tile_image') ?? '');

        return $file !== '' ? media_url('storage/logo/', $file) : null;
    }

    $presets = brand_tile_presets();

    return $presets[$style] ?? $presets['1'];
}

/**
 * Speed presets for the animated logo tile, as one full gradient pan.
 * A longer duration = slower drift; "0" freezes the gradient.
 *
 * @return array<string,string> seconds => label key
 */
function brand_tile_speeds(): array
{
    return [
        '0' => 'Static',
        '40' => 'Slow',
        '20' => 'Normal',
        '10' => 'Fast',
        '4' => 'Very fast',
    ];
}

/** Chosen tile animation duration in seconds (0 = static). */
function brand_tile_speed(): int
{
    $speed = config('settings.brand_tile_speed');

    return array_key_exists((string) $speed, brand_tile_speeds()) ? (int) $speed : 20;
}

/** P4 (4.7): whether to render the platform "Powered by" line (Agency removes it). */
function brand_show_powered_by(): bool
{
    return brand_context()->showPoweredBy();
}

/**
 * P2-6: peer-benchmark cohort key for a store, bucketed by its recent order
 * volume (privacy: cohort is coarse, never identifying). Shared by the nightly
 * aggregate job and the dashboard "vs similar stores" lookup.
 */
function peer_benchmark_cohort(int $orders30): string
{
    if ($orders30 >= 500) {
        return 'size:large';
    }
    if ($orders30 >= 100) {
        return 'size:medium';
    }

    return 'size:small';
}

/** P2-6: whether an owner opted into anonymized peer benchmarks. */
function peer_benchmark_opted_in(?int $ownerId): bool
{
    if (! $ownerId) {
        return false;
    }

    return (string) UserOption::getUserOption($ownerId, 'peer_benchmark_opt_in') === '1';
}

/**
 * Notification-sound library: every playable file under assets/global/audio.
 * File-system driven — drop an mp3/ogg in the folder and it appears in the
 * picker (id = filename, label = humanised). Cached for an hour.
 */
/**
 * Playable sound library. The global CC0 pack (ids = bare filename, played via
 * the engine base dir) plus, when a store is given, that store's custom uploads
 * (ids = `/storage/...` path so the engine plays them directly). `custom` flags
 * an owner-uploaded, deletable entry.
 */
function notification_sound_library(?int $storeId = null): array
{
    $global = Cache::remember('notif-sound-lib', 3600, function () {
        $dir = public_path('assets/global/audio');
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (! preg_match('/\.(mp3|ogg|wav)$/i', $f)) {
                continue;
            }
            $label = preg_replace('/\.(mp3|ogg|wav)$/i', '', $f);
            $label = preg_replace('/^(universfield|notification_message|pixabay)[-_]?/i', '', $label);
            $label = preg_replace('/[-_]?\d{5,}$/', '', $label);          // trailing asset id
            $label = ucwords(trim(str_replace(['-', '_'], ' ', $label)));
            $out[] = ['id' => $f, 'label' => $label !== '' ? $label : $f, 'url' => asset('assets/global/audio/'.$f), 'custom' => false];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $out;
    });

    if (! $storeId) {
        return $global;
    }

    // Per-store custom uploads on the public disk under notif-sounds/<store>/.
    $custom = [];
    $rel = 'notif-sounds/'.$storeId;
    $disk = Storage::disk('public');
    if ($disk->exists($rel)) {
        foreach ($disk->files($rel) as $path) {
            if (! preg_match('/\.(mp3|ogg|wav)$/i', $path)) {
                continue;
            }
            $file = basename($path);
            $label = ucwords(trim(str_replace(['-', '_'], ' ', preg_replace('/\.(mp3|ogg|wav)$/i', '', $file))));
            $custom[] = ['id' => '/storage/'.$path, 'label' => $label !== '' ? $label : $file, 'url' => $disk->url($path), 'custom' => true];
        }
    }

    return array_merge($custom, $global);
}

/**
 * Resolved notification-sound config for a store (store_options `notification_sounds`
 * merged over defaults). Shape: volume 0–100 + per-event {sound, repeat, interval}.
 * `sound` is a library file id ('' = muted event).
 */
/** Baseline notification-sound config (before any store/branch overrides). */
function notification_sounds_defaults(): array
{
    return [
        'volume' => 70,
        // Quiet hours: mute all sounds between from→to (local HH:MM, wraps past midnight).
        'quiet' => ['enabled' => false, 'from' => '23:00', 'to' => '07:00'],
        'events' => [
            'order_new' => ['sound' => 'notification_message-notification-alert-7-331719.mp3', 'repeat' => true,  'interval' => 20],
            'order_ready' => ['sound' => 'universfield-new-notification-061-494263.mp3',          'repeat' => false, 'interval' => 0],
            'review' => ['sound' => 'universfield-new-notification-036-485897.mp3',          'repeat' => false, 'interval' => 0],
            'dispute' => ['sound' => 'universfield-new-notification-061-494263.mp3',          'repeat' => false, 'interval' => 0],
            'waitlist' => ['sound' => 'universfield-new-notification-066-494545.mp3',          'repeat' => false, 'interval' => 0],
            'webhooks' => ['sound' => '',                                                       'repeat' => false, 'interval' => 0],
        ],
    ];
}

/** Merge one saved layer (from JSON) over a resolved config in place. */
function notification_sounds_merge(array $cfg, ?array $saved): array
{
    if (! $saved) {
        return $cfg;
    }
    if (isset($saved['volume'])) {
        $cfg['volume'] = max(0, min(100, (int) $saved['volume']));
    }
    if (isset($saved['quiet']) && is_array($saved['quiet'])) {
        $q = $saved['quiet'];
        $hhmm = fn ($v, $d) => (is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) ? $v : $d;
        $cfg['quiet'] = [
            'enabled' => ! empty($q['enabled']),
            'from' => $hhmm($q['from'] ?? null, $cfg['quiet']['from']),
            'to' => $hhmm($q['to'] ?? null, $cfg['quiet']['to']),
        ];
    }
    foreach ($cfg['events'] as $k => $d) {
        if (isset($saved['events'][$k]) && is_array($saved['events'][$k])) {
            $s = $saved['events'][$k];
            $cfg['events'][$k] = [
                'sound' => isset($s['sound']) ? (string) $s['sound'] : $d['sound'],
                'repeat' => ! empty($s['repeat']),
                'interval' => isset($s['interval']) ? max(5, min(120, (int) $s['interval'])) : $d['interval'],
            ];
        }
    }

    return $cfg;
}

/**
 * Resolved config for a store, optionally for a specific branch. Resolution is
 * branch → store → defaults: the store layer is merged over the defaults, then a
 * branch override (stored under `branches.<id>` in the same JSON) merges on top.
 */
function notification_sounds_config(?int $storeId, ?int $branchId = null): array
{
    $cfg = notification_sounds_defaults();
    if (! $storeId) {
        return $cfg;
    }

    $raw = StoreSettings::legacy((int) $storeId, 'notification_sounds');
    $saved = is_string($raw) ? (array) json_decode($raw, true) : [];

    $cfg = notification_sounds_merge($cfg, $saved);                         // store layer
    if ($branchId && ! empty($saved['branches'][$branchId]) && is_array($saved['branches'][$branchId])) {
        $cfg = notification_sounds_merge($cfg, $saved['branches'][$branchId]); // branch override
    }

    return $cfg;
}

/**
 * MoR-RUN F1: is this payment gateway folder a merchant-of-record provider?
 * MoR gateways own tax (skip local Tax records) and are platform-billing only
 * (never restaurant order checkout / BYOK). See config/mor-gateways.php.
 */
function is_mor_gateway(?string $folder): bool
{
    return $folder !== null
        && in_array($folder, (array) config('mor-gateways.folders', []), true);
}

/**
 * fusion-reseller FR-13/FR-31/FR-33: the current marketplace TENANT (reseller),
 * resolved from the request host by ResolveMarketplaceContext into app('mk.tenant').
 * Returns null on platform Fusion. Bound as a real instance only, so read through
 * the container guard.
 */
function mk_tenant(): ?Model
{
    $tenant = app()->bound('mk.tenant') ? app('mk.tenant') : null;

    return $tenant instanceof Model ? $tenant : null;
}

/** fusion-reseller: current marketplace tenant id — 0 = platform (the 0-sentinel). */
function mk_tenant_id(): int
{
    $tenant = mk_tenant();

    return $tenant ? (int) $tenant->getKey() : 0;
}

/**
 * BRAND-ROW B1: minimum follower count to SHOW a number. Below this the brand row
 * renders the value-copy CTA instead ("Follow for offers & new dishes"). Single
 * source — the partial and any caller read this, never a bare literal 10.
 */
function follower_count_min(): int
{
    return 10;
}

/**
 * BRAND-ROW B1: compact follower count for display. <1000 raw; <100k one-decimal
 * "X.Yk" (trailing .0 trimmed → "1k"); else whole "Xk". Latin digits, matching
 * prices (no localized-numeral helper exists in this app).
 */
function format_follower_count(int $count): string
{
    $count = max(0, $count);
    if ($count < 1000) {
        return (string) $count;
    }
    if ($count < 100000) {
        $k = rtrim(rtrim(number_format($count / 1000, 1, '.', ''), '0'), '.');

        return $k.'k';
    }

    return round($count / 1000).'k';
}
