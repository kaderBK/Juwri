---
title: "AI provider layer"
audience: internal
group: Internal
updated: 2026-07-25
---

# AI provider layer

Engineering reference for the provider-agnostic AI layer in
`core/app/Services/Ai/`. One interface fronts every model, so the same feature
runs on MiMo, Kimi, DeepSeek, OpenAI, Gemini or Anthropic. Owner keys (BYOK)
override the platform's env keys per restaurant. Trust the code over the spec:
`rev/features/103-ai-suite-multi-provider.md` describes the intent, but several
of its sub-tasks (analytics copilot, diner recommendations, image generation
inside the suite) are not part of this layer as built.

## Files

- `AiService.php` — the orchestrator (`complete()` free-text, `json()` strict).
- `AiProviderFactory.php` — builds the ordered fallback chain, applies BYOK.
- `AiProviderInterface.php` — the driver contract.
- `Drivers/OpenAiCompatibleProvider.php` — any `/chat/completions` endpoint.
- `Drivers/AnthropicProvider.php` — the Messages API.
- `AiResult.php`, `AiProviderException.php`, `AiLimitExceededException.php`.
- `config/ai.php` — chain order, provider config, timeouts, plan gate.

Consumers live in `MenuGeneratorService`, `TranslatorService` /
`MenuTranslationService`, `Services/Agents/`, `Services/WhatsApp/` and
`Services/Studio/`. See [services map](services-map.md).

## The fallback chain

`config('ai.default')` is tried first, then each of `config('ai.fallback')`
left to right. Defaults (from `config/ai.php`):

- default: `mimo` (`AI_DEFAULT`)
- fallback: `kimi,deepseek,openai` (`AI_FALLBACK`)

`AiProviderFactory::chain()` merges default + fallback, de-dupes, and drops any
provider whose key is not configured (`isConfigured()` is false). So the
effective order is `mimo → kimi → deepseek → openai`, skipping any without a
key. A provider advances to the next on: connection error/timeout, HTTP 429,
HTTP 5xx, HTTP 4xx (not retried on the same provider but the chain still
advances), empty content, or — for `json()` — malformed/invalid JSON after the
retry. If the whole chain is empty, `AiProviderException('No AI provider is
configured.')` is thrown.

Retry vs fall back: `AiProviderException::$retryable` distinguishes the two. A
retryable error re-attempts on the same provider up to `config('ai.retries')`
(default 1) before moving on; a non-retryable error advances immediately.

### Vision routing

When any message carries an `image` key, `messagesHaveImage()` flags the call as
needing vision. The factory then prepends `config('ai.vision')`
(`AI_VISION`, default `openai,mimo,kimi`) to the chain and filters out any
provider whose `supports_vision` is false. Menu photo/handwritten extraction
goes through this path. Note that in the shipped config only `openai`, `gemini`
and `anthropic` have `supports_vision => true` by default; `mimo`/`kimi` are
gated behind `MIMO_VISION` / `KIMI_VISION` env flags, so an image call falls to
OpenAI/Gemini unless those are enabled.

## Providers

All of MiMo, Kimi, DeepSeek, OpenAI and Gemini use the single
`OpenAiCompatibleProvider` driver — they differ only in `base_url`, `api_key`
and `model`. Anthropic uses its own driver (system prompt is a top-level field,
no `json_object` flag, `x-api-key` + `anthropic-version` headers).

| Key | Driver | Default model | Notes |
| --- | --- | --- | --- |
| `mimo` | openai | `mimo-v2.5-pro` (`api.xiaomimimo.com/v1`) | platform default |
| `kimi` | openai | `kimi-k2.5` (`api.moonshot.ai/v1`) | temperature pinned to 1; `min_output_tokens` floor 1024 |
| `deepseek` | openai | `deepseek-chat` (`api.deepseek.com`) | chat/reasoner deprecate 2026-07-24 → v4-flash/pro |
| `openai` | openai | `gpt-4o` | vision default on |
| `gemini` | openai | `gemini-2.5-flash` (OpenAI-compat endpoint) | vision; reuses `GEMINI_API_KEY` |
| `anthropic` | anthropic | `claude-sonnet-4-6` | dormant unless `ANTHROPIC_API_KEY` set; not in default chain |

Anthropic and Gemini are configured but not in the default fallback list — they
only take part if added to `AI_DEFAULT`/`AI_FALLBACK`/`AI_VISION` or selected via
BYOK. Read model ids from config, not from this table, since env can override
every value.

### Per-provider quirks encoded in config

- Kimi (`kimi-k2.5`) rejects any temperature other than 1; the driver lets a
  config `temperature` override the caller's value. It is also a thinking model
  whose reasoning consumes completion tokens, so `min_output_tokens` (1024)
  floors `max_tokens` or `content` comes back empty.
- Strict JSON: `OpenAiCompatibleProvider` sends
  `response_format=json_object` only when the provider's `supports_json` is true.
  Anthropic has no such flag, so its driver appends a "reply with a single valid
  JSON object" line to the system prompt instead.

## Calling the layer

`AiService` exposes two entry points; both take
`(..., ?int $userId, ?int $storeId, string $feature)`:

- `complete(array $messages, $options, $userId, $storeId, $feature): string`
  — free text.
