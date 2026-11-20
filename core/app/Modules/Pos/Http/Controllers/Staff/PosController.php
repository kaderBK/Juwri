<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Controllers\Staff;

use App\Models\Banner;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\StaffMember;
use App\Models\StoreTable;
use App\Services\CouponService;
use App\Services\CustomerWalletService;
use App\Services\Entitlements;
use App\Services\OrderApiService;
use App\Services\PosService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Http\Controllers\Concerns\ScopesToBranch;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Middleware\AddDashboardCspHeaders;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Core\Support\Sql;
use Juwri\Modules\Menu\Contracts\PromotionRules;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Menu\Services\Combos\ComboService;
use Juwri\Modules\Ordering\Contracts\OrderCancellations;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Models\OrderRefundItem;
use Juwri\Modules\Ordering\Services\Orders\OrderPricing;
use Juwri\Modules\Payments\Contracts\TerminalPayments;
use Juwri\Modules\Payments\Contracts\TerminalRejected;
use Juwri\Modules\Pos\Models\PosCardReversalException;
use Juwri\Modules\Pos\Models\PosOfflineCardPayment;
use Juwri\Modules\Pos\Models\PosOfflineDevice;
use Juwri\Modules\Pos\Models\PosOfflineRecord;
use Juwri\Modules\Pos\Models\PosOfflineZatcaInvoice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaUnit;
use Juwri\Modules\Pos\Models\PosTerminal;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Pos\Models\PrintJob;
use Juwri\Modules\Pos\Services\Offline\DeviceRevoked;
use Juwri\Modules\Pos\Services\Offline\OfflineCardSettlement;
use Juwri\Modules\Pos\Services\Offline\OfflineDevices;
use Juwri\Modules\Pos\Services\Offline\OfflineExceptionKinds;
use Juwri\Modules\Pos\Services\Offline\OfflineReplay;
use Juwri\Modules\Pos\Services\Offline\OfflineSnapshot;
use Juwri\Modules\Pos\Services\Offline\OfflineZatca;
use Juwri\Modules\Pos\Services\Printing\PrintDispatcher;
use Juwri\Modules\Pos\Services\Printing\PrintSettings;
use Juwri\Modules\Tables\Models\CheckPayment;
use Juwri\Modules\Tables\Models\TableCheck;
use Juwri\Modules\Tables\Models\TableMergeGroup;
use Juwri\Modules\Tables\Services\SplitBillService;
use Juwri\Modules\Tables\Services\TableMergeService;
use Juwri\Modules\Tables\Services\TableSessionService;
use Symfony\Component\HttpFoundation\Response;

/**
 * POS P1: staff register. Authenticated by the `staff` guard; every action is
 * gated by the `pos` permission and the owner's `pos_enabled` plan flag, and is
 * scoped to the staff member's own restaurant (ids never come from the client).
 */
class PosController extends Controller
{
    use ScopesToBranch;

    /**
     * Resolve + authorize the actor, returning [staff|null, restaurant]. Works for
     * a STAFF member (own restaurant, needs the `pos` permission) OR the restaurant
     * OWNER on the web guard (can run the register for any restaurant they own).
     */
    private function context(): array
    {
        // The owner (web guard) is the primary actor and reaches POS from their own
        // dashboard, so a web session wins over any staff session in the same browser —
        // EXCEPT a staff-marked session: StaffLoginController logs staff into BOTH guards
        // (web = their owner, for the dashboard), stamping `staff_session_id`. Without
        // this check a cashier would run the register AS the owner — no `pos` permission
        // gate, no per-staff sales attribution, full manager powers.
        $owner = auth('web')->user(); // explicit guard: `auth:staff,web` may default to staff
        if ($owner && ! is_staff_dashboard()) {
            $rid = (int) (request('store')
                ?: dashboard_current_store()?->id
                ?: $owner->stores()->value('id'));
            $store = Store::where('id', $rid)->where('user_id', $owner->id)->firstOrFail();
            abort_unless(plan_feature('pos_enabled', $owner), 403, 'POS is not enabled on this plan.');

            // An explicit register pick re-scopes the dashboard switcher too.
            if (request()->isMethod('GET') && request('store') && $rid !== (int) session('dashboard_store_id')) {
                session(['dashboard_store_id' => $rid]);
                session()->forget('dashboard_branch_id');
            }

            return [null, $store];
        }

        $staff = auth('staff')->user();
        abort_unless($staff && $staff->active && $staff->hasPermission('pos'), 403, 'No POS access.');
        $owner = User::find($staff->owner_id);
        $store = Store::findOrFail($staff->store_id);
        abort_unless($owner && plan_feature('pos_enabled', $owner), 403, 'POS is not enabled on this plan.');

        return [$staff, $store];
    }

    /** Permission check — the owner (no staff row) is treated as fully permitted. */
    private function can($staff, string $perm): bool
    {
        return $staff ? $staff->hasPermission($perm) : true;
    }

    /** Scope an order to the acting staff's restaurant, or 404. */
    private function ownOrder(int $orderId, Store $store): Order
    {
        return Order::where('id', $orderId)->where('store_id', $store->id)->firstOrFail();
    }

