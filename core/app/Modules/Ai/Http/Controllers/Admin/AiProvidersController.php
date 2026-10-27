<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Ai\Services\AiProviderFactory;

/**
 * features-run-4 · A5 — Superadmin platform AI keys + models.
 *
 * Manages the PLATFORM-level Credential rows (store_id NULL, feature='ai',
 * provider=<key>) that AiProviderFactory::applyByok() consults as the layer
 * between reseller BYOK and the env/config default. Setting a key here overrides
 * the matching env value without a redeploy; clearing it falls back to env.
 */
class AiProvidersController extends Controller
{
    /**
     * Curated, ordered provider list surfaced in the panel. Only providers that
     * exist in config('ai.providers') are shown.
     *
     * @return array<string,string>
     */
    protected function providers(): array
    {
        $labels = [
            'mimo' => 'MiMo (Xiaomi)',
            'kimi' => 'Kimi (Moonshot)',
            'deepseek' => 'DeepSeek',
            'openai' => 'OpenAI (GPT)',
            'anthropic' => 'Anthropic (Claude)',
            'gemini' => 'Google Gemini',
            // ZERO-TOUCH Track B: primary speech-to-text provider (whisper-large-v3-turbo).
            'groq' => 'Groq',
        ];

        return array_intersect_key($labels, (array) config('ai.providers', []));
    }

    public function index(Request $request, AiProviderFactory $factory)
    {
        $credentials = Credential::whereNull('store_id')
            ->where('feature', 'ai')
            ->get()
            ->keyBy('provider');

        $providers = [];
        foreach ($this->providers() as $key => $label) {
            $cfg = (array) config("ai.providers.$key", []);
            $cred = $credentials->get($key);
            $providers[$key] = [
                'label' => $label,
                'label_hint' => $key,
                'env_model' => $cfg['model'] ?? null,
                'allowed_models' => $factory->allowedModels($key),
                'saved_model' => is_array($cred?->secrets) ? ($cred->secrets['model'] ?? '') : '',
                'has_key' => is_array($cred?->secrets) && ! empty($cred->secrets['api_key']),
                'active' => $cred ? (bool) $cred->active : true,
                // ZERO-TOUCH Track B4: voice (STT) capability + per-key model override.
                'supports_transcription' => (bool) ($cfg['supports_transcription'] ?? false),
                'env_transcription_model' => $cfg['transcription_model'] ?? null,
                'saved_transcription_model' => is_array($cred?->secrets) ? ($cred->secrets['transcription_model'] ?? '') : '',
            ];
        }

        return view('admin.ai-providers.index', [
            'providers' => $providers,
        ]);
    }

    public function update(Request $request)
    {
        $provider = (string) $request->input('provider');
        if (! array_key_exists($provider, $this->providers())) {
            abort(404);
        }

        $incoming = (array) $request->input('secrets', []);
        $active = $request->boolean('active', true);

        $existing = Credential::whereNull('store_id')
            ->where('feature', 'ai')
            ->where('provider', $provider)
            ->first();

        $existingSecrets = is_array($existing?->secrets) ? $existing->secrets : [];

        $secrets = $existingSecrets;

        // API key: a masked placeholder means "unchanged"; empty means "clear".
        if ($request->has('secrets.api_key')) {
            $apiKey = (string) ($incoming['api_key'] ?? '');
            if (str_contains($apiKey, '••••')) {
                // keep existing
            } elseif ($apiKey === '') {
                unset($secrets['api_key']);
            } else {
                $secrets['api_key'] = trim($apiKey);
            }
        }

        // Model override (free-text/select). Empty clears it -> env default.
        if ($request->has('secrets.model')) {
            $model = trim((string) ($incoming['model'] ?? ''));
            if ($model === '') {
                unset($secrets['model']);
            } else {
                $secrets['model'] = $model;
            }
        }

        // ZERO-TOUCH Track B4: transcription (STT) model override — same
        // semantics as the chat model (empty clears -> env default).
        if ($request->has('secrets.transcription_model')) {
            $sttModel = trim((string) ($incoming['transcription_model'] ?? ''));
            if ($sttModel === '') {
                unset($secrets['transcription_model']);
            } else {
                $secrets['transcription_model'] = $sttModel;
            }
        }

        Credential::updateOrCreate(
            [
                'store_id' => null,
                'feature' => 'ai',
                'provider' => $provider,
            ],
            [
                'secrets' => $secrets,
                'active' => $active,
            ]
        );

        app_alert_success(___('Platform AI provider updated successfully'));

        return back();
    }

    public function test(Request $request)
    {
        $provider = (string) $request->input('provider');
        if (! array_key_exists($provider, $this->providers())) {
            return response()->json(['success' => false, 'message' => ___('Unknown provider')], 200);
        }

        $incoming = (array) $request->input('secrets', []);

        $existing = Credential::whereNull('store_id')
            ->where('feature', 'ai')
            ->where('provider', $provider)
            ->first();
        $existingSecrets = is_array($existing?->secrets) ? $existing->secrets : [];

        $apiKey = (string) ($incoming['api_key'] ?? '');
        if ($apiKey === '' || str_contains($apiKey, '••••')) {
            $apiKey = (string) ($existingSecrets['api_key'] ?? '');
        }

        if ($apiKey === '') {
            return response()->json(['success' => false, 'message' => ___('No API key to test')], 200);
        }

        $cfg = (array) config("ai.providers.$provider", []);
        $model = trim((string) ($incoming['model'] ?? '')) ?: ($existingSecrets['model'] ?? ($cfg['model'] ?? ''));
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? ''), '/');

        try {
            if (($cfg['driver'] ?? 'openai') === 'anthropic') {
                $resp = Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => $cfg['version'] ?? '2023-06-01',
                    'content-type' => 'application/json',
                ])->post($baseUrl.'/messages', [
                    'model' => $model ?: 'claude-3-haiku-20240307',
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                    'max_tokens' => 5,
                ]);
            } else {
                $resp = Http::withToken($apiKey)->post($baseUrl.'/chat/completions', [
                    'model' => $model ?: 'gpt-3.5-turbo',
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                    'max_tokens' => 5,
                ]);
            }

            if ($resp->successful()) {
                return response()->json(['success' => true, 'message' => ___('Connection Successful!')], 200);
            }

            $message = $resp->json('error.message') ?? ___('Connection Failed');

            return response()->json(['success' => false, 'message' => $message], 200);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 200);
        }
    }
}
