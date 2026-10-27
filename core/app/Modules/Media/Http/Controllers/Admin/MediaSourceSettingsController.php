<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Illuminate\Http\Request;

/**
 * FEATURES-5 Track B2/B3 — platform-default keys for the public media sources.
 *
 * Pexels + Unsplash (feature='stock') MAY have a platform default so the Stock
 * tab works with zero owner setup. Google CSE (feature='media_search') is
 * BYOK-ONLY by policy — its results are unlicensed web images — so it is NOT
 * settable here; owners must supply their own key.
 */
class MediaSourceSettingsController extends Controller
{
    public function index()
    {
        $platform = Credential::whereNull('store_id')
            ->whereIn('feature', ['stock'])
            ->get()
            ->keyBy('provider');

        return view('admin.media-sources.index', [
            'pexelsActive'   => (bool) optional($platform->get('pexels'))->active,
            'unsplashActive' => (bool) optional($platform->get('unsplash'))->active,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'pexels_key'   => ['nullable', 'string', 'max:255'],
            'unsplash_key' => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->filled('pexels_key')) {
            $this->upsert('pexels', ['api_key' => trim($data['pexels_key'])]);
        }
        if ($request->filled('unsplash_key')) {
            $this->upsert('unsplash', ['access_key' => trim($data['unsplash_key'])]);
        }

        return back()->with('success', ___('Saved'));
    }

    private function upsert(string $provider, array $incoming): void
    {
        $cred = Credential::firstOrNew([
            'store_id' => null,
            'feature'       => 'stock',
            'provider'      => $provider,
        ]);
        $cred->secrets = array_merge(is_array($cred->secrets) ? $cred->secrets : [], $incoming);
        $cred->active  = true;
        $cred->save();
    }
}
