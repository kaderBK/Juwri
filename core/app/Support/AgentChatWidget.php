<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Store;
use Juwri\Modules\Agents\Contracts\AgentPolicyStore;
use Juwri\Modules\Ai\Contracts\AiProviderChain;

/**
 * ZERO-TOUCH Track A — storefront agent-chat widget gate + theme data seam.
 *
 * One choke point for "may this restaurant show the chat widget?", shared by
 * the Blade partial (13 modal themes) and the window.<THEME>.agentChat data
 * seam (4 JS-runtime themes: dough/picnic/crumb/scoop) so they can never
 * drift apart. The widget is pure UI — the enforcement point remains the
 * chat endpoint gate in AgentChatController@message (option + plan feature).
 */
class AgentChatWidget
{
    /** Owner enabled the widget AND the plan carries the ai_chatbot feature. */
    public static function enabled(?Store $store): bool
    {
        if (! $store) {
            return false;
        }
        if (! app(AgentPolicyStore::class)->chatEnabled((int) $store->id)) {
            return false;
        }
        $owner = $store->user;

        return $owner && plan_feature('ai_chatbot', $owner);
    }

    /**
     * A↔B seam: the MediaRecorder→server STT fallback lights up only when the
     * Track B endpoint exists in this build (`Route::has('agent.stt')`), so
     * Track A ships before/without Track B and upgrades automatically after
     * its merge. Contract: multipart `audio` (webm/mp4/m4a/ogg, ≤60s, ≤2MB)
     * → `{"text": "..."}`.
     */
    public static function sttEndpoint(Store $store): ?string
    {
        if (! Route::has('agent.stt')) {
            return null;
        }

        /* The route existing is not the same as transcription WORKING. Without a
           configured provider chain the endpoint 422s, and on a browser with no
           Web Speech API (iOS Safari, Android WebViews) the mic is the only path
           — so it would record the user's voice, upload it, and fail. Check the
           chain here so the mic simply never appears instead. The call is
           network-free and BYOK-aware via $storeId. */
        $chain = app(AiProviderChain::class)->transcribeChain($store->id);

        return empty($chain) ? null : route('agent.stt', $store->id);
    }

    /**
     * Data seam for JS-runtime themes that build their UI in an external
     * script.js: they read window.<THEME>.agentChat and render their own
     * launcher. `['enabled' => false]` when the widget must not appear.
     */
    public static function seam(?Store $store): array
    {
        if (! self::enabled($store)) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'endpoint' => route('agent.chat.message', $store->id),
            'sttEndpoint' => self::sttEndpoint($store),
            'csrf' => csrf_token(),
            'locale' => str_replace('_', '-', (string) app()->getLocale()),
            'label' => (string) ((store_options($store->id)->agent_chat_welcome ?? '') ?: ___('Chat & order')),
            'strings' => self::strings(),
        ];
    }

    /** i18n strings shared by the Blade widget and the JS-runtime seam. */
    public static function strings(): array
    {
        return [
            'title' => ___('Ordering assistant'),
            'greeting' => ___('Hi! Ask about the menu or tell me your order.'),
            'placeholder' => ___('Type a message…'),
            'send' => ___('Send Message'),
            'close' => ___('Close chat'),
            'typing' => ___('Assistant is typing…'),
            'connection' => ___('Connection problem — please try again.'),
            'unavailable' => ___('Chat is unavailable right now.'),
            'busy' => ___('Too many messages — please wait a moment.'),
            'orderPlaced' => ___('Order placed!'),
            'total' => ___('Total'),
            'trackOrder' => ___('Track your order'),
            // C→A seam: shown only when the endpoint returns a non-null
            // eta_range (Track C). JS substitutes :range.
            'eta' => ___('Estimated ready in :range'),
            'micStart' => ___('Use voice input'),
            'micStop' => ___('Stop recording'),
            'listening' => ___('Listening…'),
            'recording' => ___('Recording…'),
            'transcribing' => ___('Transcribing…'),
            'micDenied' => ___('Microphone access was denied — you can type instead.'),
            'micFailed' => ___('Voice input is unavailable — please type instead.'),
            'sttFailed' => ___('Could not transcribe audio — please type instead.'),
            'tooLarge' => ___('Recording is too large — try a shorter message.'),
        ];
    }
}
