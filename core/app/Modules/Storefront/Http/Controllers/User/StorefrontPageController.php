<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\User;

use App\Models\StoreOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;

/**
 * HPMS E14(d): owner editor for the extra storefront info pages (FAQ, refund
 * policy, delivery policy). Same plain-text `sf_page_{slug}` idiom the built-in
 * pages use; kept out of the fenced posts/edit form. The storefront renders these
 * via the existing infoPage route + storefront_info_page_labels().
 */
class StorefrontPageController extends Controller
{
    /** The extra page slugs this editor owns. */
    private const PAGES = ['faq', 'refund_policy', 'delivery_policy'];

    private function ownedRestaurantId(Request $request): int
    {
        $ids = Store::where('user_id', auth()->id())->pluck('id')->all();
        $id = (int) ($request->query('store_id') ?: (dashboard_current_store()?->id ?: ($ids[0] ?? 0)));
        if ($id && ! in_array($id, $ids, true)) {
            abort(403);
        }

        return $id;
    }

    public function edit(Request $request): View
    {
        $storeId = $this->ownedRestaurantId($request);
        $values = [];
        foreach (self::PAGES as $slug) {
            $values[$slug] = $storeId ? (string) store_options($storeId, 'sf_page_'.$slug, '') : '';
        }

        return view('owner.stores.pages', [
            'storeId' => $storeId,
            'pages' => self::PAGES,
            'values' => $values,
            'labels' => storefront_info_page_labels(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $storeId = $this->ownedRestaurantId($request);
        abort_unless($storeId > 0, 404);

        $data = $request->validate([
            'faq' => ['nullable', 'string', 'max:20000'],
            'refund_policy' => ['nullable', 'string', 'max:20000'],
            'delivery_policy' => ['nullable', 'string', 'max:20000'],
        ]);

        foreach (self::PAGES as $slug) {
            StoreOption::updateStoreOption($storeId, 'sf_page_'.$slug, (string) ($data[$slug] ?? ''));
        }

        return back()->with('success', ___('Storefront pages saved.'));
    }
}
