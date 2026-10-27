<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Http\Controllers\User;

use App\Models\StoreOption;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;

/**
 * GAPS-RUN-2 B2-P3: consolidated owner "Automations" page — cart recovery
 * (per-channel toggles), win-back (threshold/discount/validity/frequency), and
 * birthday offers (surfaced here too). Tenant scoped; behind the
 * marketing_automation kill-switch (feature: middleware on the route).
 */
class MarketingAutomationController extends Controller
{
    use ResolvesCurrentStore;

    /** 0/1 checkbox flags. */
    private const FLAGS = [
        'customer_cart_whatsapp', 'customer_cart_email', 'customer_cart_sms',
        'winback_enabled', 'birthday_offers_enabled',
    ];

    /** Numeric/text values persisted verbatim. */
    private const VALUES = [
        'winback_after_days', 'winback_frequency_days', 'winback_reward_percentage', 'winback_validity_days',
        'birthday_reward_percentage', 'birthday_validity_days',
    ];

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        return view('owner.marketing.automations', [
            'rid' => $rid,
            'opts' => store_options($rid),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function update(Request $request)
    {
        $rid = $this->currentRestaurantId($request);

        $request->validate([
            'winback_after_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'winback_frequency_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'winback_reward_percentage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'winback_validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'birthday_reward_percentage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'birthday_validity_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        foreach (self::FLAGS as $flag) {
            StoreOption::updateStoreOption($rid, $flag, $request->boolean($flag) ? '1' : '0');
        }
        foreach (self::VALUES as $key) {
            if ($request->filled($key)) {
                StoreOption::updateStoreOption($rid, $key, (string) $request->input($key));
            }
        }

        return back()->with('success', ___('Automations saved.'));
    }
}
