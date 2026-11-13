<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User\Concerns;

use App\Models\ImageMenu;
use App\Models\MenuCategory;
use App\Models\PriceSchedule;
use App\Services\Entitlements;
use App\Services\Storefront\StorefrontMenuCache;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Models\TranslationMemory;
use Juwri\Modules\Media\Contracts\Images;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\MenuAdminService;

/**
 * The owner's menu editor: categories and sub-categories, items, the item's
 * modifiers (option groups, priced variants, extras) and the image-only menu
 * used by the flip-book / folio templates.
 *
 * Conventions shared by every endpoint here:
 *  - only the store's owner may write; anyone else gets the generic
 *    "Unexpected Error" answer (flash for form posts, JSON for AJAX);
 *  - ids sent in the body are always looked up inside the route's store;
 *  - the editor's language comes from the `Quick_user_lang_code` cookie. Text
 *    edited in the store's default language (or with no cookie) replaces the
 *    stored value; in any other language it only goes into `translations`;
 *  - writes that change what the storefront shows bust its menu cache.
 */
trait ManagesMenu
{
    /** Item descriptions keep only these inline tags. */
    private const ITEM_DESCRIPTION_TAGS = '<b><strong><em><i><br><p><ul><ol><li><span>';

    private const MENU_UPLOAD_DIR = 'storage/menu/';

    /** At most this many gallery pictures per item. */
    private const ITEM_GALLERY_MAX = 5;

    /** Checkbox columns stored as 1/0 from "was the box sent". */
    private const ITEM_FLAG_COLUMNS = [
        'is_vegan', 'is_halal', 'is_gluten_free', 'is_popular', 'is_sold_out', 'is_daily_special', 'track_inventory',
    ];

    /** Optional numeric columns: [cast, value when the field is empty]. */
    private const ITEM_NUMBER_COLUMNS = [
        'cost' => ['float', null],
        'calories' => ['int', null],
        'protein' => ['float', null],
        'carbs' => ['float', null],
        'fat' => ['float', null],
        'stock_qty' => ['int', null],
        'low_stock_threshold' => ['int', 5],
        'course' => ['int', 1],
        'prep_minutes' => ['int', null],
        'tax_rate' => ['float', null], // P6-99b: the item's own VAT % (empty = the store's rate)
    ];

    private const TIME_OF_DAY_RULE = 'regex:/^\d{2}:\d{2}(:\d{2})?$/';

    // ------------------------------------------------------------------ pages

    /**
     * The editor page. Retail and service stores have their own catalogue
     * screens; image-only templates get the image editor.
     */
    public function menu(Store $store): View|RedirectResponse
    {
        abort_unless($this->ownsStore(request(), $store), 404);

        $form = app(VerticalRegistry::class)->itemForm($store->store_type ?? null);
        $elsewhere = ['retail' => 'retail.products.index', 'service' => 'laundry.services.index'][$form] ?? null;
        if ($elsewhere !== null && Route::has($elsewhere)) {
            return redirect()->route($elsewhere, $store->id);
        }

        $template = (string) store_options($store->id, 'store_template');
        if ($template === 'flipbook' || $template === 'folio') {
            return view('owner.menu.image', ['store' => $store]);
        }

        [$languages, $editing] = $this->editorLanguages($store);

        return view('owner.menu.index', [
            'store' => $store,
            'allergies' => Allergy::availableFor($store->id)->orderBy('position')->get(),
            'menu_languages' => $languages,
            'default_menu_language' => $editing,
        ]);
    }

    /**
     * One item's modifiers page: its variants (with a readable label), and the
     * store's reusable library groups/extras with the ones already attached.
     */
    public function menuItemExtras(Store $store, Menu $menu): View
    {
        abort_unless($this->ownsStore(request(), $store), 404);
        abort_unless((int) $menu->store_id === (int) $store->id, 404);

        $groups = $menu->variantOptions;
        $variants = $menu->variants->map(function (MenuVariant $variant) use ($groups) {
            $variant->title = $this->variantLabel($variant->options, $groups);

            return $variant;
        })->all();

        [$menu_languages, $default_menu_language] = $this->editorLanguages($store);
        $library = fn (string $model) => $model::query()->where('store_id', $store->id)->where('is_global', true)->orderBy('title')->get();

        // BL-054: the view reads $extra->image_url for every extra, and the
        // accessor needs the parent menu for its store id. Point those relations
        // at the route's menu (and mark the library rows as menu-less) so the
        // page never lazy-loads `menu` while preventLazyLoading() is on.
        $menu->loadMissing('extras');
        $menu->extras->each(fn (MenuExtra $extra) => $extra->setRelation('menu', $menu));

        $globalExtras = $library(MenuExtra::class);
        $globalExtras->each(fn (MenuExtra $extra) => $extra->setRelation('menu', null));

        return view('owner.menu.extras', [
            'store' => $store,
            'menu' => $menu,
            'variants' => $variants,
            'menu_languages' => $menu_languages,
            'default_menu_language' => $default_menu_language,
            'globalExtras' => $globalExtras,
            'globalGroups' => $library(MenuVariantOption::class),
            'attachedExtraIds' => $menu->attachedExtras()->where('is_global', true)->pluck('modifiers.id')->all(),
            'attachedGroupIds' => $menu->attachedVariantOptions()->where('is_global', true)->pluck('modifier_groups.id')->all(),
        ]);
    }

    // ------------------------------------------------------------- categories

