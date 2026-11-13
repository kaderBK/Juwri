<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Controllers\User;

use App\Models\StoreOption;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Services\Agents\AgentAnalyticsService;
use Juwri\Modules\Ai\Contracts\AiProviderChain;

/**
 * Owner dashboard: AI ordering (agents) settings — toggle the on-menu chat widget
 * per restaurant, set persona/welcome, and surface the MCP + discovery endpoints.
 */
class AgentSettingsController extends Controller
{
    private function own(int $storeId): Store
    {
        return Store::where('id', $storeId)->where('user_id', auth()->id())->firstOrFail();
    }

    public function page(Request $request)
    {
        $stores = Store::where('user_id', auth()->id())->get(['id', 'title', 'slug']);
        $activeId = (int) ($request->query('store') ?: (dashboard_current_store()->id ?? $stores->first()->id ?? 0)) ?: null;
        $opts = $activeId ? store_options($activeId) : null;
        $activeSlug = $activeId ? (string) optional($stores->firstWhere('id', $activeId))->slug : '';
        if ($activeId) {
            $this->own($activeId);
        }

        // ZERO-TOUCH Track B4: read-only effective voice (STT) state — which
        // provider would transcribe mic input / voice notes for this store
        // (BYOK -> reseller -> platform resolution, no network calls).
        $sttChain = [];
        try {
            $sttChain = app(AiProviderChain::class)->describeTranscribeChain($activeId);
        } catch (\Throwable $e) {
            // effective-state display only — never break the settings page
        }

        return view('owner.ai.agent-settings', compact('stores', 'activeId', 'activeSlug', 'opts', 'sttChain'));
    }

    /** AO1: conversation → order conversion analytics for one owned restaurant. */
    public function analytics(Request $request, int $storeId)
    {
        $this->own($storeId); // 404 if not owned — no cross-tenant reach

        return response()->json(AgentAnalyticsService::conversionSummary(
            $storeId,
            $request->query('from'),
            $request->query('to'),
        ));
    }

    /** AO9 #45: recent agent conversations for one owned restaurant (transcript dashboard). */
    public function transcripts(int $storeId)
    {
        $this->own($storeId); // 404 if not owned — tenant-scoped
        $convos = AgentConversation::where('store_id', $storeId)
            ->withCount('messages')
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        return response()->json(['conversations' => $convos->map(fn ($c) => [
            'id' => $c->id,
            'channel' => $c->channel ?: 'web',
            'messages' => $c->messages_count,
            'order_id' => $c->order_id,
            'handoff' => (bool) (($c->meta['handoff'] ?? false)), // AO5 #29: needs a human
            'started_at' => optional($c->created_at)->toIso8601String(),
            'last_at' => optional($c->last_activity_at)->toIso8601String(),
        ])->values()]);
    }

    /** AO9 #45: full transcript of one conversation, guarded by post ownership. */
    public function transcript(int $storeId, int $conversationId)
    {
        $this->own($storeId);
        $convo = AgentConversation::where('id', $conversationId)
            ->where('store_id', $storeId)     // conversation must belong to this owned restaurant
            ->firstOrFail();

        $messages = $convo->messages()->orderBy('id')->get();

        return response()->json([
            'id' => $convo->id,
            'channel' => $convo->channel ?: 'web',
            'order_id' => $convo->order_id,
            'messages' => $messages->map(fn ($m) => [
                'role' => $m->role,
                'content' => (string) $m->content,
                'has_tool' => ! empty($m->tool_calls),
                'at' => optional($m->created_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    public function save(Request $request, int $storeId)
    {
        $this->own($storeId);
        $data = $request->validate([
            'agent_chat_enabled' => 'nullable|boolean',
            'agent_chat_welcome' => 'nullable|string|max:120',
            'agent_chat_prompt' => 'nullable|string|max:2000',
        ]);
        StoreOption::updateStoreOption($storeId, 'agent_chat_enabled', ! empty($data['agent_chat_enabled']) ? '1' : '0');
        StoreOption::updateStoreOption($storeId, 'agent_chat_welcome', $data['agent_chat_welcome'] ?? '');
        StoreOption::updateStoreOption($storeId, 'agent_chat_prompt', $data['agent_chat_prompt'] ?? '');

        return back()->with('success', ___('AI ordering settings saved.'));
    }
}
