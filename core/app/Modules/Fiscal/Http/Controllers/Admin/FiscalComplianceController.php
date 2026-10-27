<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Features\PlatformFeatures;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalRegistry;

/**
 * TAX-RUN R-P2: superadmin compliance-status page. A support tool aggregating,
 * per restaurant with a fiscal country set: country, identity completeness,
 * driver, and document counts (submitted / pending / rejected). Read-only.
 */
class FiscalComplianceController extends Controller
{
    public function index(Request $request)
    {
        // Restaurants that have opted into a fiscal country.
        $countryOptions = array_filter(StoreSettings::valuesFor('fiscal_country'), fn ($v) => $v !== '');

        $rows = [];
        $agg = ['submitted' => 0, 'pending' => 0, 'rejected' => 0, 'accepted' => 0];

        foreach ($countryOptions as $storeId => $country) {
            $store = Store::find($storeId);
            if (! $store) {
                continue;
            }
            $profile = FiscalRegistry::profile($country);

            $counts = FiscalDocument::where('store_id', $storeId)
                ->selectRaw('status, count(*) as c')->groupBy('status')
                ->pluck('c', 'status');

            $accepted = (int) ($counts['accepted'] ?? 0);
            $rejected = (int) ($counts['rejected'] ?? 0);
            $pending = (int) ($counts['queued'] ?? 0) + (int) ($counts['submitted'] ?? 0) + (int) ($counts['draft'] ?? 0);
            $submitted = $accepted + $rejected + $pending;

            $agg['accepted'] += $accepted;
            $agg['rejected'] += $rejected;
            $agg['pending'] += $pending;
            $agg['submitted'] += $submitted;

            $vat = (string) store_options($storeId, 'fiscal_vat_number');
            $legal = (string) store_options($storeId, 'fiscal_legal_name');

            $rows[] = [
                'id' => $storeId,
                'title' => $store->title,
                'country' => $country,
                'countryName' => $profile?->countryName() ?? $country,
                'driver' => $profile?->hasLiveDriver() ? $profile->driverMode() : 'profile-only',
                'identityComplete' => $vat !== '' && $legal !== '',
                'countryEnabled' => PlatformFeatures::active('fiscal_'.strtolower((string) $country)),
                'accepted' => $accepted,
                'rejected' => $rejected,
                'pending' => $pending,
            ];
        }

        return view('admin.fiscal.compliance', [
            'rows' => $rows,
            'agg' => $agg,
            'masterOn' => PlatformFeatures::active('fiscalization'),
            'ksaForceVatPerLine' => PlatformSettings::bool(self::FORCE_KSA_LINE, true), // J44a: default ON
        ]);
    }

    /** P6-186 (J44): read by Ordering's TaxEngine::ksaForced() (same platform setting name). */
    public const FORCE_KSA_LINE = 'zatca_force_vat_per_line';

    /**
     * P6-186 (J44): platform switch "force VAT per line for all KSA stores" (J44a: default ON). When on, every KSA
     * store's new orders use VAT per line and the owner's toggle is shown locked. Placed orders keep their method.
     */
    public function ksaVatPerLine(Request $request): RedirectResponse
    {
        $on = $request->boolean('force');
        PlatformSettings::set(self::FORCE_KSA_LINE, $on);

        return redirect()->route('admin.fiscal.compliance')->with('success', ___('Saved'));
    }
}
