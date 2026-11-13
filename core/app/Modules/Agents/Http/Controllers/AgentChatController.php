<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Controllers;

use App\Services\Agents\AgentChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Contracts\AgentPolicyStore;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\AgentMessage;
use Juwri\Modules\Agents\Services\Agents\PiiRedactor;

/**
 * Agents P2: public chat endpoint for the on-menu widget. Gated by the restaurant's
 * `agent_chat_enabled` option + the owner's `ai_chatbot` plan flag. Session-scoped;
 * AI usage is metered inside AiService (feature 'chatbot').
 */
class AgentChatController extends Controller
{
    public function message(Request $request, int $storeId)
    {
        $store = Store::findOrFail($storeId);

        // Gate: per-restaurant toggle + plan flag.
        $owner = User::find($store->user_id);
        $enabled = app(AgentPolicyStore::class)->chatEnabled((int) $store->id);
        abort_unless($enabled && $owner && plan_feature('ai_chatbot', $owner), 403, 'Chat is not available here.');

        $data = $request->validate([
            'message' => 'required|string|max:1000',
            'session_key' => 'nullable|string|max:64',
        ]);

        $sessionKey = ($data['session_key'] ?? null) ?: (string) Str::uuid();
        $conversation = AgentConversation::firstOrCreate(
            ['store_id' => $store->id, 'channel' => 'web', 'session_key' => $sessionKey],
            ['locale' => app()->getLocale()]
        );

        // AO5 #30: store a PII-redacted copy; process the live turn with the original.
        $userMsg = AgentMessage::create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => PiiRedactor::redact($data['message'])]);

        $reply = AgentChatService::handle($conversation, $store, $data['message']);

        AgentMessage::create(['conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => $reply]);

        // ZERO-TOUCH A1.3: surface this turn's successful create_order tool result
        // as a structured payload so the widget can render a confirm card with a
        // track link (and Track C's nullable eta_range when present). Read from
        // the tool log — the agent loop and its guardrails stay untouched.
        $order = $this->orderPayloadFromTurn($conversation->id, $userMsg->id);
        if ($order && $conversation->order_id === null) {
            // Also links the conversation to its order (AO1 conversion analytics
            // + owner transcript dashboard) — was never set on the web channel.
            $conversation->forceFill(['order_id' => $order['order_id']])->save();
        }

        $conversation->forceFill(['last_activity_at' => now()])->save();

        return response()->json(['reply' => $reply, 'session_key' => $sessionKey, 'order' => $order]);
    }

    /** Latest successful create_order logged after this turn's user message, or null. */
    private function orderPayloadFromTurn(int $conversationId, int $sinceMessageId): ?array
    {
        $toolMsgs = AgentMessage::where('conversation_id', $conversationId)
            ->where('role', 'tool')
            ->where('id', '>', $sinceMessageId)
            ->orderByDesc('id')
            ->get();

        foreach ($toolMsgs as $msg) {
            if ((string) ($msg->tool_calls['tool'] ?? '') !== 'create_order') {
                continue;
            }
            $result = (array) (json_decode((string) $msg->content, true)['result'] ?? []);
            if (empty($result['ok'])) {
                continue;
            }

            return [
                'order_id' => $result['order_id'] ?? null,
                'total' => $result['total'] ?? null,
                'track_token' => $result['track_token'] ?? null,
                'continue_url' => $result['continue_url'] ?? null,
                // Human tracking PAGE for the widget link; continue_url stays the
                // API JSON contract used by MCP/manifest consumers.
                'track_url' => isset($result['track_token'])
                    ? route('store.orderTrack', $result['track_token'])
                    : null,
                // C→A seam: createOrder gains a nullable eta_range when Track C
                // lands; the widget renders it only when non-null.
                'eta_range' => $result['eta_range'] ?? null,
            ];
        }

        return null;
    }
}
