<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services;


interface AiProviderInterface
{
    /**
     * Provider key (e.g. 'mimo', 'kimi', 'openai').
     */
    public function name(): string;

    /**
     * True when this provider has a usable API key configured.
     */
    public function isConfigured(): bool;

    /**
     * True when this provider can accept image input.
     */
    public function supportsVision(): bool;

    /**
     * Run a chat completion.
     *
     * @param array $messages OpenAI-style [['role'=>'system|user|assistant','content'=>...]].
     *                        A user message MAY carry an 'image' key (base64 data URL or raw
     *                        base64) which vision-capable drivers attach; others ignore it.
     * @param array $options  ['json' => bool, 'max_tokens' => int, 'temperature' => float]
     * @return AiResult
     *
     * @throws \Juwri\Modules\Ai\Services\AiProviderException on transport/HTTP failure.
     */
    public function chat(array $messages, array $options = []): AiResult;

    /**
     * True when this provider can GENERATE images (OpenAI-compatible
     * /images/generations). Guards the FEATURES-5 Track B4 AI-image tab.
     */
    public function supportsImageGeneration(): bool;

    /**
     * True when this provider can transcribe audio (OpenAI-compatible
     * /audio/transcriptions). ZERO-TOUCH Track B (voice input + voice notes).
     */
    public function supportsTranscription(): bool;

    /**
     * Transcribe an audio file (speech-to-text).
     *
     * @param string $audioPath Absolute path to the audio file on disk
     *                          (webm/opus posts to the API directly, no conversion).
     * @param array  $options   ['model' => ?override, 'language' => ?ISO-639-1 hint,
     *                           'filename' => ?upload name, 'mime' => ?client mime,
     *                           'duration_seconds' => ?caller-known length]
     * @return TranscriptionResult text + optional language/duration.
     *
     * @throws \Juwri\Modules\Ai\Services\AiProviderException on transport/HTTP failure or
     *         when the provider cannot transcribe audio.
     */
    public function transcribe(string $audioPath, array $options = []): TranscriptionResult;

    /**
     * Generate an image from a text prompt.
     *
     * @param string $prompt  Natural-language description.
     * @param array  $options ['size' => '1024x1024', 'model' => ?override]
     * @return AiImageResult  raw image bytes + mime + provider/model.
     *
     * @throws \Juwri\Modules\Ai\Services\AiProviderException on transport/HTTP failure or
     *         when the provider cannot generate images.
     */
    public function generateImage(string $prompt, array $options = []): AiImageResult;
}
