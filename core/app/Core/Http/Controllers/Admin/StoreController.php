<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use App\Models\Order;
use App\Models\StoreView;
use App\Support\SearchTerm;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Requests\Admin\BulkDeleteRequest;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreTransfer;
use Juwri\Core\Models\User;
use Juwri\Core\Policies\Admin\PanelAbilities;
use Juwri\Core\Services\OwnershipTransferService;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\Admin\DataTable;
use Juwri\Core\Support\ExternalRedirect;

/**
 * Admin › Restaurants: every store on the platform, with the superadmin-only
 * tools (store redirects, force transfer, custom-code access, verified badge).
 */
class StoreController extends Controller
{
    /**
     * Admin › Restaurants. The page itself is server-rendered (stats, filters,
     * template / scans / redirect columns); an XHR asks for the older
     * DataTables answer instead.
     */
    public function index(Request $request): View|JsonResponse
    {
        Gate::authorize(PanelAbilities::STORES);

        if ($request->ajax()) {
            return $this->dataTable($request);
        }

        $last30 = Carbon::now()->subDays(30);

        $stats = [
            // P6-317: real stores only (developer sandboxes are not business metrics).
            'total' => Store::excludingSandbox()->count(),
            'new30' => Store::excludingSandbox()->where('created_at', '>=', $last30)->count(),
            'orders30' => Order::where('created_at', '>=', $last30)->whereIn('store_id', Store::excludingSandbox()->select('id'))->distinct()->count('store_id'),
            'scanned30' => StoreView::where('date', '>=', $last30)->whereIn('store_id', Store::excludingSandbox()->select('id'))->distinct()->count('store_id'),
        ];

        $query = Store::with('user')->orderByDesc('id');

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(function ($q) use ($term) {
                SearchTerm::apply($q, ['title', 'slug'], $term, ['phone']);
                // P6-317: support usually has a store id, a custom domain or the store's phone.
                if (ctype_digit($term)) {
                    $q->orWhere('stores.id', (int) $term);
                }
                $host = mb_strtolower(trim($term));
                if (mb_strlen($host) >= 3) {
                    $q->orWhereIn('stores.id', DB::table('store_domains')
                        ->select('store_id')
                        ->whereRaw('host LIKE ? ESCAPE ?', [SearchTerm::like($host), SearchTerm::ESCAPE]));
                }
            });
        }
        if ($request->filled('owner')) {
            $term = trim((string) $request->query('owner'));
            $query->whereHas('user', function ($q) use ($term) {
                SearchTerm::apply($q, ['name', 'email', 'username'], $term);
            });
        }
        if (in_array((int) $request->query('days'), [7, 30], true)) {
            $query->where('created_at', '>=', Carbon::now()->subDays((int) $request->query('days')));
        }

        $stores = $query->paginate(25)->withQueryString();

        $pageIds = $stores->getCollection()->pluck('id');

        // Template per restaurant: one indexed store_options lookup for the page
        // (same read pattern as the menu-template admin's usage counts).
        $templates = collect(StoreSettings::valuesFor('store_template', $pageIds->map(fn ($id) => (int) $id)->all()));

        $scanCounts = StoreView::query()
            ->whereIn('store_id', $pageIds)
            ->where('date', '>=', $last30)
            ->selectRaw('store_id, COUNT(*) as c')
            ->groupBy('store_id')
            ->pluck('c', 'store_id');

        // rev/external-redirect C1: which stores on this page are redirecting, so
        // the list can badge them. One indexed lookup (same pattern as templates).
        $redirects = collect(array_keys(array_filter(
            StoreSettings::valuesFor('external_redirect_enabled', $pageIds->map(fn ($id) => (int) $id)->all()),
            fn ($v) => $v === '1'
        )))->flip();

