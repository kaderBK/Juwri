<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\User;

use App\Models\Order;
use App\Services\Verticals\FulfillmentRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\PlanGate;
use Juwri\Modules\Integrations\Models\OdooSyncRecord;
use Juwri\Modules\Integrations\Services\Odoo\OdooClient;
use Juwri\Modules\Integrations\Services\Odoo\OdooConnection;
use Juwri\Modules\Integrations\Services\Odoo\OdooError;
use Juwri\Modules\Integrations\Services\Odoo\OdooMapping;
use Juwri\Modules\Integrations\Services\Odoo\OdooSyncQueue;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P4-21: the store's Odoo connection (minimal settings screen; the full mapping screen is a later item). The
 * current dashboard store of its OWNER only (a staff session is refused by the staff route map); every path checks
 * the plan gate. The API key is write-only: never sent back to the page, kept when the field is left empty.
 */
class OdooController extends Controller
{
    private function store(): Store
    {
        abort_if(is_staff_dashboard(), 403);
        $current = dashboard_current_store();

        return Store::query()->where('user_id', auth()->id())->findOrFail((int) ($current->id ?? 0));
    }

    public function show(Request $request): View
    {
        $store = $this->store();
        $allowed = PlanGate::storeAllows($store, PlanGate::ODOO_CONNECTOR);
        $conn = OdooConnection::for((int) $store->id);
        $options = ['taxes' => [], 'journals' => [], 'pickingTypes' => []];
        $error = null;
        if ($allowed && $conn !== null) {
            try {
                $odoo = new OdooClient($conn);
                $options['taxes'] = $odoo->searchRead('account.tax', [['type_tax_use', '=', 'sale']], ['id', 'name', 'amount', 'amount_type', 'price_include']);
                $options['journals'] = $odoo->searchRead('account.journal', [['type', 'in', ['cash', 'bank']]], ['id', 'name', 'type']);
                $options['pickingTypes'] = $odoo->searchRead('stock.picking.type', [['code', '=', 'outgoing']], ['id', 'name']);
            } catch (OdooError $e) {
                $error = $e->getMessage();
            }
        }
        $filter = (string) $request->query('status', '');
        $filter = in_array($filter, ['pending', 'retrying', 'synced', 'failed', 'skipped'], true) ? $filter : '';
        $records = OdooSyncRecord::query()->where('store_id', $store->id)->when($filter !== '', fn ($q) => $q->where('status', $filter))->orderByDesc('id')->limit(50)->get();
        $counts = OdooSyncRecord::query()->where('store_id', $store->id)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all();
        $seen = [
            'methods' => DB::table('orders')->where('store_id', $store->id)->whereNotNull('payment_method')->distinct()->orderBy('payment_method')->limit(20)->pluck('payment_method')->all(),
            'types' => DB::table('orders')->where('store_id', $store->id)->whereNotNull('type')->distinct()->orderBy('type')->limit(10)->pluck('type')->all(),
        ];
        $skuPreview = $this->skuPreview($store, $allowed && $conn !== null && $error === null ? $conn : null);
        $settings = [];
        foreach (OdooConnection::SETTINGS as $name) {
            $settings[$name] = StoreSettings::get((int) $store->id, $name);
        }

        return view('owner.integrations.odoo', [
            'store' => $store, 'allowed' => $allowed, 'connected' => $conn !== null, 'error' => $error, 'odooOptions' => $options, 'records' => $records,
            'odooSettings' => $settings, 'filter' => $filter, 'counts' => $counts, 'seen' => $seen, 'skuPreview' => $skuPreview,
            'connection' => $conn ? ['url' => $conn->url, 'database' => $conn->database, 'username' => $conn->username] : null,
        ]);
    }