    public function addCategory(Request $request, Store $store): RedirectResponse
    {
        return $this->createCategory($request, $store, ['image' => 'nullable|string|max:255'],
            fn () => ['image' => $request->filled('image') ? basename((string) $request->get('image')) : null]); // P6-308: file name only, like items
    }

    public function addSubCategory(Request $request, Store $store): RedirectResponse
    {
        return $this->createCategory($request, $store, ['parent' => 'required|integer'],
            fn () => ['parent' => $request->get('parent')]);
    }

    /**
     * Rename / re-image a category, show or hide it, and set its availability
     * window (same stored vocabulary as the item-level columns).
     */
    public function updateCategory(Request $request, Store $store): RedirectResponse
    {
        return $this->editCategory($request, $store, [
            'id' => 'required|integer',
            'image' => 'nullable|string|max:255',
            // '0' hides the category and all its items from customers.
            'active' => 'nullable|in:0,1',
            'available_from' => ['nullable', self::TIME_OF_DAY_RULE],
            'available_to' => ['nullable', self::TIME_OF_DAY_RULE],
            'available_days' => 'nullable', // day-code array (form) or CSV (API)
            'available_order_types' => 'nullable',
        ], function (MenuCategory $category) use ($request) {
            $changes = [
                // An absent field (older API clients) keeps the stored value.
                'image' => $request->has('image') ? ($request->get('image') ? basename((string) $request->get('image')) : null) : $category->image,
                'active' => $request->filled('active') ? (string) $request->get('active') : $category->getRawOriginal('active'),
            ];

            // The dashboard always posts the window; an unticked day group posts
            // nothing, which means "no restriction".
            return $changes + $this->availabilityColumns($request);
        }, rememberTranslation: true);
    }

    public function updateSubCategory(Request $request, Store $store): RedirectResponse
    {
        return $this->editCategory($request, $store, ['parent' => 'required|integer', 'id' => 'required|integer'],
            fn () => ['parent' => $request->get('parent')], rememberTranslation: false);
    }

