<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Http\Controllers\User;

use App\Core\Support\UploadSniffer;
use App\Services\Entitlements;
use App\Services\Verticals\VerticalRegistry;
use App\Services\Verticals\VerticalVocab;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Store\StoreSettingsService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\MenuAdminService;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyComplianceService;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyMarkets;
use Juwri\Modules\Verticals\Http\Controllers\User\RetailItemController;

/**
 * rev/multi-vertical Lane F1/F2 — OTC-ONLY (D3) compliance surface: the
 * age-gate flag + batch/expiry tracking for a pharmacy (or any future
 * `age_gate`/`expiry`-capable vertical) store.
 *
 * A NEW dashboard surface, deliberately kept OUT of Lane A's RetailItemController
 * + its shared item-form partial (the pack's hard rule forbids forking/editing
 * that seam) — this controller owns its own routes/views and reuses only
 * {@see MenuAdminService} (the same neutral persistence engine RetailItemController
 * itself calls) so a compliance save is auditied/limited identically to every
 * other Menu write. Reachable from the dashboard via a small, capability-gated
 * nav button on the store list (see resources/views/owner/stores/index.blade.php).
 *
 * D3 reminder: this controller NEVER reads or writes anything about a
 * prescription, a pharmacist, a controlled substance, or a patient identity —
 * only `age_restricted` (bool), `batch_no` (string) and `expiry_date` (date)
 * on the existing `menu` row. See PharmacyOtcGuardrailTest (F4).
 */
class PharmacyComplianceController extends Controller
{
    public function __construct(private VerticalRegistry $registry, private PharmacyComplianceService $compliance, private PharmacyMarkets $markets) {}

    /**
     * Tenancy (G2) + vertical-capability guard shared by every action here.
     *
     * rev/multi-vertical Lane V (V4) — non-restaurant MANAGE surfaces need the
     * SAME `multi_vertical` entitlement F0-6 already requires to CREATE the
     * store: a store keeps existing if the entitlement later lapses
     * (grandfathered data, per Entitlements' own docblock), but ongoing
     * management of a premium surface stops immediately. A restaurant never
     * reaches this controller at all (the capability check below always
     * 404s it first), so this is a no-op for every legacy store.
     */
    private function authorizeStore(Request $request, Store $store): void
    {
        abort_unless((int) $store->user_id === (int) $request->user()->id, 404);
        abort_unless($this->ageGateOn($store) || $this->expiryOn($store), 404);
        abort_unless(Entitlements::allows($request->user(), 'multi_vertical'), 403);
    }

    private function ageGateOn(Store $store): bool
    {
        return $this->registry->has($store->store_type, 'age_gate');
    }

    private function expiryOn(Store $store): bool
    {
        return $this->registry->has($store->store_type, 'expiry');
    }

    public function index(Request $request, Store $store, VerticalVocab $vocab): View
    {
        $this->authorizeStore($request, $store);

        $items = Menu::where('store_id', $store->id)
            ->orderBy('category_id')->orderBy('position')
            ->paginate(25)->withQueryString();

        return view('owner.catalog.pharmacy.index', [
            'store' => $store,
            'items' => $items,
            'vocab' => $vocab->map($store->store_type),
            'ageGateOn' => $this->ageGateOn($store),
            'expiryOn' => $this->expiryOn($store),
            'expiredItems' => $this->expiryOn($store) ? $this->compliance->expiredItems($store) : collect(),
            'expiringSoonItems' => $this->expiryOn($store) ? $this->compliance->expiringSoon($store, 30) : collect(),
            'hideExpiredEnabled' => $this->compliance->hideExpiredEnabled($store),
            'disclaimerText' => $this->compliance->disclaimerText($store),
            'entryGateEnabled' => $this->compliance->entryGateEnabled($store),
            'discreetMessagesEnabled' => $this->compliance->discreetMessagesEnabled($store), // P6-46
            // P1-23 (AUP 3.1): publish status, market and licence.
            'isPharmacy' => PharmacyMarkets::isPharmacy($store),
            'publishReason' => $this->markets->unpublishedReason($store),
            'marketCountry' => $this->markets->country($store),
            'enabledCountries' => PharmacyMarkets::enabledCountries(),
            'marketCap' => PharmacyMarkets::marketRules($this->markets->country($store))['quantity_cap'],
            'quantityCap' => $this->markets->quantityCap($store),
            'medicinePromotionsBlocked' => $this->markets->medicinePromotionsBlocked($store), // P1-23 follow-up
            'ownCap' => (int) store_options($store->id, PharmacyMarkets::OPTION_QUANTITY_CAP, 0),
            'licence' => PharmacyLicence::latestFor((int) $store->id),
        ]);
    }

    /**
     * P1-23 (AUP 3.1(b)): upload the licence documents. Each upload is a new row waiting for the platform's review
     * (the store stays unpublished until a reviewer approves it); the scanned document goes to the private disk.
     */
    public function storeLicence(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        abort_unless(PharmacyMarkets::isPharmacy($store), 404);

        $data = $request->validate([
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'licence_number' => ['required', 'string', 'max:80'],
            'issuing_authority' => ['required', 'string', 'max:160'],
            'expires_on' => ['required', 'date', 'after:today'],
            'responsible_person_name' => ['nullable', 'string', 'max:160'],
            'responsible_person_registration' => ['nullable', 'string', 'max:80'],
            'online_sales_permit' => ['nullable', 'string', 'max:160'],
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.(int) config('pharmacy.licence_max_kb', 8192)],
        ]);

