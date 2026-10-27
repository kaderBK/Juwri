<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\User;

use App\Models\StoreOption;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;

/**
 * P5-1: structured integrations — the validated-field replacement for the
 * P0-3 arbitrary head/js custom code. Only IDs matching the official formats
 * pass; the render side emits the official snippets with the ID escaped, so
 * no script can ever ride through. Available to every owner (the whole point:
 * the common cases stop needing the dangerous path).
 */
class StructuredIntegrationsController extends Controller
{
    /** Official ID shapes — the validation IS the security boundary. */
    private const RULES = [
        'ga_id' => ['nullable', 'string', 'regex:/^(G|AW|GTM)-[A-Z0-9]{4,20}$/i'],
        'meta_pixel_id' => ['nullable', 'string', 'regex:/^[0-9]{5,20}$/'],
        // A Google-font family name: letters/digits/spaces/+ only.
        'font_family' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9 +]+$/'],
        // D2/D3: keyless store-map embed toggle (default OFF). Checkbox/select
        // submits '1' or '0'; absent = leave OFF.
        'store_map_embed' => ['nullable', 'in:0,1'],
    ];

    private function ownRestaurant(int $storeId): Store
    {
        return Store::where('id', $storeId)->where('user_id', auth()->id())->firstOrFail();
    }

    public function show(int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $options = store_options($store->id);

        $data = [
            'ga_id' => $options->integration_ga_id ?? null,
            'meta_pixel_id' => $options->integration_meta_pixel_id ?? null,
            'font_family' => $options->integration_font_family ?? null,
            'store_map_embed' => (string) ($options->store_map_embed ?? '0') === '1' ? '1' : '0',
        ];

        // XHR / API consumers keep the JSON contract; a browser gets the real
        // dashboard page (D3) — previously this endpoint only ever returned JSON,
        // so the "Open integrations" link showed raw JSON.
        if (request()->expectsJson()) {
            return response()->json($data);
        }

        // NB: view name is `structured-integrations`, NOT `integrations` — the
        // latter is the User SettingsController's AI/SMS providers page.
        return view('owner.integrations.structured', [
            'store' => $store,
            'postOptions' => $options,
            'integration' => $data,
        ]);
    }

    public function save(Request $request, int $storeId)
    {
        $store = $this->ownRestaurant($storeId);
        $data = $request->validate(self::RULES);

        StoreOption::updateStoreOption($store->id, 'integration_ga_id', $data['ga_id'] ?? '');
        StoreOption::updateStoreOption($store->id, 'integration_meta_pixel_id', $data['meta_pixel_id'] ?? '');
        StoreOption::updateStoreOption($store->id, 'integration_font_family', $data['font_family'] ?? '');
        StoreOption::updateStoreOption($store->id, 'store_map_embed', ($data['store_map_embed'] ?? '0') === '1' ? '1' : '0');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()
            ->route('integrations.structured.show', $store->id)
            ->with('success', ___('Integrations saved.'));
    }
}