    /**
     * Read-only: which menu SKUs Odoo knows (product.default_code). Never writes; the sync matches the same way.
     *
     * @return array{total: int, withSku: int, matched: list<string>, missing: list<string>, checked: bool}
     */
    private function skuPreview(Store $store, ?OdooConnection $conn): array
    {
        $menus = Menu::query()->where('store_id', $store->id);
        $total = (clone $menus)->count();
        $skus = (clone $menus)->whereNotNull('external_sku')->where('external_sku', '!=', '')->distinct()->orderBy('external_sku')->limit(200)->pluck('external_sku')->map(fn ($v) => (string) $v)->all();
        $out = ['total' => $total, 'withSku' => count($skus), 'matched' => [], 'missing' => $skus, 'checked' => false];
        if ($conn === null || $skus === []) {
            return $out;
        }
        try {
            $found = [];
            foreach ((new OdooClient($conn))->searchRead('product.product', [['default_code', 'in', $skus]], ['id', 'default_code']) as $row) {
                $found[(string) $row['default_code']] = true;
            }
        } catch (OdooError) {
            return $out;
        }
        $out['matched'] = array_values(array_filter($skus, fn ($s) => isset($found[$s])));
        $out['missing'] = array_values(array_filter($skus, fn ($s) => ! isset($found[$s])));
        $out['checked'] = true;

        return $out;
    }

    /** Read-only check of the saved connection (nothing is written, nothing is sent to Odoo). */
    public function test(): RedirectResponse
    {
        $store = $this->store();
        abort_unless(PlanGate::storeAllows($store, PlanGate::ODOO_CONNECTOR), 403, PlanGate::upsell(PlanGate::ODOO_CONNECTOR));
        $conn = OdooConnection::for((int) $store->id);
        if ($conn === null) {
            return back()->with('error', ___('Save the connection first.'));
        }
        try {
            (new OdooClient($conn))->uid();
        } catch (OdooError $e) {
            return back()->with('error', ___('Odoo did not accept the connection:').' '.$e->getMessage());
        }

        return back()->with('success', ___('The connection works.'));
    }

    public function save(Request $request): RedirectResponse
    {
        $store = $this->store();
        abort_unless(PlanGate::storeAllows($store, PlanGate::ODOO_CONNECTOR), 403, PlanGate::upsell(PlanGate::ODOO_CONNECTOR));
        $data = $request->validate([
            'url' => 'required|url|max:255',
            'database' => 'required|string|max:120',
            'username' => 'required|string|max:190',
            'api_key' => 'nullable|string|max:255',
            'enabled' => 'nullable|boolean',
            'odoo_tax_id' => 'nullable|integer|min:1',
            'odoo_journal_cash_id' => 'nullable|integer|min:1',
            'odoo_journal_bank_id' => 'nullable|integer|min:1',
            'odoo_picking_type_id' => 'nullable|integer|min:1',
        ]);
        if (! OdooClient::urlAllowed($data['url'])) {
            return back()->withErrors(['url' => ___('The Odoo address must be a public http(s) address.')])->withInput($request->except('api_key'));
        }
        $existing = Credential::query()->where('store_id', $store->id)->where('feature', OdooConnection::FEATURE)->first();
        $key = trim((string) ($data['api_key'] ?? '')) !== '' ? (string) $data['api_key'] : (string) ((is_array($existing?->secrets) ? $existing->secrets : [])['api_key'] ?? '');
        if ($key === '') {
            return back()->withErrors(['api_key' => ___('Enter the Odoo API key.')])->withInput($request->except('api_key'));
        }
        Credential::query()->updateOrCreate(
            ['store_id' => $store->id, 'feature' => OdooConnection::FEATURE],
            ['provider' => 'odoo', 'active' => true, 'secrets' => ['url' => rtrim($data['url'], '/'), 'database' => $data['database'], 'username' => $data['username'], 'api_key' => $key]],
        );
        StoreSettings::set((int) $store->id, 'odoo_enabled', $request->boolean('enabled') ? '1' : '0');
        foreach (['odoo_tax_id', 'odoo_journal_cash_id', 'odoo_journal_bank_id', 'odoo_picking_type_id'] as $name) {
            StoreSettings::set((int) $store->id, $name, isset($data[$name]) ? (string) (int) $data[$name] : '');
        }

        // Check the connection now, so a wrong address or key shows at once.
        try {
            (new OdooClient(OdooConnection::for((int) $store->id) ?? throw new OdooError('Not connected.')))->uid();
        } catch (OdooError $e) {
            return back()->with('error', ___('Saved, but Odoo did not accept the connection:').' '.$e->getMessage());
        }

        return back()->with('success', ___('Odoo connection saved.'));
    }