        $file = $request->file('document');
        $ext = UploadSniffer::licenceExtension($file->getRealPath());
        if ($ext === null) {
            return back()->withErrors(['document' => ___('Upload a PDF, JPG, PNG or WebP file.')])->withInput();
        }
        $path = $file->storeAs(
            'pharmacy-licences/'.$store->id,
            Str::random(32).'.'.$ext,
            ['disk' => (string) config('pharmacy.licence_disk', 'local')]
        );

        $licence = new PharmacyLicence([
            'store_id' => $store->id,
            'country' => strtoupper($data['country']),
            'licence_number' => $data['licence_number'],
            'issuing_authority' => $data['issuing_authority'],
            'expires_on' => $data['expires_on'],
            'responsible_person_name' => $data['responsible_person_name'] ?? null,
            'responsible_person_registration' => $data['responsible_person_registration'] ?? null,
            'online_sales_permit' => $data['online_sales_permit'] ?? null,
            'document_path' => (string) $path,
        ]);
        $licence->status = PharmacyLicence::PENDING;
        $licence->save();

        log_activity('pharmacy.licence_uploaded', $licence, ['country' => $licence->country], $store->id);
        app_alert_success(___('Licence documents received. We will review them before your pharmacy is published.'));

        return redirect()->route('pharmacy.compliance.index', $store);
    }

    public function edit(Request $request, Store $store, Menu $product, VerticalVocab $vocab): View
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        return view('owner.catalog.pharmacy.edit', [
            'store' => $store,
            'item' => $product,
            'vocab' => $vocab->map($store->store_type),
            'ageGateOn' => $this->ageGateOn($store),
            'expiryOn' => $this->expiryOn($store),
        ]);
    }

    public function update(Request $request, Store $store, Menu $product): RedirectResponse
    {
        $this->authorizeStore($request, $store);
        abort_unless((int) $product->store_id === (int) $store->id, 404);

        $rules = [];
        if ($this->ageGateOn($store)) {
            $rules['age_restricted'] = ['nullable', 'boolean'];
        }
        if ($this->expiryOn($store)) {
            $rules['batch_no'] = ['nullable', 'string', 'max:64'];
            $rules['expiry_date'] = ['nullable', 'date'];
        }

        $validator = Validator::make($request->all(), $rules);
        $validator->validate();
        $v = $validator->validated();

        $data = [];
        // A checkbox that is unchecked never arrives in the request, so this is
        // set explicitly (has()) rather than left out like a normal optional
        // field — otherwise unchecking it could never persist.
        if ($this->ageGateOn($store)) {
            $data['age_restricted'] = $request->has('age_restricted') ? 1 : 0;
        }
        // P1-23: a pharmacy item is a medicine unless the owner says otherwise (per-order cap, no promotions).
        if (PharmacyMarkets::isPharmacy($store)) {
            $data['is_medicine'] = $request->has('not_medicine') ? 0 : 1;
        }
        // Only a field the request actually TOUCHED lands in $data (mirrors
        // RetailItemController::validated()) — a partial update (e.g. this
        // controller's own expiry-only save, or a future API caller) never
        // clobbers the sibling field back to null.
        if ($this->expiryOn($store)) {
            if ($request->has('batch_no')) {
                $data['batch_no'] = $v['batch_no'] ?: null;
            }
            if ($request->has('expiry_date')) {
                $data['expiry_date'] = $v['expiry_date'] ?: null;
            }
        }

        MenuAdminService::updateItem($product, $data);
        $this->compliance->applyHideIfExpired($product->fresh(), $store);

        log_activity('menu.pharmacy_compliance_updated', $product, $data, $store->id);
        app_alert_success(___('Saved Successfully'));

        return redirect()->route('pharmacy.compliance.index', $store);
    }

    public function updateSettings(Request $request, Store $store): RedirectResponse
    {
        $this->authorizeStore($request, $store);

        if ($this->expiryOn($store)) {
            $this->compliance->setHideExpiredEnabled($store, $request->boolean('hide_expired_items'));
        }

        if ($this->ageGateOn($store)) {
            // Unchecked checkbox never arrives, so read it explicitly (boolean()).
            $this->compliance->setEntryGateEnabled($store, $request->boolean('show_entry_gate'));
            if ($request->filled('disclaimer_text')) {
                $this->compliance->setDisclaimerText($store, (string) $request->input('disclaimer_text'));
            }
        }

        // P6-46: medicine names in customer messages (unchecked checkbox never arrives, so boolean()).
        if (PharmacyMarkets::isPharmacy($store)) {
            $this->compliance->setDiscreetMessagesEnabled($store, $request->boolean('discreet_messages'));
        }

        // P1-23: the store's own per-order cap for one medicine — it can only be lower than the market's.
        if (PharmacyMarkets::isPharmacy($store) && $request->has('quantity_cap')) {
            $request->validate(['quantity_cap' => ['nullable', 'integer', 'min:0', 'max:999']]);
            $own = (int) $request->input('quantity_cap', 0);
            $market = PharmacyMarkets::marketRules($this->markets->country($store))['quantity_cap'];
            app(StoreSettingsService::class)->applyOptions($store, [
                PharmacyMarkets::OPTION_QUANTITY_CAP => $own > 0 ? (string) min($own, $market) : '',
            ]);
        }

        app_alert_success(___('Saved Successfully'));

        return redirect()->route('pharmacy.compliance.index', $store);
    }
}
