<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\User;

use App\Support\BioLinkOutbound;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppBroadcast;

/**
 * Marketing broadcast UI: compose a message and send it to the restaurant's
 * opted-in WhatsApp audience. (spec §7 broadcast)
 */
class WhatsAppCampaignController extends Controller
{
    public function index(Store $store, WhatsAppBroadcast $broadcast)
    {
        $this->authorizeOwner($store);
        $store = $store;
        $audience = $broadcast->audienceSize($store->id);
        // biolink-tpl lane-d (T2-3): bio URL for the "Insert bio link" control —
        // null (control hidden) unless this store's bio page is actually live.
        $bioUrl = BioLinkOutbound::liveUrl($store, 'share');

        return view('owner.whatsapp.campaign', compact('store', 'audience', 'bioUrl'));
    }

    public function send(Request $request, Store $store, WhatsAppBroadcast $broadcast)
    {
        $this->authorizeOwner($store);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
            'template' => ['nullable', 'string', 'max:128'],
        ]);

        $count = $broadcast->send($store->id, $data['body'], $data['template'] ?? null);

        if ($count < 0) {
            app_alert_error(___('Campaign paused: your WhatsApp number quality is low (flagged). Resolve it before sending marketing.'));
        } else {
            app_alert_success(___('Campaign queued to :n recipients', ['n' => $count]));
        }

        return back();
    }

    private function authorizeOwner(Store $store): void
    {
        abort_unless(auth()->check() && (int) $store->user_id === (int) auth()->id() && dashboard_can_access_store($store), 403);
    }
}
