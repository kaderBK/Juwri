<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Contracts\WhatsApp;
use Juwri\Modules\Messaging\Models\CannedReply;
use Juwri\Modules\Messaging\Models\WhatsAppInbound;
use Juwri\Modules\Messaging\Services\WhatsAppInboxService;

/**
 * Team inbox: view inbound WhatsApp messages per restaurant and reply inline.
 * Replies go out as in-window free-form service messages. (spec §6 inbox)
 */
class WhatsAppInboxController extends Controller
{
    public function index(Store $store)
    {
        $this->authorizeOwner($store);

        // Latest message per contact, most-recent first, with unhandled counts.
        $messages = WhatsAppInbound::where('store_id', $store->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $threads = $messages->groupBy('from_phone')->map(function ($msgs) {
            return [
                'phone' => $msgs->first()->from_phone,
                'name' => $msgs->firstWhere('contact_name', '!=', null)?->contact_name,
                'latest' => $msgs->first(),
                'unhandled' => $msgs->where('handled', false)->count(),
                'messages' => $msgs->sortBy('id')->values(),
            ];
        })->values();

        $store = $store;

        return view('owner.whatsapp.inbox', compact('store', 'threads'));
    }

    public function reply(Request $request, Store $store, WhatsApp $wa)
    {
        $this->authorizeOwner($store);

        $data = $request->validate([
            'to' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:4096'],
        ]);

        $result = $wa->send($data['to'], $data['body'], $store->id, 'general');

        // Mark that contact's inbound messages as handled.
        WhatsAppInbound::where('store_id', $store->id)
            ->where('from_phone', $wa->normalizePhone($data['to']))
            ->where('handled', false)
            ->update(['handled' => true, 'replied_at' => now()]);

        if ($result->ok()) {
            app_alert_success(___('Reply sent'));
        } else {
            app_alert_error(___('Could not send reply').': '.$result->status);
        }

        return back();
    }

    // ---- S-P2: shared-inbox upgrade ----

    public function setStatus(Request $request, Store $store, WhatsAppInboxService $inbox)
    {
        $this->authorizeOwner($store);
        $data = $request->validate(['phone' => ['required', 'string', 'max:32'], 'status' => ['required', 'in:open,pending,closed']]);
        $inbox->setStatus($inbox->conversationFor($store->id, $data['phone']), $data['status']);

        return back()->with('success', ___('Status updated.'));
    }

    public function assign(Request $request, Store $store, WhatsAppInboxService $inbox)
    {
        $this->authorizeOwner($store);
        $data = $request->validate(['phone' => ['required', 'string', 'max:32'], 'staff_id' => ['nullable', 'integer']]);
        $inbox->assign($inbox->conversationFor($store->id, $data['phone']), $data['staff_id'] ?? null);

        return back()->with('success', ___('Conversation assigned.'));
    }

    public function storeCanned(Request $request, Store $store)
    {
        $this->authorizeOwner($store);
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:2000']]);
        CannedReply::create(['store_id' => $store->id, 'title' => $data['title'], 'body' => $data['body']]);

        return back()->with('success', ___('Canned reply saved.'));
    }

    private function authorizeOwner(Store $store): void
    {
        abort_unless(auth()->check() && (int) $store->user_id === (int) auth()->id() && dashboard_can_access_store($store), 403);
    }
}
