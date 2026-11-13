<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Controllers;

use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Contracts\AgentPolicyStore;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;
use Juwri\Modules\Ordering\Models\OrderVoiceNote;

/**
 * ZERO-TOUCH Track B2 — storefront speech-to-text for the agent chat widget
 * (Track A's server-STT fallback when the Web Speech API is unavailable).
 *
 * Contract (seam locked in rev/zero-touch/KICKOFF.md): POST {store}/agent/stt
 * with a multipart `audio` blob (webm/mp4/m4a/ogg/wav, <=60s, <=2MB) ->
 * {"text": "..."} or 422 {"error": "..."}. No auth (storefront customer
 * session); abuse controls = restaurant scoping + per-session throttle +
 * AiSpendGuard caps + the same plan/toggle gate as the chat widget itself.
 */
class AgentSttController extends Controller
{
    /** Accepted upload mimes: the voice-note list + wav (contract superset). */
    public const ALLOWED_MIMES = [
        ...OrderVoiceNote::ALLOWED_MIMES,
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
    ];

    public function transcribe(Request $request, $store)
    {
        $store = $this->resolveRestaurant($request, $store);

        // Gate: same as AgentChatController — per-restaurant widget toggle +
        // the owner's ai_chatbot plan flag. STT exists only for the widget.
        $owner = User::find($store->user_id);
        $enabled = app(AgentPolicyStore::class)->chatEnabled((int) $store->id);
        abort_unless($enabled && $owner && plan_feature('ai_chatbot', $owner), 403, 'Chat is not available here.');

        $file = $request->file('audio');
        if (! $file || ! $file->isValid()) {
            return response()->json(['error' => ___('No audio was received.')], 422);
        }

        // Size cap (2 MB — matches the voice-note recorder + provider guard).
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > OrderVoiceNote::MAX_BYTES) {
            return response()->json(['error' => ___('The recording is too large.')], 422);
        }

        // Mime allowlist (server-side; never trust the client label alone).
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $baseMime = strtolower(trim(explode(';', $mime)[0]));
        if (! in_array($baseMime, self::ALLOWED_MIMES, true)) {
            return response()->json(['error' => ___('That audio format is not supported.')], 422);
        }

        // Duration cap (client-reported, clamped defensively — the size cap is
        // the hard backstop).
        $duration = (int) round((float) $request->input('duration', 0));
        if ($duration < 0) {
            $duration = 0;
        }
        if ($duration > OrderVoiceNote::MAX_SECONDS + 2) {
            return response()->json(['error' => ___('The recording is too long.')], 422);
        }

        try {
            $result = app(Ai::class)->transcribe(
                $file->getRealPath(),
                array_filter([
                    'filename' => $this->uploadName($baseMime, $file->getClientOriginalName()),
                    'mime' => $baseMime,
                    'language' => $this->languageHint($request),
                    'duration_seconds' => $duration ?: null,
                ]),
                userId: (int) $store->user_id,
                storeId: (int) $store->id,
                feature: 'agent_stt',
            );
        } catch (AiLimitExceeded $e) {
            return response()->json(['error' => ___('Voice input is temporarily unavailable. Please type instead.')], 429);
        } catch (AiProviderFailure $e) {
            // No configured provider OR every provider failed — the widget
            // degrades to typing either way. Never leak provider internals.
            return response()->json(['error' => ___('Could not transcribe the audio. Please type instead.')], 422);
        }

        return response()->json(['text' => $result->text]);
    }

    /**
     * Resolve the {store} segment (id or slug) exactly like the
     * voice-note upload endpoint does — implicit binding may hand us a string.
     */
    protected function resolveRestaurant(Request $request, $store): Store
    {
        if ($store instanceof Store && $store->exists) {
            return $store;
        }

        $param = $request->route('store');
        if ($param instanceof Store && $param->exists) {
            return $param;
        }

        return Store::query()
            ->idOrSlug($param)
            ->firstOrFail();
    }

    /**
     * A stable upload filename whose EXTENSION matches the detected mime —
     * the provider layer validates by extension, and MediaRecorder blobs
     * arrive as nameless "blob" parts.
     */
    protected function uploadName(string $baseMime, ?string $clientName): string
    {
        $ext = match ($baseMime) {
            'audio/webm', 'video/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac',
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            default => 'webm',
        };

        return 'speech.'.$ext;
    }

    /** Optional ISO-639-1 hint from the widget (better Whisper accuracy). */
    protected function languageHint(Request $request): ?string
    {
        $lang = strtolower(trim((string) $request->input('language', '')));

        return preg_match('/^[a-z]{2}$/', $lang) ? $lang : null;
    }
}
