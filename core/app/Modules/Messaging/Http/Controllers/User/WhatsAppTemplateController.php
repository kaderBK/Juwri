<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers\User;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Models\WhatsAppTemplate;

/**
 * Registry of a restaurant's approved WhatsApp templates. Owners record the
 * template names they've had approved on their provider so the app can send
 * them outside the 24h window (order confirm, status, marketing, OTP). (spec §5.3)
 */
class WhatsAppTemplateController extends Controller
{
    public function index(Store $store)
    {
        $this->authorizeOwner($store);
        $store = $store;
        $templates = WhatsAppTemplate::where('store_id', $store->id)->orderBy('name')->get();

        return view('owner.whatsapp.templates', compact('store', 'templates'));
    }

    public function store(Request $request, Store $store)
    {
        $this->authorizeOwner($store);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9_]+$/'],
            'provider' => ['required', 'in:meta,twilio'],
            'language' => ['required', 'string', 'max:12'],
            'category' => ['required', 'in:marketing,utility,authentication'],
            'status' => ['required', 'in:draft,pending,approved,rejected'],
            'body' => ['nullable', 'string', 'max:4096'],
        ], [], ['name' => ___('template name')]);

        WhatsAppTemplate::updateOrCreate(
            [
                'store_id' => $store->id, 'provider' => $data['provider'],
                'name' => $data['name'], 'language' => $data['language'],
            ],
            [
                'category' => $data['category'], 'status' => $data['status'], 'body' => $data['body'] ?? null,
            ]
        );

        app_alert_success(___('Template saved'));

        return back();
    }

    public function destroy(Store $store, WhatsAppTemplate $template)
    {
        $this->authorizeOwner($store);
        abort_unless((int) $template->store_id === (int) $store->id, 404);
        $template->delete();

        app_alert_success(___('Template removed'));

        return back();
    }

    private function authorizeOwner(Store $store): void
    {
        abort_unless(auth()->check() && (int) $store->user_id === (int) auth()->id() && dashboard_can_access_store($store), 403);
    }
}