    /** The register screen. */
    public function index()
    {
        [$staff, $store] = $this->context();
        $isOwner = $staff === null;

        // Owner may run the register for any restaurant they own; staff are pinned to theirs.
        $stores = $isOwner
            ? auth('web')->user()->stores()->orderBy('id')->get(['id', 'title'])
            : Store::where('id', $store->id)->get(['id', 'title']);

        // Branches (tinyint `active`, so the int form is correct here) for the active restaurant.
        $branches = Branch::where('store_id', $store->id)
            ->where('active', 1)->orderBy('position')->get(['id', 'name']);
        $activeBranchId = $this->activeBranchId($store, $staff);

        // P3/P1: idle auto-lock minutes from the resolved POS settings (default 10 — decision A).
        $autolockMinutes = max(0, (int) $this->posSettings($store, $activeBranchId)['autolock_minutes']);

        // Menu languages this restaurant publishes (owner-configured), for the in-register
        // language switcher. Same source the customer storefront uses; the chosen code is
        // persisted in the `Quick_user_lang_code` cookie which the Menu accessors read.
        [$menuLanguages, $currentLang] = $this->menuLanguages($store);

        // T1-4: tax/delivery config for the client-side totals preview. Mirrors
        // Order::computeTax + the delivery charge; the placed order stays server-
        // authoritative — this only powers the cart's live "Payable" estimate.
        $opts = store_options($store->id);
        $posTax = [
            'charge' => (float) (@$opts->restaurant_taxx_charge ?: 0),
            'type' => (string) (@$opts->restaurant_tax_type ?: 'percentage'),
            'delivery' => Branch::effectiveDeliveryFee((int) $store->id, Branch::ofStore((int) $store->id, $activeBranchId), $opts), // P5-31
        ];

        // T2-4: active loyalty program (read-only) for the selected-customer card's
        // points balance + "earns ~N" preview. Mirrors the earn seam in
        // StoreController: stamps → +1/order, points → floor(total * earn_rate / 100).
        $program = LoyaltyProgram::where('store_id', $store->id)->where('active', true)->first();
        $posLoyalty = $program ? [
            'active' => true,
            'type' => (string) $program->type,       // 'stamps' | 'points'
            'earn_rate' => (int) $program->earn_rate,
            'threshold' => (int) $program->reward_threshold,
        ] : ['active' => false];

        // "Back to dashboard" rail link: staff log into the web guard as their owner
        // at sign-in (StaffLoginController), and the owner is always a web user, so a
        // live web session is the reliable test for dashboard access from the register.
        $canDashboard = auth('web')->check();

        // STF-013: whether the void/refund controls render. Server-side the
        // endpoints re-check `pos_refund`, so this only hides dead buttons.
        $canRefund = $isOwner || $this->can($staff, 'pos_refund');

        // D2: drawer-handoff controls + the receiving-cashier picker. The endpoint
        // re-checks pos_drawer_open, so this only hides dead UI; the current staff is
        // excluded (you hand OVER, not to yourself).
        $canDrawer = $isOwner || $this->can($staff, 'pos_drawer_open');
        $posStaff = StaffMember::where('store_id', $store->id)
            ->where('active', 1)
            ->when($staff, fn ($q) => $q->where('id', '!=', $staff->id))
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => (string) $s->name])->values();

        // P6-122: the register runs under the enforced CSP (csp.app). The print bridge is reached by fetch() from the browser,
        // so connect-src must name the loopback default and every bridge address this store configured.
        $bridges = PrinterProfile::where('store_id', $store->id)->where('transport', 'bridge')->get(['bridge_url'])->toBase()
            ->map(fn (PrinterProfile $p) => $p->bridgeBase())->push(PrinterProfile::DEFAULT_BRIDGE_URL);
        request()->attributes->set(AddDashboardCspHeaders::EXTRA_CONNECT, $bridges->unique()->values()->all());

        // The register page is rendered for ONE restaurant: the service worker caches it per restaurant (this header) so
        // an offline navigation never serves another store's register (orders would queue under the wrong store).
        return response()->view('staff.pos.register', compact(
            'staff', 'store', 'stores', 'branches', 'activeBranchId', 'isOwner',
            'autolockMinutes', 'menuLanguages', 'currentLang', 'posTax', 'posLoyalty', 'canDashboard',
            'canRefund', 'canDrawer', 'posStaff'
        ))->header('X-Pos-Restaurant', (string) $store->id);
    }

    /**
     * The restaurant's published menu languages + the currently active code.
     * Returns [Collection<Language>, string]. Empty collection when the owner
     * hasn't configured multi-language, in which case the switcher is hidden.
     */
    private function menuLanguages(Store $store): array
    {
        $opts = store_options($store->id);
        $codes = (array) (@$opts->menu_languages ?: []);
        if (count($codes) < 2) {
            return [collect(), app()->getLocale()];
        }

        $languages = Language::whereIn('code', $codes)->get(['code', 'name', 'direction']);

        // Drive the register UI (___() chrome + <html lang/dir>) in a menu language
        // ONLY when the operator has EXPLICITLY chosen one via the in-register
        // switcher — switchLang() sets the Quick_user_lang_code cookie and reloads.
        // Without an explicit choice we keep the operator's own dashboard language,
        // so opening a store whose menu default_language differs no longer silently
        // flips the whole register UI (the reported "language changes on POS click").
        // Item names localise from the SAME cookie via the Menu accessors,
        // independent of the app locale, so which language products render in is
        // unchanged — only the chrome stops following the store default.
        $choice = ! empty($_COOKIE['Quick_user_lang_code']) && $languages->firstWhere('code', $_COOKIE['Quick_user_lang_code'])
            ? (string) $_COOKIE['Quick_user_lang_code']
            : null;
        if ($choice !== null) {
            app()->setLocale($choice);
        }

        return [$languages, $choice ?? app()->getLocale()];
    }

    /**
     * P1 (IP12): resolve POS settings for the active branch, falling back to a
     * restaurant-level `pos_settings` post_option, then to defaults. Fields:
     * auto_print (off|receipt|kitchen|both), copies, station_name, autolock_minutes.
     */
    private function posSettings(Store $store, ?int $branchId): array
    {
        $defaults = ['auto_print' => 'off', 'copies' => 1, 'station_name' => '', 'autolock_minutes' => 10];

        if ($branchId) {
            $b = Branch::where('store_id', $store->id)->where('id', $branchId)->first();
            if ($b && is_array($b->pos_settings) && $b->pos_settings) {
                return array_merge($defaults, $b->pos_settings);
            }
        }

        $raw = store_options($store->id, 'pos_settings');
        if ($raw) {
            $arr = is_string($raw) ? json_decode($raw, true) : json_decode(json_encode($raw), true);
            if (is_array($arr)) {
                return array_merge($defaults, $arr);
            }
        }

        return $defaults;
    }

    /** Print instructions for the settle response, derived from posSettings(). */
    private function printInstructions(Store $store, Order $order): array
    {
        $s = $this->posSettings($store, $order->branch_id);
        $auto = in_array($s['auto_print'], ['off', 'receipt', 'kitchen', 'both'], true) ? $s['auto_print'] : 'off';
        $out = ['auto' => $auto, 'copies' => max(1, (int) $s['copies']), 'station' => (string) $s['station_name']];

        if ($auto !== 'off') {
            // Carry the register's current language onto the print URL so the auto-
            // printed copy matches the language on screen (not the site default).
            $base = url('pos/orders/'.$order->id.'/receipt').'?store='.$store->id
                .'&print=1&lang='.app()->getLocale();
            $out['receipt_url'] = in_array($auto, ['receipt', 'both'], true) ? $base : null;
            $out['kitchen_url'] = in_array($auto, ['kitchen', 'both'], true) ? $base.'&format=kitchen' : null;
        }

        return $out;
    }

    /** Resolve the active branch: ?branch= (validated to this restaurant) → sidebar/session
     *  selection (owner) or pin/saved default (staff) → none. */
    private function activeBranchId(Store $store, $staff): ?int
    {
        // P6-180: staff PINNED to a branch stay in it — a crafted ?branch= must not
        // move them into another branch's tables, merges or orders.
        if ($staff && $staff->branch_id) {
            return (int) $staff->branch_id;
        }

        $req = (int) request('branch');
        if ($req && Branch::where('store_id', $store->id)->where('id', $req)->exists()) {
            return $req;
        }

        if (! $staff) {
            $session = dashboard_current_branch();

            return ($session && (int) $session->store_id === (int) $store->id) ? $session->id : null;
        }

        $default = (int) $staff->default_branch_id;
        if ($default && Branch::where('store_id', $store->id)->where('id', $default)->exists()) {
            return $default;
        }

        return null;
    }

    /**
     * Menu JSON for the register. POS-specific (not the public payload) so it can:
     *  - return names/descriptions in the active menu language (Menu accessors read
     *    the `Quick_user_lang_code` cookie the switcher sets),
     *  - price each item for the active branch (override-aware), and
     *  - hide items not available at that branch.
     * Only the priced `variants` (a single-select MenuVariant → `variation`) and
     * `extras` are surfaced, because those are the only modifiers the order path
     * prices and accepts. Free choice-groups are intentionally omitted.
     */
    public function menu()
    {
        [$staff, $store] = $this->context();
        $branchId = $this->activeBranchId($store, $staff);
        $rid = $store->id;

        $categories = MenuCategory::where('store_id', $rid)
            ->where('active', '1')->orderBy('position')->get();
        $items = Menu::where('store_id', $rid)->where('active', '1')
            // N+1: eager-load what resolved_extras / resolved_variant_options read, so
            // those accessors resolve from memory instead of 4 queries per item.
            ->with(['variants', 'branchSettings', 'attachedExtras', 'extras', 'attachedVariantOptions', 'variantOptions'])->get();

        /* PARITY-RUN T5: resolve scheduled availability + happy-hour pricing on
           the STORE's clock. The register keeps selling everything — a cashier
           overriding the window is legitimate and OrderApiService deliberately
           does not enforce it for `source=pos` — but it must SEE the truth:
           `available_now` drives the "outside hours" badge and `schedule_label`
           the happy-hour chip, so the till quotes the same price the storefront
           is advertising instead of the list price.

           The branch price is folded in FIRST so a percentage comes off what
           this branch actually charges, matching the storefront ordering. */
        foreach ($items as $m) {
            $m->price = $m->priceForBranch($branchId);
        }
        MenuScheduleService::decorate($items, $rid);

        $byCategory = $items->groupBy('category_id');
        $promotionRules = app(PromotionRules::class);

        return response()->json([
            'restaurant' => ['id' => $store->id, 'title' => $store->title],
            'categories' => $categories->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'items' => ($byCategory[$c->id] ?? collect())
                    ->where('is_sold_out', '!=', 1)
                    ->filter(fn ($m) => $m->availableAtBranch($branchId))
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'name' => $m->name,
                        'description' => $m->description,
                        'plu' => $m->plu, // T1-6: barcode / PLU for the scanner wedge
                        // T5: branch price with any live schedule already applied.
                        'price' => (float) $m->price,
                        'original_price' => $m->original_price !== null ? (float) $m->original_price : null,
                        'schedule_label' => $m->schedule_label,
                        'available_now' => $m->available_now !== false,
                        'available_from' => $m->available_from_label,
                        // P1-23: no promotion touches this item (a medicine where the market blocks them) — the
                        // register leaves it out of the loyalty earn preview.
                        'no_promotions' => $promotionRules->blocksPromotions($m),
                        'image' => $m->image ? media_url('storage/menu/', $m->image, $rid) : null,
                        // Priced variant combos — `id` is the value the order path expects as `variation`.
                        'variants' => $m->variants->where('active', true)->map(fn ($v) => [
                            'id' => $v->id,
                            'price' => (float) $v->price,
                            'options' => $v->options,
                        ])->values(),
                        // Extras (price-override applied); `id` is the value the order path expects in `extras[]`.
                        'extras' => $m->resolved_extras->map(fn ($e) => [
                            'id' => $e->id,
                            'title' => $e->title,
                            'price' => (float) $e->price,
                            'image' => ! empty($e->image) ? media_url('storage/menu/', $e->image, $rid) : null,
                        ])->values(),
                        // T6-3: choice-group (variant-option) groups — same shape the
                        // order path validates (labels + parallel prices → {label, price}).
                        'variant_groups' => $m->resolved_variant_options->map(function ($g) {
                            $labels = array_values((array) json_decode(json_encode($g->options), true));
                            $prices = (array) ($g->option_prices ?? []);

                            return [
                                'id' => (int) $g->id,
                                'title' => $g->title,
                                'required' => (bool) $g->required,
                                'min_select' => (int) ($g->min_select ?? 0),
                                'max_select' => (int) ($g->max_select ?? 0), // 0 = unlimited
                                'options' => collect($labels)->map(fn ($label, $idx) => [
                                    'id' => OfflineSnapshot::optionId((int) $g->id, (int) $idx), // as in the offline snapshot
                                    'label' => $label,
                                    'price' => (float) ($prices[$idx] ?? 0),
                                ])->values(),
                            ];
                        })->values(),
                    ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Offline POS (OFFLINE-POS-SPEC §2): the catalogue snapshot this till keeps to sell offline, for the acting store
     * and branch only. `?branch=` must be an active branch of the store (and the staff member's own when they are tied
     * to one), else 404. `If-None-Match: "<version>"` → 304 when nothing changed.
     */
    public function offlineSnapshot(Request $request, OfflineSnapshot $snapshots): Response
    {
        [$staff, $store] = $this->context();
        $branchId = 0;
        if ($request->filled('branch')) {
            $branchId = (int) $request->query('branch');
            abort_unless(Branch::where('store_id', $store->id)->where('id', $branchId)->where('active', 1)->exists(), 404);
            abort_if($staff && $staff->branch_id && (int) $staff->branch_id !== $branchId, 404);
        } else {
            $branchId = (int) ($this->activeBranchId($store, $staff) ?? 0);
        }
        // A registered till gets its lease and its own peppered PIN verifiers; a revoked till gets only "wipe".
        $device = null;
        if ($request->hasHeader(OfflineDevices::HEADER_ID)) {
            $device = app(OfflineDevices::class)->authenticate($store, $request, true);
            abort_if($device === null, 401, 'Unknown till.');
            if (OfflineDevices::status($device)['wipe']) {
                return response()->json(['code' => $device->revoked_at !== null ? 'device_revoked' : 'wipe_requested', 'wipe' => true, 'message' => 'This till must wipe its offline data.'], 403);
            }
        }
        $row = $snapshots->current($store, $branchId);
        $etag = '"'.$row->version.'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'private, no-store']);
        }

        return response()->json(OfflineSnapshot::document($store, $row, $device), 200, ['ETag' => $etag, 'Cache-Control' => 'private, no-store']);
    }

    /**
     * Offline POS (OFFLINE-POS-SPEC §3): replay what this till recorded offline — sales, tenders, voids and drawer
     * events, each keyed by its client UUIDv7, exactly once and in the till's order. Branch rules as the snapshot.
     */
    public function offlineReplay(Request $request, OfflineReplay $replay): JsonResponse
    {
        [$staff, $store] = $this->context();
        $branchId = 0;
        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            abort_unless(Branch::where('store_id', $store->id)->where('id', $branchId)->exists(), 404);
            abort_if($staff && $staff->branch_id && (int) $staff->branch_id !== $branchId, 404);
        } else {
            $branchId = (int) ($this->activeBranchId($store, $staff) ?? 0);
        }
        // Only a registered till replays; a revoked one may still upload what it holds, then is told to wipe.
        $device = app(OfflineDevices::class)->authenticate($store, $request, true);
        if ($device === null || $device->device_id !== (string) $request->input('device_id')) {
            return response()->json(['message' => 'Register this till first.', 'code' => 'device_unknown'], 401);
        }
        try {
            return response()->json($replay->replay($store, $branchId, $request->all()) + OfflineDevices::status($device));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_batch'], 422);
        }
    }

    /**
     * Offline POS (OFFLINE-POS-SPEC §4, §8): the offline exceptions of this store for the manager — replayed records
     * that were refused or conflicted, and accepted ones the replay flagged (old prices, tax change, oversell, ...).
     */
    public function offlineExceptions(Request $request): JsonResponse
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_shift_close'), 403);

        $kind = (string) $request->query('kind', '');
        $deviceId = (string) $request->query('device_id', '');
        $shiftRaw = $request->query('shift_id');

        // A non-numeric shift id is a bad filter, not "no filter" — refuse it rather than silently ignoring it.
        $shiftId = null;
        if ($shiftRaw !== null && $shiftRaw !== '') {
            if (! is_numeric($shiftRaw)) {
                return response()->json(['message' => ___('Invalid shift filter.'), 'code' => 'bad_filter'], 422);
            }
            $shiftId = (int) $shiftRaw;
        }

        // `kind=declined_after_offline` is the card bucket; a record kind narrows to records only; anything else is an
        // unknown kind and shows nothing (never everything).
        $isCardKind = $kind === 'declined_after_offline';
        $isReversalKind = $kind === 'card_reversal_not_booked';
        $isRecordKind = $kind !== '' && in_array($kind, OfflineExceptionKinds::ALL, true);
        $isUnknownKind = $kind !== '' && ! $isCardKind && ! $isRecordKind && ! $isReversalKind;

        // The distinct shifts/devices of the WHOLE store (cheap, store-scoped) drive the filter selects — not just the
        // rows on this page, so an old till or a closed shift stays selectable.
        $shifts = Order::query()->where('store_id', $store->id)->whereNotNull('pos_shift_id')
            ->distinct()->orderBy('pos_shift_id')->pluck('pos_shift_id')->map(fn ($id) => (int) $id)->values();
        $devices = PosOfflineRecord::query()->where('store_id', $store->id)
            ->whereNotNull('device_id')->distinct()->pluck('device_id')
            ->merge(PosOfflineCardPayment::query()->where('store_id', $store->id)
                ->whereNotNull('device_id')->distinct()->pluck('device_id'))
            ->filter()->unique()->sort()->values();

        $rows = collect();
        $cards = collect();
        $reversals = collect();

        // P5-28: provider-confirmed card refunds/voids the POS could not book — store-scoped, open first. Not tied to a
        // till, so a till filter hides them; a shift filter narrows by the order's shift.
        if (($kind === '' || $isReversalKind) && $deviceId === '') {
            $reversalQuery = PosCardReversalException::query()->where('store_id', $store->id);
            if ($shiftId !== null) {
                $reversalQuery->whereIn('order_id', Order::query()->select('id')->where('store_id', $store->id)->where('pos_shift_id', $shiftId));
            }
            $reversals = $reversalQuery->orderByRaw("case when state = 'open' then 0 else 1 end")->orderByDesc('id')->limit(200)
                ->get(['id', 'order_id', 'kind', 'provider', 'provider_reference', 'amount_minor', 'currency_code', 'reason', 'state', 'resolution_note', 'resolved_at', 'created_at']);
        }

        if (! $isUnknownKind && ! $isReversalKind) {
            // Filters run in SQL BEFORE the limit so an old row is still found. The kind vocabulary is derived in PHP,
            // so a kind filter pulls up to 1000 newest, filters, then cuts to 200.
            $limit = $kind !== '' ? 1000 : 200;

            // Restrict to the orders of one shift (store-scoped subquery — another tenant's shift never matches).
            $shiftOrders = fn () => Order::query()->select('id')
                ->where('store_id', $store->id)->where('pos_shift_id', $shiftId);

            if (! $isCardKind) {
                $recordsQuery = PosOfflineRecord::query()->where('store_id', $store->id)
                    ->where(fn ($q) => $q->where('status', '!=', 'accepted')->orWhereNotNull('flags'));
                if ($deviceId !== '') {
                    $recordsQuery->where('device_id', $deviceId);
                }
                if ($shiftId !== null) {
                    $recordsQuery->whereIn('order_id', $shiftOrders());
                }
                $records = $recordsQuery->orderByDesc('id')->limit($limit)
                    ->get(['client_key', 'type', 'status', 'reason', 'flags', 'order_id', 'device_id', 'device_seq', 'staff_id', 'business_at', 'created_at']);
            } else {
                $records = collect();
            }

            if (! $isRecordKind) {
                $cardsQuery = PosOfflineCardPayment::query()->where('store_id', $store->id)
                    ->whereIn('state', ['declined_after_offline', 'resolved']);
                if ($deviceId !== '') {
                    $cardsQuery->where('device_id', $deviceId);
                }
                if ($shiftId !== null) {
                    $cardsQuery->whereIn('order_id', $shiftOrders());
                }
                $cards = $cardsQuery->orderByDesc('id')->limit(200)
                    ->get(['id', 'order_id', 'amount_minor', 'currency_code', 'state', 'masked_pan', 'scheme', 'device_id', 'taken_at', 'decided_at', 'resolution_method', 'resolved_at']);
            }

            // One query for the whole page: the shifted order's `pos_shift_id` for every order a record or card points at.
            // Store-scoped so an id that slipped in can never resolve to another tenant's shift.
            $orderIds = $records->pluck('order_id')->merge($cards->pluck('order_id'))->filter()->unique()->values();
            $shiftByOrder = $orderIds->isEmpty() ? collect() : Order::query()
                ->where('store_id', $store->id)->whereIn('id', $orderIds->all())->pluck('pos_shift_id', 'id');
            $shiftFor = fn ($orderId): ?int => $orderId !== null && isset($shiftByOrder[(int) $orderId])
                ? (int) $shiftByOrder[(int) $orderId] : null;

            // Add the derived fields: `kinds` (the manager-facing exception vocabulary) and `shift_id` (the order's shift).
            $rows = $records->map(function ($record) use ($shiftFor) {
                $row = $record->toArray();
                $row['kinds'] = OfflineExceptionKinds::for($row);
                $row['shift_id'] = $shiftFor($record->order_id);

                return $row;
            });

            if ($isRecordKind) {
                $rows = $rows->filter(fn ($row) => in_array($kind, $row['kinds'], true))->values();
            }
            $rows = $rows->take(200)->values();
            $cards = $cards->values();
        }

        return response()->json(['exceptions' => $rows, 'cards' => $cards, 'reversals' => $reversals->values(), 'shifts' => $shifts, 'devices' => $devices]);
    }

    /** The manager "Offline exceptions" screen (manager-gated; the data comes from `pos.offline.exceptions` as JSON). */
    public function offlineExceptionsScreen(): View
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_shift_close'), 403);

        return view('staff.pos.exceptions', compact('store'));
    }

    /** Offline card declined after the sale: the manager collected the money another way (cash / card). */
    public function offlineCardResolve(Request $request, int $id, OfflineCardSettlement $settlement): JsonResponse
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_shift_close'), 403);
        $method = (string) $request->input('method');
        try {
            $payment = $settlement->resolve($store, $id, $method, $staff);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_resolvable'], 422);
        }

        return response()->json(['id' => (int) $payment->id, 'state' => $payment->state, 'resolution_method' => $payment->resolution_method]);
    }

    /**
     * P5-28: the manager resolves a card refund/void the POS could not book (after fixing the order / the books by hand).
     * Idempotent: resolving a resolved one answers the same; another store's id is a 404.
     */
    public function cardReversalResolve(Request $request, int $id): JsonResponse
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_shift_close'), 403);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $row = DB::transaction(function () use ($store, $id, $staff, $data) {
            $row = PosCardReversalException::query()->where('store_id', $store->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->state !== 'resolved') {
                $row->forceFill(['state' => 'resolved', 'resolution_note' => $data['note'] ?? null, 'resolved_by_staff_id' => $staff?->id, 'resolved_at' => now()])->save();
            }

            return $row;
        });

        return response()->json(['id' => (int) $row->id, 'state' => $row->state, 'resolved_at' => $row->resolved_at?->toIso8601String()]);
    }

    /**
     * Offline POS (OFFLINE-POS-SPEC §6): register this device as a till of the store. Returns its token once (kept
     * only as a hash). A revoked till cannot register again under the same device id (403 device_revoked).
     */
    public function offlineDeviceRegister(Request $request, OfflineDevices $devices): JsonResponse
    {
        [$staff, $store] = $this->context();
        try {
            [$device, $token] = $devices->register($store, $staff, (int) ($this->activeBranchId($store, $staff) ?? 0),
                (string) $request->input('device_id'), $request->filled('name') ? (string) $request->input('name') : null, $request->input('platform'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_device'], 422);
        } catch (DeviceRevoked) {
            return response()->json(['code' => 'device_revoked', 'wipe' => true, 'message' => 'This till was removed from the store.'], 403);
        }

        return response()->json(['device_id' => $device->device_id, 'token' => $token] + OfflineDevices::status($device), 201);
    }

    /** The till asks about itself on every start and contact: revoked → wipe; else its renewed lease. */
    public function offlineDeviceStatus(Request $request, OfflineDevices $devices): JsonResponse
    {
        [, $store] = $this->context();
        $device = $devices->authenticate($store, $request, true);
        abort_if($device === null, 401, 'Unknown till.');

        return response()->json(OfflineDevices::status($device));
    }

    /**
     * The till's escrow key, for a signed-in staff session on the registered till itself (online only): the browser till
     * opens its encrypted offline store with it. Never for a revoked till or one asked to wipe.
     */
    public function offlineDeviceEscrow(Request $request, OfflineDevices $devices): JsonResponse
    {
        [, $store] = $this->context();
        $device = $devices->authenticate($store, $request, true);
        abort_if($device === null, 401, 'Unknown till.');
        abort_if(OfflineDevices::status($device)['wipe'] || (string) $device->escrow === '', 403);

        return response()->json(['escrow' => (string) $device->escrow], 200, ['Cache-Control' => 'no-store']);
    }

    /** The till reports that it wiped itself (after uploading what it held). */
    public function offlineDeviceWiped(Request $request, OfflineDevices $devices): JsonResponse
    {
        [, $store] = $this->context();
        $device = $devices->authenticate($store, $request, true);
        abort_if($device === null || ! OfflineDevices::status($device)['wipe'], 404);
        $device->forceFill(['wiped_at' => now(), 'token_hash' => null])->save(); // the token is dead after the wipe (a till in service registers again)

        return response()->json(['wiped' => true]);
    }

    // ── OP-12: the till as its own ZATCA EGS unit (off until OP-0: pos.offline.zatca_device_signing) ──

    /**
     * The registered till making this request (device headers), or 401. Revoked tills only where $allowRevoked.
     *
     * @return array{0: StaffMember|null, 1: Store, 2: PosOfflineDevice}
     */
    private function zatcaTill(Request $request, OfflineDevices $devices, bool $allowRevoked = false): array
    {
        abort_unless(OfflineZatca::enabled(), 404);
        [$staff, $store] = $this->context();
        $device = $devices->authenticate($store, $request, $allowRevoked);
        abort_if($device === null, 401, 'Unknown till.');

        return [$staff, $store, $device];
    }

    /** Owner on the till: CSR made on the device (its key never leaves it) + the FATOORA OTP → compliance CSID. */
    public function offlineZatcaOnboard(Request $request, OfflineDevices $devices, OfflineZatca $zatca): JsonResponse
    {
        [$staff, $store, $device] = $this->zatcaTill($request, $devices);
        abort_if($staff !== null, 403);
        abort_if(OfflineDevices::status($device)['wipe'], 403);
        $data = $request->validate(['csr' => ['required', 'string', 'max:8000'], 'otp' => ['required', 'string', 'max:16']]);
        try {
            $unit = $zatca->onboard($store, $device, $data['csr'], $data['otp']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['code' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['code' => 'zatca_refused', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => $unit->status, 'compliance_certificate' => $unit->compliance_certificate], 201);
    }

    /** Owner on the till: the compliance samples the till signed → FATOORA checks → production CSID. */
    public function offlineZatcaCompliance(Request $request, OfflineDevices $devices, OfflineZatca $zatca): JsonResponse
    {
        [$staff, , $device] = $this->zatcaTill($request, $devices);
        abort_if($staff !== null, 403);
        $data = $request->validate(['samples' => ['required', 'array', 'min:1', 'max:6'], 'samples.*.xml' => ['required', 'string', 'max:200000'], 'samples.*.signature' => ['required', 'string', 'max:400']]);
        $unit = $zatca->active($device);
        abort_if($unit === null, 404);
        try {
            $ok = $zatca->compliance($unit, array_values($data['samples']));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['code' => $e->getMessage()], 422);
        }
        abort_unless($ok, 422, 'Compliance checks did not pass.');

        return response()->json(['status' => 'production', 'production_certificate' => $unit->fresh()?->production_certificate]);
    }

    /** The till's unit and the chain state the server holds (what the till resumes from after a restore). */
    public function offlineZatcaStatus(Request $request, OfflineDevices $devices, OfflineZatca $zatca): JsonResponse
    {
        [, , $device] = $this->zatcaTill($request, $devices);
        $unit = $zatca->active($device);

        return response()->json($unit === null ? ['status' => 'none'] : ['status' => $unit->status, 'last_icv' => (int) $unit->last_icv, 'last_hash' => $unit->last_hash,
            'production_certificate' => $unit->production_certificate]);
    }

    /** After sync: the invoices the till signed offline, in ICV order. A revoked till may still upload (they were issued). */
    public function offlineZatcaInvoices(Request $request, OfflineDevices $devices, OfflineZatca $zatca): JsonResponse
    {
        [, $store, $device] = $this->zatcaTill($request, $devices, true);
        $data = $request->validate(['invoices' => ['required', 'array', 'min:1', 'max:100'], 'invoices.*.xml' => ['required', 'string', 'max:200000'],
            'invoices.*.signature' => ['required', 'string', 'max:400'], 'invoices.*.client_key' => ['nullable', 'string', 'max:36']]);
        $unit = $zatca->active($device);
        abort_if($unit === null, 409, 'This till has no ZATCA unit.');
        $results = array_map(fn (array $inv) => $zatca->accept($unit, $inv), array_values($data['invoices']));
        $storeId = (int) $store->id;
        dispatch(fn () => app(OfflineZatca::class)->report($storeId))->afterResponse(); // report right after sync

        return response()->json(['results' => $results, 'last_icv' => (int) $unit->refresh()->last_icv]);
    }

    /** Owner only: the store's tills (never their tokens or peppers). */
    public function offlineDevices(): JsonResponse
    {
        [$staff, $store] = $this->context();
        abort_if($staff !== null, 403);
        $rows = PosOfflineDevice::query()->where('store_id', $store->id)->orderBy('id')
            ->get(['id', 'device_id', 'name', 'platform', 'branch_id', 'registered_at', 'last_seen_at', 'last_seq', 'missing_seqs', 'clock_skew_seconds', 'revoked_at', 'wiped_at', 'wipe_requested_at', 'wipe_requested_by_user_id']);
        $pending = PosOfflineCardPayment::query()->where('store_id', $store->id)->where('state', 'pending_offline')
            ->selectRaw('device_id, count(*) as n')->groupBy('device_id')->pluck('n', 'device_id');

        // OP-12: each till's ZATCA unit and what its chain check / reporting found (for the owner to review)
        $zatca = [];
        if (OfflineZatca::enabled()) {
            $units = PosOfflineZatcaUnit::query()->where('store_id', $store->id)->where('status', '!=', 'retired')->get()->keyBy('pos_offline_device_id');
            $counts = PosOfflineZatcaInvoice::query()->where('store_id', $store->id)
                ->selectRaw("device_id, sum(case when chain = 'gap' then 1 else 0 end) as gaps, sum(case when chain = 'fork' then 1 else 0 end) as forks, sum(case when status = 'pending' then 1 else 0 end) as unreported, sum(case when status = 'rejected' then 1 else 0 end) as rejected")
                ->groupBy('device_id')->get()->keyBy('device_id');
            foreach ($rows as $d) {
                $u = $units[$d->id] ?? null;
                $c = $counts[$d->device_id] ?? null;
                $zatca[$d->id] = ['zatca' => $u === null ? null : ['status' => $u->status, 'last_icv' => (int) $u->last_icv,
                    'gaps' => (int) ($c->gaps ?? 0), 'forks' => (int) ($c->forks ?? 0), 'unreported' => (int) ($c->unreported ?? 0), 'rejected' => (int) ($c->rejected ?? 0)]];
            }
        }

        return response()->json(['devices' => $rows->map(fn ($d) => $d->toArray() + ['pending_offline_cards' => (int) ($pending[$d->device_id] ?? 0)] + ($zatca[$d->id] ?? []))->values()]);
    }

    /** Owner only: wipe a till that stays in service (it uploads first, wipes, then registers again). */
    public function offlineDeviceWipe(int $id, OfflineDevices $devices): JsonResponse
    {
        [$staff, $store] = $this->context();
        abort_if($staff !== null, 403);
        $device = $devices->requestWipe($store, $id, auth('web')->user());

        return response()->json(['id' => (int) $device->id, 'wipe_requested_at' => $device->wipe_requested_at?->toIso8601ZuluString()]);
    }

    /** Owner only: revoke a till (lost or stolen). It wipes on next contact, or by itself when its lease ends. */
    public function offlineDeviceRevoke(int $id, OfflineDevices $devices): JsonResponse
    {
        [$staff, $store] = $this->context();
        abort_if($staff !== null, 403);
        $device = $devices->revoke($store, $id, auth('web')->user());

        return response()->json(['id' => (int) $device->id, 'revoked_at' => $device->revoked_at?->toIso8601ZuluString()]);
    }

    /**
     * T6 follow-up: combo meals for the register's combo tiles, as JSON.
     *
     * Mirrors the storefront's `_partials/combos.blade.php` — `sellable()` gates
     * on the feature (returns empty when off, so no 403 and no dead tile) and on
     * every required slot still having an orderable component, and `payload()`
     * shapes the configurator. Prices here are DISPLAY only; the register posts
     * combo_id + picks and `ComboService::resolve()` re-derives the money inside
     * the already-wired `pos.orders.store` path.
     */
    public function combos()
    {
        [$staff, $store] = $this->context();
        $branchId = $this->activeBranchId($store, $staff);

        $combos = ComboService::sellable($store->id, $branchId)
            ->map(fn ($c) => ComboService::payload($c))
            ->values();

        return response()->json(['combos' => $combos]);
    }

    /**
     * T1-3: read-only coupon validation for the register's "Coupon" add-row.
     * Reuses the ONE shared resolver (CouponService::resolve) with the food_order
     * surface, tenant-scoped to the active restaurant's owner — no side effects,
     * no `used` increment (that happens only when the order is actually placed).
     */
    public function couponCheck(Request $request)
    {
        [$staff, $store] = $this->context();

        $data = $request->validate([
            'code' => 'required|string|max:64',
            'subtotal' => 'nullable|numeric|min:0',
        ]);

        $result = (new CouponService)->resolve(trim($data['code']), [
            'surface' => 'food_order',
            'owner_id' => (int) $store->user_id,
            'branch_id' => $this->activeBranchId($store, $staff),
            'subtotal' => (float) ($data['subtotal'] ?? 0),
            'currency' => store_currency((int) $store->id), // bug batch 4 · 2: the order currency's decimals
        ]);

        if (empty($result['ok'])) {
            return response()->json([
                'valid' => false,
                'message' => $result['message'] ?? ___('Coupon code is invalid.'),
            ]);
        }

        return response()->json([
            'valid' => true,
            'code' => strtoupper(trim($data['code'])),
            'value' => (float) ($result['discount'] ?? 0),
            'free_delivery' => (bool) ($result['free_delivery'] ?? false),
        ]);
    }

    /**
     * T2-1: customer search for the register's "Add customer" panel. Staff-auth +
     * tenant-scoped to the active restaurant. `?q=` matches name/phone/email
     * (limit 20); with no query, returns the 10 most recent by last order. Each
     * row carries read-only store-credit balance + loyalty points.
     */
    public function customers(Request $request)
    {
        [, $store] = $this->context();

        $q = trim((string) $request->query('q', ''));
        $base = Customer::where('store_id', $store->id);

        if ($q !== '') {
            // P1-17: Scout database search (name/phone/email; `%` and `_` literal on every database), this store only.
            $customers = Customer::searchInStores($q, (int) $store->id)->orderBy('name')->take(20)->get();
        } else {
            $customers = Sql::orderByDescNullsLast($base, 'last_order_at')->orderByDesc('id')->limit(10)->get();
        }

        $program = LoyaltyProgram::where('store_id', $store->id)->where('active', true)->first();

        return response()->json($customers->map(function ($c) use ($store, $program) {
            $points = (int) (LoyaltyAccount::where('customer_id', $c->id)
                ->where('store_id', $store->id)->value('balance') ?? 0);

            return [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'email' => $c->email,
                'wallet' => CustomerWalletService::balanceFor($c),
                'loyalty_points' => $points,
                'tier' => $program ? $program->tierFor($points) : null,
            ];
        })->values());
    }

    /**
     * T2-3: inline create-customer from the register. Tenant-scoped; name required,
     * phone/email optional and unique per restaurant (the table enforces
     * (store_id, phone) and (store_id, email) — validate to a clean 422
     * instead of a DB error). Returns the created row in the search-row shape so
     * the client can auto-attach it.
     */
    public function storeCustomer(Request $request)
    {
        [, $store] = $this->context();

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => [
                'nullable', 'string', 'max:40',
                Rule::unique('customers', 'phone')->where('store_id', $store->id),
            ],
            'email' => [
                'nullable', 'email', 'max:190',
                Rule::unique('customers', 'email')->where('store_id', $store->id),
            ],
        ]);

        $customer = Customer::create([
            'store_id' => $store->id,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'source' => 'pos',
        ]);

        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'wallet' => 0.0,
            'loyalty_points' => 0,
        ], 201);
    }

    /** Edit a customer from the register (tenant-scoped; same dup rules as create). */
    public function updateCustomer(Request $request, int $customerId)
    {
        [, $store] = $this->context();
        $customer = Customer::where('store_id', $store->id)->findOrFail($customerId);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => [
                'nullable', 'string', 'max:40',
                Rule::unique('customers', 'phone')->where('store_id', $store->id)->ignore($customer->id),
            ],
            'email' => [
                'nullable', 'email', 'max:190',
                Rule::unique('customers', 'email')->where('store_id', $store->id)->ignore($customer->id),
            ],
        ]);

        $customer->update([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ]);

        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'wallet' => CustomerWalletService::balanceFor($customer),
            'loyalty_points' => (int) (LoyaltyAccount::where('customer_id', $customer->id)
                ->where('store_id', $store->id)->value('balance') ?? 0),
        ]);
    }

    /** A customer's recent orders (for the panel's history card). Tenant-scoped. */
    public function customerOrders(int $customerId)
    {
        [, $store] = $this->context();
        $customer = Customer::where('store_id', $store->id)->findOrFail($customerId);

        $orders = Order::where('store_id', $store->id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')->limit(10)->get();

        return response()->json($orders->map(fn ($o) => [
            'id' => $o->id,
            'time' => optional($o->created_at)->toIso8601String(),
            'type' => $o->type,
            'total' => (float) $o->total,
            'is_paid' => (bool) $o->is_paid,
            'status' => $o->status,
        ])->values());
    }

    /**
     * T3-1: table floor state for the register's "Select Table" screen. Tenant +
     * branch scoped. Per table: id, name, seats, zone, status derived from live
     * orders (occupied = an open unpaid order at the table; served = that order is
     * ready/served; vacant otherwise) + the current order id/total when occupied.
     */
    public function tables()
    {
        [$staff, $store] = $this->context();
        $branchId = $this->activeBranchId($store, $staff);

        // Canonical multi-branch rule (ScopesToBranch): legacy NULL-branch rows belong
        // to the DEFAULT branch only. Keeps the register's table set identical to the
        // dashboard Floor Map + Staff App instead of leaking NULL tables into every branch.
        $tables = $this->scopeToBranch(
            StoreTable::where('store_id', $store->id), $store->id, $branchId
        )->orderBy('table_no')->get();

        /* PARITY-RUN T4: grouped, not keyed. A merged party has SEVERAL open
           orders sitting on the primary table's number, and the old
           `keyBy('table_number')` silently dropped all but the last of them —
           which would have hidden a merged guest's ticket and understated the
           bill. `->last()` reproduces keyBy's exact pick for the unmerged case. */
        $openByTable = $this->scopeToBranch(
            Order::where('store_id', $store->id)
                // Perf: this poll runs every ~6s and reads $order->total per occupied
                // table (Order::computeMoney walks items→menu/variant/itemExtras.extra).
                // Eager-load that graph so a busy floor costs a handful of queries per
                // poll instead of ~N per table.
                ->with(['items.menu', 'items.variant', 'items.itemExtras.extra'])
                ->whereNull('held_at')->where('is_paid', 0)
                ->live()
                ->whereIn('status', ['new', 'preparing', 'ready', 'served', 'pending'])
                ->whereNotNull('table_number'),
            $store->id, $branchId
        )->get()->groupBy('table_number');
        $open = $openByTable->map(fn ($g) => $g->last());

        // PARITY-RUN T4: live merge groups, one query, keyed by table id.
        $mergeGroups = TableMergeService::enabled((int) $store->id)
            ? TableMergeService::groupsByTableId((int) $store->id, $branchId)
            : [];
        $mergeLabels = [];
        $tableNumbers = $tables->pluck('table_no', 'id')->all();
        $tableSeats = $tables->pluck('seats', 'id')->all();

        // Hotel: room tables with a checked-in guest, so the register can offer
        // "charge to room" as a tender on that room's tab. One query, keyed by table id.
        $roomStays = collect();
        if (app(RoomCharge::class)->enabled((int) $store->id)) {
            $roomStays = app(RoomCharge::class)->checkedInStays((int) $store->id)
                ->filter(fn ($stay) => $stay->getAttribute('table_id') !== null)
                ->keyBy('table_id');
        }

        // Imminent reservations (next 3h) keyed by table_number — a vacant table with
        // an upcoming booking shows as `reserved` so staff don't seat a walk-in there.
        // reserved_at is a wall-clock, so the 3h window runs on the store's clock.
        $posNow = now(store_tz($store->id));
        $reserved = Reservation::where('store_id', $store->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->whereNotNull('table_number')
            ->whereBetween('reserved_at', [$posNow, $posNow->copy()->addHours(3)])
            ->orderBy('reserved_at')->get()->keyBy('table_number');

        $data = $tables->map(function ($t) use ($open, $openByTable, $reserved, $mergeGroups, &$mergeLabels, $tableNumbers, $tableSeats, $roomStays) {
            $order = $open[$t->table_no] ?? null;
            $resv = $reserved[$t->table_no] ?? null;

            /* PARITY-RUN T4 merge state. A secondary's orders have already moved
               onto the primary, so it reads as vacant here — the `merged` status
               is what stops the register from seating a walk-in on a table that
               is physically part of a live party. */
            $group = $mergeGroups[(int) $t->id] ?? null;
            $isPrimary = $group && (int) $group->primary_table_id === (int) $t->id;
            if ($group && ! isset($mergeLabels[$group->id])) {
                $mergeLabels[$group->id] = TableMergeService::label($group, $tableNumbers);
            }

            // Floor Map parity (FloorMapController::status): the table's own `status`
            // column (free|seated|ordering|food|bill|dirty|cleaning) is the source of
            // truth; only when it is 'free' do we fall back to order-derived occupancy.
            // A dirty/cleaning table is BLOCKED (not seatable) — previously it showed
            // as vacant because the register ignored the stored status entirely.
            $raw = $t->status ?: 'free';
            if ($group && ! $isPrimary) {
                $status = 'merged'; // linked to a party; the bill lives on the primary
            } elseif (in_array($raw, ['dirty', 'cleaning'], true)) {
                $status = 'blocked';
            } elseif ($group) {
                $status = $order && in_array($order->status, ['ready', 'served'], true) ? 'served' : 'occupied';
            } elseif ($raw === 'bill') {
                $status = 'served'; // asked for the bill → ready to settle
            } elseif (in_array($raw, ['seated', 'ordering', 'food'], true) || $order) {
                $status = ($order && in_array($order->status, ['ready', 'served'], true)) ? 'served' : 'occupied';
            } elseif ($resv) {
                $status = 'reserved'; // vacant but booked within the next 3h
            } else {
                $status = 'vacant';
            }

            return [
                'id' => $t->id,
                'name' => $t->label ?: (___('Table').' '.$t->table_no),
                'table_no' => $t->table_no,
                'seats' => (int) $t->seats,
                'zone' => $t->section ?: ($t->floor_label ?: null),
                'status' => $status,
                'raw_status' => $raw,
                'covers' => $order?->covers,
                'order_id' => $order?->id,
                // A merged primary shows the PARTY's bill: the sum of every open
                // order's own Order::getTotalAttribute(), never a client-side sum.
                'order_total' => $isPrimary
                    ? (float) ($openByTable[$t->table_no] ?? collect())->sum(fn ($o) => (float) $o->total)
                    : ($order ? (float) $order->total : null),
                // PARITY-RUN T4 merge state (null when the table is not merged).
                'merge_group_id' => $group?->id,
                'merge_is_primary' => $group ? $isPrimary : null,
                'merge_label' => $group ? ($mergeLabels[$group->id] ?? null) : null,
                'merge_table_ids' => $group ? $group->members->pluck('table_id')->map(fn ($id) => (int) $id)->values()->all() : null,
                // Capacity sums: a merged party seats the total of its tables.
                'merge_seats' => $group && $isPrimary
                    ? (int) $group->members->sum(fn ($m) => (int) ($tableSeats[(int) $m->table_id] ?? 0))
                    : null,
                'merge_order_ids' => $isPrimary
                    ? ($openByTable[$t->table_no] ?? collect())->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                    : null,
                // Reservation hint (imminent booking on a vacant table).
                'reserved_at' => $status === 'reserved' ? optional($resv->reserved_at)->toIso8601String() : null,
                'reserved_name' => $status === 'reserved' ? $resv->name : null,
                // Hotel: is this table a guest room, and does it have a checked-in stay
                // the register can charge a tab to? Drives the "Charge to room" tender.
                'is_room' => $t->area_type === 'room',
                'can_charge_to_room' => $roomStays->has($t->id),
                'room_guest' => $roomStays->has($t->id) ? (trim((string) $roomStays->get($t->id)->guest_name) ?: null) : null,
                // Elapsed-time chip: since the open order was placed, or since the
                // stored status changed (dirty 40m ≠ dirty 2m).
                'since' => optional($order?->created_at ?: ($raw !== 'free' ? $t->status_changed_at : null))->toIso8601String(),
            ];
        });

        return response()->json($data->values());
    }

    /**
     * T5-6: create (or replace, if unpaid) an even N-way split for an order,
     * reusing the shared SplitBillService (#20) — no new schema. Tenant-scoped.
     */
    public function splitOrder(Request $request, int $orderId)
    {
        [, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);
        $data = $request->validate(['parts' => 'required|integer|min:1|max:50']);

        $check = SplitBillService::createEvenSplit($order, (int) $data['parts']);

        return response()->json($this->splitPayload($check->fresh('payments'), $order->fresh()));
    }

    /**
     * T5-6: mark one split share paid (method per part). When the last share is
     * paid the shared service settles the order (is_paid=1 + order.paid webhook).
     */
    public function splitPay(Request $request, int $orderId)
    {
        [, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);
        $data = $request->validate([
            'share_id' => 'required|integer',
            'method' => 'required|in:cash,card,online,other',
            'payer' => 'nullable|string|max:120',
        ]);

        // The share must belong to this order (tenant-scoped via ownOrder above).
        $share = CheckPayment::whereHas('tableCheck', fn ($q) => $q->where('order_id', $order->id))
            ->findOrFail($data['share_id']);

        SplitBillService::markSharePaid($share, $data['payer'] ?? null, $data['method']);

        $check = TableCheck::where('order_id', $order->id)->with('payments')->latest('id')->first();

        return response()->json($this->splitPayload($check, $order->fresh()));
    }

    /** Shape a split check for the register (shares + order paid flag). */
    private function splitPayload(TableCheck $check, Order $order): array
    {
        return [
            'check_id' => $check->id,
            'status' => $check->status,
            'is_paid' => (bool) $order->is_paid,
            'shares' => $check->payments->sortBy('share_no')->map(fn ($p) => [
                'id' => $p->id,
                'share_no' => $p->share_no,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'status' => $p->status,
            ])->values(),
        ];
    }

    /** Ring an order — held (park) or sent to the kitchen. */
    public function store(Request $request)
    {
        [$staff, $store] = $this->context();

        // A manual discount is a financial giveaway equivalent to a refund, so it
        // needs the same manager permission — otherwise a waiter could comp a full
        // order via discount_amount and bypass the pos_refund gate. Owner ($staff
        // null) is always permitted.
        if ((float) $request->input('discount_amount', 0) > 0) {
            abort_unless($this->can($staff, 'pos_refund'), 403, ___('Manager permission required to apply a discount.'));
        }

        $data = $request->validate([
            'type' => 'required|in:on-table,takeaway,delivery',
            'table_number' => 'nullable|string|max:40',
            'covers' => 'nullable|integer|min:1|max:99', // guests on a dine-in order
            'customer_name' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:500', // T4-2: hold note → orders.message
            'phone' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:255',
            'tip_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'service_charge_amount' => 'nullable|numeric|min:0',
            'held' => 'nullable|boolean',
            'branch_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
            // Coupons & Wallets P7-4a: an owner coupon applied via the shared
            // concern, distinct from and stacking on the manual discount_amount.
            'coupon_code' => 'nullable|string|max:64',
            // P7-4b [decision A]: pay-with-credit only takes effect when a
            // customer_id is attached (the shared concern gates on it); a walk-in
            // with no customer is unaffected even if use_wallet is sent.
            'use_wallet' => 'nullable|boolean',
            'wallet_amount' => 'nullable|numeric|min:0',
            'client_uuid' => 'nullable|string|max:64',
            'client_order_id' => 'nullable|string|max:64',
            'items' => 'required_without:combos|array',
            'items.*.item_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.variation' => 'nullable|integer',
            'items.*.extras' => 'nullable|array',
            'items.*.extras.*' => 'integer',
            'items.*.note' => 'nullable|string|max:255',
            // T6-3: choice-group selections — {group_id, selected:[label,…]}.
            'items.*.options' => 'nullable|array',
            'items.*.options.*.group_id' => 'required|integer',
            'items.*.options.*.selected' => 'nullable|array',
            'items.*.options.*.selected.*' => 'string|max:190',
            /* PARITY-RUN T6: combo lines. Only ids travel — never a price. The
               register displays what ComboService would charge and the server
               re-derives it from the owner's own combo rows. */
            'combos' => 'nullable|array',
            'combos.*.combo_id' => 'required|integer',
            'combos.*.quantity' => 'nullable|integer|min:1|max:999',
            'combos.*.note' => 'nullable|string|max:255',
            'combos.*.picks' => 'nullable|array',
            'combos.*.picks.*.slot_id' => 'required|integer',
            'combos.*.picks.*.option_ids' => 'nullable|array',
            'combos.*.picks.*.option_ids.*' => 'integer',
        ]);

        // Only accept a branch that belongs to this restaurant; otherwise fall back to the actor's.
        if (! empty($data['branch_id']) && ! Branch::where('store_id', $store->id)->where('id', $data['branch_id'])->exists()) {
            unset($data['branch_id']);
        }
        $data['branch_id'] = $data['branch_id'] ?? $this->activeBranchId($store, $staff);

        // Only accept a customer that belongs to this restaurant — a POS actor must
        // not attach (or, at P7-4b, spend the credit of) another tenant's customer.
        if (! empty($data['customer_id']) && ! Customer::where('store_id', $store->id)->where('id', $data['customer_id'])->exists()) {
            unset($data['customer_id']);
        }

        /* J27-A2: the order key (body client_order_id / client_uuid or Idempotency-Key) must be a UUID; the
           register's pre-J27 `pos-<ms>-<rand>` keys stay accepted so sales queued offline still replay. A resend
           returns the same order (same answer); the same key with another cart is a 409. */
        unset($data['client_order_id']);
        $data['client_uuid'] = ClientOrderId::fromRequest($request, acceptLegacyPos: true);

        $order = PosService::createOrder($store, $staff, $data);

        // Resolve the register's language so the print URL below is localised, and
        // return print instructions so the client can print the new order (auto per
        // settings, or manually via the Print button).
        $this->menuLanguages($store);

        return response()->json($this->detail($order) + ['print' => $this->printInstructions($store, $order)], 201);
    }

    /**
     * Add products to an existing unpaid order (POS "Add products" on an occupied
     * table). Same item shape as store(); tenant-scoped via ownOrder().
     */
    public function addItems(Request $request, int $orderId)
    {
        [, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);

        $data = $request->validate([
            'items' => 'required_without:combos|array',
            'items.*.item_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.variation' => 'nullable|integer',
            'items.*.extras' => 'nullable|array',
            'items.*.extras.*' => 'integer',
            'items.*.note' => 'nullable|string|max:255',
            'items.*.options' => 'nullable|array',
            'items.*.options.*.group_id' => 'required|integer',
            'items.*.options.*.selected' => 'nullable|array',
            'items.*.options.*.selected.*' => 'string|max:190',
            /* PARITY-RUN T6: combo lines. Only ids travel — never a price. The
               register displays what ComboService would charge and the server
               re-derives it from the owner's own combo rows. */
            'combos' => 'nullable|array',
            'combos.*.combo_id' => 'required|integer',
            'combos.*.quantity' => 'nullable|integer|min:1|max:999',
            'combos.*.note' => 'nullable|string|max:255',
            'combos.*.picks' => 'nullable|array',
            'combos.*.picks.*.slot_id' => 'required|integer',
            'combos.*.picks.*.option_ids' => 'nullable|array',
            'combos.*.picks.*.option_ids.*' => 'integer',
        ]);

        $order = OrderApiService::appendItems($order, $data['items'] ?? [], $data['combos'] ?? []);

        return response()->json($this->detail($order));
    }

    /**
     * Cart-shaped lines for loading an existing order back into the register cart
     * (POS "Edit order"). Unlike orderDetail (display-only), this returns the ids +
     * unit price the client cart needs to rebuild editable lines.
     */
    public function orderCart(int $orderId)
    {
        [, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);
        $order->load(['items.menu', 'items.variant', 'items.itemExtras.extra']);
        // P1-05: unit prices at the order currency's decimals (the rule Order money rounds with — MC-10/MC-22),
        // HALF_UP and PHP-version independent; was a hard-coded 2 (a KWD 1.235 line reloaded as 1.24).
        $dec = money_decimals($order->currencyCode());

        $lines = $order->items->map(function ($it) use ($dec) {
            /* T6: a combo line has no menu row; its whole price is the snapshot,
               which snapshotUnitPrice() sums exactly as Order::getTotalAttribute
               does. Without this the register reloaded a combo at 0.00 and the
               "Edit order" save would have re-priced the bill down. */
            if ($it->isCombo()) {
                $snap = $it->comboSnapshot();

                return [
                    'id' => null,
                    'combo_id' => (int) $it->combo_id,
                    'name' => $it->displayName(),
                    'qty' => (int) $it->quantity,
                    'variation' => null,
                    'variationLabel' => null,
                    'extras' => [],
                    'options' => collect($snap['components'])->map(fn ($c) => [
                        'group_id' => null,
                        'title' => $c['slot'],
                        'selected' => [['option' => $c['name'], 'price' => (float) $c['price']]],
                    ])->values(),
                    'note' => $it->order_note,
                    'unitPrice' => Money::round($it->snapshotUnitPrice(), $dec),
                ];
            }
            /* WV.V — a recalled line must come back at the price the order was
               taken at. `Order::getTotalAttribute()` lets a snapshotted T5
               `unit_price` replace the derived menu/variant price; deriving it
               again here reloaded a happy-hour line at LIST price, so the
               register showed a bill higher than the one the order actually
               carries. NULL wherever no schedule applied. */
            $base = $it->menu
                ? ($it->unit_price !== null
                    ? (float) $it->unit_price
                    : ($it->variation && $it->variant ? (float) $it->variant->price : (float) $it->menu->price))
                : 0.0;
            $optPrice = 0.0;
            foreach ((array) $it->options as $g) {
                foreach ((array) ($g['selected'] ?? []) as $o) {
                    $optPrice += (float) ($o['price'] ?? 0);
                }
            }
            $extras = $it->itemExtras->map(fn ($e) => $e->extra ? [
                'id' => $e->extra_id, 'title' => $e->extra->getRawOriginal('title'), 'price' => (float) $e->extra->price,
            ] : null)->filter()->values();
            $extraPrice = $extras->sum('price');

            return [
                'id' => $it->item_id,                       // menu id (cart line "id")
                'name' => optional($it->menu)->getRawOriginal('name') ?? '—',
                'qty' => (int) $it->quantity,
                'variation' => $it->variation ?: null,
                'variationLabel' => $it->variant?->name,
                'extras' => $extras,
                'options' => collect((array) $it->options)->map(fn ($g) => [
                    'group_id' => $g['group_id'] ?? null,
                    'title' => $g['title'] ?? '',
                    'selected' => collect($g['selected'] ?? [])->map(fn ($o) => ['option' => $o['option'] ?? '', 'price' => (float) ($o['price'] ?? 0)])->values(),
                ])->values(),
                'note' => $it->order_note,
                'unitPrice' => Money::round($base + $optPrice + $extraPrice, $dec),
            ];
        })->values();

        return response()->json($this->detail($order) + ['lines' => $lines]);
    }

    /** Replace all items on an existing unpaid order (POS "Edit order"). */
    public function replaceItems(Request $request, int $orderId)
    {
        [, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);

        $data = $request->validate([
            'items' => 'required_without:combos|array',
            'items.*.item_id' => 'required|integer',
            'items.*.quantity' => 'required|integer|min:1|max:999',
            'items.*.variation' => 'nullable|integer',
            'items.*.extras' => 'nullable|array',
            'items.*.extras.*' => 'integer',
            'items.*.note' => 'nullable|string|max:255',
            'items.*.options' => 'nullable|array',
            'items.*.options.*.group_id' => 'required|integer',
            'items.*.options.*.selected' => 'nullable|array',
            'items.*.options.*.selected.*' => 'string|max:190',
            /* PARITY-RUN T6: combo lines. Only ids travel — never a price. The
               register displays what ComboService would charge and the server
               re-derives it from the owner's own combo rows. */
            'combos' => 'nullable|array',
            'combos.*.combo_id' => 'required|integer',
            'combos.*.quantity' => 'nullable|integer|min:1|max:999',
            'combos.*.note' => 'nullable|string|max:255',
            'combos.*.picks' => 'nullable|array',
            'combos.*.picks.*.slot_id' => 'required|integer',
            'combos.*.picks.*.option_ids' => 'nullable|array',
            'combos.*.picks.*.option_ids.*' => 'integer',
        ]);

        $order = OrderApiService::replaceItems($order, $data['items'] ?? [], $data['combos'] ?? []);

        return response()->json($this->detail($order));
    }

    /**
     * Apply a coupon to an EXISTING unpaid order (settle-time coupon). Reuses the
     * shared OrderPricing concern so the discount is computed + validated exactly
     * like the create path; idempotent (a re-applied code never double-discounts).
     */
    public function applyCoupon(Request $request, int $orderId)
    {
        [, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);
        abort_if((bool) $order->is_paid, 422, 'A paid order cannot be couponed.');

        $data = $request->validate(['coupon_code' => 'required|string|max:64']);

        $pricing = OrderPricing::applyCouponAndCredit($order, [
            'coupon_code' => $data['coupon_code'],
            'owner_id' => $store->user_id,
            'customer_id' => $order->customer_id,
            'branch_id' => $order->branch_id,
        ]);
        $order->refresh();

        if ($order->coupon_id === null) {
            $reason = $pricing['claim_denied_reason'] ?? null;

            return response()->json([
                'message' => match ($reason) {
                    'per_customer' => ___('You have already used this coupon.'),   // M3
                    'limit' => ___('Coupon code usage limit exceeded.'),           // C5
                    'promotions_restricted' => ___('Coupons cannot be used on medicines in this market.'), // P1-23
                    default => ___('Coupon code is invalid.'),
                },
            ], 422);
        }

        return response()->json($this->detail($order));
    }

    /** Settle payment on an order. */
    public function settle(Request $request, int $orderId)
    {
        [, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);

        $data = $request->validate([
            // Coupons C6: 'coupon' = nothing to collect (PosService::settle checks the server amount due).
            'method' => 'required|in:cash,card,online,other,charge_to_room,coupon',
            'tendered' => 'nullable|numeric|min:0',
            'tip' => 'nullable|numeric|min:0',
        ]);

        /* Hotel: settle a room's tab onto the guest folio instead of collecting at
           the register. Only valid when the order sits on a room table that has a
           checked-in stay; otherwise there is nothing to charge the room to. The tab
           still closes (PosService::settle stamps payment_method='charge_to_room' +
           is_paid), and HotelFolioService posts the receivable to the folio via the
           same idempotent `hotel_folio:order:{id}` ledger path the guest flow uses. */
        if ($data['method'] === 'charge_to_room') {
            $stay = $this->folioStayForOrder($order, $store);
            abort_unless($stay !== null, 422, ___('This order is not on a checked-in room, so it cannot be charged to a room.'));
            $order = PosService::settle($order, 'charge_to_room', null, $data['tip'] ?? null);
            app(RoomCharge::class)->chargeOrderForTender($order, $stay);
        } else {
            $order = PosService::settle($order, $data['method'], $data['tendered'] ?? null, $data['tip'] ?? null);
        }

        // Resolve the register's chosen menu language (cookie) so the auto-print URL
        // built below carries it — the printed receipt then matches the on-screen language.
        $this->menuLanguages($store);

        // P1: tell the register what to auto-print (browser opens the receipt route(s),
        // which call window.print(); the OS dialog still chooses the physical printer).
        return response()->json($this->detail($order) + ['print' => $this->printInstructions($store, $order)]);
    }

    /**
     * Hotel: resolve the checked-in stay a POS order can be charged to, or null.
     * An order is charge-to-room-eligible when the store runs hotel mode and the
     * order's table_number maps to a room (`area_type='room'`) that has an active
     * stay. Returns null (never throws) for non-hotel stores, tab-less orders, or
     * ordinary tables — the caller treats null as "not eligible".
     */
    private function folioStayForOrder(Order $order, Store $store): ?Model
    {
        if (! app(RoomCharge::class)->enabled((int) $store->id)) {
            return null;
        }
        $tableNo = (string) ($order->table_number ?? '');
        if ($tableNo === '') {
            return null;
        }
        $room = StoreTable::where('store_id', (int) $store->id)
            ->where('table_no', $tableNo)
            ->where('area_type', 'room')
            ->first();
        if (! $room) {
            return null;
        }

        return app(RoomCharge::class)->activeStayForRoom((int) $store->id, (int) $room->id);
    }

    /**
     * P1 (IP12): POS print/station settings for a branch (or the restaurant when no
     * branch). Manager/owner only. Honest about the browser-print limitation (§1): this
     * configures WHAT auto-prints on settle, never WHICH physical printer.
     */
    // ── D8: terminal registry (minimal MultiPOS) ─────────────────────────────

    /** Live (non-retired) terminals for this register's store, newest heartbeat first. */
    public function terminals(): JsonResponse
    {
        [$staff, $store] = $this->context();
        $branchId = $this->activeBranchId($store, $staff);

        $terminals = PosTerminal::where('store_id', $store->id)->active()
            ->orderByRaw('last_seen_at IS NULL')->orderByDesc('last_seen_at')->orderBy('name')->get();

        return response()->json([
            'active_branch' => $branchId,
            'terminals' => $terminals->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'branch_id' => $t->branch_id,
                'printer_profile_id' => $t->printer_profile_id,
                'last_seen_at' => optional($t->last_seen_at)->toIso8601String(),
                'online' => $t->isOnline(),
            ])->values(),
        ]);
    }

    /** Register unlock names a terminal (or re-adopts one). Any register user. */
    public function storeTerminal(Request $request): JsonResponse
    {
        [$staff, $store] = $this->context();
        $data = $request->validate(['name' => 'required|string|max:80']);
        $branchId = $this->activeBranchId($store, $staff);

        // Pricing build (PRICING.md §3): the plan's POS device count (Starter 1). No `pos_terminal_limit` on the plan
        // = unlimited (every plan made before the key existed); a retired terminal frees its place.
        $owner = $store->user;
        $limit = $owner ? Entitlements::limit($owner, 'pos_terminal') : null;
        if ($limit !== null && PosTerminal::where('store_id', $store->id)->active()->count() >= $limit) {
            return response()->json(['error' => 'pos_terminal_limit', 'limit' => $limit, 'message' => ___('Limit exceeded, please upgrade your membership.')], 403);
        }

        $terminal = PosTerminal::create([
            'store_id' => $store->id,
            'branch_id' => $branchId,
            'name' => $data['name'],
            'last_seen_at' => now(),
        ]);

        return response()->json(['id' => $terminal->id, 'name' => $terminal->name], 201);
    }

    /** Heartbeat: the register calls this on its poll so last_seen tracks liveness. */
    public function terminalHeartbeat(Request $request, int $terminalId): JsonResponse
    {
        [, $store] = $this->context();
        $terminal = PosTerminal::where('id', $terminalId)->where('store_id', $store->id)->active()->first();
        if (! $terminal) {
            return response()->json(['ok' => false], 404);
        }
        $terminal->forceFill(['last_seen_at' => now()])->save();

        return response()->json(['ok' => true, 'last_seen_at' => $terminal->last_seen_at->toIso8601String()]);
    }

    /** Rename / re-pin the printer on a terminal (manager). */
    public function updateTerminal(Request $request, int $terminalId): JsonResponse
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_shift_close'), 403, 'Manager permission required.');
        $terminal = PosTerminal::where('id', $terminalId)->where('store_id', $store->id)->firstOrFail();

        $data = $request->validate([
            'name' => 'nullable|string|max:80',
            'printer_profile_id' => 'nullable|integer',
        ]);

        if (isset($data['name']) && $data['name'] !== '') {
            $terminal->name = $data['name'];
        }
        // A pinned printer must be one of this store's own profiles.
        $pid = (int) ($data['printer_profile_id'] ?? 0);
        $terminal->printer_profile_id = ($pid && PrinterProfile::where('store_id', $store->id)->where('id', $pid)->exists())
            ? $pid : null;
        $terminal->save();

        return response()->json(['id' => $terminal->id, 'name' => $terminal->name, 'printer_profile_id' => $terminal->printer_profile_id]);
    }

    /** Retire (soft-hide) a decommissioned terminal (manager). */
    public function retireTerminal(int $terminalId): JsonResponse
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_shift_close'), 403, 'Manager permission required.');
        $terminal = PosTerminal::where('id', $terminalId)->where('store_id', $store->id)->firstOrFail();
        $terminal->forceFill(['retired_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function settingsShow()
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_shift_close'), 403, 'Manager permission required.');

        $branches = Branch::where('store_id', $store->id)->where('active', 1)
            ->orderBy('position')->get(['id', 'name']);
        $activeBranchId = $this->activeBranchId($store, $staff);
        $posSettings = $this->posSettings($store, $activeBranchId);

        // D8: named devices + the printer profiles a terminal can pin.
        $terminals = PosTerminal::where('store_id', $store->id)->active()
            ->orderByRaw('last_seen_at IS NULL')->orderByDesc('last_seen_at')->orderBy('name')->get();
        $printerProfiles = PrinterProfile::where('store_id', $store->id)
            ->orderBy('position')->orderBy('id')->get(['id', 'name']);

        return view('staff.pos.settings', compact('store', 'branches', 'activeBranchId', 'posSettings', 'terminals', 'printerProfiles'));
    }

    public function settingsSave(Request $request)
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_shift_close'), 403, 'Manager permission required.');

        $data = $request->validate([
            'branch_id' => 'nullable|integer',
            'auto_print' => 'required|in:off,receipt,kitchen,both',
            'copies' => 'required|integer|min:1|max:5',
            'station_name' => 'nullable|string|max:40',
            'autolock_minutes' => 'required|integer|min:0|max:120',
        ]);

        $settings = [
            'auto_print' => $data['auto_print'],
            'copies' => (int) $data['copies'],
            'station_name' => (string) ($data['station_name'] ?? ''),
            'autolock_minutes' => (int) $data['autolock_minutes'],
        ];

        // Branch-scoped when a valid branch is given; else restaurant-level.
        $branchId = ! empty($data['branch_id']) ? (int) $data['branch_id'] : null;
        if ($branchId && Branch::where('store_id', $store->id)->where('id', $branchId)->exists()) {
            Branch::where('store_id', $store->id)->where('id', $branchId)
                ->update(['pos_settings' => json_encode($settings)]);
        } else {
            StoreSettings::set((int) $store->id, 'pos_settings', json_encode($settings));
        }

        return response()->json(['ok' => true, 'settings' => $settings]);
    }

    public function hold(int $orderId)
    {
        [, $store] = $this->context();
        $order = PosService::hold($this->ownOrder($orderId, $store));

        return response()->json($this->detail($order));
    }

    public function resume(int $orderId)
    {
        [, $store] = $this->context();
        $order = PosService::resume($this->ownOrder($orderId, $store));

        return response()->json($this->detail($order));
    }

    /** Currently held (parked) orders for the register's held-orders drawer. */
    public function held()
    {
        [, $store] = $this->context();
        $orders = Order::where('store_id', $store->id)
            // detail() reads $order->total + voiceNote per row; eager-load the graph.
            ->with(['items.menu', 'items.variant', 'items.itemExtras.extra', 'voiceNote'])
            ->whereNotNull('held_at')->orderByDesc('id')->get();

        return response()->json($orders->map(fn ($o) => $this->detail($o))->values());
    }

    /**
     * P3: quick-switch lock unlock. Reuses the staff-PIN credential (same match as
     * StaffAuthController::pin) — a PIN swaps the SESSION staff actor so context()
     * re-resolves on the next request (held orders persist server-side). An owner
     * running the register (web guard) unlocks with their password instead. The
     * restaurant is resolved server-side via context(), so a PIN only matches staff
     * of THIS restaurant. Throttled at the route.
     */
    public function unlock(Request $request)
    {
        [, $store] = $this->context();

        // PIN path — staff quick-switch (works on a dedicated staff terminal).
        if ($request->filled('pin')) {
            $data = $request->validate(['pin' => 'required|digits_between:4,6']);

            $match = StaffMember::authenticateByPin($store->id, $data['pin']);

            if (! $match && StaffMember::pinLoginLocked($store->id)) {
                return response()->json(['error' => 'PIN login is locked for a few minutes.'], 429);
            }
            if (! $match || ! $match->hasPermission('pos')) {
                return response()->json(['error' => 'Invalid PIN.'], 401);
            }

            Auth::guard('staff')->login($match); // session actor swap
            // Mark the session as staff-driven so context() resolves the cashier even
            // when an owner web session coexists (handover on the owner's terminal).
            session(['staff_session_id' => $match->id]);
            $match->forceFill(['last_login_at' => now()])->save();

            return response()->json(['ok' => true, 'actor' => ['id' => (int) $match->id, 'name' => $match->name, 'role' => $match->role]]);
        }

        // Password path — the owner re-authenticates their own web session.
        $owner = auth('web')->user();
        abort_unless($owner, 401, 'A PIN is required to unlock.');
        $data = $request->validate(['password' => 'required|string']);

        if (! Hash::check($data['password'], $owner->password)) {
            return response()->json(['error' => 'Incorrect password.'], 401);
        }

        // The owner reclaims the register: drop the staff marker so context()
        // resolves the owner again after a PIN handover.
        session()->forget('staff_session_id');
        // ...and log the PIN cashier out of the staff guard too: `auth:staff,web` prefers a logged-in staff guard, whose
        // tenant scope would otherwise narrow the owner to that cashier's one store (a second own store then 404s).
        // Same hand-over as the owner app login.
        Auth::guard('staff')->logout();

        return response()->json(['ok' => true, 'actor' => ['name' => $owner->name, 'role' => 'owner']]);
    }

    /**
     * P2: customer-facing display (2nd screen). Full-screen mirror of the register's
     * cart. The live path is a same-device BroadcastChannel from the register; this
     * page also polls displayState() every 5s as a cross-device fallback.
     */
    public function display(Request $request)
    {
        [, $store] = $this->context();

        // Localise the customer-facing display. The route has no locale prefix, so it
        // rendered in the site default (English) regardless of the register language.
        // Honour an explicit ?lang=, else the register's chosen menu language
        // (Quick_user_lang_code cookie — the display window is same-origin, so it
        // carries it) via menuLanguages().
        $lang = (string) $request->query('lang', '');
        if ($lang !== '' && Language::where('code', $lang)->exists()) {
            app()->setLocale($lang);
        } else {
            $this->menuLanguages($store);
        }

        return view('staff.pos.display', compact('store'));
    }

    /**
     * P2 polling fallback: the actor's current held/open (unpaid) POS order, scoped
     * to their restaurant. Returns `active:false` when nothing is in progress (idle).
     */
    public function displayState()
    {
        [, $store] = $this->context();

        $order = Order::where('store_id', $store->id)
            ->where('source', 'pos')->where('is_paid', 0)
            // Perf: 5s poll — eager-load the line graph the cart mirror walks below.
            ->with(['items.menu', 'items.variant', 'items.itemExtras.extra'])
            ->orderByDesc('id')->first();

        // SYSTEMS-RUN D-P1: display config (tip prompt, branding, idle promo).
        $config = [
            'tip_enabled' => (bool) store_options($store->id, 'display_tip_enabled', 0),
            'tip_percents' => array_values(array_filter(array_map('intval', explode(',', (string) store_options($store->id, 'display_tip_percents', '10,15,20'))))),
            'brand_color' => (string) ($store->color ?: '#111'),
            'brand_logo' => (string) ($store->main_image ?: ''),
        ];

        if (! $order) {
            // Idle mode: promo slideshow from the Banners module when no active cart.
            $promos = [];
            try {
                $promos = Banner::where('store_id', $store->id)->where('active', 1)
                    ->orderBy('position')->limit(10)->get(['image', 'title'])->toArray();
            } catch (\Throwable $e) {
            }

            return response()->json(['active' => false, 'lines' => [], 'subtotal' => 0, 'total' => 0, 'config' => $config, 'idle_promos' => $promos]);
        }

        $subtotal = 0.0;
        $lines = $order->items->map(function ($it) use (&$subtotal) {
            $price = ($it->variation && $it->variant) ? (float) $it->variant->price : (float) optional($it->menu)->price;
            foreach ($it->itemExtras as $ex) {
                if ($ex->extra) {
                    $price += (float) $ex->extra->price;
                }
            }
            $lineTotal = $price * (int) $it->quantity;
            $subtotal += $lineTotal;

            return [
                'name' => optional($it->menu)->getRawOriginal('name') ?? '—',
                'qty' => (int) $it->quantity,
                'price' => $price,
                'line_total' => $lineTotal,
            ];
        })->values();

        return response()->json([
            'active' => true,
            'type' => $order->type,
            'lines' => $lines,
            'subtotal' => Money::round($subtotal, money_decimals($order->currencyCode())), // P1-05: was a hard-coded 2
            'total' => (float) ($order->total ?: $subtotal),
            'tip_amount' => (float) ($order->tip_amount ?? 0),
            'pay_status' => $order->is_paid ? 'paid' : 'awaiting_payment',
            'config' => $config,
            'customer' => $order->customer_id ? ['name' => optional($order->customer)->name, 'loyalty' => optional(LoyaltyAccount::where('customer_id', $order->customer_id)->first())->balance] : null,
        ]);
    }

    /** SYSTEMS-RUN D-P1: loyalty check-in from the customer display (phone lookup). */
    public function displayCheckIn(Request $request)
    {
        [, $store] = $this->context();
        $data = $request->validate(['phone' => 'required|string|max:32']);

        $customer = Customer::where('store_id', $store->id)->where('phone', $data['phone'])->first();
        if (! $customer) {
            return response()->json(['found' => false]);
        }
        $order = Order::where('store_id', $store->id)->where('source', 'pos')->where('is_paid', 0)->orderByDesc('id')->first();
        if ($order) {
            $order->update(['customer_id' => $customer->id]);
        }
        $balance = optional(LoyaltyAccount::where('customer_id', $customer->id)->first())->balance ?? 0;

        return response()->json(['found' => true, 'name' => $customer->name, 'loyalty' => $balance]);
    }

    /**
     * P6: POS sales report for a day — totals by payment method, by staff, by hour,
     * and tips per staff. Manager/analytics only.
     */
    public function sales(Request $request)
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'analytics') || $this->can($staff, 'pos_shift_close'), 403, 'Manager permission required.');

        // The POS day runs on the store's own clock. created_at is an app-tz
        // wall-clock, so the store-local window is normalised back to the app
        // timezone for the query; the returned labels keep the store-local date.
        $tz = store_tz($store->id);
        $appTz = config('app.timezone') ?: 'UTC';
        // rescue(): a garbage ?from/?to (e.g. ?from=xyz) makes Carbon::parse throw and
        // 500s the report; fall back to today's window instead.
        $fromLocal = ($request->query('from')
            ? rescue(fn () => Carbon::parse($request->query('from'), $tz), Carbon::now($tz), false)
            : Carbon::now($tz))->startOfDay();
        $toLocal = ($request->query('to')
            ? rescue(fn () => Carbon::parse($request->query('to'), $tz), Carbon::now($tz), false)
            : Carbon::now($tz))->endOfDay();
        $from = $fromLocal->copy()->setTimezone($appTz);
        $to = $toLocal->copy()->setTimezone($appTz);

        // D9: branch dimension. Default to the register's own branch; a manager (the
        // gate above already requires analytics/pos_shift_close) may switch via
        // ?branch_id, or pass branch_id=0 for the whole restaurant.
        $branchFilter = $request->has('branch_id')
            ? ((int) $request->query('branch_id') ?: null)
            : $this->activeBranchId($store, $staff);

        $orders = Order::where('store_id', $store->id)
            ->where('source', 'pos')->where('is_paid', 1)
            ->when($branchFilter, fn ($q) => $q->where('branch_id', $branchFilter))
            ->whereBetween('created_at', [$from, $to])->get();

        // P1-05: sums at the store currency's decimals, HALF_UP (was a hard-coded 2).
        $dec = currency_decimals(store_currency((int) $store->id));
        $byMethod = [];
        $byStaff = [];
        $byHour = array_fill(0, 24, 0.0);
        $tipsByStaff = [];
        $total = 0.0;

        foreach ($orders as $o) {
            $amt = (float) $o->total;
            $total += $amt;
            $m = $o->payment_method ?: 'other';
            $byMethod[$m] = Money::round(($byMethod[$m] ?? 0) + $amt, $dec);
            $sid = (int) ($o->created_by_staff_id ?: 0);
            $byStaff[$sid] = Money::round(($byStaff[$sid] ?? 0) + $amt, $dec);
            $tipsByStaff[$sid] = Money::round(($tipsByStaff[$sid] ?? 0) + (float) ($o->tip_amount ?? 0), $dec);
            // Hour buckets read in the store's local clock (created_at is app-tz).
            $h = $o->created_at ? (int) $o->created_at->copy()->setTimezone($tz)->format('G') : 0;
            $byHour[$h] = Money::round($byHour[$h] + $amt, $dec);
        }

        // Resolve staff ids → names for the panel (0 = the owner/register itself).
        $names = StaffMember::whereIn('id', array_filter(array_keys($byStaff)))->pluck('name', 'id');
        $staffRows = collect($byStaff)->map(fn ($amt, $sid) => [
            'id' => (int) $sid,
            'name' => $sid ? ($names[$sid] ?? ('#'.$sid)) : ___('Owner'),
            'total' => $amt,
            'tips' => $tipsByStaff[$sid] ?? 0,
        ])->values();

        return response()->json([
            'from' => $fromLocal->toDateString(),
            'to' => $toLocal->toDateString(),
            'branch_id' => $branchFilter, // D9: which branch these figures cover (null = all)
            'orders' => $orders->count(),
            'total' => Money::round($total, $dec),
            'sales_by_method' => $byMethod,
            'sales_by_staff' => $staffRows,
            'sales_by_hour' => $byHour,
            'tips_by_staff' => $tipsByStaff,
        ]);
    }

    /**
     * P5: service worker for the register — caches the shell + menu so the POS
     * keeps working through a brief outage. Served from the /pos scope. Public
     * (SWs load pre-auth); it caches nothing sensitive beyond the menu.
     */
    public function serviceWorker()
    {
        // The "connect once" page must read in the locale the till navigates under, not only the locale of the request
        // that registered the worker: build the text for en, ar and ur plus the current app locale, each with its lang
        // and writing direction. The app locale is moved around the ___() calls and always restored (finally).
        $rtl = ['ar', 'ur', 'fa', 'he'];
        $text = fn (string $locale): array => [
            'title' => ___('This store is not ready offline'),
            'body' => ___('Connect once with this store open on this device, then it works offline.'),
            'dir' => in_array($locale, $rtl, true) ? 'rtl' : 'ltr',
            'lang' => $locale,
        ];
        $current = app()->getLocale();
        $build = function (string $locale) use ($text): array {
            $previous = app()->getLocale();
            app()->setLocale($locale);
            try {
                return $text($locale);
            } finally {
                app()->setLocale($previous);
            }
        };
        $texts = [];
        foreach (array_values(array_unique(array_merge(['en', 'ar', 'ur'], [$current]))) as $locale) {
            $texts[$locale] = $build($locale);
        }
        $offline = json_encode($build($current), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $offlineAll = json_encode($texts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $js = <<<'JS'
const CACHE = 'pos-v16'; // bumped: v16 = the offline pricing scripts (till-calculator.js + pos-offline.js) precached; v15 = keys/tokens/status never cached (v14 cached the escrow: deleted with the old cache); v14 = cold start (menu + shell warmed on first visit, ack to the page); v13 = pos-store.js (encrypted offline store); v12 = pos-device.js; v11 = per-restaurant shells
// Static register assets (same for every store). The register page itself is NOT here: it is rendered for one
// restaurant, so it is cached per restaurant (shellKey) from the X-Pos-Restaurant header the server sends with it.
const SHELL = ['/assets/pos/pos-register.css', '/assets/pos/pos-core.js', '/assets/pos/pos-shortcuts.js', '/assets/pos/pos-customers.js', '/assets/pos/pos-tables.js', '/assets/pos/pos-hold.js', '/assets/pos/pos-payments.js', '/assets/pos/pos-manage.js', '/assets/pos/pos-print.js', '/assets/pos/pos-merge.js', '/assets/pos/pos-store.js', '/assets/pos/pos-device.js', '/assets/pos/till-calculator.js', '/assets/pos/pos-offline.js', '/assets/pos/alpine.min.js', '/assets/js/howler.min.js', '/assets/js/notify-sounds.js'];
const OFFLINE = __OFFLINE__;
const OFFLINE_ALL = __OFFLINE_ALL__;
const shellKey = (id) => '/pos/__shell/' + encodeURIComponent(id);
const LAST = '/pos/__shell/last';
const isShell = (url) => new URL(url).pathname === '/pos';
async function keepShell(res) {
    const id = res.headers.get('X-Pos-Restaurant');
    if (!res.ok || res.redirected || !id) return false; // a login page, an error or a redirect is never a store's shell
    const c = await caches.open(CACHE);
    await c.put(shellKey(id), res.clone());
    await c.put(LAST, new Response(id));
    return true;
}
const sameOrigin = (u) => { try { return new URL(u).origin === self.location.origin; } catch (e) { return false; } };
// Cold start: the first visit loaded the register and its menu before this worker controlled the page, so neither was
// cached and a till restarted offline had no register (503) and no menu. The page hands over both URLs; they are
// fetched and kept now, and the page is told when it is ready offline.
async function warm(url, extra, told) {
    const c = await caches.open(CACHE);
    const get = (u, init) => { const ab = new AbortController(); const t = setTimeout(() => ab.abort(), 15000); return fetch(u, { ...init, signal: ab.signal }).finally(() => clearTimeout(t)); };
    const keep = (u) => get(u, { credentials: 'include', headers: { Accept: 'application/json' } })
        .then(async (r) => { if (!cacheable(r) || r.redirected) return false; await c.put(u, r); return true; }).catch(() => false);
    const ok = (u) => sameOrigin(u) && !networkOnly(u) && /^\/(pos|assets)\//.test(new URL(u).pathname); // the menu and the page's own (versioned) scripts
    const isAsset = (u) => new URL(u).pathname.startsWith('/assets/');
    // the register and the menu first (what a restarted till needs): the page is told as soon as those are kept
    const first = await Promise.all([get(url, { credentials: 'include' }).then(keepShell).catch(() => false), ...extra.filter((u) => ok(u) && !isAsset(u)).map(keep)]);
    told(first.every(Boolean));
    // then only the scripts the install precache lacks (versioned URLs fall back to it with ignoreSearch)
    for (const u of extra.filter((x) => ok(x) && isAsset(x))) {
        if (!(await c.match(u, { ignoreSearch: true }))) await keep(u);
    }
}


async function offlineShell(url, req) {
    const c = await caches.open(CACHE);
    let id = new URL(url).searchParams.get('store');
    if (!id) { // bare /pos renders the session's store: the store this device last opened
        const last = await c.match(LAST);
        id = last ? await last.text() : null;
    }
    const hit = id ? await c.match(shellKey(id)) : null;
    if (hit) return hit;
    const esc = (s) => String(s).replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]));
    // The register scope is /pos, so the URL carries no locale: pick the text from the browser's Accept-Language (first tag we
    // carry), else the locale of the request that registered the worker.
    const has = (k) => Object.prototype.hasOwnProperty.call(OFFLINE_ALL, k);
    let T = OFFLINE;
    const al = req && req.headers && req.headers.get ? (req.headers.get('accept-language') || '') : '';
    for (const part of al.split(',')) {
        const tag = part.split(';')[0].trim().toLowerCase();
        const k = has(tag) ? tag : tag.split('-')[0];
        if (k && has(k)) { T = OFFLINE_ALL[k]; break; }
    }
    return new Response('<!doctype html><html lang="' + esc(T.lang || 'en') + '" dir="' + esc(T.dir || 'ltr') + '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' + esc(T.title)
        + '</title></head><body><main style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem"><h1>' + esc(T.title) + '</h1><p>' + esc(T.body) + '</p></main></body></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}
self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL).catch(() => {})));
    self.skipWaiting();
});
self.addEventListener('activate', (e) => e.waitUntil(caches.keys()
    .then((keys) => Promise.all(keys.filter((k) => k.startsWith('pos-') && k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim())
    // a register tab opened before this worker existed: keep its shell even if its own message is lost
    .then(() => self.clients.matchAll({ type: 'window', includeUncontrolled: true }))
    .then((list) => Promise.all(list.filter((w) => sameOrigin(w.url) && isShell(w.url)).map((w) => fetch(w.url, { credentials: 'include' }).then(keepShell).catch(() => false))))));
// The page that registered this worker loaded before the worker controlled it, so its own navigation was never
// cached (why a store had no offline shell after its first visit): the page asks for its URL to be kept.
self.addEventListener('message', (e) => {
    const url = e.data && e.data.type === 'pos-shell' ? String(e.data.url || '') : '';
    if (!url || !sameOrigin(url) || !isShell(url)) return;
    const extra = Array.isArray(e.data.warm) ? e.data.warm.map(String) : [];
    const port = e.ports && e.ports[0];
    let sent = false;
    const told = (ok) => { if (!sent && port) { sent = true; port.postMessage({ ok }); } };
    e.waitUntil(warm(url, extra, told).catch(() => false).then(() => told(false)));
});
// Keys, tokens and till status are network-only: never served from or written to a cache. A cached escrow let a till
// restarted offline open its encrypted store with no PIN. Any response the server marks no-store is not cached either.
const networkOnly = (url) => { const p = new URL(url).pathname; return p.startsWith('/pos/offline/') || p === '/pos/unlock' || p.startsWith('/pos/device'); };
const cacheable = (res) => res.ok && !/no-store/i.test(res.headers.get('Cache-Control') || '');
self.addEventListener('fetch', (e) => {
    if (e.request.method !== 'GET') return; // never cache order mutations
    if (networkOnly(e.request.url)) return; // the browser fetches it; offline it fails, and the till stays locked
    if (e.request.mode === 'navigate' && isShell(e.request.url)) {
        e.respondWith(fetch(e.request).then((res) => { keepShell(res).catch(() => {}); return res; })
            .catch(() => offlineShell(e.request.url, e.request)));
        return;
    }
    e.respondWith(
        fetch(e.request).then((res) => {
            if (cacheable(res)) {
                const copy = res.clone();
                caches.open(CACHE).then((c) => c.put(e.request, copy)).catch(() => {});
            }
            return res;
        }).catch(async () => (await caches.match(e.request))
            || (new URL(e.request.url).pathname.startsWith('/assets/') ? await caches.match(e.request, { ignoreSearch: true }) : null) // a ?v= the install precache did not see
            || Response.error())
    );
});
JS;
        $js = str_replace(['__OFFLINE__', '__OFFLINE_ALL__'], [$offline, $offlineAll], $js);

        return response($js, 200, ['Content-Type' => 'application/javascript', 'Service-Worker-Allowed' => '/pos']);
    }

    // ── Floor map live mode (P4) ──────────────────────────────────────────────

    /** Tables with live occupancy derived from active (unpaid, un-held) orders. */
    public function floor()
    {
        [$staff, $store] = $this->context();
        $branchId = $this->activeBranchId($store, $staff);

        // Same ScopesToBranch rule as tables() + the dashboard Floor Map — the live
        // floor never spans other branches (was unscoped, showing every branch's tables).
        $tables = $this->scopeToBranch(
            StoreTable::where('store_id', $store->id), $store->id, $branchId
        )->get();

        $open = $this->scopeToBranch(
            Order::where('store_id', $store->id)
                ->whereNull('held_at')->where('is_paid', 0)
                ->live() // T1: unreleased scheduled orders don't occupy the floor yet
                ->whereIn('status', ['new', 'preparing', 'ready', 'pending'])
                ->whereNotNull('table_number'),
            $store->id, $branchId
        )->get()->keyBy('table_number');

        $data = $tables->map(function ($t) use ($open) {
            $order = $open[$t->table_no] ?? null;

            return [
                'id' => $t->id,
                'table_no' => $t->table_no,
                'seats' => $t->seats,
                'map_x' => $t->map_x,
                'map_y' => $t->map_y,
                'shape' => $t->shape,
                'floor_label' => $t->floor_label,
                'state' => $order ? 'occupied' : 'free',
                'order_id' => $order?->id,
                'order_total' => $order ? $order->total : null,
            ];
        });

        return response()->json($data->values());
    }

    /**
     * Transfer an order to another table (both scoped to this restaurant).
     *
     * PARITY-RUN T4 rewrote the body: the move itself is unchanged in effect,
     * but it now runs through TableMergeService::transfer(), which refuses a
     * settled order, refuses a cross-branch move, refuses while a split bill is
     * open, lands on the PRIMARY when the chosen table is a merged secondary,
     * and writes the who/when/from/to audit row. Nothing about the order's
     * money is touched, so Order::getTotalAttribute() is identical afterwards.
     *
     * Deliberately NOT feature-gated: transferring an order predates
     * `table_merge` and pulling it behind the kill-switch would remove a
     * capability stores already rely on. The MERGE endpoints below are gated.
     */
    public function transfer(Request $request, int $orderId)
    {
        [$staff, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);
        $data = $request->validate(['table_no' => 'required']);

        $table = StoreTable::where('store_id', $store->id)
            ->where('table_no', $data['table_no'])->firstOrFail();

        try {
            $order = TableMergeService::transfer($order, $table, ['staff_id' => $staff?->id, 'user_id' => auth('web')->id()]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->detail($order));
    }

    /* =====================================================================
     | PARITY-RUN T4 — table merge / order transfer (register half)
     |
     | The owner's half is User\TableMergeController (dashboard floor map),
     | which can carry `feature:table_merge` middleware. These endpoints cannot:
     | they run on the STAFF guard, whose user is not the buyer, so the gate
     | asks about the STORE OWNER via TableMergeService::enabled() — the same
     | in-controller shape T1's print endpoints use.
     |
     | All three delegate to TableMergeService. A merge moves EXISTING orders;
     | it never creates one (orders come from OrderApiService alone) and never
     | re-prices anything.
     * ================================================================== */

    /** Live merge groups for the register's table screen. */
    public function mergeState()
    {
        [$staff, $store] = $this->context();
        $branchId = $this->activeBranchId($store, $staff);

        if (! TableMergeService::enabled((int) $store->id)) {
            return response()->json(['enabled' => false, 'groups' => []]);
        }

        return response()->json([
            'enabled' => true,
            'groups' => $this->mergePayload((int) $store->id, $branchId),
        ]);
    }

    /** Merge the selected tables onto one bill. */
    public function mergeTables(Request $request)
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'orders'), 403, 'Manager permission required.');
        abort_unless(TableMergeService::enabled((int) $store->id), 403, 'Table merge is not enabled for this store.');

        $branchId = $this->activeBranchId($store, $staff);
        $data = $request->validate([
            'primary_table_id' => 'required|integer',
            'table_ids' => 'required|array|min:1',
            'table_ids.*' => 'integer',
        ]);

        // P6-180: a branch register only merges its own branch's tables.
        if ($branchId !== null) {
            $primary = StoreTable::where('store_id', $store->id)->find((int) $data['primary_table_id']);
            abort_if($primary && TableMergeService::tableBranchId($primary)
                !== TableMergeService::effectiveBranchId((int) $store->id, $branchId), 404);
        }

        try {
            $group = TableMergeService::merge(
                (int) $store->id,
                (int) $data['primary_table_id'],
                $data['table_ids'],
                ['staff_id' => $staff?->id, 'user_id' => auth('web')->id()]
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'group' => $this->mergeGroupPayload($group),
            'groups' => $this->mergePayload((int) $store->id, $branchId),
        ], 201);
    }

    /** Release a merge from the register. */
    public function unmergeTables(Request $request, int $groupId)
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'orders'), 403, 'Manager permission required.');
        abort_unless(TableMergeService::enabled((int) $store->id), 403, 'Table merge is not enabled for this store.');

        $branchId = $this->activeBranchId($store, $staff);
        $group = TableMergeGroup::where('id', $groupId)
            ->where('store_id', $store->id)->firstOrFail();
        // P6-180: a branch register only releases its own branch's parties.
        abort_if($branchId !== null && TableMergeService::effectiveBranchId((int) $store->id, $group->branch_id)
            !== TableMergeService::effectiveBranchId((int) $store->id, $branchId), 404);

        TableMergeService::unmerge($group, ['staff_id' => $staff?->id, 'user_id' => auth('web')->id()], 'manual');

        return response()->json(['released' => true, 'groups' => $this->mergePayload((int) $store->id, $branchId)]);
    }

    /** @return array<int, array<string, mixed>> */
    private function mergePayload(int $storeId, ?int $branchId): array
    {
        return TableMergeService::activeGroups($storeId, $branchId)
            ->map(fn ($g) => $this->mergeGroupPayload($g))->values()->all();
    }

    /** @return array<string, mixed> */
    private function mergeGroupPayload(TableMergeGroup $group): array
    {
        $group->loadMissing('members');
        $tables = StoreTable::whereIn('id', $group->members->pluck('table_id'))->get(['id', 'table_no', 'seats']);
        $bill = TableMergeService::groupBill($group);

        return [
            'id' => (int) $group->id,
            'primary_table_id' => (int) $group->primary_table_id,
            'table_ids' => $group->members->pluck('table_id')->map(fn ($id) => (int) $id)->values()->all(),
            'label' => TableMergeService::label($group, $tables->pluck('table_no', 'id')->all()),
            'seats' => (int) $tables->sum('seats'),
            'order_count' => $bill['order_count'],
            'orders' => $bill['orders'],
            // Server-side sum of Order::getTotalAttribute() — the price authority.
            'total' => $bill['total'],
            'amount_due' => $bill['amount_due'],
        ];
    }

    /**
     * P3: printable 80mm receipt (or kitchen ticket with ?format=kitchen). Grand
     * total is Order::getTotalAttribute so it can never drift from the real total.
     *
     * P6-272: the second and later prints (orders.receipt_print_count, or ?copy=1) are a COPY. Refund adjustments attached to the order are rendered as their own
     * negative lines plus a "Net paid" row, and ?adjustment=<id> renders a single
     * refund receipt (title, amount, reason) for one adjustment row.
     */
    public function receipt(Request $request, int $orderId)
    {
        [, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);

        // Localise the printout. The receipt route carries no locale prefix, so it
        // rendered in the site default (Arabic) regardless of the language chosen in
        // the register. Honour an explicit ?lang=, otherwise the register's chosen
        // menu language (Quick_user_lang_code cookie, resolved by menuLanguages()).
        $lang = (string) $request->query('lang', '');
        if ($lang !== '' && Language::where('code', $lang)->exists()) {
            app()->setLocale($lang);
        } else {
            $this->menuLanguages($store);
        }

        $kitchen = $request->query('format') === 'kitchen';
        $options = store_options($store->id);
        $station = (string) $this->posSettings($store, $order->branch_id)['station_name']; // P1

        // P6-272: refund receipt sub-mode (?adjustment=<id>) — single adjustment,
        // must belong to this order and be of type refund, else 404. Loads only
        // what's needed for the minimal receipt; no item list, no copy detection.
        if (($aid = (int) $request->query('adjustment', 0)) > 0) {
            $adjustment = OrderAdjustment::where('id', $aid)
                ->where('order_id', $order->id)
                ->where('type', 'refund')
                ->firstOrFail(); // 404

            return view('staff.pos.receipt', compact('order', 'store', 'options', 'station', 'adjustment'));
        }

        $order->load('items.menu', 'items.itemExtras.extra', 'items.variant');

        // P6-272: every print after the first is a COPY (tax rules). The count lives on the order row, bumped
        // under a row lock, so two simultaneous first prints yield exactly one original. Kitchen tickets don't count.
        $isCopy = (string) $request->query('copy', '') === '1';
        if (! $kitchen) {
            $before = DB::transaction(function () use ($order) {
                $n = (int) DB::table('orders')->where('id', $order->id)->lockForUpdate()->value('receipt_print_count');
                DB::table('orders')->where('id', $order->id)->update(['receipt_print_count' => $n + 1, 'receipt_printed_at' => now()]);

                return $n;
            });
            $isCopy = $isCopy || $before > 0;
        }

        // P6-272: refund adjustments on this order. OrderAdjustment::$amount is
        // already a major-units string in the order's currency.
        $refunds = OrderAdjustment::where('order_id', $order->id)
            ->where('type', 'refund')
            ->orderBy('id')
            ->get();

        return view($kitchen ? 'staff.pos.ticket' : 'staff.pos.receipt', compact(
            'order', 'store', 'options', 'station', 'isCopy', 'refunds'
        ));
    }

    /* ── PARITY-RUN T1: direct printing ────────────────────────────────────────
       The register is the only page that can reach a printer: it holds the Web
       Serial/WebUSB device handle, it runs on the Android tablet that has RawBT,
       and it sits on the same machine as the desktop LAN bridge. So the server
       renders and parks jobs, and these four endpoints are how the page collects
       and reports them.

       Gated with FeatureAccess::storeAllows() inside the controller rather than
       `feature:` middleware — the register is reachable by the STAFF guard, whose
       user is not the buyer, and the kill-switch subject must be the store owner.
    */

    /** What this register should pair with: profiles, transports, bridge token. */
    public function printConfig(Request $request)
    {
        [$staff, $store] = $this->context();
        $branchId = $this->activeBranchId($store, $staff);

        if (! PrintDispatcher::enabled((int) $store->id)) {
            return response()->json(['enabled' => false, 'profiles' => []]);
        }

        $profiles = PrinterProfile::where('store_id', $store->id)
            ->where('active', true)->orderBy('position')->orderBy('id')->get()
            ->filter(fn ($p) => $p->servesBranch($branchId ? (int) $branchId : null))
            ->values();

        // D8: with a terminal in play, resolve the effective printer (terminal
        // override → branch → store default) so the register defaults to it.
        $terminal = null;
        if ($tid = (int) $request->query('terminal_id', 0)) {
            $terminal = PosTerminal::where('id', $tid)->where('store_id', $store->id)->active()->first();
        }
        $preferred = PrinterProfile::resolveForContext((int) $store->id, $branchId ? (int) $branchId : null, $terminal);

        return response()->json([
            'enabled' => true,
            'token' => PrintSettings::pairingToken((int) $store->id),
            'preferred_profile_id' => $preferred?->id, // D8
            'profiles' => $profiles->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'transport' => $p->transport,
                'target' => $p->target,
                'bridge_url' => $p->transport === 'bridge' ? $p->bridgeBase() : null,
                'width_mm' => (int) $p->width_mm,
                'copies' => (int) $p->copies,
                'events' => (array) ($p->events ?: []),
                'auto_print' => (bool) $p->auto_print,
            ])->values(),
        ]);
    }

    /**
     * Claim the pending jobs this register can deliver. A claim that is never
     * acked (tab closed mid-print) becomes collectable again after 60 s, and
     * anything older than printing.job_ttl_minutes is abandoned rather than
     * printed late — lunch tickets at dinner are worse than no tickets.
     */
    public function printJobs(Request $request)
    {
        [$staff, $store] = $this->context();
        abort_unless(PrintDispatcher::enabled((int) $store->id), 403, 'Printing is not enabled for this store.');

        $branchId = $this->activeBranchId($store, $staff);
        $ttl = (int) config('printing.job_ttl_minutes');

        // P6-238: sweep per job with a conditional update (a job a concurrent register just acked
        // is left alone), route each timed-out ticket to its fallback printer, and report it so the
        // register shows "not printed" instead of the ticket vanishing silently.
        $expired = [];
        $stale = PrintJob::where('store_id', $store->id)
            ->whereIn('status', ['pending', 'claimed'])
            ->where('created_at', '<', now()->subMinutes($ttl))
            ->orderBy('id')->limit(100)->get();
        foreach ($stale as $old) {
            $swept = PrintJob::where('id', $old->id)->whereIn('status', ['pending', 'claimed'])
                ->update(['status' => 'failed', 'error' => 'etimedout']);
            if (! $swept) {
                continue;
            }
            $old->refresh();
            $fb = PrintDispatcher::requeueToFallback($old);
            Log::warning('[print] ticket timed out unprinted', ['job_id' => $old->id, 'order_id' => $old->order_id, 'fallback_job_id' => $fb?->id]);
            $expired[] = ['id' => $old->id, 'order_id' => $old->order_id, 'event' => $old->event, 'fallback_job_id' => $fb?->id];
        }

        $candidates = PrintJob::where('store_id', $store->id)
            ->where(function ($q) {
                $q->where('status', 'pending')
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'claimed')->where('claimed_at', '<', now()->subSeconds(60));
                    });
            })
            ->when($branchId, fn ($q) => $q->where(function ($q2) use ($branchId) {
                $q2->whereNull('branch_id')->orWhere('branch_id', $branchId);
            }))
            ->orderBy('id')->limit(20)->get();

        // P6-238: atomic per-job claim - only the register whose conditional UPDATE matches a row
        // gets the ticket; a second polling register matches zero rows and skips it.
        $jobs = $candidates->filter(function ($job) {
            return PrintJob::where('id', $job->id)
                ->where(function ($q) {
                    $q->where('status', 'pending')
                        ->orWhere(function ($q2) {
                            $q2->where('status', 'claimed')->where('claimed_at', '<', now()->subSeconds(60));
                        });
                })
                ->update(['status' => 'claimed', 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]) === 1;
        })->values();

        return response()->json(['jobs' => $jobs->map(fn ($j) => [
            'id' => $j->id,
            'job_key' => $j->job_key,
            'profile_id' => $j->profile_id,
            'event' => $j->event,
            'transport' => $j->transport,
            'target' => $j->target,
            'width_mm' => (int) $j->width_mm,
            'copies' => (int) $j->copies,
            'order_id' => $j->order_id,
            'payload' => $j->payload,
        ])->values(), 'expired' => $expired]);
    }

    /** The register reports what actually happened at the device. */
    public function printAck(Request $request, int $jobId)
    {
        [, $store] = $this->context();
        $data = $request->validate([
            'ok' => 'required|boolean',
            'error' => 'nullable|string|max:40',
        ]);

        $job = PrintJob::where('id', $jobId)->where('store_id', $store->id)->firstOrFail();

        // P6-238: only a CLAIMED job can be acked. A late ack on a done / timed-out-failed job must not
        // flip it (and re-print on the fallback); the conditional UPDATE is the guard.
        $fallbackJobId = null;
        if ($data['ok']) {
            $won = PrintJob::where('id', $job->id)->where('status', 'claimed')
                ->update(['status' => 'done', 'error' => null, 'completed_at' => now()]);
        } else {
            $err = (string) ($data['error'] ?? 'internal');
            $won = PrintJob::where('id', $job->id)->where('status', 'claimed')
                ->update([
                    'status' => 'failed',
                    'error' => in_array($err, PrintJob::ERRORS, true) ? $err : 'internal',
                    'attempts' => DB::raw('attempts + 1'),
                ]);
        }
        if (! $won) {
            return response()->json(['ok' => false, 'error' => 'not_claimed', 'status' => $job->fresh()->status], 409);
        }
        $job->refresh();
        if (! $data['ok']) {
            // D3: re-route the failed ticket to the profile's fallback printer once.
            $fallbackJobId = PrintDispatcher::requeueToFallback($job)?->id;
        }

        return response()->json(['ok' => true, 'status' => $job->status, 'fallback_job_id' => $fallbackJobId]);
    }

    /** Queue a test page for one profile, from the register's printer menu. */
    public function printTest(int $profileId)
    {
        [, $store] = $this->context();
        abort_unless(PrintDispatcher::enabled((int) $store->id), 403, 'Printing is not enabled for this store.');

        $profile = PrinterProfile::where('id', $profileId)
            ->where('store_id', $store->id)->firstOrFail();

        $job = PrintDispatcher::testPrint($profile);

        return response()->json(['ok' => (bool) $job, 'job_id' => $job?->id]);
    }

    /** Manual print of an existing order to a chosen profile (receipt or KOT). */
    public function printOrder(Request $request, int $orderId)
    {
        [, $store] = $this->context();
        abort_unless(PrintDispatcher::enabled((int) $store->id), 403, 'Printing is not enabled for this store.');

        $data = $request->validate([
            'profile_id' => 'required|integer',
            'event' => 'nullable|in:new_order,paid,kot',
        ]);

        $order = $this->ownOrder($orderId, $store);
        $profile = PrinterProfile::where('id', $data['profile_id'])
            ->where('store_id', $store->id)->firstOrFail();

        $job = PrintDispatcher::printOrderTo($profile, $order, $data['event'] ?? 'new_order');

        return response()->json(['ok' => (bool) $job, 'job_id' => $job?->id]);
    }

    // ── Shifts (P2) ───────────────────────────────────────────────────────────

    public function openShift(Request $request)
    {
        [$staff, $store] = $this->context();
        // D2: opening a drawer is its own accountable act — gate it on pos_drawer_open
        // (manager + cashier by default). Owner (no staff row) is always permitted.
        abort_unless($this->can($staff, 'pos_drawer_open'), 403, 'Drawer permission required.');
        $data = $request->validate(['opening_float' => 'nullable|numeric|min:0',
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $store->id)]]); // P6-59b

        try {
            $shift = PosService::openShift($store, $staff, $data['branch_id'] ?? null, (float) ($data['opening_float'] ?? 0));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['shift_id' => $shift->id, 'opened_at' => $shift->opened_at], 201);
    }

    /**
     * D2 — hand the open drawer to another cashier: closes the current shift with the
     * counted cash (over/short recorded) and opens a fresh shift for `to_staff_id`
     * with that amount as the opening float, chained via handoff_from_shift_id. Same
     * pos_drawer_open gate as opening; a hidden idempotency_key absorbs a double-tap.
     */
    public function handoffShift(Request $request): JsonResponse
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_drawer_open'), 403, 'Drawer permission required.');

        $data = $request->validate([
            'to_staff_id' => 'required|integer',
            'counted_cash' => 'required|numeric|min:0',
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        // The receiving cashier must be an active member of this same restaurant.
        $toStaff = StaffMember::where('id', $data['to_staff_id'])
            ->where('store_id', $store->id)->where('active', 1)->first();
        abort_unless($toStaff !== null, 422, 'Unknown staff member.');

        $branchId = $this->activeBranchId($store, $staff);

        try {
            $result = PosService::handoffShift($store, $toStaff, (float) $data['counted_cash'], $branchId, $data['idempotency_key'] ?? null);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'shift_id' => $result['shift']->id,
            'opened_at' => $result['shift']->opened_at,
            'closed' => $result['closed'],
        ], 201);
    }

    public function closeShift(Request $request, int $shiftId)
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_shift_close'), 403, 'Manager permission required.');

        $shift = PosShift::where('id', $shiftId)->where('store_id', $store->id)->firstOrFail();
        $data = $request->validate(['counted_cash' => 'required|numeric|min:0']);
        // P6-332: the service locks the row and re-checks; a shift another close got to
        // first keeps its count — this caller is told so and shown that first Z-report.
        $zreport = $shift->status === 'closed'
            ? PosService::zReport($shift) + ['already_closed' => true]
            : PosService::closeShift($shift, (float) $data['counted_cash']);
        if (! empty($zreport['already_closed'])) {
            return response()->json(['message' => 'Shift already closed.', 'zreport' => $zreport], 422);
        }

        return response()->json($zreport);
    }

    /** The branch's open shift (or null) — powers the Manage panel's Shift tab. */
    public function currentShift()
    {
        [$staff, $store] = $this->context();
        $branchId = $this->activeBranchId($store, $staff);
        $shift = PosService::currentShift((int) $store->id, $branchId);

        return response()->json(['shift' => $shift ? [
            'shift_id' => $shift->id,
            'opened_at' => optional($shift->opened_at)->toIso8601String(),
            'opening_float' => (float) $shift->opening_float,
        ] : null]);
    }

    public function zReport(int $shiftId)
    {
        [, $store] = $this->context();
        $shift = PosShift::where('id', $shiftId)->where('store_id', $store->id)->firstOrFail();

        return response()->json(PosService::zReport($shift));
    }

    // ── Voids / refunds (P2, manager-gated) ───────────────────────────────────

    public function void(Request $request, int $orderId)
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_refund'), 403, 'Manager permission required.');
        $order = $this->ownOrder($orderId, $store);
        abort_if((bool) $order->is_paid, 422, 'Paid order — use refund, not void.');
        // A split bill collects share by share: it can hold PAID cash shares while the
        // order itself is still is_paid=0 (status 'partial'). Those shares are real money
        // in the drawer, but only become visible to reconciliation once the whole order
        // settles (markSharePaid stamps payment_method='split'; expectedCash counts the
        // cash shares of 'split' orders only). Voiding a partial split cancels the order,
        // so that already-collected cash is orphaned off the till forever. Refuse — the
        // paid shares must be refunded or the bill finished settling first.
        abort_if(
            CheckPayment::whereHas('tableCheck', fn ($q) => $q->where('order_id', $order->id))
                ->where('status', 'paid')->exists(),
            422,
            'This bill already has paid split shares — refund those or finish settling it, do not void.'
        );

        $data = $request->validate(['reason' => 'required|string|max:190', 'order_item_id' => 'nullable|integer']);
        $adj = PosService::void($order, $staff, $data['reason'], $data['order_item_id'] ?? null);

        return response()->json(['adjustment_id' => $adj->id, 'type' => 'void'], 201);
    }

    public function refund(Request $request, int $orderId)
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_refund'), 403, 'Manager permission required.');
        $order = $this->ownOrder($orderId, $store);

        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|in:cash,card,online,other',
            'reason' => 'required|string|max:190',
            // Mirror refundItems: a per-render key absorbs a double-submit / offline-replay.
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        $adj = PosService::refund($order, $staff, (float) $data['amount'], $data['method'], $data['reason'], $data['idempotency_key'] ?? null);

        return response()->json(['adjustment_id' => $adj->id, 'type' => 'refund'], 201);
    }

    /**
     * D1 — itemised refund. Same `pos_refund` gate as the amount refund; a hidden
     * per-render `idempotency_key` absorbs a double-submit. Each line carries the
     * pinned fields (order_item_id, qty, amount); the service caps per-line qty and
     * the total against the money collected.
     */
    public function refundItems(Request $request, int $orderId): JsonResponse
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_refund'), 403, 'Manager permission required.');
        $order = $this->ownOrder($orderId, $store);

        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.order_item_id' => 'required|integer',
            'lines.*.qty' => 'required|integer|min:1',
            'lines.*.amount' => 'required|numeric|min:0',
            'method' => 'nullable|in:cash,card,online,other',
            'reason' => 'required|string|max:190',
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        $adj = PosService::refundItems(
            $order,
            $data['lines'],
            $data['reason'],
            $staff,
            $data['method'] ?? 'cash',
            $data['idempotency_key'] ?? null
        );

        return response()->json(['adjustment_id' => $adj->id, 'type' => 'refund'], 201);
    }

    /**
     * D1 — per-unit subtotal contribution of one line (base/variant/snapshot price
     * + extras + choice-group options), mirroring Order::computeMoney()'s per-item
     * math. Tax/discount are order-level, so this is the default refund value the
     * register pre-fills per unit; the server still caps the total at amount_due.
     */
    private function lineUnitValue(OrderItem $it, int $storeId): float
    {
        $unit = 0.0;
        if ($it->menu) {
            $price = $it->menu->price;
            if ($it->variation && $it->variant) {
                $price = $it->variant->price;
            }
            if ($it->unit_price !== null) {
                $price = $it->unit_price;
            }
            $unit += (float) $price;
            foreach ($it->itemExtras as $ex) {
                $exPrice = $ex->price ?? optional($ex->extra)->price;
                if ($exPrice !== null) {
                    $unit += (float) $exPrice;
                }
            }
        }
        foreach ((array) $it->options as $grp) {
            foreach ((array) ($grp['selected'] ?? []) as $opt) {
                $unit += (float) ($opt['price'] ?? 0);
            }
        }

        return Money::round($unit, money_decimals(store_currency($storeId)));
    }

    /**
     * D5 — flag / unflag an order as RUSH. Not money-adjacent, so no idempotency
     * key; any register user (the `pos` gate in context()) may prioritise a ticket.
     * With `is_rush` in the body it sets that value; without, it flips the flag.
     */
    public function toggleRush(Request $request, int $orderId): JsonResponse
    {
        [, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);
        $request->validate(['is_rush' => 'nullable|boolean']);

        $order->is_rush = $request->has('is_rush') ? $request->boolean('is_rush') : ! (bool) $order->is_rush;
        $order->save();

        return response()->json(['id' => $order->id, 'is_rush' => (bool) $order->is_rush]);
    }

    private function detail(Order $order): array
    {
        return [
            'id' => $order->id,
            'source' => $order->source,
            'type' => $order->type,
            'status' => $order->status,
            'is_paid' => (bool) $order->is_paid,
            'payment_method' => $order->payment_method,
            'amount_tendered' => $order->amount_tendered,
            'change_due' => $order->change_due,
            'held' => $order->held_at !== null,
            'is_rush' => (bool) $order->is_rush, // D5
            'total' => $order->total,
            // STF-013: what has already been refunded, so the register can cap
            // the refund form at the remaining amount.
            'refunded_total' => (float) OrderAdjustment::where('order_id', $order->id)
                ->where('type', 'refund')->sum(DB::raw(OrderAdjustment::minorMoneySql('amount'))),
            'track_token' => $order->track_token,
            // T4-1: orders-hub list fields (additive).
            'time' => optional($order->created_at)->toIso8601String(),
            'table_number' => $order->table_number,
            'covers' => $order->covers,
            'item_count' => (int) $order->items->sum('quantity'),
            'note' => $order->message, // T4-2: hold note
            // FEATURES-5 Track C: customer voice note (authorized streaming URL).
            'voice_note_url' => $order->voiceNote ? route('stores.orders.voiceNote', $order->id) : null,
            'voice_note_duration' => $order->voiceNote?->duration_seconds,
            // ZERO-TOUCH Track B3: transcript text under the audio player.
            'voice_note_transcript' => $order->voiceNote?->transcript,
        ];
    }

    /**
     * T4-3: full order detail (lines w/ variant/extras/options/notes) for the
     * orders-hub detail panel. Tenant-scoped via ownOrder.
     */
    public function orderDetail(int $orderId)
    {
        [, $store] = $this->context();
        $order = $this->ownOrder($orderId, $store);
        $order->load(['items.menu', 'items.variant', 'items.itemExtras.extra', 'voiceNote']);

        // D1: itemised refund needs to know how much of each line already came back.
        $refundedByItem = OrderRefundItem::where('order_id', $order->id)
            ->selectRaw('order_item_id, SUM(qty) as q')->groupBy('order_item_id')
            ->pluck('q', 'order_item_id');

        return response()->json($this->detail($order) + [
            'lines' => $order->items->map(fn ($it) => [
                // D1: order_item_id + per-unit value + remaining qty drive the item-refund picker.
                'id' => $it->id,
                'line_value' => $this->lineUnitValue($it, (int) $order->store_id),
                'refunded_qty' => (int) ($refundedByItem[$it->id] ?? 0),
                // T6: displayName() names a combo line from its snapshot (no menu row).
                'name' => $it->displayName() ?: '—',
                'qty' => (int) $it->quantity,
                'variant' => $it->variant?->name,
                'extras' => $it->itemExtras->map(fn ($e) => $e->extra?->getRawOriginal('title'))->filter()->values(),
                'options' => collect((array) $it->options)->map(fn ($g) => [
                    'title' => $g['title'] ?? '',
                    'selected' => collect($g['selected'] ?? [])->pluck('option')->join(', '),
                ])->values(),
                'note' => $it->order_note,
            ])->values(),
        ]);
    }

    /**
     * T4-1: recent POS orders for the orders-hub history tab. Tenant-scoped slim
     * list (newest first). POS channel only — the register's own tickets.
     */
    public function recentOrders(Request $request)
    {
        [, $store] = $this->context();

        $orders = Order::where('store_id', $store->id)
            ->where('source', 'pos')
            ->whereNull('held_at')
            ->with(['items.menu', 'items.variant', 'items.itemExtras.extra']) // extras: $o->total walks them
            ->orderByDesc('id')->limit(30)->get();

        return response()->json($orders->map(fn ($o) => [
            'id' => $o->id,
            'time' => optional($o->created_at)->toIso8601String(),
            'type' => $o->type,
            'table_number' => $o->table_number,
            'total' => (float) $o->total,
            'status' => $o->status,
            'is_paid' => (bool) $o->is_paid,
            'item_count' => (int) $o->items->sum('quantity'),
        ])->values());
    }

    /**
     * Orders that originated OUTSIDE the register (online storefront, WhatsApp,
     * delivery aggregators, API…) — i.e. source is anything but 'pos' (legacy NULL
     * counts as external too). Branch-scoped via ScopesToBranch so the register only
     * surfaces incoming orders for its own branch. Lets cashiers see + act on web
     * orders without leaving the POS.
     */
    public function incomingOrders(Request $request)
    {
        [$staff, $store] = $this->context();
        $branchId = $this->activeBranchId($store, $staff);

        $orders = $this->scopeToBranch(
            Order::where('store_id', $store->id)
                ->where(fn ($q) => $q->where('source', '!=', 'pos')->orWhereNull('source'))
                ->whereNull('held_at')
                ->with(['items.menu', 'items.variant', 'items.itemExtras.extra']), // extras: $o->total walks them
            $store->id, $branchId
        )->orderByDesc('id')->limit(30)->get();

        return response()->json($orders->map(fn ($o) => [
            'id' => $o->id,
            'time' => optional($o->created_at)->toIso8601String(),
            'type' => $o->type,
            'table_number' => $o->table_number,
            'total' => (float) $o->total,
            'status' => $o->status,
            'is_paid' => (bool) $o->is_paid,
            'item_count' => (int) $o->items->sum('quantity'),
            'source' => $o->source ?: 'online',
            // D7 (integrations pack): a human channel name for the order-card badge.
            // Aggregator orders read "Deliverect"/"Talabat"/…; native orders fall back
            // to a title-cased source. `is_channel` lets the card style the two apart.
            'source_label' => $o->channelLabel() ?? ucfirst((string) ($o->source ?: 'online')),
            'is_channel' => $o->channelLabel() !== null,
        ])->values());
    }

    /* =====================================================================
     | PARITY-RUN T3 — staff approval queue (verification mode staff_approve)
     |
     | A dine-in order placed while the store runs `staff_approve` lands as
     | Order::STATUS_PENDING_APPROVAL. Order::scopeLive() keeps it out of every
     | kitchen/floor query and KdsService::syncOrderStatus() refuses to derive
     | over it, so nothing is cooked until one of these two endpoints runs.
     |
     | Approve = hand it to the KDS ('new', the status the boards poll for).
     | Reject  = 'cancelled' with a stamp, exactly like every other cancel path.
     | Both go through Eloquent save(), so the Order::booted() status choke point
     | still fires (shopper push / WhatsApp lifecycle).
     * ================================================================== */

    /** Pending-approval queue for the register badge + list. */
    public function pendingApprovals(Request $request)
    {
        [$staff, $store] = $this->context();
        $branchId = $this->activeBranchId($store, $staff);

        $orders = $this->scopeToBranch(
            Order::where('store_id', $store->id)
                ->where('status', Order::STATUS_PENDING_APPROVAL)
                ->with(['items.menu', 'items.variant', 'items.itemExtras.extra']), // extras: $o->total walks them
            $store->id, $branchId
        )->orderBy('id')->limit(50)->get();

        return response()->json([
            'mode' => TableSessionService::mode($store->id),
            'orders' => $orders->map(fn ($o) => [
                'id' => $o->id,
                'time' => optional($o->created_at)->toIso8601String(),
                'type' => $o->type,
                'table_number' => $o->table_number,
                'customer' => $o->customer_name,
                'total' => (float) $o->total,
                'item_count' => (int) $o->items->sum('quantity'),
            ])->values(),
        ]);
    }

    /** Approve one parked order — it enters the KOT flow now. */
    public function approveOrder(Request $request, int $id)
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'orders'), 403, 'Manager permission required.');

        $order = $this->ownOrder($id, $store);
        abort_unless($order->status === Order::STATUS_PENDING_APPROVAL, 422, 'This order is not awaiting approval.');

        $order->status = 'new';
        $order->save();

        log_activity('order.approved', $order, ['order_id' => $order->id, 'via' => 'pos'], $order->store_id);

        return response()->json(['id' => $order->id, 'status' => $order->status]);
    }

    /** Reject one parked order — cancelled, never cooked. */
    public function rejectOrder(Request $request, int $id)
    {
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'orders'), 403, 'Manager permission required.');

        $order = $this->ownOrder($id, $store);
        abort_unless($order->status === Order::STATUS_PENDING_APPROVAL, 422, 'This order is not awaiting approval.');

        $order->status = 'cancelled';
        $order->cancelled_at = now();
        $order->save();

        // A pending-approval order already deducted stock (and may hold gift-card /
        // wallet / loyalty tender) at creation — P6-205: the shared cancel routine.
        app(OrderCancellations::class)->reverse($order);

        log_activity('order.rejected', $order, ['order_id' => $order->id, 'via' => 'pos'], $order->store_id);

        return response()->json(['id' => $order->id, 'status' => $order->status]);
    }

    /* ---------------- P5-21: SoftPOS card payments (Payments TerminalPayments contract) ---------------- */

    private function terminalPayments(): TerminalPayments
    {
        $t = app(TerminalPayments::class);
        abort_unless($t->anyEnabled(), 404);

        return $t;
    }

    /** @param \Closure(): array<string, mixed> $call */
    private function terminalAnswer(\Closure $call): JsonResponse
    {
        try {
            return response()->json($call())->header('Cache-Control', 'no-store, private');
        } catch (TerminalRejected $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], $e->status);
        }
    }

    /** Charge a card on this till (the store's own acquirer, store currency); idempotent on client_uuid. */
    public function terminalCharge(Request $request): JsonResponse
    {
        $terminal = $this->terminalPayments();
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos'), 403);
        $data = $request->validate(['client_uuid' => ['required', 'uuid'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'order_id' => ['nullable', 'integer'], 'tip_minor' => ['nullable', 'integer', 'min:0', 'max:100000000']]);
        $currency = (string) store_currency((int) $store->id);
        $orderId = isset($data['order_id']) ? (int) $data['order_id'] : null;
        $tip = isset($data['tip_minor']) ? (int) $data['tip_minor'] : null;
        if ($orderId !== null) {
            // P5-26: the card pays this order — what is due on it now (with the new tip, if any), nothing else
            $order = $this->ownOrder($orderId, $store);
            $due = Money::minorFromMajor((string) $order->amount_due, $currency)
            - ($tip !== null ? Money::minorFromMajor((string) ($order->tip_amount ?? 0), $currency) - $tip : 0);
            if (! $order->is_paid && $order->status !== 'cancelled' && (int) $data['amount_minor'] !== $due) {
                return response()->json(['message' => ___('The card amount must be the amount due on the order.'), 'code' => 'amount_mismatch', 'due_minor' => $due], 422);
            }
            if ($order->status === 'cancelled') {
                return response()->json(['message' => ___('This order is cancelled.'), 'code' => 'order_closed'], 422);
            }
        }

        return $this->terminalAnswer(fn () => $terminal->charge($store, strtolower($data['client_uuid']), (int) $data['amount_minor'], $currency, $orderId, $tip));
    }

    /**
     * P5-26: a card refund / void the provider confirmed is reversed on the settled order through the POS refund path
     * (drawer shift, loyalty, GL, refund webhook, ZATCA credit note), keyed by the till's UUID so a resend books it once.
     * P5-28: when the POS refuses it (the refundable amount already went to a cash refund, the order is no longer paid)
     * the money still left the acquirer — it becomes one store-scoped manager exception per till key, and the till is told.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $charge
     * @return array<string, mixed> the extra answer fields for the till (empty when booked or nothing to book)
     */
    private function terminalReverse(array $result, array $charge, ?StaffMember $staff, Store $store, string $reason): array
    {
        if (! in_array($result['state'] ?? null, ['refunded', 'voided'], true) || empty($charge['order_id']) || empty($charge['settled'])) {
            return [];
        }
        $order = $this->ownOrder((int) $charge['order_id'], $store);
        $uuid = (string) $result['client_uuid'];
        $existing = PosCardReversalException::query()->where('store_id', $store->id)->where('client_uuid', $uuid)->first();
        if ($existing === null) {
            try {
                if (! $order->is_paid) {
                    throw ValidationException::withMessages(['amount' => ___('Order is not paid — void it instead of refunding.')]);
                }
                PosService::refund($order, $staff, Money::majorFromMinor((int) $result['amount_minor'], (string) $result['currency']), 'card', $reason, 'softpos:'.$uuid);

                return [];
            } catch (ValidationException $e) {
                $why = (string) collect($e->errors())->flatten()->first();
            }
            try {
                $existing = PosCardReversalException::create(['store_id' => $store->id, 'order_id' => $order->id, 'client_uuid' => $uuid,
                    'charge_uuid' => (string) ($charge['client_uuid'] ?? ''), 'kind' => $result['state'] === 'voided' ? 'void' : 'refund',
                    'provider' => (string) ($charge['provider'] ?? ''), 'provider_reference' => isset($charge['reference']) ? (string) $charge['reference'] : null,
                    'amount_minor' => (int) $result['amount_minor'], 'currency_code' => (string) $result['currency'], 'reason' => mb_substr($why, 0, 255),
                    'staff_id' => $staff?->id, 'state' => 'open']);
                Log::warning('softpos.reversal_not_booked', ['store_id' => $store->id, 'order_id' => $order->id, 'exception_id' => $existing->id]);
            } catch (UniqueConstraintViolationException) {
                $existing = PosCardReversalException::query()->where('store_id', $store->id)->where('client_uuid', $uuid)->firstOrFail();
            }
        }

        return ['booked' => false, 'code' => 'reversal_not_booked', 'exception_id' => (int) $existing->id,
            'message' => ___('The card was refunded, but the refund could not be booked on the order. A manager must resolve it under Offline exceptions.')];
    }

    public function terminalStatus(string $uuid): JsonResponse
    {
        $terminal = $this->terminalPayments();
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos'), 403);

        return $this->terminalAnswer(fn () => $terminal->status($store, strtolower($uuid)));
    }

    /** Partial or full refund of a captured card payment (manager permission). */
    public function terminalRefund(Request $request, string $uuid): JsonResponse
    {
        $terminal = $this->terminalPayments();
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_refund'), 403, 'Manager permission required.');
        $data = $request->validate(['client_uuid' => ['required', 'uuid'], 'amount_minor' => ['required', 'integer', 'min:1', 'max:100000000']]);

        return $this->terminalAnswer(function () use ($terminal, $store, $staff, $data, $uuid) {
            $result = $terminal->refund($store, strtolower($data['client_uuid']), strtolower($uuid), (int) $data['amount_minor']);

            return $result + $this->terminalReverse($result, $terminal->payment($store, strtolower($uuid)) ?? [], $staff, $store, ___('Card refund'));
        });
    }

    /** Void (same-day reversal) of a card payment (manager permission). */
    public function terminalVoid(Request $request, string $uuid): JsonResponse
    {
        $terminal = $this->terminalPayments();
        [$staff, $store] = $this->context();
        abort_unless($this->can($staff, 'pos_refund'), 403, 'Manager permission required.');
        $data = $request->validate(['client_uuid' => ['required', 'uuid']]);

        return $this->terminalAnswer(function () use ($terminal, $store, $staff, $data, $uuid) {
            $result = $terminal->void($store, strtolower($data['client_uuid']), strtolower($uuid));

            return $result + $this->terminalReverse($result, $terminal->payment($store, strtolower($uuid)) ?? [], $staff, $store, ___('Card payment voided'));
        });
    }

    /** Owner only: the store's terminal accounts (credentials masked). */
    public function terminalAccounts(): JsonResponse
    {
        $terminal = $this->terminalPayments();
        [$staff, $store] = $this->context();
        abort_if($staff !== null, 403);

        return response()->json(['providers' => $terminal->enabledProviders(), 'accounts' => $terminal->accounts($store)])->header('Cache-Control', 'no-store, private');
    }

    /** Owner only: set the store's own acquirer account (credentials encrypted; an empty value keeps the stored one). */
    public function terminalAccountSave(Request $request): JsonResponse
    {
        $terminal = $this->terminalPayments();
        [$staff, $store] = $this->context();
        abort_if($staff !== null, 403);
        $data = $request->validate([
            'provider' => ['required', 'in:'.implode(',', $terminal->enabledProviders())],
            'merchant_id' => ['required', 'string', 'max:191'],
            'enabled' => ['required', 'boolean'],
            'credentials' => ['present', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
        ]);
        $allowed = $data['provider'] === 'geidea_softpos' ? ['public_key', 'api_password'] : ['api_key', 'callback_secret'];
        $creds = array_map('strval', array_filter(array_intersect_key((array) $data['credentials'], array_flip($allowed)), fn ($v) => $v !== null));

        return $this->terminalAnswer(fn () => $terminal->saveAccount($store, $data['provider'], $data['merchant_id'], $creds, (bool) $data['enabled']));
    }
}