- `json(array $messages, $options, ?callable $validator, $userId, $storeId, $feature): array`
  — forces JSON, decodes tolerantly (`decodeJson()` strips ```` ```json ````
  fences and grabs the outermost `{...}`), runs the optional validator, and on
  failure retries once feeding the parse error back to the model before falling
  through.

Messages are OpenAI-style `['role' => ..., 'content' => ...]`; a user message
may carry `'image'` (base64 or data URL). Security rule enforced by convention:
untrusted content (menu text/images) is passed only in user-role messages, never
folded into the system prompt, so injected instructions stay in the data
channel. Callers treat all model output as untrusted and re-validate before
persisting (e.g. `MenuGeneratorService::normalizeDraft`).

## Where AI is used

The `$feature` string is recorded on every `ai_usage` row. Known features:

- `menu_generate` — `MenuGeneratorService` (`fromText`, `fromDescription`,
  `fromImage`). Powers dashboard menu generation and the Studio menu reader
  (`StudioController` calls the same shared service; injection-safe + metered).
- `menu_translate` — `TranslatorService` (chunked string translation), driven by
  `MenuTranslationService::translateRestaurant` via `TranslateMenuJob`.
- `chatbot` — `Services/Agents/AgentChatService` (agentic ordering / storefront
  chat), resolved from the container so tests can swap a scripted fake.
- `whatsapp_chatbot` — `Services/WhatsApp/WhatsAppChatbot::reply`.
- `studio_template_fit` — `Services/Studio/StudioFitService` (trims long
  descriptions to fit a PDF template; has a deterministic fallback clamp for when
  the model skips trims).
- `general` — default when a caller passes no feature.

Studio image generation is separate. `Services/Studio/GeminiImageProvider`
("Nano Banana", `gemini-*-image`) hits Gemini's `generateContent` image endpoint
directly and does not go through the chat provider interface or the fallback
chain — it reads `config('studio.gemini.*')`, not `config('ai.*')`.

## Key management and BYOK

Two layers:

1. Platform keys from env (`MIMO_API_KEY`, `KIMI_API_KEY`, `DEEPSEEK_API_KEY`,
   `OPENAI_API_KEY`, `GEMINI_API_KEY`, `ANTHROPIC_API_KEY`) wired in
   `config/ai.php`.
2. Per-restaurant BYOK overrides from the `Credential` vault
   (`feature = 'ai'`, `provider = 'mimo'|'kimi'|'openai'|...`), stored in the
   `encrypted:array` `secrets` column as `{api_key, model?, base_url?}`.

`AiProviderFactory::applyByok()` looks up the active `Credential` row for that
restaurant + provider and merges its non-empty `api_key`/`model`/`base_url` over
the env config. Only the matching provider's own row overrides it. Owners manage
these under the dashboard Integrations page: routes `settings.integrations`
(view), `settings.integrations.update` (save) and `settings.integrations.test`
(test connection) in `User/SettingsController`; the dashboard UI is
`resources/views/templates/old-theme/user/integrations.blade.php` (MiMo, Kimi,
DeepSeek, OpenAI, Gemini, Anthropic cards). A parallel admin page
(`Admin/SettingsController`) manages platform-level credentials (rows with a null
`store_id`). `testIntegration` runs a live key check only for `openai`,
`anthropic` and `gemini` (plus `twilio` for SMS and `s3` for storage); the MiMo,
Kimi and DeepSeek cards render a Test button but hit no branch, so it returns the
generic "Connection failed" — their keys are validated only at real call time. See
[entitlements](entitlements.md) for plan gating context.

## Metering and plan gate

Every successful call writes an `ai_usage` row via `AiService::meter()`
(`provider`, `model`, `feature`, `prompt_tokens`, `completion_tokens`,
`fell_back`, `user_id`). Metering is wrapped in try/catch — a metering failure is
logged but never breaks the feature.

`assertWithinLimit()` enforces `config('ai.monthly_call_limit')`
(`AI_MONTHLY_CALL_LIMIT`, 0 = unlimited): if the user's `ai_usage` count since
`startOfMonth()` reaches the limit, it throws `AiLimitExceededException`, which
controllers surface as a friendly upgrade message. This is a global count-based
ceiling; per-plan overrides are noted in config as a future wiring point, not yet
built. BYOK does not currently bypass the monthly count — the spec calls for it
but the code counts all `ai_usage` rows regardless of key ownership.

## Known key issues and gotchas

- MiMo key returning 401: the platform MiMo key has been observed 401-ing; the
  chain falls back to Kimi/DeepSeek/OpenAI, so features keep working but silently
  off the intended default. Check `[AI] provider failed, falling back` warnings.
- CA-bundle: outbound HTTPS to provider APIs can fail cert verification depending
  on the local PHP CA bundle; a misconfigured bundle surfaces as connection
  errors (retryable) that walk the whole chain.
- Kimi empty content: if `max_tokens` is set below the `min_output_tokens` floor,
  the thinking budget starves the answer and `content` returns empty (treated as
  retryable). The config floor guards this; do not pass a smaller cap.
- DeepSeek model deprecation: `deepseek-chat`/`reasoner` deprecate 2026-07-24 in
  favour of v4-flash/pro; update `DEEPSEEK_MODEL` before then.
- Vision misroute: with `MIMO_VISION`/`KIMI_VISION` off (the default), any image
  call requires OpenAI or Gemini to have a key, or the vision chain is empty and
  the call fails with "No AI provider is configured."
- Gemini shares `GEMINI_API_KEY` with the Studio image model — rotating it
  affects both the text provider and Nano Banana.
