<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Http\Controllers\User;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;
use Juwri\Modules\Ai\Data\CopyLanguage;
use Juwri\Modules\Marketing\Services\Ai\SocialPostWriter;
use Juwri\Modules\Menu\Models\Menu;

/**
 * P1-17 (J27-B): owner "Social post writer" (Grow > Marketing). The owner picks a menu item or describes an offer,
 * picks Arabic or English, and gets a draft post + hashtags to copy. Nothing is posted or saved. Store-scoped through
 * ResolvesCurrentStore (?store_id must be one of the owner's stores; an item must belong to that store). The
 * draft route carries `ratelimit:ai`; AI credits and spend caps are enforced inside AiService.
 */
class SocialPostController extends Controller
{
    use ResolvesCurrentStore;

    public function index(Request $request): View
    {
        $store = $this->currentRestaurant($request);

        return view('owner.marketing.social-post', [
            'rid' => $store->id,
            'store' => $store,
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
            'items' => Menu::where('store_id', $store->id)->orderBy('name')->limit(500)->get(['id', 'name']),
            'defaultLang' => CopyLanguage::storeDefault((int) $store->id)->code,
        ]);
    }

    public function draft(Request $request, SocialPostWriter $writer): JsonResponse
    {
        $store = $this->currentRestaurant($request);

        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'required_without:offer'],
            'offer' => ['nullable', 'string', 'max:500', 'required_without:item_id'],
            'lang' => ['nullable', 'string', 'in:'.implode(',', CopyLanguage::CODES)],
        ]);

        $facts = ['store' => (string) $store->title, 'offer' => trim((string) ($data['offer'] ?? ''))];
        if (! empty($data['item_id'])) {
            $item = Menu::where('store_id', $store->id)->find((int) $data['item_id']);
            abort_if($item === null, 404);
            $facts += SocialPostWriter::itemFacts($item);
        }

        $language = CopyLanguage::pick($data['lang'] ?? null, (int) $store->id);

        try {
            $post = $writer->draft($facts, $language, (int) $request->user()->id, (int) $store->id);
        } catch (AiLimitExceeded $e) {
            return response()->json(['error' => $e->getMessage()], 429);
        } catch (AiProviderFailure $e) {
            Log::warning('[AI social post] '.$e->getMessage());

            return response()->json(['error' => ___('The AI service is unavailable right now. Please try again in a moment.')], 502);
        }

        return response()->json([
            'success' => true,
            'text' => $post['text'],
            'hashtags' => $post['hashtags'],
            'lang' => $language->code,
            'dir' => $language->dir(),
        ]);
    }
}
