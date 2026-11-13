<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Http\Controllers\User;

use App\Models\StoreOption;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Messaging\Models\WhatsAppRoute;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;

/**
 * Per-restaurant WhatsApp channel settings: which provider, BYOK-vs-platform
 * keys, own-vs-platform number, auto-send toggles, chatbot, templates.
 * (spec §8.1)
 */
class WhatsAppSettingsController extends Controller
{
    /** Simple store_options flags/values persisted verbatim. */
    private const OPTION_KEYS = [
        'whatsapp_provider', 'whatsapp_number_mode',
        /* WA-E1: `whatsapp_number` … `whatsapp_number10` are deliberately absent.
           A store's numbers live on its branches now (WA-D2 moved them there), and
           E3 deletes these rows. Writing one back would resurrect a row the
           migration had already retired — and, worse, would make D4's read-log lie
           about whether anything still depends on them. Reads still fall back
           while the rows exist; only the write is gone. */
        'whatsapp_message', 'whatsapp_order_template', 'whatsapp_status_template',
        'whatsapp_chatbot_prompt',
        /* WA-S1: what happens after checkout. Behaviour used to be hardcoded per
           theme and inconsistent — some opened a popup, some navigated. */
        'whatsapp_handoff_mode', 'whatsapp_handoff_target', 'whatsapp_handoff_delay',
        'whatsapp_handoff_route',
    ];

    /** Checkbox flags (0/1). */
    private const FLAG_KEYS = [
        'customer_order_whatsapp', 'customer_status_whatsapp', 'whatsapp_chatbot_enable',
        /* WA-C3: the ordering on/off switch, moved here from the QuickOrder plugin
           page. It belongs beside the provider that decides how the message is sent. */
        'whatsapp_ordering_enable',
    ];

    public function index(Store $store)
    {
        $this->authorizeOwner($store);

        $store = $store;
        $postOptions = store_options($store->id);
        $providers = WhatsAppProviderFactory::available();

        // Which providers already have a BYOK credential (for the "configured" badge).
        $byok = Credential::where('store_id', $store->id)
            ->where('feature', 'whatsapp')
            ->pluck('active', 'provider')
            ->toArray();

        // C1: can inbound webhooks for this store actually be authenticated?
        $waSignature = [];
        foreach (['meta' => 'app_secret', 'twilio' => 'auth_token'] as $prov => $field) {
            $cred = Credential::where('store_id', $store->id)
                ->where('feature', 'whatsapp')->where('provider', $prov)->where('active', true)->first();
            $waSignature[$prov] = [
                'has_secret' => (bool) ($cred && ! empty($cred->secrets[$field])),
                'routed' => WhatsAppRoute::where('provider', $prov)
                    ->where('store_id', $store->id)->exists(),
            ];
        }

        return view('owner.whatsapp.settings', compact('store', 'postOptions', 'providers', 'byok', 'waSignature'));
    }

    public function update(Request $request, Store $store)
    {
        $this->authorizeOwner($store);

        $request->validate([
            'whatsapp_provider' => ['nullable', 'string', 'max:20'],
            'whatsapp_number_mode' => ['nullable', 'in:platform,own'],
            // WA-S1
            'whatsapp_handoff_mode' => ['nullable', 'in:auto,button,none'],
            'whatsapp_handoff_target' => ['nullable', 'in:same,new'],
            'whatsapp_handoff_delay' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'whatsapp_handoff_route' => ['nullable', 'in:branch,fixed'],
        ]);

        foreach (self::OPTION_KEYS as $key) {
            StoreOption::updateStoreOption($store->id, $key, (string) $request->get($key, ''));
        }
        foreach (self::FLAG_KEYS as $key) {
            StoreOption::updateStoreOption($store->id, $key, $request->boolean($key) ? '1' : '0');
        }

        $this->saveByok($request, $store);

        app_alert_success(___('Updated Successfully'));

        return back();
    }

    /**
     * WA-D3: dismiss the "place your extra WhatsApp numbers" notice.
     *
     * Persisted as a post option rather than localStorage so it sticks across
     * devices — an owner who dismissed it on a laptop should not meet it again on
     * their phone. Idempotent: dismissing twice is harmless.
     */
    public function dismissExtrasNotice(Store $store)
    {
        $this->authorizeOwner($store);

        StoreOption::updateStoreOption($store->id, 'whatsapp_extras_notice_dismissed', '1');

        return back();
    }

    /**
     * Upsert per-restaurant BYOK secrets. Empty fields leave existing secrets
     * untouched (so we never wipe a saved key by re-saving the form).
     */
    private function saveByok(Request $request, Store $store): void
    {
        $maps = [
            'twilio' => ['sid' => 'twilio_sid', 'auth_token' => 'twilio_token', 'from_number' => 'twilio_from'],
            'meta' => ['access_token' => 'meta_access_token', 'phone_number_id' => 'meta_phone_number_id',
                'app_secret' => 'meta_app_secret', 'webhook_verify_token' => 'meta_webhook_verify_token'],
        ];

        foreach ($maps as $provider => $fields) {
            $incoming = [];
            foreach ($fields as $secretKey => $formKey) {
                if ($request->filled($formKey)) {
                    $incoming[$secretKey] = trim((string) $request->get($formKey));
                }
            }
            if (! $incoming) {
                continue; // nothing entered for this provider
            }

            $cred = Credential::firstOrNew([
                'store_id' => $store->id, 'feature' => 'whatsapp', 'provider' => $provider,
            ]);
            $cred->secrets = array_merge((array) ($cred->secrets ?? []), $incoming);
            $cred->active = true;
            $cred->save();

            /* C1: register this store's sender identity so an inbound webhook can
               be attributed to it BEFORE its signature is checked. `whatsapp_routes`
               had no writer anywhere in the app — the table was empty in production,
               so every inbound message landed with store_id = NULL. Meta routes
               on phone_number_id (what MetaProvider::parseInbound returns as `to`),
               Twilio on the E.164 number. unique(provider, sender_id) means moving a
               number between stores re-points the row rather than duplicating it. */
            $senderId = $provider === 'meta'
                ? ($cred->secrets['phone_number_id'] ?? null)
                : ltrim((string) ($cred->secrets['from_number'] ?? ''), 'whatsapp:');

            if (! empty($senderId)) {
                WhatsAppRoute::updateOrCreate(
                    ['provider' => $provider, 'sender_id' => (string) $senderId],
                    ['store_id' => $store->id]
                );
            }
        }
    }

    private function authorizeOwner(Store $store): void
    {
        abort_unless(auth()->check() && (int) $store->user_id === (int) auth()->id(), 403);
    }
}