    /**
     * GET integrations/odoo/mapping — the per-item mapping and what can be mapped: the store's order types and the
     * payment methods it has used or offers (JSON, for the mapping screen).
     */
    public function mapping(FulfillmentRegistry $fulfilment): JsonResponse
    {
        $store = $this->store();
        abort_unless(PlanGate::storeAllows($store, PlanGate::ODOO_CONNECTOR), 403, PlanGate::upsell(PlanGate::ODOO_CONNECTOR));
        $used = Order::query()->where('store_id', $store->id)->whereNotNull('payment_method')
            ->distinct()->limit(OdooMapping::MAX_ENTRIES)->pluck('payment_method')->map(fn ($m) => strtolower((string) $m))->all();

        return response()->json([
            'map' => OdooMapping::read((int) $store->id),
            'tax_keys' => OdooMapping::TAX_KEYS,
            'order_types' => $fulfilment->modes(),
            'payment_methods' => array_values(array_unique(array_merge(['cash', 'card'], array_filter($used, fn ($m) => preg_match('/^[a-z0-9_]{1,40}$/', $m) === 1)))),
        ]);
    }

    /** PUT integrations/odoo/mapping — replaces the per-item mapping (unknown keys and malformed ids are refused). */
    public function saveMapping(Request $request, FulfillmentRegistry $fulfilment): JsonResponse
    {
        $store = $this->store();
        abort_unless(PlanGate::storeAllows($store, PlanGate::ODOO_CONNECTOR), 403, PlanGate::upsell(PlanGate::ODOO_CONNECTOR));
        $code = 'regex:/^[a-z0-9_]{1,40}$/';
        $data = $request->validate([
            'tax' => 'present|array:'.implode(',', OdooMapping::TAX_KEYS),
            'tax.*' => 'nullable|integer|min:1',
            'payment' => 'present|array|max:'.OdooMapping::MAX_ENTRIES,
            'payment.*' => 'nullable|integer|min:1',
            'order_type' => 'present|array|max:'.OdooMapping::MAX_ENTRIES,
            'order_type.*' => 'array:tax_id,picking_type_id',
            'order_type.*.tax_id' => 'nullable|integer|min:1',
            'order_type.*.picking_type_id' => 'nullable|integer|min:1',
        ]);
        foreach (array_keys((array) $data['payment']) as $k) {
            validator(['k' => (string) $k], ['k' => [$code]])->validate();
        }
        $modes = $fulfilment->modes();
        foreach (array_keys((array) $data['order_type']) as $k) {
            abort_unless(in_array((string) $k, $modes, true), 422, ___('Unknown order type.'));
        }
        OdooMapping::write((int) $store->id, $data);

        return response()->json(['map' => OdooMapping::read((int) $store->id)]);
    }

    public function retry(int $record, OdooSyncQueue $queue): RedirectResponse
    {
        $store = $this->store();
        abort_unless(PlanGate::storeAllows($store, PlanGate::ODOO_CONNECTOR), 403, PlanGate::upsell(PlanGate::ODOO_CONNECTOR));
        $row = OdooSyncRecord::query()->where('store_id', $store->id)->findOrFail($record);
        $queue->retry($row);

        return back()->with('success', ___('Sent to Odoo again.'));
    }

    public function disconnect(): RedirectResponse
    {
        $store = $this->store();
        Credential::query()->where('store_id', $store->id)->where('feature', OdooConnection::FEATURE)->delete();
        StoreSettings::set((int) $store->id, 'odoo_enabled', '0');

        return back()->with('success', ___('Odoo disconnected.'));
    }
}
