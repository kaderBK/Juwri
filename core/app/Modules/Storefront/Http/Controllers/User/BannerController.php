<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\User;

use App\Models\MenuBanner;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;

/**
 * Storefront promo / hero banners. Images are picked from the media library (folder=banner).
 */
class BannerController extends Controller
{
    private function ownerRestaurantIds(): array
    {
        return dashboard_store_ids(); // BL-036: a staff session is bound to its own store
    }

    private function assertOwnsRestaurant(int $storeId): void
    {
        if (! in_array($storeId, $this->ownerRestaurantIds(), true)) {
            abort(403);
        }
    }

    private function ownedBanner(int $id): MenuBanner
    {
        $banner = MenuBanner::findOrFail($id);
        $this->assertOwnsRestaurant((int) $banner->store_id);

        return $banner;
    }

    /**
     * Per-locale title/subtitle, trimmed and dropping locales that are entirely
     * empty (base title/subtitle cover the default language, so the accessor
     * falls back to them where a locale is blank). Mirrors the allergen idiom.
     *
     * @return array<int, array{title: string, subtitle: string}>|null
     */
    private function cleanTranslations(Request $request): ?array
    {
        $translations = $request->collect('translations')
            ->map(fn ($t) => ['title' => trim($t['title'] ?? ''), 'subtitle' => trim($t['subtitle'] ?? '')])
            ->filter(fn ($t) => $t['title'] !== '' || $t['subtitle'] !== '')
            ->all();

        return $translations ?: null;
    }

    public function index(Request $request)
    {
        $storeIds = $this->ownerRestaurantIds();
        $storeId = (int) $request->query('store_id', dashboard_current_store()->id ?? 0);
        if ($storeId && ! in_array($storeId, $storeIds, true)) {
            abort(403);
        }

        $stores = Store::whereIn('id', $storeIds)->get(['id', 'title']);
        if (! $storeId && $stores->count() === 1) {
            $storeId = (int) $stores->first()->id;
        }

        $banners = $storeId
            ? MenuBanner::where('store_id', $storeId)->orderBy('position')->orderBy('id')->get()
            : collect();

        // Per-locale banner text: resolve the store's menu languages the same way
        // StoreController/ManagesMenu does, so the add/edit UI can offer one input
        // per language. Falls back to empty (single-language store) when unset.
        $menu_languages = $default_menu_language = [];
        if ($storeId
            && store_options($storeId, 'menu_languages')
            && store_options($storeId, 'default_language')) {
            $menu_languages = Language::query()
                ->whereIn('code', store_options($storeId, 'menu_languages'))
                ->get();
            if ($menu_languages->isNotEmpty()) {
                if (! empty($_COOKIE['Quick_user_lang_code'])) {
                    /* Get default language by cookie if user changed it */
                    $default_menu_language = $menu_languages
                        ->where('code', $_COOKIE['Quick_user_lang_code'])
                        ->first();
                }
                if (empty($default_menu_language)) {
                    /* Get default language */
                    $default_menu_language = $menu_languages
                        ->where('code', store_options($storeId, 'default_language'))
                        ->first();
                }
            }
        }

        return view('owner.marketing.banners',
            compact('stores', 'storeId', 'banners', 'menu_languages', 'default_menu_language'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'image' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'url', 'regex:#^https?://#i', 'max:500'],
            'active' => ['nullable', 'boolean'],
            'translations' => ['nullable', 'array'],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
            'translations.*.subtitle' => ['nullable', 'string', 'max:255'],
        ]);
        $this->assertOwnsRestaurant((int) $data['store_id']);

        $next = (int) MenuBanner::where('store_id', $data['store_id'])->max('position');
        MenuBanner::create([
            'store_id' => $data['store_id'],
            'image' => $data['image'],
            'title' => $data['title'] ?? null,
            'subtitle' => $data['subtitle'] ?? null,
            'translations' => $this->cleanTranslations($request),
            'link_url' => $data['link_url'] ?? null,
            'active' => $request->has('active'),
            'position' => $next + 1,
        ]);

        return back()->with('success', ___('Saved Successfully'));
    }

    public function update(Request $request, $banner)
    {
        $banner = $this->ownedBanner((int) $banner);
        $data = $request->validate([
            'image' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'url', 'regex:#^https?://#i', 'max:500'],
            'active' => ['nullable', 'boolean'],
            'translations' => ['nullable', 'array'],
            'translations.*.title' => ['nullable', 'string', 'max:255'],
            'translations.*.subtitle' => ['nullable', 'string', 'max:255'],
        ]);

        $banner->update([
            'image' => $data['image'],
            'title' => $data['title'] ?? null,
            'subtitle' => $data['subtitle'] ?? null,
            'translations' => $this->cleanTranslations($request),
            'link_url' => $data['link_url'] ?? null,
            'active' => $request->has('active'),
        ]);

        return $this->ok($request);
    }

    public function destroy(Request $request, $banner)
    {
        $banner = $this->ownedBanner((int) $banner);
        $banner->delete();

        return $this->ok($request);
    }

    public function reorder(Request $request)
    {
        $ids = (array) $request->get('position', []);
        foreach ($ids as $i => $id) {
            $banner = MenuBanner::find((int) $id);
            if ($banner && in_array((int) $banner->store_id, $this->ownerRestaurantIds(), true)) {
                $banner->update(['position' => $i]);
            }
        }

        return response()->json(['success' => true]);
    }

    private function ok(Request $request)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => ___('Saved Successfully')]);
        }

        return back()->with('success', ___('Saved Successfully'));
    }
}
