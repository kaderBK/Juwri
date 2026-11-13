<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Studio;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Core\Models\Store;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Services\Ai\MenuGeneratorService;
use App\Services\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Task 3 — bridges the public AI Studio into store creation. When a signed-in
 * owner builds a menu in Studio (a guest draft keyed on the `studio_guest`
 * cookie) and then creates a store through the dashboard wizard, this imports
 * that draft into the new store's menu instead of leaving them to re-key it.
 *
 * Unlike StudioController::persistDraft (the leaner in-Studio "Publish" path),
 * this carries variants + extras and enforces the owner's plan category/item
 * limits, so a wizard-seeded store matches what the dashboard's own AI import
 * would produce. The Studio session is marked `published` once consumed so a
 * later store creation on the same cookie doesn't re-import the same menu.
 */
class StudioDraftImporter
{
    /** Same cookie StudioController mints/reads (a session credential). */
    public const GUEST_COOKIE = 'studio_guest';

    /**
     * The live, unconsumed Studio session with a draft for this request, or null.
     *
     * Cookie-first (the guest fast path is unchanged). When the request is
     * authed and the guest cookie yields no usable draft session, fall back to
     * the owner's latest live session (StudioSessionService::ownedLiveSession)
     * so a draft built on another device — or after clearing the cookie — still
     * seeds the new store. A cookie session owned by a DIFFERENT user is ignored
     * (never import a stranger's draft into this owner's store): it is treated as
     * "no cookie session" so the owner fallback decides.
     */
    public function pendingSession(Request $request): ?StudioSession
    {
        $userId = auth()->id();

        // 1. Cookie session (guests always take this path; owners take it when
        //    the cookie points at their own or an unclaimed guest session).
        $token = $request->cookie(self::GUEST_COOKIE);
        if (is_string($token) && $token !== '') {
            $session = StudioSession::where('guest_token', $token)->first();
            if ($this->isImportable($session, $userId)) {
                return $session;
            }
        }

        // 2. Owner fallback: the account's latest live session may hold a draft
        //    even when this browser has no (or a stranger's) guest cookie.
        if ($userId !== null) {
            $owned = app(StudioSessionService::class)->ownedLiveSession((int) $userId);
            if ($this->isImportable($owned, $userId)) {
                return $owned;
            }
        }

        return null;
    }

    /**
     * A session is importable when it exists, is not expired, has not already
     * been consumed (status published), still has a draft.json on the local
     * disk, AND — for an authed request — is not owned by a *different* user.
     */
    private function isImportable(?StudioSession $session, ?int $userId): bool
    {
        if (! $session || $session->isExpired() || $session->status === 'published') {
            return false; // no session, expired, or already imported once
        }

        // Never import a session owned by someone other than the current owner.
        // (A null owner is an unclaimed guest draft — fair game to convert.)
        if ($userId !== null && $session->user_id !== null && (int) $session->user_id !== $userId) {
            return false;
        }

        return Storage::disk('local')->exists($session->storagePath('draft.json'));
    }

    public function hasPendingDraft(Request $request): bool
    {
        return $this->pendingSession($request) !== null;
    }

    /**
     * Import the pending Studio draft into $store. No-op (returns null) when
     * there is no pending draft. Never throws on an empty/corrupt draft — the
     * store must survive even if the import finds nothing to add.
     *
     * @return array{categories:int,items:int}|null
     */
    public function importInto(Store $store, User $user, Request $request): ?array
    {
        $session = $this->pendingSession($request);
        if (! $session) {
            return null;
        }

        $raw = json_decode((string) Storage::disk('local')->get($session->storagePath('draft.json')), true);
        // The stored draft is user-editable → re-normalise server-side (untrusted).
        $draft = app(MenuGeneratorService::class)->normalizeDraft($raw['categories'] ?? []);
        if (empty($draft['categories'])) {
            $session->update(['user_id' => $user->id, 'status' => 'published']); // consume so it can't linger

            return null;
        }

        $counts = $this->persist($store, $user, $draft['categories']);
        $session->update(['user_id' => $user->id, 'status' => 'published']);

        return $counts;
    }

    /**
     * Write normalised draft categories + items (with variants/extras) to the
     * store, appending after any existing rows and respecting plan limits.
     *
     * @param  array<int,array<string,mixed>>  $categories
     * @return array{categories:int,items:int}
     */
    private function persist(Store $store, User $user, array $categories): array
    {
        $categoryLimit = effective_limit('category', $user);
        $menuLimit = effective_limit('menu', $user);
        $totalCategories = $store->menu_categories()->count();
        $totalItems = Menu::where('store_id', $store->id)->count();
        $savedCategories = 0;
        $savedItems = 0;

        DB::transaction(function () use ($categories, $store, $categoryLimit, $menuLimit, &$totalCategories, &$totalItems, &$savedCategories, &$savedItems) {
            foreach ($categories as $catData) {
                if (! Entitlements::isUnlimitedSentinel($categoryLimit) && $totalCategories >= $categoryLimit) {
                    break;
                }

                $category = new MenuCategory;
                $category->store_id = $store->id;
                $category->name = $catData['name'];
                $category->active = '1';
                $category->position = $totalCategories;
                $category->save();
                $totalCategories++;
                $savedCategories++;

                $position = 0;
                foreach ($catData['items'] as $itemData) {
                    if (! Entitlements::isUnlimitedSentinel($menuLimit) && $totalItems >= $menuLimit) {
                        break;
                    }

                    $menu = new Menu;
                    $menu->store_id = $store->id;
                    $menu->category_id = $category->id;
                    $menu->name = $itemData['name'];
                    $menu->description = $itemData['description'] ?? '';
                    $menu->price = $itemData['price'] ?? 0;
                    $menu->position = $position++;
                    $menu->active = '1';
                    $menu->save();
                    $totalItems++;
                    $savedItems++;

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
        });

        return ['categories' => $savedCategories, 'items' => $savedItems];
    }
}
