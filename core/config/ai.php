<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [

    /*
    |--------------------------------------------------------------------------
    | Default provider + fallback chain
    |--------------------------------------------------------------------------
    | Providers are tried left-to-right. If the default fails (timeout, 5xx,
    | rate-limit, or malformed JSON after a retry), the next one is attempted.
    | Owner BYOK keys (Credential vault, feature='ai') override the platform
    | env keys per restaurant.
    */
    'default'  => env('AI_DEFAULT', 'mimo'),
    'fallback' => array_values(array_filter(array_map('trim', explode(
        ',', env('AI_FALLBACK', 'kimi,deepseek,openai')
    )))),

    /*
    | Providers that can accept image input (vision). JSON/strict-extraction
    | tasks that include an image are routed to the first available one.
    */
    'vision'   => array_values(array_filter(array_map('trim', explode(
        ',', env('AI_VISION', 'openai,mimo,kimi')
    )))),

    /*
    | Providers that can GENERATE images (OpenAI-compatible /images/generations).
    | Tried left-to-right; the first configured + capable one serves the request.
    | FEATURES-5 Track B4.
    */
    'image'    => array_values(array_filter(array_map('trim', explode(
        ',', env('AI_IMAGE', 'openai,gemini')
    )))),

    /*
    | Providers that can TRANSCRIBE audio (OpenAI-compatible
    | /audio/transcriptions). Tried left-to-right; the first configured +
    | capable one serves the request. Groq first: whisper-large-v3-turbo is the
    | cheapest hosted Whisper (~$0.04/hr, generous free tier).
    | ZERO-TOUCH Track B.
    */
    'transcribe' => array_values(array_filter(array_map('trim', explode(
        ',', env('AI_TRANSCRIBE', 'groq,openai')
    )))),

    // Per-call hard timeout (seconds) and retry attempts on a single provider.
    'timeout'  => (int) env('AI_TIMEOUT', 60),

    // P6-68b: provider base URLs stored in the credential vault must be public https hosts (SSRF guard). Set
    // AI_ALLOW_PRIVATE_BASE_URL=true ONLY to let the platform-admin row point at a private/LAN host (e.g. a
    // self-hosted LLM). Merchant and reseller base URLs are always held to the public-host rule.
    'allow_private_base_url' => (bool) env('AI_ALLOW_PRIVATE_BASE_URL', false),
    'retries'  => (int) env('AI_RETRIES', 1),

    /*
    |--------------------------------------------------------------------------
    | Monthly usage cap (plan gate fallback)
    |--------------------------------------------------------------------------
    | 0 = unlimited. Per-plan overrides can be wired later; this is the global
    | safety ceiling counted from the ai_usage table.
    */
    'monthly_call_limit' => (int) env('AI_MONTHLY_CALL_LIMIT', 0),

    /*
    |--------------------------------------------------------------------------
    | No-training providers only (P1-23)
    |--------------------------------------------------------------------------
    | The Subscription Terms (5.3) and the DPA (11.2) promise that juwri Cloud
    | only sends data to AI providers whose commercial terms exclude training on
    | it. With this switch ON, a provider whose `no_training` flag is false is
    | skipped whenever the PLATFORM key would be used (text, vision, image and
    | transcription chains, and the Menu Studio image model). A store's or a
    | reseller's own key (BYOK) is their own contract with the provider and is
    | not filtered. OFF by default so a self-hosted install keeps every
    | configured provider; juwri Cloud sets AI_REQUIRE_NO_TRAINING=true.
    |
    | `no_training` per provider = "our contract/plan with this provider
    | excludes training on API data". Defaults: true for the providers whose
    | standard API terms say so (OpenAI API, Anthropic API, Groq); false where
    | it depends on the plan (Gemini: only the paid tier) or is not established
    | (MiMo, Kimi, DeepSeek). The owner flips a flag only after checking the
    | provider's current terms for the account in use.
    */
    'require_no_training' => (bool) env('AI_REQUIRE_NO_TRAINING', false),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | driver: 'openai' = OpenAI-compatible chat/completions (MiMo, Kimi,
    | DeepSeek, OpenAI all use this). 'anthropic' = Messages API.
    | Keys read from env here are the PLATFORM defaults; BYOK overrides come
    | from the Credential vault at runtime.
    */
    'providers' => [

        // Self-hosted: AI through juwri's hosted AI credits proxy (hosted.gate:ai), paid with the installation's juwri
        // AI credits; no key here. AI_DEFAULT=juwri_hosted after `php artisan juwri:services:connect`.
        'juwri_hosted' => [
            'driver' => 'juwri_hosted',
            'enabled' => (bool) env('AI_JUWRI_HOSTED', false),
        ],

        'mimo' => [
            'no_training' => (bool) env('MIMO_NO_TRAINING', false),
            'driver'   => 'openai',
            'base_url' => env('MIMO_BASE_URL', 'https://api.xiaomimimo.com/v1'),
            'api_key'  => env('MIMO_API_KEY'),
            'model'    => env('MIMO_MODEL', 'mimo-v2.5-pro'),
            // Curated BYOK dropdown suggestions (owners may still free-text any
            // model the provider accepts). Order = recommended first.
            'allowed_models' => [
                'mimo-v2.5-pro',
                'mimo-v2.5',
                'mimo-v2',
            ],
            'supports_json'   => true,
            'supports_vision' => (bool) env('MIMO_VISION', false),
        ],

        'kimi' => [
            'no_training' => (bool) env('KIMI_NO_TRAINING', false),
            'driver'   => 'openai',
            'base_url' => env('KIMI_BASE_URL', 'https://api.moonshot.ai/v1'),
            'api_key'  => env('KIMI_API_KEY'),
            'model'    => env('KIMI_MODEL', 'kimi-k2.5'),
            'allowed_models' => [
                'kimi-k2.5',
                'kimi-k2',
                'moonshot-v1-128k',
                'moonshot-v1-32k',
                'moonshot-v1-8k',
            ],
            // kimi-k2.5 rejects any temperature other than 1.
            'temperature'     => env('KIMI_TEMPERATURE', 1),
            // Thinking model: reasoning_content consumes completion tokens before
            // the answer, so guarantee headroom or `content` comes back empty.
            'min_output_tokens' => (int) env('KIMI_MIN_OUTPUT_TOKENS', 1024),
            'supports_json'   => true,
            'supports_vision' => (bool) env('KIMI_VISION', false),
        ],

        'deepseek' => [
            'no_training' => (bool) env('DEEPSEEK_NO_TRAINING', false),
            'driver'   => 'openai',
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            'api_key'  => env('DEEPSEEK_API_KEY'),
            // deepseek-chat/reasoner deprecate 2026-07-24 -> v4-flash/pro.
            'model'    => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'allowed_models' => [
                'deepseek-chat',
                'deepseek-reasoner',
                'deepseek-v4-flash',
                'deepseek-v4-pro',
            ],
            'supports_json'   => true,
            'supports_vision' => false,
        ],

        'openai' => [
            'no_training' => (bool) env('OPENAI_NO_TRAINING', true),
            'driver'   => 'openai',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key'  => env('OPENAI_API_KEY'),
            'model'    => env('OPENAI_MODEL', 'gpt-4o'),
            'allowed_models' => [
                'gpt-4o',
                'gpt-4o-mini',
                'gpt-4.1',
                'gpt-4.1-mini',
                'o4-mini',
            ],
            'supports_json'   => true,
            'supports_vision' => true,
            // FEATURES-5 Track B4: image GENERATION via /v1/images/generations.
            'supports_image_generation' => (bool) env('OPENAI_IMAGE_GEN', true),
            'image_model'               => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
            // ZERO-TOUCH Track B: speech-to-text via /v1/audio/transcriptions.
            'supports_transcription' => (bool) env('OPENAI_TRANSCRIBE', true),
            'transcription_model'    => env('OPENAI_TRANSCRIBE_MODEL', 'whisper-1'),
        ],

        // ZERO-TOUCH Track B: Groq — OpenAI-compatible endpoint; primary
        // speech-to-text provider (whisper-large-v3-turbo). Chat-capable too
        // (fast Llama serving) so it slots into any chain once a key exists.
        // BYOK: per-restaurant Credential(feature='ai', provider='groq').
        'groq' => [
            'no_training' => (bool) env('GROQ_NO_TRAINING', true),
            'driver'   => 'openai',
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'api_key'  => env('GROQ_API_KEY'),
            'model'    => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'allowed_models' => [
                'llama-3.3-70b-versatile',
                'llama-3.1-8b-instant',
                'openai/gpt-oss-120b',
            ],
            'supports_json'   => true,
            'supports_vision' => false,
            'supports_transcription' => (bool) env('GROQ_TRANSCRIBE', true),
            'transcription_model'    => env('GROQ_TRANSCRIBE_MODEL', 'whisper-large-v3-turbo'),
        ],

        // Google Gemini via its OpenAI-compatible endpoint. Vision-capable, so it
        // handles photo/handwritten menu extraction. Reuses GEMINI_API_KEY (same
        // key as the studio image model). BYOK: a per-restaurant Credential(provider='gemini').
        'gemini' => [
            'no_training' => (bool) env('GEMINI_NO_TRAINING', false),
            'driver'   => 'openai',
            'base_url' => env('GEMINI_TEXT_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
            'api_key'  => env('GEMINI_API_KEY'),
            'model'    => env('GEMINI_TEXT_MODEL', 'gemini-2.5-flash'),
            'allowed_models' => [
                'gemini-2.5-flash',
                'gemini-2.5-pro',
                'gemini-2.0-flash',
                'gemini-1.5-pro',
            ],
            'supports_json'   => true,
            'supports_vision' => true,
        ],

        'anthropic' => [
            'no_training' => (bool) env('ANTHROPIC_NO_TRAINING', true),
            'driver'   => 'anthropic',
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'api_key'  => env('ANTHROPIC_API_KEY'),
            'model'    => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
            'version'  => env('ANTHROPIC_VERSION', '2023-06-01'),
            'allowed_models' => [
                'claude-sonnet-4-6',
                'claude-opus-4-6',
                'claude-haiku-4-6',
                'claude-3-5-sonnet-latest',
            ],
            'supports_json'   => true,
            'supports_vision' => true,
        ],
    ],
];
