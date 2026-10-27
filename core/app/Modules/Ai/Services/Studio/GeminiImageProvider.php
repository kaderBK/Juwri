<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services\Studio;

use Illuminate\Support\Facades\Http;
use Juwri\Modules\Ai\Services\AiProviderException;

/**
 * Nano Banana — Google Gemini image model (config-driven model id, decision D1).
 * Generates/edits a styled menu image from a text prompt + optional reference
 * images. Standalone (image modality) rather than the chat AiProvider interface.
 */
class GeminiImageProvider implements \Juwri\Modules\Ai\Contracts\ImageGenerator
{
    public function isConfigured(): bool
    {
        // P1-23: the platform Gemini key only when its plan excludes training (paid tier) or the switch is off.
        return !empty(config('studio.gemini.api_key'))
            && (! config('ai.require_no_training') || (bool) config('studio.gemini.no_training', false));
    }

    public function model(): string
    {
        return (string) config('studio.gemini.model', 'gemini-3.1-flash-lite-image');
    }

    /**
     * @param  array<int,string>  $refImagesB64  raw base64 (no data: prefix)
     * @return string  raw generated image bytes
     *
     * @throws AiProviderException
     */
    public function generate(string $prompt, array $refImagesB64 = []): string
    {
        if (!$this->isConfigured()) {
            throw new AiProviderException('Gemini image key not configured.');
        }

        $base = rtrim((string) config('studio.gemini.base_url'), '/');
        $url  = $base.'/models/'.$this->model().':generateContent';

        $parts = [['text' => $prompt]];
        foreach ($refImagesB64 as $b64) {
            $parts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $b64]];
        }

        try {
            $resp = Http::withHeaders(['x-goog-api-key' => config('studio.gemini.api_key')])
                ->acceptJson()
                ->post($url, ['contents' => [['parts' => $parts]]]);
        } catch (\Throwable $e) {
            throw new AiProviderException('Gemini image request failed: '.$e->getMessage());
        }

        if (!$resp->successful()) {
            throw new AiProviderException('Gemini image HTTP '.$resp->status());
        }

        $json = $resp->json();
        // The image comes back as inline base64 on one of the response parts.
        foreach ((array) data_get($json, 'candidates.0.content.parts', []) as $part) {
            $b64 = $part['inline_data']['data'] ?? $part['inlineData']['data'] ?? null;
            if ($b64) {
                $bytes = base64_decode($b64, true);
                if ($bytes !== false) {
                    return $bytes;
                }
            }
        }

        throw new AiProviderException('Gemini returned no image.');
    }
}
