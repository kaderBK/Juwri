<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\User;

use App\Models\Story;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\SafeUrl;

/**
 * theme-pack-blade · F3 Story / reel promo.
 *
 * Owner admin CRUD (mirrors User\BannerController) plus the public data endpoint
 * the customer story-viewer consumes. Media is picked from the media library
 * (folder=banner/story), so we persist only the library filename.
 */
class StoryController extends Controller
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

    private function ownedStory(int $id): Story
    {
        $story = Story::findOrFail($id);
        $this->assertOwnsRestaurant((int) $story->store_id);

        return $story;
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

        $stories = $storeId
            ? Story::where('store_id', $storeId)->orderBy('sort')->orderBy('id')->get()
            : collect();

        return view('owner.marketing.stories', compact('stores', 'storeId', 'stories'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true);
        $this->assertOwnsRestaurant((int) $data['store_id']);

        $next = (int) Story::where('store_id', $data['store_id'])->max('sort');
        Story::create([
            'store_id' => $data['store_id'],
            'media_url' => $data['media_url'],
            'caption' => $data['caption'] ?? null,
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $data['cta_url'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'active' => $request->has('active'),
            'sort' => $next + 1,
        ]);

        return back()->with('success', ___('Saved Successfully'));
    }

    public function update(Request $request, $story)
    {
        $story = $this->ownedStory((int) $story);
        $data = $this->validated($request, false);

        $story->update([
            'media_url' => $data['media_url'],
            'caption' => $data['caption'] ?? null,
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $data['cta_url'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'active' => $request->has('active'),
        ]);

        return $this->ok($request);
    }

    public function destroy(Request $request, $story)
    {
        $story = $this->ownedStory((int) $story);
        $story->delete();

        return $this->ok($request);
    }

    public function reorder(Request $request)
    {
        $ids = (array) $request->get('sort', []);
        foreach ($ids as $i => $id) {
            $story = Story::find((int) $id);
            if ($story && in_array((int) $story->store_id, $this->ownerRestaurantIds(), true)) {
                $story->update(['sort' => $i]);
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Public data endpoint for the customer story-viewer.
     * Returns active, currently-in-window stories sorted by `sort`.
     *
     * Resolves the restaurant explicitly (by model, id, or slug) rather than
     * relying solely on implicit route-model binding, so the endpoint behaves
     * identically whether or not SubstituteBindings ran.
     */
    public function publicIndex($store)
    {
        $store = $store instanceof Store
            ? $store
            : Store::idOrSlug($store)->firstOrFail();

        /* This XHR route carries no locale middleware, so localize the default
           CTA label from the same signal the storefront content uses: the
           shopper's language cookie, falling back to the store's own default
           language. An owner-typed cta_label always wins untouched. */
        $langCode = $_COOKIE['Quick_user_lang_code']
            ?? StoreSettings::legacy((int) $store->id, 'default_language');
        if ($langCode && preg_match('/^[a-z]{2}$/', $langCode)) {
            app()->setLocale($langCode);
        }

        $stories = Story::where('store_id', $store->id)
            ->visible()
            ->get()
            ->map(function (Story $s) {
                return [
                    'id' => $s->id,
                    'media_url' => $s->media_full_url,
                    'caption' => $s->caption,
                    'cta_label' => $s->cta_label ?: ($s->cta_url ? ___('Order now') : null),
                    'cta_url' => $s->cta_url,
                    'sort' => $s->sort,
                    'starts_at' => optional($s->starts_at)->toIso8601String(),
                    'expires_at' => optional($s->expires_at)->toIso8601String(),
                ];
            })
            ->values();

        return response()->json(['data' => $stories]);
    }

    private function validated(Request $request, bool $withRestaurant): array
    {
        $rules = [
            'media_url' => ['required', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:500'],
            'cta_label' => ['nullable', 'string', 'max:120'],
            'cta_url' => ['nullable', 'string', 'max:500', static function (string $attribute, mixed $value, \Closure $fail): void {
                // Public href: no javascript:/data: schemes (stored XSS on the storefront).
                if (is_string($value) && trim($value) !== '' && SafeUrl::link($value) === null) {
                    $fail(___('Please enter a valid URL.'));
                }
            }],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'active' => ['nullable', 'boolean'],
        ];
        if ($withRestaurant) {
            $rules['store_id'] = ['required', 'integer'];
        }

        return $request->validate($rules);
    }

    private function ok(Request $request)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => ___('Saved Successfully')]);
        }

        return back()->with('success', ___('Saved Successfully'));
    }
}
