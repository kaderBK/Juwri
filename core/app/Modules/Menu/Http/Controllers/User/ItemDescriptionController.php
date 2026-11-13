<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;
use Juwri\Modules\Ai\Data\CopyLanguage;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Ai\ItemDescriptionWriter;

/**
 * P1-17 (J27-B): "Write with AI" in the menu item form. Returns a description draft as JSON; it NEVER saves the item —
 * the owner reviews the text in the form and saves through the normal add/update item endpoints. Store-scoped (the
 * store must be one this dashboard user controls; an item_id must belong to that store). Route: `ratelimit:ai`; the
 * AI credit allowance and spend caps are enforced inside AiService like every other AI tool.
 */
class ItemDescriptionController extends Controller
{
    public function draft(Request $request, int|string $store, ItemDescriptionWriter $writer): JsonResponse
    {
        // Resolved here (not by route-model binding) so the ownership check never depends on middleware order.
        abort_unless(in_array((int) $store, dashboard_store_ids(), true), 403);
        $store = Store::findOrFail((int) $store);

        $data = $request->validate([
            'item_id' => ['nullable', 'integer'],
            'name' => ['required_without:item_id', 'nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lang' => ['nullable', 'string', 'in:'.implode(',', CopyLanguage::CODES)],
        ]);

        $facts = ['name' => '', 'category' => '', 'ingredients' => [], 'options' => [], 'dietary' => []];
        if (! empty($data['item_id'])) {
            $item = Menu::where('store_id', $store->id)->find((int) $data['item_id']);
            abort_if($item === null, 404);
            $facts = ItemDescriptionWriter::factsFor($item);
        }
        // What the owner typed in the open form wins over the saved values (unsaved edits).
        if (trim((string) ($data['name'] ?? '')) !== '') {
            $facts['name'] = trim((string) $data['name']);
        }
        if (! empty($data['category_id'])) {
            $facts['category'] = ItemDescriptionWriter::categoryName((int) $data['category_id'], (int) $store->id) ?: $facts['category'];
        }
        $facts['notes'] = trim((string) ($data['notes'] ?? ''));

        if ($facts['name'] === '') {
            return response()->json(['error' => ___('Enter the item name first.')], 422);
        }

        $language = CopyLanguage::pick($data['lang'] ?? null, (int) $store->id);

        try {
            $text = $writer->draft($facts, $language, (int) $request->user()->id, (int) $store->id);
        } catch (AiLimitExceeded $e) {
            return response()->json(['error' => $e->getMessage()], 429);
        } catch (AiProviderFailure $e) {
            Log::warning('[AI item description] '.$e->getMessage());

            return response()->json(['error' => ___('The AI service is unavailable right now. Please try again in a moment.')], 502);
        }

        return response()->json([
            'success' => true,
            'description' => $text,
            'lang' => $language->code,
            'dir' => $language->dir(),
        ]);
    }
}
