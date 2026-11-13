<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers;

use App\Models\MenuCategory;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\StoreDomain;

/**
 * Agents P4: agent-discovery surfaces. A UCP-style manifest declares what an agent
 * can do here (discovery/cart/order-create; checkout is escalation-only — pay on
 * arrival) and where the MCP endpoint is. A per-restaurant llms.txt summarizes the
 * menu + how to order. Minimal by design — expands as ucp.dev settles.
 */
class AgentManifestController extends Controller
{
    private function manifestFor(Store $store): array
    {
        return [
            'version' => '0.1',
            'service' => 'dev.ucp.shopping',
            'store' => ['id' => $store->id, 'name' => $store->title],
            'capabilities' => [
                'discovery' => true,
                'cart' => true,
                'order_create' => true,
                'checkout' => 'escalation_only', // agent places unpaid order → continue_url
            ],
            'mcp_endpoint' => url('/mcp/storefront'),
            'auth' => 'X-Storefront-Token (agent scope)',
            'continue_url_pattern' => url('/api/v1/track/{track_token}'),
            'payment' => 'pay_on_arrival',
            // MCP-expansion P2-6: point agents at the platform-wide Catalog MCP
            // for cross-store discovery (public, no auth).
            'catalog_mcp' => url('/mcp/catalog'),
        ];
    }

    /** GET /agent/{storeId}/manifest — explicit UCP-style manifest. */
    public function manifest(int $storeId)
    {
        return response()->json($this->manifestFor(Store::findOrFail($storeId)));
    }

    /** GET /.well-known/ucp — manifest for the restaurant on this host (custom domain). */
    public function wellKnown()
    {
        $store = StoreDomain::resolve(request()->getHost());
        abort_unless($store !== null, 404);

        return response()->json($this->manifestFor($store));
    }

    /** GET /agent/{storeId}/llms.txt — menu summary + ordering how-to for agents. */
    public function llms(int $storeId)
    {
        $store = Store::findOrFail($storeId);
        $cats = MenuCategory::where('store_id', $store->id)->where('active', 1)->orderBy('position')->get();
        $items = Menu::where('store_id', $store->id)->where('active', '1')->get()->groupBy('category_id'); // enum('0','1'): string, not index

        $lines = ["# {$store->title} — menu for AI agents", '', 'Order via MCP: '.url('/mcp/storefront').' (X-Storefront-Token, agent scope). Unpaid orders; pay on arrival.', ''];
        foreach ($cats as $c) {
            $lines[] = "## {$c->name}";
            foreach (($items[$c->id] ?? collect())->where('is_sold_out', '!=', 1) as $m) {
                $lines[] = "- [{$m->id}] {$m->getRawOriginal('name')} — ".number_format((float) $m->price, 2);
            }
            $lines[] = '';
        }
        $lines[] = 'To order: call create_order with items:[{item_id, quantity}] (takeaway or on-table).';

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
