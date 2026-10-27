<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\WhatsApp;

use App\Services\Agents\AgentChatService;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Messaging\Contracts\WhatsApp;

/**
 * Optional inbound auto-reply. When a restaurant enables the chatbot, an inbound
 * message is answered by the AI provider layer and sent back inside the open
 * 24h window (a free service message). Must never break webhook processing, so
 * every failure is swallowed. See rev/whatsapp/IMPLEMENTATION-SPEC.md §8.
 */
class WhatsAppChatbot
{
    /** Inbound keywords that are handled as opt-in/out, not chatted. */
    protected const CONTROL = ['STOP', 'UNSUBSCRIBE', 'CANCEL', 'QUIT', 'START', 'SUBSCRIBE', 'YES', 'UNSTOP'];

    public function __construct(protected Ai $ai, protected WhatsApp $wa) {}

    public function maybeReply(?int $storeId, string $from, string $body, string $provider): void
    {
        $body = trim($body);
        if (! $storeId || $body === '') {
            return;
        }
        if (in_array(strtoupper($body), self::CONTROL, true)) {
            return; // control keyword, not a conversation
        }
        if (! store_options($storeId, 'whatsapp_chatbot_enable')) {
            return;
        }

        try {
            $reply = $this->generate($storeId, $from, $body);
            if ($reply !== '') {
                // Customer just messaged => window open => free-form service reply.
                $this->wa->send($from, $reply, $storeId, 'general', false);
            }
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] chatbot reply failed: '.$e->getMessage());
        }
    }

    protected function generate(int $storeId, string $from, string $body): string
    {
        // Agents P5: when agent ordering is enabled, use the shared tool loop so the
        // customer can search the menu and place an order over WhatsApp. Falls back
        // to a plain Q&A reply otherwise.
        $agentReply = AgentChatService::forWhatsApp($storeId, $from, $body);
        if ($agentReply !== null) {
            return $agentReply;
        }

        $store = Store::find($storeId);
        $name = $store->title ?? 'the restaurant';
        $ownerId = $store->user_id ?? null;

        $system = store_options($storeId, 'whatsapp_chatbot_prompt')
            ?: "You are a friendly WhatsApp assistant for {$name}. Answer customer questions briefly and helpfully. "
             .'If they want to order, guide them. Keep replies under 3 short sentences.';

        return trim($this->ai->complete(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $body],
            ],
            [],
            $ownerId,
            $storeId,
            'whatsapp_chatbot'
        ));
    }
}