        return view('admin.stores.index', [
            'stats' => $stats,
            'stores' => $stores,
            'templates' => $templates,
            'scanCounts' => $scanCounts,
            'redirects' => $redirects,
            'filters' => $request->only(['q', 'owner', 'days']),
        ]);
    }

    /**
     * rev/external-redirect C1: superadmin per-store redirect editor (menu + bio
     * toggle/URL/interstitial — the same field set as the owner surface B1).
     */
    public function editExternalRedirect(Store $store): View
    {
        return view('admin.stores.external-redirect', ['store' => $store]);
    }

    /**
     * Persist the per-store redirect fields through the shared save path. The
     * ActivityLog actor is the acting admin (resolved by log_activity from auth).
     */
    public function updateExternalRedirect(Request $request, Store $store): RedirectResponse
    {
        if ($error = ExternalRedirect::saveFromRequest($store, $request)) {
            app_alert_error($error);

            return back()->withInput();
        }

        app_alert_success(___('Store redirect updated.'));

        return redirect()->route('admin.stores.index');
    }

    /**
     * rev/external-redirect C2: the migration-wave bulk tool. Set (from one
     * `{slug}{query}` template URL) or clear the menu redirect on the selected
     * stores. The template is validated ONCE; each store is written individually
     * via updateStoreOption (never a raw bulk ->update(), which skips the audit and
     * per-store handling). Returns a JSON report.
     */
    public function bulkExternalRedirect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', 'in:selected,filter'],
            'ids' => ['nullable', 'array', 'max:2000'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:set,clear'],
            'template' => ['nullable', 'string', 'max:2048'],
            'filters' => ['nullable', 'array'],
            'filters.q' => ['nullable', 'string', 'max:255'],
            'filters.owner' => ['nullable', 'string', 'max:255'],
            'filters.days' => ['nullable', 'integer', 'in:7,30'],
        ]);

        // scope=filter targets EVERY store matching the list's current filter (the
        // whole migration wave, not just the visible page); else the checked rows.
        if (($data['scope'] ?? 'selected') === 'filter') {
            $ids = $this->filteredPostIds($data['filters'] ?? []);
        } else {
            $ids = array_map('intval', $data['ids'] ?? []);
        }
        if (empty($ids)) {
            return response()->json(['success' => false, 'message' => ___('No stores selected.')], 422);
        }

        if ($data['action'] === 'set') {
            $template = trim((string) ($data['template'] ?? ''));
            if (($error = ExternalRedirect::validate($template)) !== null) {
                return response()->json(['success' => false, 'message' => $error], 422);
            }
        }

        $applied = 0;
        Store::query()->whereIn('id', $ids)->get()->each(function (Store $store) use ($data, &$applied) {
            if ($data['action'] === 'set') {
                ExternalRedirect::applyMenu($store, trim((string) $data['template']));
            } else {
                ExternalRedirect::clearMenu($store);
            }
            $applied++;
        });

        return response()->json([
            'success' => true,
            'applied' => $applied,
            'message' => $data['action'] === 'set'
                ? ___('Redirect set on :count stores.', ['count' => $applied])
                : ___('Redirect cleared on :count stores.', ['count' => $applied]),
        ], 200);
    }

    /**
     * Post ids matching the admin restaurants-list filter (q / owner / days) —
     * the same query index() paginates, minus pagination. Used by the bulk tool's
     * "apply to all matching" scope. Capped so one action can never run away.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    private function filteredPostIds(array $filters): array
    {
        $query = Store::query();

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $query->where(function ($q) use ($term) {
                SearchTerm::apply($q, ['title', 'slug'], $term);
            });
        }
        if (! empty($filters['owner'])) {
            $term = trim((string) $filters['owner']);
            $query->whereHas('user', function ($q) use ($term) {
                SearchTerm::apply($q, ['name', 'email', 'username'], $term);
            });
        }
        if (in_array((int) ($filters['days'] ?? 0), [7, 30], true)) {
            $query->where('created_at', '>=', Carbon::now()->subDays((int) $filters['days']));
        }

        return $query->limit(5000)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Bulk delete from the list; each store goes through its model events. */
    public function delete(BulkDeleteRequest $request): JsonResponse
    {
        Gate::authorize(PanelAbilities::STORES);

        Store::query()->whereKey($request->ids())->get()->each(fn (Store $store) => $store->delete());

        return response()->json(['success' => true, 'message' => ___('Deleted Successfully')]);
    }

    /**
     * WI-G7: superadmin force-transfer of a store to another user, with no
     * accept step. Only reachable behind the `admin` middleware, and every apply
     * is audited by the service.
     */
    public function forceTransfer(Request $request, int|string $id): RedirectResponse
    {
        $data = $request->validate(['to_email' => 'required|email']);
        $store = Store::findOrFail((int) $id);
        $recipient = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $data['to_email']))])->first();
        abort_unless($recipient && $recipient->id !== (int) $store->user_id, 422, ___('Invalid transfer target.'));

        $transfer = StoreTransfer::create([
            'kind' => 'store',
            'token' => StoreTransfer::newToken(),
            'from_user_id' => $store->user_id,
            'to_user_id' => $recipient->id,
            'to_email' => $recipient->email,
            'store_id' => $store->id,
            'status' => 'pending',
            'by_superadmin' => true,
        ]);
        app(OwnershipTransferService::class)->applyStore($transfer);

        return back()->with('success', ___('Store ownership transferred.'));
    }

    /**
     * P0-3: superadmin-only per-tenant grant/revoke of custom-code access
     * (css/head/js/footer/pixels). Default off; only routes under the `admin`
     * middleware reach this, so an owner can never self-enable.
     */
    public function toggleCustomCode(Request $request): JsonResponse
    {
        $store = Store::findOrFail((int) $request->id);
        $store->custom_code_enabled = ! $store->custom_code_enabled;
        $store->save();

        log_activity('post.custom_code_'.($store->custom_code_enabled ? 'enabled' : 'disabled'), $store);

        return response()->json([
            'success' => true,
            'enabled' => (bool) $store->custom_code_enabled,
            'message' => $store->custom_code_enabled
                ? ___('Custom code access enabled.')
                : ___('Custom code access disabled.'),
        ], 200);
    }

    /**
     * F2 · superadmin-only toggle of a restaurant's "Official store ✓" verified
     * badge. Default off; only routes under the `admin` middleware reach this, so
     * an owner can never self-verify.
     */
    public function toggleVerified(Request $request): JsonResponse
    {
        $store = Store::findOrFail((int) $request->id);
        $store->is_verified = ! $store->is_verified;
        $store->save();

        log_activity('post.verified_'.($store->is_verified ? 'on' : 'off'), $store);

        return response()->json([
            'success' => true,
            'verified' => (bool) $store->is_verified,
            'message' => $store->is_verified
                ? ___('Official store badge enabled.')
                : ___('Official store badge disabled.'),
        ], 200);
    }

    /** DataTables answer: stores whose owner account still exists. */
    private function dataTable(Request $request): JsonResponse
    {
        $stores = Store::query()->with('user')->whereHas('user');

        return DataTable::make($request, [0 => 'id', 1 => 'title', 3 => 'created_at'], ['title', 'description', 'sub_title', 'address'])
            ->respond($stores, fn (Store $store) => [
                Cells::text((string) $store->id),
                Cells::html($this->storeCell($store)),
                Cells::html($this->ownerCell($store->user)),
                Cells::text(date_formating($store->created_at)),
                Cells::actions('<a href="'.e(route('publicView', $store->slug)).'" title="'.e(___('View')).'" class="btn btn-primary btn-icon"'
                    .' data-tippy-placement="top" target="_blank" rel="noopener"><i class="icon-feather-eye"></i></a>'),
                Cells::checkbox($store->id),
            ]);
    }

    private function storeCell(Store $store): string
    {
        $url = e(route('publicView', $store->slug));

        return '<div class="app-user-box align-items-center">'
            .'<a class="app-user-avatar" href="'.$url.'" target="_blank" rel="noopener"><img src="'.e(asset('storage/store/logo/'.$store->main_image)).'" alt=""></a>'
            .'<a class="text-reset" href="'.$url.'" target="_blank" rel="noopener">'.e((string) $store->title).'</a>'
            .'</div>';
    }

    private function ownerCell(User $owner): string
    {
        $url = e(route('admin.users.edit', $owner->id));

        return '<div class="d-flex align-items-center">'
            .'<div class="avatar me-2"><a href="'.$url.'"><img alt="'.e((string) $owner->username).'" src="'.e(asset('storage/profile/'.$owner->image)).'"></a></div>'
            .'<div><a class="text-body fw-semibold text-truncate" href="'.$url.'">'.e((string) $owner->name).'</a>'
            .'<p class="text-muted mb-0">@'.e((string) $owner->username).'</p></div>'
            .'</div>';
    }
}
