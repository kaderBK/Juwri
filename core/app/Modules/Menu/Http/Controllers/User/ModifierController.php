<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Contracts\AiProviderChain;
use Juwri\Modules\Ai\Contracts\Translator;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\Ai\ExtraNamerService;

/**
 * Reusable modifier library: global extras and variant-option groups that can be
 * attached to many menu items (Toast/Square-style), with per-attach overrides.
 */
class ModifierController extends Controller
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

    private function ownedMenu(int $menuId): Menu
    {
        $menu = Menu::findOrFail($menuId);
        $this->assertOwnsRestaurant((int) $menu->store_id);

        return $menu;
    }

    // ---- Library admin page ---------------------------------------------------

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

        $extras = collect();
        $groups = collect();
        $menu_languages = $default_menu_language = null;
        if ($storeId) {
            $extras = MenuExtra::where('store_id', $storeId)->where('is_global', true)
                ->withCount('menus')->orderBy('title')->get();
            $groups = MenuVariantOption::where('store_id', $storeId)->where('is_global', true)
                ->withCount('menus')->orderBy('title')->get();
            [$menu_languages, $default_menu_language] = $this->menuLanguages($storeId);
        }

        // Can the bulk builder auto-name from images? True only if a vision-capable provider is configured.
        $aiVision = false;
        try {
            $aiVision = ! empty(app(AiProviderChain::class)->chain($storeId ?: null, true));
        } catch (\Throwable $e) {
            $aiVision = false;
        }
        $translatable = $menu_languages && $menu_languages->count() > 1;

        return view('owner.menu.modifiers', compact(
            'stores', 'storeId', 'extras', 'groups', 'menu_languages', 'default_menu_language',
            'aiVision', 'translatable'
        ));
    }

    /** Resolve the restaurant's translation languages + the currently-active editing language. */
    private function menuLanguages(int $storeId): array
    {
        $codes = store_options($storeId, 'menu_languages');
        $default = store_options($storeId, 'default_language');
        if (! $codes || ! $default) {
            return [collect(), null];
        }
        $languages = Language::whereIn('code', (array) $codes)->get();
        $active = null;
        if (! empty($_COOKIE['Quick_user_lang_code'])) {
            $active = $languages->firstWhere('code', $_COOKIE['Quick_user_lang_code']);
        }
        if (! $active) {
            $active = $languages->firstWhere('code', $default);
        }

        return [$languages, $active];
    }

    /** Apply a title (+ optional options) edit to the right language slot. */
    private function applyTranslation($model, int $storeId, string $title, ?array $options = null): array
    {
        $translations = $model->translations ?? new \stdClass;
        $resolvedTitle = $model->getOriginal('title');
        $resolvedOptions = null;
        if ($options !== null) {
            $rawOptions = $model->getOriginal('options');
            $resolvedOptions = is_string($rawOptions) ? (json_decode($rawOptions, true) ?: []) : (array) $rawOptions;
        }

        $lang = $_COOKIE['Quick_user_lang_code'] ?? null;
        if ($lang) {
            $entry = ['title' => $title];
            if ($options !== null) {
                $entry['options'] = $options;
            }
            $translations->{$lang} = $entry;
            if ($lang == store_options($storeId, 'default_language')) {
                $resolvedTitle = $title;
                if ($options !== null) {
                    $resolvedOptions = $options;
                }
            }
        } else {
            $resolvedTitle = $title;
            if ($options !== null) {
                $resolvedOptions = $options;
            }
        }

        return [$resolvedTitle, $resolvedOptions, $translations];
    }

    // ---- Global extras --------------------------------------------------------

    /**
     * Suggest a title for one image (vision). Used by the bulk builder to
     * auto-name each picked image. Always 200s with a (possibly empty) title so
     * the row falls back to manual entry on any AI failure.
     */
    public function aiExtraTitle(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'image' => ['required', 'string', 'max:255'],
        ]);
        $this->assertOwnsRestaurant((int) $data['store_id']);

        $base64 = $this->imageFileToBase64(basename($data['image']), (int) $data['store_id']);
        if (! $base64) {
            return response()->json(['success' => true, 'title' => '']);
        }

        [$languages, $active] = $this->menuLanguages((int) $data['store_id']);
        $langName = $active->name ?? 'English';

        $title = app(ExtraNamerService::class)
            ->titleFromImage($base64, $langName, auth()->id(), (int) $data['store_id']);

        return response()->json(['success' => true, 'title' => $title ?? '']);
    }

    /**
     * Create many global extras in one shot from a reviewed draft (image +
     * title + price per row). Optionally translate the titles into the
     * restaurant's other active menu languages.
     */
    public function bulkStoreExtras(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'translate' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.image' => ['nullable', 'string', 'max:255'],
        ]);
        $storeId = (int) $data['store_id'];
        $this->assertOwnsRestaurant($storeId);

        $created = [];
        foreach ($data['items'] as $row) {
            $created[] = MenuExtra::create([
                'store_id' => $storeId,
                'is_global' => true,
                'menu_id' => null,
                'title' => $row['title'],
                'price' => $row['price'],
                'image' => ! empty($row['image']) ? basename($row['image']) : null,
                'active' => 1,
            ]);
        }

        if (! empty($data['translate'])) {
            $this->translateExtras($created, $storeId);
        }

        return response()->json(['success' => true, 'created' => count($created)]);
    }

    /** Translate a batch of just-created extras into the other active menu languages. */
    private function translateExtras(array $extras, int $storeId): void
    {
        $default = store_options($storeId, 'default_language');
        $codes = array_values(array_diff((array) store_options($storeId, 'menu_languages'), [$default]));
        if (empty($default) || empty($codes)) {
            return;
        }

        $fields = [];
        foreach ($extras as $i => $extra) {
            $fields['t'.$i] = $extra->getOriginal('title');
        }

        $translator = app(Translator::class);
        foreach ($codes as $lang) {
            try {
                $out = $translator->translate($fields, $lang, $default, auth()->id(), $storeId);
            } catch (\Throwable $e) {
                Log::warning('[AI extras translate] '.$lang.': '.$e->getMessage());

                continue;
            }
            foreach ($extras as $i => $extra) {
                $t = trim((string) ($out['t'.$i] ?? ''));
                if ($t === '') {
                    continue;
                }
                $translations = $extra->translations ?? new \stdClass;
                $translations->{$lang} = ['title' => $t];
                $extra->translations = $translations;
                $extra->save();
            }
        }
    }

    /** Read a stored menu image (local or CDN) and return downscaled JPEG base64 for vision. */
    private function imageFileToBase64(string $filename, int $storeId): ?string
    {
        try {
            $local = public_path('storage/menu/'.$filename);
            $bytes = is_file($local) ? file_get_contents($local) : @file_get_contents(media_url('storage/menu/', $filename, $storeId));
            if (! $bytes) {
                return null;
            }
            $img = Image::make($bytes);
            $img->resize(768, 768, function ($c) {
                $c->aspectRatio();
                $c->upsize();
            });

            return base64_encode((string) $img->encode('jpg', 80));
        } catch (\Throwable $e) {
            Log::warning('[AI extra namer] image read failed: '.$e->getMessage());

            return null;
        }
    }

    public function storeExtra(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:255'],
        ]);
        $this->assertOwnsRestaurant((int) $data['store_id']);

        MenuExtra::create([
            'store_id' => $data['store_id'],
            'is_global' => true,
            'menu_id' => null,
            'title' => $data['title'],
            'price' => $data['price'],
            'image' => $data['image'] ?? null,
        ]);

        return back()->with('success', ___('Saved Successfully'));
    }

    public function updateExtra(Request $request, $extra)
    {
        $extra = MenuExtra::where('is_global', true)->findOrFail($extra);
        $this->assertOwnsRestaurant((int) $extra->store_id);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:255'],
            'active' => ['nullable', 'boolean'],
        ]);

        [$title, , $translations] = $this->applyTranslation($extra, (int) $extra->store_id, $data['title']);

        $extra->update([
            'title' => $title,
            'translations' => $translations,
            'price' => $data['price'],
            'image' => $request->has('image') ? ($data['image'] ?? null) : $extra->image,
            'active' => $request->has('active'),
        ]);

        return $this->ok($request);
    }

    public function destroyExtra(Request $request, $extra)
    {
        $extra = MenuExtra::where('is_global', true)->findOrFail($extra);
        $this->assertOwnsRestaurant((int) $extra->store_id);
        $extra->menus()->detach();
        $extra->delete();

        return $this->ok($request);
    }

    // ---- Global variant-option groups ----------------------------------------

    public function storeGroup(Request $request)
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'options' => ['required', 'string'],
            'option_prices' => ['nullable', 'string'],
            'required' => ['nullable', 'boolean'],
            'min_select' => ['nullable', 'integer', 'min:0', 'max:50'],
            'max_select' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);
        $this->assertOwnsRestaurant((int) $data['store_id']);

        $options = array_values(array_filter(array_map('trim', explode(',', $data['options']))));
        $prices = $this->parsePrices($data['option_prices'] ?? '', count($options), (int) $data['store_id']);

        MenuVariantOption::create([
            'store_id' => $data['store_id'],
            'is_global' => true,
            'menu_id' => null,
            'title' => $data['title'],
            'options' => $options,
            'option_prices' => $prices,
            'required' => $request->boolean('required'),
            'min_select' => (int) ($data['min_select'] ?? 0),
            'max_select' => ! empty($data['max_select']) ? (int) $data['max_select'] : null,
        ]);

        return back()->with('success', ___('Saved Successfully'));
    }

    public function updateGroup(Request $request, $group)
    {
        $group = MenuVariantOption::where('is_global', true)->findOrFail($group);
        $this->assertOwnsRestaurant((int) $group->store_id);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'options' => ['required', 'string'],
            'option_prices' => ['nullable', 'string'],
            'required' => ['nullable', 'boolean'],
            'min_select' => ['nullable', 'integer', 'min:0', 'max:50'],
            'max_select' => ['nullable', 'integer', 'min:0', 'max:50'],
            'active' => ['nullable', 'boolean'],
        ]);

        $options = array_values(array_filter(array_map('trim', explode(',', $data['options']))));
        [$title, $resolvedOptions, $translations] = $this->applyTranslation($group, (int) $group->store_id, $data['title'], $options);
        // Prices align to the DEFAULT option order; derive count from the default options.
        $prices = $this->parsePrices($data['option_prices'] ?? '', count($resolvedOptions), (int) $group->store_id);

        $group->update([
            'title' => $title,
            'options' => $resolvedOptions,
            'translations' => $translations,
            'option_prices' => $prices,
            'required' => $request->boolean('required'),
            'min_select' => (int) ($data['min_select'] ?? 0),
            'max_select' => ! empty($data['max_select']) ? (int) $data['max_select'] : null,
            'active' => $request->has('active'),
        ]);

        return $this->ok($request);
    }

    public function destroyGroup(Request $request, $group)
    {
        $group = MenuVariantOption::where('is_global', true)->findOrFail($group);
        $this->assertOwnsRestaurant((int) $group->store_id);
        $group->menus()->detach();
        $group->delete();

        return $this->ok($request);
    }

    // ---- Attach / detach to a specific item ----------------------------------

    public function attachExtra(Request $request, $menu)
    {
        $menu = $this->ownedMenu((int) $menu);
        $data = $request->validate([
            'extra_id' => ['required', 'integer'],
            'price_override' => ['nullable', 'numeric', 'min:0'],
        ]);

        $extra = MenuExtra::findOrFail($data['extra_id']);
        $this->assertOwnsRestaurant((int) $extra->store_id);

        $menu->attachedExtras()->syncWithoutDetaching([
            $extra->id => [
                'price_override' => $data['price_override'] ?? null,
                'position' => (int) $menu->attachedExtras()->count(),
                'active' => true,
            ],
        ]);

        // Pivot writes fire no model event, so BustsMenuCache never runs — bust explicitly.
        StorefrontMenuCache::bust((int) $menu->store_id);

        return $this->ok($request);
    }

    public function detachExtra(Request $request, $menu, $extra)
    {
        $menu = $this->ownedMenu((int) $menu);
        $menu->attachedExtras()->detach((int) $extra);
        StorefrontMenuCache::bust((int) $menu->store_id);

        return $this->ok($request);
    }

    public function attachGroup(Request $request, $menu)
    {
        $menu = $this->ownedMenu((int) $menu);
        $data = $request->validate([
            'group_id' => ['required', 'integer'],
            'required' => ['nullable', 'boolean'],
            'min_select' => ['nullable', 'integer', 'min:0', 'max:50'],
            'max_select' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);

        $group = MenuVariantOption::findOrFail($data['group_id']);
        $this->assertOwnsRestaurant((int) $group->store_id);

        $menu->attachedVariantOptions()->syncWithoutDetaching([
            $group->id => [
                'required' => $request->has('required') ? $request->boolean('required') : null,
                'min_select' => $data['min_select'] ?? null,
                'max_select' => $data['max_select'] ?? null,
                'position' => (int) $menu->attachedVariantOptions()->count(),
                'active' => true,
            ],
        ]);

        // Pivot writes fire no model event, so BustsMenuCache never runs — bust explicitly.
        StorefrontMenuCache::bust((int) $menu->store_id);

        return $this->ok($request);
    }

    public function detachGroup(Request $request, $menu, $group)
    {
        $menu = $this->ownedMenu((int) $menu);
        $menu->attachedVariantOptions()->detach((int) $group);
        StorefrontMenuCache::bust((int) $menu->store_id);

        return $this->ok($request);
    }

    /**
     * Parse a comma-separated per-option price string into a float array aligned to the option
     * count. Returns null when every price is zero/empty (group is free).
     */
    private function parsePrices(string $raw, int $count, int $storeId): ?array
    {
        if (trim($raw) === '') {
            return null;
        }
        $parts = array_map(fn ($p) => (float) trim($p), explode(',', $raw));
        $prices = [];
        // P6-258: KWD/BHD/JOD option prices keep 3 decimals.
        $dec = money_decimals(store_currency($storeId));
        for ($i = 0; $i < $count; $i++) {
            $prices[$i] = money_round((float) ($parts[$i] ?? 0), $dec);
        }

        return array_sum($prices) > 0 ? $prices : null;
    }

    private function ok(Request $request)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => ___('Saved Successfully')]);
        }

        return back()->with('success', ___('Saved Successfully'));
    }
}
