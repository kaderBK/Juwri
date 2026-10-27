<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User\Concerns;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\Entitlements;
use App\Services\QueueGuard;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Facades\Image;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\AbuseGuard;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;
use Juwri\Modules\Ai\Contracts\ImageGenerator;
use Juwri\Modules\Media\Contracts\Images;
use Juwri\Modules\Menu\Jobs\GenerateMenuItemImagesJob;
use Juwri\Modules\Menu\Jobs\TranslateMenuJob;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\Ai\MenuGeneratorService;

/**
 * H11: extracted from StoreController (mechanical split, no behavior change).
 * AI menu generation/translation endpoints.
 */
trait GeneratesMenuWithAi
{
    /**
     * Show the Menu page
     *
     * @return View
     */
    public function aiGenerateMenu(Request $request, Store $store)
    {
        if ($store->user_id != $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $service = app(MenuGeneratorService::class);
        $userId = $request->user()->id;
        // Vertical-AI: flavour the prompt to the store's group (food/goods/
        // services) so a retail/laundry store gets catalog/service wording
        // instead of restaurant wording. A restaurant (or any store with no/
        // unknown group) resolves to 'food', so this is a no-op for every
        // existing caller.
        $group = (string) app(VerticalRegistry::class)
            ->capability($store->store_type, 'group', MenuGeneratorService::GROUP_FOOD);

        try {
            // Photo mode: a vision-capable provider parses an uploaded menu image.
            if ($request->hasFile('image')) {
                $base64 = $this->imageToBase64($request->file('image'));
                if ($base64 === null) {
                    return response()->json(['error' => 'Could not read the uploaded image.'], 400);
                }
                $draft = $service->fromImage($base64, $userId, $store->id, $group);
            } else {
                $text = trim((string) $request->input('text'));
                if ($text === '') {
                    return response()->json(['error' => 'Paste some menu text or upload a menu image first.'], 400);
                }
                // "Describe" tab: generative (the model invents a fitting menu).
                // Plain text mode stays extractive (parse a pasted menu verbatim).
                $draft = $request->input('mode') === 'describe'
                    ? $service->fromDescription($text, $request->boolean('with_variants'), $userId, $store->id, $group)
                    : $service->fromText($text, $userId, $store->id, $group);
            }

            if (empty($draft['categories'])) {
                return response()->json(['error' => 'No menu items could be read from the input. Try clearer text or a sharper photo.'], 422);
            }

            // Keep the response shape the existing UI expects.
            return response()->json(['success' => true, 'data' => $draft]);
        } catch (AiLimitExceeded $e) {
            return response()->json(['error' => $e->getMessage()], 429);
        } catch (AiProviderFailure $e) {
            Log::warning('[AI menu] generation failed: '.$e->getMessage());

            return response()->json(['error' => 'The AI service is unavailable right now. Please try again in a moment.'], 502);
        } catch (\Throwable $e) {
            Log::error('[AI menu] unexpected error: '.$e->getMessage());

            return response()->json(['error' => 'Something went wrong generating the menu.'], 500);
        }
    }

    /**
     * Downscale an uploaded menu image and return raw base64 (JPEG), or null on
     * failure. Keeps the payload small for vision models.
     */
    protected function imageToBase64(UploadedFile $file): ?string
    {
        try {
            if (! str_starts_with((string) $file->getMimeType(), 'image/')) {
                return null;
            }
            // Decompression-bomb guard: reject oversized rasters before Intervention
            // decodes the full bitmap (~w*h*4 bytes) — same 25MP cap as StorageService.
            [$w, $h] = array_pad((array) @getimagesize($file->getRealPath()), 2, 0);
            if (! app(Images::class)->uploadPixelsOk((int) $w, (int) $h)) {
                return null;
            }
            $img = Image::make($file->getRealPath());
            $img->resize(1600, 1600, function ($c) {
                $c->aspectRatio();
                $c->upsize();
            });

            return base64_encode((string) $img->encode('jpg', 80));
        } catch (\Throwable $e) {
            Log::warning('[AI menu] image processing failed: '.$e->getMessage());

            return null;
        }
    }

    public function aiSaveMenu(Request $request, Store $store)
    {
        if ($store->user_id != $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Re-normalise the reviewed draft server-side (it is user-editable, so
        // untrusted): trim, clamp, re-parse prices, drop empties, cap volume.
        $draft = app(MenuGeneratorService::class)
            ->normalizeDraft($request->input('categories', []));
        $categories = $draft['categories'];

        if (empty($categories)) {
            return response()->json(['error' => 'No data to save.'], 400);
        }

        // Limit checks (#67: effective_limit includes add-on boosts)
        $user = $request->user();
        $categoryLimit = effective_limit('category', $user);
        $menuLimit = effective_limit('menu', $user);

        $totalCategories = $store->menu_categories()->count();
        $totalMenuItems = Menu::where('store_id', $store->id)->count();

        $savedCategories = 0;
        $savedItems = 0;
        $savedItemIds = [];
        $limitHit = false;

        DB::beginTransaction();
        try {
            foreach ($categories as $catData) {
                if (! Entitlements::isUnlimitedSentinel($categoryLimit) && $totalCategories >= $categoryLimit) { // P6-1
                    $limitHit = true;
                    break; // no room for more categories
                }

                $category = new MenuCategory;
                $category->store_id = $store->id;
                $category->name = $catData['name'];
                $category->active = '1';
                $category->position = $totalCategories; // append in order
                $category->save();
                $totalCategories++;
                $savedCategories++;

                $position = 0;
                foreach ($catData['items'] as $itemData) {
                    if (! Entitlements::isUnlimitedSentinel($menuLimit) && $totalMenuItems >= $menuLimit) { // P6-1
                        $limitHit = true;
                        break; // no room for more items
                    }

                    $menu = new Menu;
                    $menu->store_id = $store->id;
                    $menu->category_id = $category->id;
                    $menu->name = $itemData['name'];
                    $menu->description = $itemData['description'];
                    $menu->price = $itemData['price'] ?? 0;
                    $menu->position = $position++;
                    $menu->active = '1';
                    $menu->save();
                    $totalMenuItems++;
                    $savedItems++;
                    $savedItemIds[] = $menu->id;

                    foreach ($itemData['variants'] ?? [] as $variant) {
                        MenuVariantOption::create([
                            'menu_id' => $menu->id,
                            'store_id' => $store->id,
                            'title' => $variant['title'],
                            'options' => $variant['options'],
                            'option_prices' => $variant['prices'],
                        ]);
                    }
                    foreach ($itemData['extras'] ?? [] as $extra) {
                        MenuExtra::create([
                            'menu_id' => $menu->id,
                            'store_id' => $store->id,
                            'title' => $extra['title'],
                            'price' => $extra['price'] ?? 0,
                        ]);
                    }
                }
            }

            DB::commit();

            // Optional AI photos for the newly created items, in the background.
            // Isolated try: the menu IS saved past this point — a queueing/cache
            // failure must not fall into the outer catch and report an error
            // (the user would hit Save again and import everything twice).
            $imagesQueued = false;
            try {
                if ($request->boolean('generate_images') && ! empty($savedItemIds)
                    && app(ImageGenerator::class)->isConfigured()) {
                    $ids = array_slice($savedItemIds, 0, GenerateMenuItemImagesJob::MAX_ITEMS);
                    if (QueueGuard::allow($user->id)) {
                        Cache::put(
                            GenerateMenuItemImagesJob::progressKey($store->id),
                            ['status' => 'queued', 'done' => 0, 'total' => count($ids), 'store_id' => $store->id],
                            3600
                        );
                        dispatch_job_reliably(new GenerateMenuItemImagesJob($store->id, $ids, $user->id));
                        $imagesQueued = true;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[AI menu] photo queueing failed: '.$e->getMessage());
            }

            $message = "Added {$savedCategories} categories and {$savedItems} items.";
            if ($limitHit) {
                $message .= ' Some were skipped — you reached your plan limit.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'imported' => $savedItems,
                'limit_hit' => $limitHit,
                'images_queued' => $imagesQueued,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[AI menu] save failed: '.$e->getMessage());

            return response()->json(['error' => 'Error saving menu. Please try again.'], 500);
        }
    }

    /**
     * S1-P2: merge several page/image drafts (multi-page PDF or a 2-sided menu
     * photographed page-by-page) into one draft. The client generates a draft per
     * page via aiGenerate, then posts the collected drafts here. Merge + normalise
     * happen server-side (the drafts are untrusted client data).
     */
    public function aiMergeMenu(Request $request, Store $store)
    {
        if ($store->user_id != $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $drafts = $request->input('drafts', []);
        if (! is_array($drafts) || empty($drafts)) {
            return response()->json(['error' => 'No pages to merge.'], 400);
        }
        // Abuse Guard P5: cap pages per merge via the admin-set scan page limit.
        $maxPages = app(Images::class)->maxScanPages();
        if ($maxPages > 0 && count($drafts) > $maxPages) {
            AbuseGuard::hit('scan', 'pages_over_cap', [
                'user_id' => optional($request->user())->id,
                'meta' => ['pages' => count($drafts), 'max' => $maxPages],
            ]);
            $drafts = array_slice($drafts, 0, $maxPages);
        }

        $merged = app(MenuGeneratorService::class)->mergeDrafts($drafts);

        if (empty($merged['categories'])) {
            return response()->json(['error' => 'No menu items could be read from those pages.'], 422);
        }

        return response()->json(['success' => true, 'data' => $merged]);
    }

    /**
     * #34: Fill missing menu translations for the restaurant's supported
     * languages via AI. Runs in the background (queued); the owner refreshes to
     * see machine-translated fields flagged for review.
     */
    public function aiTranslateMenu(Request $request, Store $store)
    {
        if ($store->user_id != $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $default = store_options($store->id, 'default_language');
        $codes = store_options($store->id, 'menu_languages');
        $codes = is_array($codes) ? $codes : (array) $codes;

        // #7/#8: the owner explicitly picks the language the menu is currently
        // written in. Validate everything FIRST — a rejected request must not
        // mutate the restaurant's default language.
        $source = trim((string) $request->input('source'));
        if ($source !== '') {
            $allowed = array_values(array_unique(array_filter(array_merge($codes, [$default]))));
            if (! in_array($source, $allowed, true)
                || ! Language::where('active', 1)->where('code', $source)->exists()) {
                return response()->json(['error' => ___('Unknown source language.')], 422);
            }
        }

        $effectiveDefault = $source !== '' ? $source : $default;
        $targets = array_values(array_filter($codes, fn ($c) => $c && $c !== $effectiveDefault));

        if (empty($targets)) {
            return response()->json(['error' => 'Add more menu languages first, then run translate.'], 422);
        }

        // #8: refuse to translate an empty menu (nothing in the source language).
        $hasContent = Menu::where('store_id', $store->id)->whereRaw("TRIM(COALESCE(name, '')) <> ''")->exists()
            || MenuCategory::where('store_id', $store->id)->whereRaw("TRIM(COALESCE(name, '')) <> ''")->exists();
        if (! $hasContent) {
            return response()->json(['error' => ___('Your menu is empty — add categories and items before translating.')], 422);
        }

        // All guards passed — now the chosen source becomes the store default
        // (base columns hold this language).
        if ($source !== '' && $source !== $default) {
            StoreOption::updateStoreOption($store->id, 'default_language', $source);
        }

        // Seed progress so the UI shows "queued" immediately.
        Cache::put(
            TranslateMenuJob::progressKey($store->id),
            ['status' => 'queued', 'done' => 0, 'total' => count($targets), 'store_id' => $store->id],
            3600
        );

        // Queue when a worker is draining jobs; otherwise run after the response
        // so translation still works on hosts with no queue worker (#6).
        if (QueueGuard::allow($request->user()->id)) {
            dispatch_job_reliably(new TranslateMenuJob($store->id, $request->user()->id));
        }

        return response()->json([
            'success' => true,
            'message' => 'Translating your menu into '.count($targets).' language(s).',
            'languages' => count($targets),
        ]);
    }

    /**
     * Live progress of the background AI item-photo generation.
     */
    public function aiMenuImagesProgress(Request $request, Store $store)
    {
        if ($store->user_id != $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $progress = Cache::get(GenerateMenuItemImagesJob::progressKey($store->id));

        return response()->json($progress ?: ['status' => 'idle']);
    }

    /**
     * #34 (#6): live progress of the background translation job.
     */
    public function aiTranslateProgress(Request $request, Store $store)
    {
        if ($store->user_id != $request->user()->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $progress = Cache::get(TranslateMenuJob::progressKey($store->id));

        return response()->json($progress ?: ['status' => 'idle']);
    }
}