    /**
     * Delete a category with its items. When it has sub-categories the owner
     * must pick `mode` = cascade or reparent; without one we answer
     * `needs_choice` and delete nothing (MenuAdminService::deleteCategory).
     */
    public function deleteCategory(Request $request, Store $store): JsonResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return $this->menuJson(false, 'Unexpected Error');
        }
        if ($refusal = $this->ajaxInputRefusal($request, ['id' => 'required|integer'])) {
            return $refusal;
        }

        $category = MenuCategory::query()->where('store_id', $store->id)->findOrFail($request->get('id'));
        $mode = $request->get('mode');
        $outcome = MenuAdminService::deleteCategory($category, in_array($mode, ['cascade', 'reparent'], true) ? $mode : 'refuse', forceItems: true);

        if (! $outcome['ok'] && $outcome['reason'] === 'has_subcategories') {
            return $this->menuJson(false, 'This category has sub-categories.', [
                'needs_choice' => true,
                'subcategories' => $outcome['subcategories'],
            ]);
        }

        StorefrontMenuCache::bust((int) $store->id);

        return $this->menuJson(true, 'Deleted Successfully');
    }

    public function reorderCategory(Request $request, Store $store): JsonResponse
    {
        if ($this->ownsStore($request, $store)) {
            $this->storePositions(MenuCategory::query()->where('store_id', $store->id), $request->position);
        }
        StorefrontMenuCache::bust((int) $store->id);

        return $this->menuJson(true, 'Updated Successfully');
    }

    // ------------------------------------------------------------------ items

    public function addMenuItem(Request $request, Store $store): JsonResponse
    {
        $store = $this->editorStore($store);
        if (! $this->ownsStore($request, $store)) {
            return $this->menuJson(false, 'Unexpected Error');
        }
        if ($refusal = $this->ajaxInputRefusal($request, $this->menuItemRules($store))) {
            return $refusal;
        }

        $limit = effective_limit('menu');
        // Same counter as the REST/staff APIs, the MCP tools and Entitlements::usage().
        if (! Entitlements::isUnlimitedSentinel($limit)
            && MenuAdminService::usedFor((int) $store->id, 'menu', $request->get('category_id')) >= $limit) {
            return $this->menuJson(false, 'Limit exceeded, please upgrade your membership.');
        }

        $description = $request->get('description');
        $gallery = $this->itemGallery($request, array_filter((array) $request->get('gallery', [])), self::ITEM_GALLERY_MAX);

        $item = Menu::create([
            'store_id' => $store->id,
            'name' => $request->get('name'),
            'description' => $description ? strip_tags($description, self::ITEM_DESCRIPTION_TAGS) : null,
            'image' => $this->itemMainImage($request, $store, 'default.png'),
            'gallery_images' => $gallery ?: null,
        ] + $this->menuItemColumns($request));

        log_activity('menu.create', $item, ['name' => $request->get('name')], $store->id);

        return $this->menuJson(true, 'Saved Successfully');
    }

    public function updateMenuItem(Request $request, Store $store): JsonResponse
    {
        $store = $this->editorStore($store);
        if (! $this->ownsStore($request, $store)) {
            return $this->menuJson(false, 'Unexpected Error');
        }
        if ($refusal = $this->ajaxInputRefusal($request, $this->menuItemRules($store))) {
            return $refusal;
        }

        // The route proves ownership of the store only; the item id is from the body.
        $item = Menu::query()->where('store_id', $store->id)->findOrFail($request->get('id'));

        $texts = $this->languageAwareChanges($item, $store, [
            'name' => ['title', $request->get('name')],
            'description' => ['description', $request->get('description')],
        ], rememberTranslation: true);
        $texts['description'] = $texts['description'] ? strip_tags($texts['description'], self::ITEM_DESCRIPTION_TAGS) : null;

        // A sent gallery[] is the full ordered list; otherwise start from the saved one.
        $current = $request->has('gallery') ? array_filter((array) $request->get('gallery')) : ($item->gallery_images ?? []);
        $gallery = $this->itemGallery($request, $current, PHP_INT_MAX, drop: true);

        $item->update($texts + [
            'image' => $this->itemMainImage($request, $store, (string) $item->image),
            'gallery_images' => $gallery ?: null,
        ] + $this->menuItemColumns($request));

        log_activity('menu.update', $item, ['name' => $request->get('name')], $store->id);

        return $this->menuJson(true, 'Saved Successfully');
    }

    /**
     * Quick-edit only the item's main image from the menu list, without
     * touching any other field (a full updateMenuItem would overwrite them).
     * Accepts a media-library filename (empty = reset to default.png).
     */
    public function quickImageMenuItem(Request $request, Store $store): JsonResponse
    {
        $store = $this->editorStore($store);
        if (! $this->ownsStore($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        if ($refusal = $this->ajaxInputRefusal($request, ['id' => 'required|integer', 'image' => 'nullable|string|max:255'])) {
            return $refusal;
        }

        $menu = Menu::where('id', $request->get('id'))->where('store_id', $store->id)->firstOrFail();
        $image = $request->filled('image') ? basename($request->input('image')) : 'default.png';
        $previous = $menu->image;
        $menu->update(['image' => $image]);

        // The full editor logs menu.update; this path changed the image silently,
        // so a picture that changed here left no trace to investigate later.
        log_activity('items.image', $menu, ['from' => $previous, 'to' => $image], $store->id);

        return response()->json([
            'success' => true,
            'message' => 'Saved Successfully.',
            'image' => $image,
            'url' => media_url(self::MENU_UPLOAD_DIR, $image, $store->id),
        ]);
    }

    /**
     * Quick-edit an item's name and/or price inline from the menu list, without
     * touching any other field. Name edits respect the active editing language
     * (translation), same as the full editor.
     */
    public function quickEditMenuItem(Request $request, Store $store): JsonResponse
    {
        $store = $this->editorStore($store);
        if (! $this->ownsStore($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        if ($refusal = $this->ajaxInputRefusal($request, [
            'id' => 'required|integer', 'name' => 'nullable|string|max:255', 'price' => 'nullable|numeric|min:0',
        ])) {
            return $refusal;
        }

        $menu = Menu::where('id', $request->get('id'))->where('store_id', $store->id)->firstOrFail();
        $data = [];

        if ($request->filled('name')) {
            $lang = $this->editingLanguage();
            $default = store_options($store->id, 'default_language');
            if ($lang && $default && $lang !== $default) {
                $translations = $menu->translations ?? new \stdClass;
                $existing = (array) ($translations->{$lang} ?? []);
                $existing['title'] = $request->get('name');
                $translations->{$lang} = $existing;
                $data['translations'] = $translations;
                TranslationMemory::remember($store->id, $lang, (string) $menu->getRawOriginal('name'), (string) $request->get('name'));
            } else {
                $data['name'] = $request->get('name');
            }
        }

        if ($request->filled('price')) {
            $data['price'] = (float) $request->get('price');
        }

        if (! empty($data)) {
            $menu->update($data);
        }
        $menu->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Saved Successfully.',
            'name' => $menu->name,
            'price' => (float) $menu->price,
            'price_formatted' => store_price_symbol($menu->price, $menu->store_id), // MC-21: store currency, not platform
        ]);
    }

    /** Soft-deletes the item (D4 trash) and records who removed what. */
    public function deleteMenuItem(Request $request, Store $store): JsonResponse
    {
        $store = $this->editorStore($store);
        if (! $this->ownsStore($request, $store)) {
            return $this->menuJson(false, 'Unexpected Error');
        }
        if ($refusal = $this->ajaxInputRefusal($request, ['id' => 'required|integer'])) {
            return $refusal;
        }

        $item = Menu::query()->where('store_id', $store->id)->findOrFail($request->get('id'));
        $item->delete();
        log_activity('menu.delete', null, ['id' => $request->get('id'), 'name' => $item->name], $store->id);

        return $this->menuJson(true, 'Deleted Successfully');
    }

    public function reorderMenuItem(Request $request, Store $store): JsonResponse
    {
        if ($this->ownsStore($request, $store)) {
            $this->storePositions(Menu::query()->where('store_id', $store->id), $request->position);
        }

        return $this->menuJson(true, 'Updated Successfully');
    }

    // -------------------------------------------------------------- allergens

    /**
     * Create an owner-defined allergen for this restaurant (adds to the global
     * catalog the owner can pick from). Reuses an existing same-title allergen
     * (global or own) instead of duplicating.
     */
    public function storeAllergen(Request $request, Store $store): JsonResponse
    {
        $store = $this->editorStore($store);
        if (! $this->ownsStore($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'translations' => ['nullable', 'array'],
            'translations.*.title' => ['nullable', 'string', 'max:100'],
        ]);
        $title = trim($data['title']);
        // per-locale titles, trimmed and dropping empties (base title covers the
        // default language, so the accessor falls back to it where a locale is blank)
        $translations = $request->collect('translations')
            ->map(fn ($t) => ['title' => trim($t['title'] ?? '')])
            ->filter(fn ($t) => $t['title'] !== '')
            ->all();

        $existing = Allergy::availableFor($store->id)
            ->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])
            ->first();
        if ($existing) {
            return response()->json(['success' => true, 'id' => $existing->id, 'title' => $existing->title, 'existing' => true]);
        }

        $allergen = Allergy::create([
            'store_id' => $store->id,
            'title' => $title,
            'translations' => $translations ?: null,
            'active' => '1',
            'position' => (int) Allergy::max('position') + 1,
        ]);

        return response()->json(['success' => true, 'id' => $allergen->id, 'title' => $allergen->title]);
    }

    /**
     * Delete an owner-defined allergen for this restaurant and strip its id from
     * any of the restaurant's menu items. Global (superadmin) allergens can't be
     * deleted here.
     */
    public function destroyAllergen(Request $request, Store $store, $allergen): JsonResponse
    {
        $store = $this->editorStore($store);
        if (! $this->ownsStore($request, $store)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Only this restaurant's own allergen — never a global one.
        $row = Allergy::where('id', $allergen)->where('store_id', $store->id)->first();
        if (! $row) {
            return response()->json(['success' => false, 'message' => ___('Not found')], 404);
        }
        $id = (string) $row->id;
        $row->delete();

        // Remove the id from any item that referenced it (DB-portable: filter in PHP).
        Menu::where('store_id', $store->id)
            ->whereNotNull('allergies')->where('allergies', '!=', '')
            ->get()
            ->each(function ($m) use ($id) {
                $orig = array_map('trim', explode(',', (string) $m->allergies));
                if (! in_array($id, $orig, true)) {
                    return;
                }
                $ids = array_values(array_filter($orig, fn ($x) => $x !== '' && $x !== $id));
                $m->update(['allergies' => $ids ? implode(',', $ids) : null]);
            });

        return response()->json(['success' => true]);
    }

    // -------------------------------------------------------------- modifiers

    /** An option group ("Size": S, M, L) on one item. */
    public function menuAddVariantOption(Request $request, Store $store, Menu $menu): RedirectResponse
    {
        return $this->createModifier($request, $store, $menu, ['title' => 'required|string|max:255', 'options' => 'required|string'],
            fn () => [MenuVariantOption::class, $request->only('title') + [
                'options' => $this->optionValues($request),
                'store_id' => $store->getKey(),
            ]]);
    }

    /** A priced combination of option values. */
    public function menuAddVariant(Request $request, Store $store, Menu $menu): RedirectResponse
    {
        return $this->createModifier($request, $store, $menu, ['price' => 'required|numeric|min:0'],
            fn () => [MenuVariant::class, ['options' => $request->input('options')] + $request->only('price')]);
    }

    /** A priced add-on ("Extra cheese"). */
    public function menuAddExtra(Request $request, Store $store, Menu $menu): RedirectResponse
    {
        return $this->createModifier($request, $store, $menu, [
            'title' => 'required|string|max:255', 'price' => 'required|numeric|min:0', 'image' => 'nullable|string|max:255',
        ], fn () => [MenuExtra::class, $request->only('title', 'price') + [
            'image' => $request->filled('image') ? $request->get('image') : null,
            'store_id' => $store->getKey(),
        ]]);
    }

    /**
     * Rename an option group and its values (per editing language), set the
     * per-value pictures (media-library filenames aligned to the values) and
     * the active flag.
     */
    public function menuUpdateVariantOption(Request $request, Store $store, Menu $menu): JsonResponse
    {
        return $this->editModifier($request, $store, MenuVariantOption::class, [
            'title' => 'required|string|max:255', 'options' => 'required|string',
        ], function (MenuVariantOption $group) use ($request, $store) {
            $pictures = $group->option_images;
            if ($request->has('option_images')) {
                $sent = array_map(fn ($file) => $file ?: null, (array) $request->get('option_images'));
                $pictures = array_filter($sent) ? array_values($sent) : null;
            }

            $group->update($this->languageAwareChanges($group, $store, [
                'title' => ['title', $request->get('title')],
                'options' => ['options', $this->optionValues($request)],
            ]) + ['option_images' => $pictures, 'active' => $request->has('active')]);

            return $this->menuJson(true, 'Saved Successfully');
        });
    }

    /**
     * Re-price a variant / change its option values; answers the variant's
     * new label ("L, Thin") for the row.
     */
    public function menuUpdateVariant(Request $request, Store $store, Menu $menu): JsonResponse
    {
        return $this->editModifier($request, $store, MenuVariant::class, ['price' => 'required|numeric|min:0'],
            function (MenuVariant $variant) use ($request, $menu) {
                $variant->update([
                    'price' => $request->get('price'),
                    'options' => $request->get('options'),
                    'active' => $request->has('active'),
                ]);

                return $this->menuJson(true, 'Saved Successfully', [
                    'title' => $this->variantLabel($request->get('options'), $menu->variantOptions),
                ]);
            });
    }

    public function menuUpdateExtra(Request $request, Store $store, Menu $menu): JsonResponse
    {
        return $this->editModifier($request, $store, MenuExtra::class, [
            'title' => 'required|string|max:255', 'price' => 'required|numeric|min:0', 'image' => 'nullable|string|max:255',
        ], function (MenuExtra $extra) use ($request, $store) {
            $extra->update($this->languageAwareChanges($extra, $store, ['title' => ['title', $request->get('title')]]) + [
                'price' => $request->get('price'),
                'image' => $request->has('image') ? ($request->get('image') ?: null) : $extra->image,
                'active' => $request->has('active'),
            ]);

            return $this->menuJson(true, 'Saved Successfully');
        });
    }

    public function menuDeleteVariantOption(Request $request, Store $store): JsonResponse
    {
        return $this->dropModifier($request, $store, MenuVariantOption::class);
    }

    public function menuDeleteVariant(Request $request, Store $store): JsonResponse
    {
        return $this->dropModifier($request, $store, MenuVariant::class);
    }

    public function menuDeleteExtra(Request $request, Store $store): JsonResponse
    {
        return $this->dropModifier($request, $store, MenuExtra::class);
    }

    public function menuReorderVariantOption(Request $request, Store $store): JsonResponse
    {
        return $this->sortModifiers($request, $store, MenuVariantOption::class);
    }

    public function menuReorderVariant(Request $request, Store $store): JsonResponse
    {
        return $this->sortModifiers($request, $store, MenuVariant::class);
    }

    public function menuReorderExtra(Request $request, Store $store): JsonResponse
    {
        return $this->sortModifiers($request, $store, MenuExtra::class);
    }

    // ------------------------------------------------------------- image menu

    /**
     * One page of an image-only menu. The dashboard posts these as plain forms,
     * AJAX callers expect JSON — see pictureMenuAnswer().
     */
    public function addImageMenuItem(Request $request, Store $store): JsonResponse|RedirectResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return $this->pictureMenuAnswer($request, false, ___('Unexpected Error'));
        }
        $check = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'image' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);
        if ($check->fails()) {
            return $this->pictureMenuAnswer($request, false, $this->validationSummary($check));
        }

        $limit = effective_limit('menu');
        if (! Entitlements::isUnlimitedSentinel($limit) && ImageMenu::query()->where('store_id', $store->id)->count() >= $limit) {
            return $this->pictureMenuAnswer($request, false, ___('Limit exceeded, please upgrade your membership.'));
        }

        ImageMenu::create(['image' => $this->storePicturePage($request, $store)]
            + $this->picturePageColumns($request) + ['store_id' => $store->getKey()]);

        return $this->pictureMenuAnswer($request, true, ___('Saved Successfully'));
    }

    public function updateImageMenuItem(Request $request, Store $store): JsonResponse|RedirectResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return $this->pictureMenuAnswer($request, false, ___('Unexpected Error'));
        }
        $check = Validator::make($request->all(), [
            'id' => 'required|integer',
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);
        if ($check->fails()) {
            return $this->pictureMenuAnswer($request, false, $this->validationSummary($check));
        }

        $page = ImageMenu::query()->where('store_id', $store->id)->findOrFail($request->get('id'));
        $page->update($this->picturePageColumns($request) + [
            'image' => $request->has('image') ? $this->storePicturePage($request, $store, $page->image) : $page->image,
        ]);

        return $this->pictureMenuAnswer($request, true, ___('Saved Successfully'));
    }

    public function deleteImageMenuItem(Request $request, Store $store): JsonResponse|RedirectResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return $this->pictureMenuAnswer($request, false, ___('Unexpected Error'));
        }
        $check = Validator::make($request->all(), ['id' => 'required|integer']);
        if ($check->fails()) {
            return $this->pictureMenuAnswer($request, false, $this->validationSummary($check));
        }

        ImageMenu::query()->where('store_id', $store->id)->findOrFail($request->get('id'))->delete();

        return $this->pictureMenuAnswer($request, true, ___('Deleted Successfully'));
    }

    public function reorderImageMenuItem(Request $request, Store $store): JsonResponse
    {
        if ($this->ownsStore($request, $store)) {
            $this->storePositions(ImageMenu::query()->where('store_id', $store->id), $request->position);
        }

        return $this->menuJson(true, 'Updated Successfully');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The route's store. When route-model binding did not run (no middleware),
     * `{store}` is read as an id or a slug.
     */
    private function editorStore(Store $bound): Store
    {
        if ($bound->exists) {
            return $bound;
        }
        $key = request()->route('store');

        return Store::query()->idOrSlug($key)->firstOrFail();
    }

    /**
     * `{success, message}` (+ extra keys) for the editor's AJAX calls.
     *
     * @param  array<string, mixed>  $extra
     */
    private function menuJson(bool $success, string $messageKey, array $extra = []): JsonResponse
    {
        return response()->json(['success' => $success, 'message' => ___($messageKey)] + $extra);
    }

    /** Form posts: flash the outcome and go back. */
    private function menuFormBack(bool $success, string $messageKey): RedirectResponse
    {
        $success ? app_alert_success(___($messageKey)) : app_alert_error(___($messageKey));

        return back();
    }

    /**
     * The JSON failure answer when the input does not pass, else null.
     *
     * @param  array<string, mixed>  $rules
     */
    private function ajaxInputRefusal(Request $request, array $rules): ?JsonResponse
    {
        $check = Validator::make($request->all(), $rules);

        return $check->fails() ? $this->validationFailureJson($check) : null;
    }

    /**
     * Flash the validation messages and go back when the input does not pass, else null.
     *
     * @param  array<string, mixed>  $rules
     */
    private function formInputRefusal(Request $request, array $rules): ?RedirectResponse
    {
        $check = Validator::make($request->all(), $rules);
        if (! $check->fails()) {
            return null;
        }
        $this->flashValidationFailure($check);

        return back();
    }

    /**
     * The image menu's answer: JSON for AJAX callers, otherwise a flash message
     * and a redirect back (the dashboard submits these as normal HTML forms,
     * which must not land on a raw JSON body).
     */
    private function pictureMenuAnswer(Request $request, bool $success, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => $success, 'message' => $message]);
        }
        $success ? app_alert_success($message) : app_alert_error($message);

        return redirect()->back();
    }

    /** @return array{name: mixed, active: string} */
    private function picturePageColumns(Request $request): array
    {
        return ['name' => $request->input('name'), 'active' => $this->checkboxDigit($request, 'active')];
    }

    /** '1' when the checkbox was sent, else '0' (enum-string columns). */
    private function checkboxDigit(Request $request, string $field): string
    {
        return $request->has($field) ? '1' : '0';
    }

    /** Upload a picture page (max 1000px) plus its -w900.webp srcset variant; returns the filename. */
    private function storePicturePage(Request $request, Store $store, ?string $replacing = null): string
    {
        $file = $request->file('image');
        $name = (string) image_upload($file, self::MENU_UPLOAD_DIR, '1000', null, $replacing === 'default.png' ? null : $replacing);
        folio_make_variant($file, $name, (int) $store->id);

        return $name;
    }

    /** Write `position` = list index for each id in $ids that $scope contains. */
    /** @param  Builder<covariant Model>  $scope */
    private function storePositions(Builder $scope, mixed $ids): void
    {
        foreach (is_array($ids) ? $ids : [] as $position => $id) {
            (clone $scope)->whereKey($id)->update(['position' => $position]);
        }
    }

    /**
     * @param  class-string<Model>  $model  a modifier model keyed by `menu_id`
     * @return Builder<Model>
     */
    private function modifiersOfStore(string $model, Store $store): Builder
    {
        return $model::query()->whereIn('menu_id', Menu::query()->where('store_id', $store->id)->select('id'));
    }

    /**
     * The editor's language pair for the views: the store's menu languages and
     * the one being edited (the cookie's, else the store default). Both are
     * empty arrays until the store has menu languages and a default.
     *
     * @return array{0: Collection<int, Language>|array{}, 1: Language|array{}|null}
     */
    private function editorLanguages(Store $store): array
    {
        $codes = store_options($store->id, 'menu_languages');
        $storeDefault = store_options($store->id, 'default_language');
        if (! $codes || ! $storeDefault) {
            return [[], []];
        }

        $languages = Language::query()->whereIn('code', $codes)->get();
        $editing = $this->editingLanguage();
        $current = $editing !== null ? $languages->firstWhere('code', $editing) : null;

        return [$languages, $current ?? $languages->firstWhere('code', $storeDefault)];
    }

    /** The dashboard's editing-language cookie, or null. */
    private function editingLanguage(): ?string
    {
        $code = $_COOKIE['Quick_user_lang_code'] ?? null;

        return empty($code) ? null : (string) $code;
    }

    /**
     * Column changes for edited text on a translatable row.
     *
     * $edited maps column => [translation key, new value]. The values always go
     * into `translations` under the editing language. The columns themselves
     * change when there is no editing language or it is the store default;
     * otherwise they keep their current value and, with $rememberTranslation,
     * each pair (stored text → edited text) feeds the translation memory.
     *
     * @param  array<string, array{0: string, 1: mixed}>  $edited
     * @return array<string, mixed>
     */
    private function languageAwareChanges(Model $row, Store $store, array $edited, bool $rememberTranslation = false): array
    {
        $lang = $this->editingLanguage();
        $translations = $row->translations ?? new \stdClass;
        $replacesBase = $lang === null || $lang == store_options($store->id, 'default_language');

        $changes = [];
        $translated = [];
        foreach ($edited as $column => [$key, $value]) {
            $translated[$key] = $value;
            $changes[$column] = $replacesBase ? $value : $row->{$column};
            if (! $replacesBase && $rememberTranslation) {
                TranslationMemory::remember($store->id, $lang, (string) $row->getRawOriginal($column), (string) $value);
            }
        }
        if ($lang !== null) {
            $translations->{$lang} = $translated;
        }

        return $changes + ['translations' => $translations];
    }

    /**
     * Shared by add-category and add-sub-category: owner check, validation,
     * the category limit, then create with the extra columns from $columns().
     */
    /** @param  array<string, mixed>  $rules */
    private function createCategory(Request $request, Store $store, array $rules, callable $columns): RedirectResponse
    {
        if ((int) $store->user_id !== (int) $request->user()->id) {
            return $this->menuFormBack(false, 'Unexpected Error');
        }
        if ($refusal = $this->formInputRefusal($request, ['name' => 'required|string|max:255'] + $rules)) {
            return $refusal;
        }

        $limit = effective_limit('category');
        if (! Entitlements::isUnlimitedSentinel($limit) && MenuAdminService::usedFor((int) $store->id, 'category') >= $limit) {
            return $this->menuFormBack(false, 'Limit exceeded, please upgrade your membership.');
        }

        MenuCategory::create(['name' => $request->get('name'), 'store_id' => $store->id] + $columns());
        StorefrontMenuCache::bust((int) $store->id);

        return $this->menuFormBack(true, 'Saved Successfully');
    }

    /**
     * Shared by update-category and update-sub-category: the name follows the
     * editing language; $columns($category) adds the form's other columns.
     */
    /** @param  array<string, mixed>  $rules */
    private function editCategory(Request $request, Store $store, array $rules, callable $columns, bool $rememberTranslation): RedirectResponse
    {
        if ((int) $store->user_id !== (int) $request->user()->id) {
            return $this->menuFormBack(false, 'Unexpected Error');
        }
        if ($refusal = $this->formInputRefusal($request, ['name' => 'required|string|max:255'] + $rules)) {
            return $refusal;
        }

        $category = MenuCategory::query()->where('store_id', $store->id)->findOrFail($request->get('id'));
        $name = $this->languageAwareChanges($category, $store, ['name' => ['title', $request->get('name')]], $rememberTranslation);
        $category->update($columns($category) + $name);
        StorefrontMenuCache::bust((int) $store->id);

        return $this->menuFormBack(true, 'Saved Successfully');
    }

    /**
     * Shared by the three "add modifier" forms. $build() returns
     * [model class, columns]; the row is tied to the route's item.
     */
    /** @param  array<string, mixed>  $rules */
    private function createModifier(Request $request, Store $store, Menu $item, array $rules, callable $build): RedirectResponse
    {
        if ((int) $store->user_id !== (int) $request->user()->id) {
            return $this->menuFormBack(false, 'Unexpected Error');
        }
        if ($refusal = $this->formInputRefusal($request, $rules)) {
            return $refusal;
        }
        abort_unless((int) $item->store_id === (int) $store->id, 404);

        [$model, $columns] = $build();
        $model::create($columns + ['menu_id' => $item->id]);
        StorefrontMenuCache::bust((int) $store->id);

        return $this->menuFormBack(true, 'Saved Successfully');
    }

    /**
     * Shared by the three "update modifier" calls: owner check, `id` + $rules,
     * the row looked up among the store's items' modifiers, then $apply($row).
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $rules
     */
    private function editModifier(Request $request, Store $store, string $model, array $rules, callable $apply): JsonResponse
    {
        if ((int) $store->user_id !== (int) $request->user()->id) {
            return $this->menuJson(false, 'Unexpected Error');
        }
        if ($refusal = $this->ajaxInputRefusal($request, ['id' => 'required'] + $rules)) {
            return $refusal;
        }

        return $apply($this->modifiersOfStore($model, $store)->findOrFail($request->get('id')));
    }

    /**
     * Delete one modifier row. An id outside the store's items matches nothing
     * and still answers success (as the list is re-read afterwards).
     *
     * @param  class-string<Model>  $model
     */
    private function dropModifier(Request $request, Store $store, string $model): JsonResponse
    {
        if ((int) $store->user_id !== (int) $request->user()->id) {
            return $this->menuJson(false, 'Unexpected Error');
        }
        if ($refusal = $this->ajaxInputRefusal($request, ['id' => 'required|integer'])) {
            return $refusal;
        }
        $this->modifiersOfStore($model, $store)->whereKey($request->get('id'))->delete();

        return $this->menuJson(true, 'Deleted Successfully');
    }

    /** @param  class-string<Model>  $model */
    private function sortModifiers(Request $request, Store $store, string $model): JsonResponse
    {
        if ((int) $store->user_id === (int) $request->user()->id) {
            $this->storePositions($this->modifiersOfStore($model, $store), $request->position);
        }

        return $this->menuJson(true, 'Updated Successfully');
    }

    /**
     * "S, M ,L" → ['S', 'M', 'L'].
     *
     * @return list<string>
     */
    private function optionValues(Request $request): array
    {
        return array_map('trim', explode(',', (string) $request->get('options')));
    }

    /**
     * A variant's readable label: the chosen value of each option group, in the
     * variant's own order, joined by ", ". Unknown groups/values are skipped.
     */
    /** @param  EloquentCollection<int, MenuVariantOption>  $groups */
    private function variantLabel(mixed $picks, EloquentCollection $groups): string
    {
        return collect((array) $picks)->map(function ($valueIndex, $groupId) use ($groups) {
            $values = $groups->find($groupId)?->options;

            return is_array($values) ? ($values[$valueIndex] ?? null) : null;
        })->filter(fn ($label) => $label !== null)->implode(', ');
    }

    /**
     * Validation for the full item form. `type` (veg/nonveg) is a food-only
     * attribute: required for restaurants, optional for other verticals.
     */
    /** @return array<string, mixed> */
    private function menuItemRules(Store $store): array
    {
        $optionalFlag = 'nullable|boolean';

        return [
            // P6-59b: the category must be one of THIS store's (another tenant's category is a 422).
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('store_id', $store->id)],
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'cost' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:5000',
            'type' => ($store->isFoodVertical() ? 'required' : 'nullable').'|in:veg,nonveg',
            // main picture: a media-library filename, or (older clients) an upload
            'image' => 'nullable|string|max:255',
            'image_file' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:2048',
            'is_vegan' => $optionalFlag,
            'is_halal' => $optionalFlag,
            'is_gluten_free' => $optionalFlag,
            'spice_level' => 'nullable|integer|between:0,3',
            'is_popular' => $optionalFlag,
            'is_sold_out' => $optionalFlag,
            'is_daily_special' => $optionalFlag,
            'available_from' => ['nullable', self::TIME_OF_DAY_RULE],
            'available_to' => ['nullable', self::TIME_OF_DAY_RULE],
            'available_days' => 'nullable', // CSV (API) or day-code array (form)
            'available_order_types' => 'nullable', // T5: order-type scope
            'calories' => 'nullable|integer|min:0|max:9999',
            'protein' => 'nullable|numeric|min:0',
            'carbs' => 'nullable|numeric|min:0',
            'fat' => 'nullable|numeric|min:0',
            'track_inventory' => $optionalFlag,
            'stock_qty' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'course' => 'nullable|integer|min:1|max:9',
            'prep_minutes' => 'nullable|integer|min:0|max:240',
            'tax_rate' => 'nullable|numeric|min:0|max:100', // P6-99b
            'tax_category' => 'nullable|in:S,Z,E',
        ];
    }

    /**
     * The item form's non-text columns, identical for create and update.
     *
     * @return array<string, mixed>
     */
    private function menuItemColumns(Request $request): array
    {
        $columns = [
            'category_id' => $request->get('category_id'),
            'price' => $request->get('price'),
            // Non-food verticals never show veg/nonveg; store a harmless value.
            'type' => $request->get('type') ?: 'veg',
            'allergies' => $request->has('allergies') ? implode(',', $request->get('allergies')) : null,
            'active' => $this->checkboxDigit($request, 'active'),
            'spice_level' => (int) $request->get('spice_level', 0),
            'tax_category' => in_array($request->get('tax_category'), ['Z', 'E'], true) ? $request->get('tax_category') : null, // P6-99b
        ];
        foreach (self::ITEM_FLAG_COLUMNS as $flag) {
            $columns[$flag] = (int) $request->has($flag);
        }
        foreach (self::ITEM_NUMBER_COLUMNS as $field => [$cast, $whenEmpty]) {
            $raw = $request->get($field);
            $columns[$field] = $request->filled($field) ? ($cast === 'int' ? (int) $raw : (float) $raw) : $whenEmpty;
        }

        return $columns + $this->availabilityColumns($request);
    }

    /**
     * The availability window shared by items and categories: time of day,
     * days of week and order types. An empty field clears that restriction.
     *
     * @return array<string, string|null>
     */
    private function availabilityColumns(Request $request): array
    {
        return [
            'available_from' => $request->filled('available_from') ? $request->get('available_from') : null,
            'available_to' => $request->filled('available_to') ? $request->get('available_to') : null,
            'available_days' => $this->normalizeAvailableDays($request->input('available_days')),
            'available_order_types' => $this->normalizeOrderTypes($request->input('available_order_types')),
        ];
    }

    /**
     * The item's main picture after this request: a new upload (500px, plus a
     * 200px thumb for srcset; replaces the old file unless it is the default),
     * a media-library pick, an empty field ("Remove") → default.png, or the
     * current picture when the field was not sent.
     */
    private function itemMainImage(Request $request, Store $store, string $current): string
    {
        if ($request->hasFile('image_file')) {
            $upload = $request->file('image_file');
            $name = (string) image_upload($upload, self::MENU_UPLOAD_DIR, '500x500', null, $current === 'default.png' ? null : $current);
            // Best effort: a thumb failure must not fail the save.
            app(Images::class)->thumbnail($upload, self::MENU_UPLOAD_DIR.'thumb/', $name, '200', $store->id);

            return $name;
        }
        if (! $request->has('image')) {
            return $current;
        }

        return $request->filled('image') ? basename($request->input('image')) : 'default.png';
    }

    /**
     * The item's gallery: $start (library picks or the saved list), plus
     * uploaded `gallery_images[]` (800px) while fewer than $uploadCap pictures
     * are held, minus `remove_gallery_images[]` when $drop, de-duplicated and
     * capped at ITEM_GALLERY_MAX.
     *
     * @param  array<int|string, string>  $start
     * @return list<string>
     */
    private function itemGallery(Request $request, array $start, int $uploadCap, bool $drop = false): array
    {
        $files = array_values($start);
        foreach ($request->hasFile('gallery_images') ? $request->file('gallery_images') : [] as $upload) {
            if ($upload->isValid() && count($files) < $uploadCap) {
                $files[] = image_upload($upload, self::MENU_UPLOAD_DIR, '800x800');
            }
        }
        $removed = $drop ? (array) $request->get('remove_gallery_images', []) : [];
        if ($removed) {
            $files = array_filter($files, fn ($file) => ! in_array($file, $removed));
        }

        return array_slice(array_values(array_unique($files)), 0, self::ITEM_GALLERY_MAX);
    }

    /**
     * Normalize a day-of-week availability input to a canonical CSV of 3-letter
     * codes ("mon,wed,fri"). Accepts either the dashboard form's array of day
     * codes (available_days[]) or a CSV string (API / import). Unknown tokens are
     * dropped, so this is safe to store and to compare against in the order path.
     */
    private function normalizeAvailableDays($value): ?string
    {
        return $this->canonicalCsv($value, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
    }

    /**
     * PARITY-RUN T5: normalize an order-type availability scope to a canonical
     * CSV of `orders.type` values. Accepts the dashboard form's array or a CSV
     * string (API / import); unknown tokens are dropped, so a typo can never
     * silently hide an item from every channel.
     */
    private function normalizeOrderTypes($value): ?string
    {
        return $this->canonicalCsv($value, PriceSchedule::ORDER_TYPES);
    }

    /**
     * Lower-cased, trimmed, known, unique tokens from an array or CSV, or null.
     *
     * @param  list<string>  $known
     */
    private function canonicalCsv(mixed $value, array $known): ?string
    {
        $tokens = is_string($value) ? explode(',', $value) : $value;
        if (! is_array($tokens)) {
            return null;
        }
        $kept = array_unique(array_filter(
            array_map(fn ($token) => strtolower(trim((string) $token)), $tokens),
            fn ($token) => in_array($token, $known, true)
        ));

        return $kept ? implode(',', $kept) : null;
    }
}
