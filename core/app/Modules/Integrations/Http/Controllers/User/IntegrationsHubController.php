<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Http\Controllers\User;

use App\Models\WebhookEndpoint;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Integrations\Models\ApiToken;

/**
 * D1 (integrations pack): the owner Integrations hub. One page listing every
 * automation/aggregator integration with a live status chip built from real data —
 * Deliverect connections, webhook endpoints (+ auto-disable health), API tokens.
 *
 * Route-gated `feature:integrations` (the group in routes/integrations/ui.php);
 * when the feature is off but sellable the middleware already redirects to the
 * upsell (feature.locked), so this controller only ever runs for an entitled owner.
 */
class IntegrationsHubController extends Controller
{
    public function index(): View
    {
        $ownerId = (int) auth()->id();
        $storeIds = Store::where('user_id', $ownerId)->pluck('id');

        $deliverect = AggregatorConnection::where('provider', 'deliverect')
            ->whereIn('store_id', $storeIds)->get();
        $webhooks = WebhookEndpoint::where('user_id', $ownerId)->get();
        $tokenCount = ApiToken::where('user_id', $ownerId)->count();

        // A dead endpoint = auto-disabled after 15 consecutive failures (F0/A4).
        $webhookAttention = $webhooks->contains(fn ($e) => ! $e->active && $e->disabled_reason);
        $deliverectActive = $deliverect->firstWhere('status', 'active') !== null;
        $deliverectAttention = $deliverect->contains(fn ($c) => in_array($c->status, ['paused', 'error'], true));

        // status: not_connected | connected | attention. `has_token` drives the
        // Zapier/Make/n8n cards — those "connect" by minting a webhooks:write token.
        $cards = [
            'deliverect' => [
                'status' => $deliverect->isEmpty() ? 'not_connected' : ($deliverectAttention ? 'attention' : ($deliverectActive ? 'connected' : 'not_connected')),
                'locations' => $deliverect->count(),
            ],
            'webhooks' => [
                'status' => $webhookAttention ? 'attention' : ($webhooks->isEmpty() ? 'not_connected' : 'connected'),
                'count' => $webhooks->count(),
            ],
            'zapier' => ['status' => $tokenCount > 0 ? 'connected' : 'not_connected'],
            'make' => ['status' => $tokenCount > 0 ? 'connected' : 'not_connected'],
            'n8n' => ['status' => $tokenCount > 0 ? 'connected' : 'not_connected'],
        ];

        return view('owner.integrations.hub', compact('cards', 'deliverect', 'webhooks', 'tokenCount'));
    }
}
