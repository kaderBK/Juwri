<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services\Drivers;

use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Ai\Services\AiImageResult;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiProviderInterface;
use Juwri\Modules\Ai\Services\AiResult;
use Juwri\Modules\Ai\Services\TranscriptionResult;
use Illuminate\Support\Facades\Http;

/**
 * Driver for any OpenAI-compatible /chat/completions endpoint.
 * Covers MiMo, Kimi (Moonshot), DeepSeek and OpenAI itself — only the
 * base_url, api_key and model differ (supplied via config).
 */
class OpenAiCompatibleProvider implements AiProviderInterface
{
    public function __construct(protected string $name, protected array $config)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['api_key']);
    }

    public function supportsVision(): bool
    {
        return (bool) ($this->config['supports_vision'] ?? false);
    }

    public function chat(array $messages, array $options = []): AiResult
    {
        if (!$this->isConfigured()) {
            throw new AiProviderException("Provider [{$this->name}] has no API key configured.", $this->name, false);
        }

        // A provider may pin temperature (e.g. kimi-k2.5 only allows 1); that
        // fixed value wins over any caller/default value.
        $temperature = $this->config['temperature'] ?? $options['temperature'] ?? 0.2;

        // Floor the token budget for thinking models whose reasoning would
        // otherwise starve the visible answer.
        $maxTokens = (int) ($options['max_tokens'] ?? 0);
        $floor     = (int) ($this->config['min_output_tokens'] ?? 0);
        if ($floor > 0) {
            $maxTokens = max($maxTokens, $floor);
        }

        // Request strict JSON where the provider supports it.
        $providerOptions = (!empty($options['json']) && ($this->config['supports_json'] ?? false))
            ? ['response_format' => ['type' => 'json_object']]
            : [];

        // P1-15: the HTTP call runs through laravel/ai's OpenAI-compatible provider.
        return LaravelAiChat::run(
            $this->name,
            LaravelAiChat::OPENAI_COMPATIBLE,
            ['url' => $this->config['base_url'], 'key' => $this->config['api_key'], 'model' => $this->config['model']],
            $messages,
            $this->supportsVision(),
            null,
            $maxTokens > 0 ? $maxTokens : null,
            is_numeric($temperature) ? (float) $temperature : null,
            $providerOptions,
        );
    }

    public function supportsImageGeneration(): bool
    {
        return (bool) ($this->config['supports_image_generation'] ?? false)
            && !empty($this->config['api_key']);
    }

    public function generateImage(string $prompt, array $options = []): AiImageResult
    {
        if (!$this->supportsImageGeneration()) {
            throw new AiProviderException("Provider [{$this->name}] cannot generate images.", $this->name, false);
        }

        $model = $options['model'] ?? $this->config['image_model'] ?? 'gpt-image-1';
        $size  = $options['size'] ?? '1024x1024';

        $payload = [
            'model'  => $model,
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => $size,
        ];

        $base = rtrim($this->config['base_url'], '/');

        try {
            $response = Http::timeout((int) config('ai.timeout', 60))
                ->withToken($this->config['api_key'])
                ->acceptJson()
                ->asJson()
                ->post($base . '/images/generations', $payload);
        } catch (\Throwable $e) {
            throw new AiProviderException("Provider [{$this->name}] image connection error: {$e->getMessage()}", $this->name, true, $e);
        }

        if ($response->failed()) {
            $status    = $response->status();
            $retryable = $status === 429 || $status >= 500;
            throw new AiProviderException(
                "Provider [{$this->name}] image HTTP {$status}: " . $response->body(),
                $this->name,
                $retryable
            );
        }

        $data = $response->json();

        // OpenAI images API returns either b64_json or a url in data[0].
        $b64 = data_get($data, 'data.0.b64_json');
        if (is_string($b64) && $b64 !== '') {
            $bytes = base64_decode($b64, true);
            if ($bytes === false || $bytes === '') {
                throw new AiProviderException("Provider [{$this->name}] returned undecodable image data.", $this->name, true);
            }
            return new AiImageResult($bytes, 'image/png', $this->name, (string) $model);
        }

        $url = data_get($data, 'data.0.url');
        if (is_string($url) && $url !== '') {
            [$bytes, $mime] = $this->fetchProviderImage($url);
            return new AiImageResult($bytes, $mime, $this->name, (string) $model);
        }

        throw new AiProviderException("Provider [{$this->name}] returned no image.", $this->name, true);
    }

    /** P6-68: cap for a provider-returned image URL download. */
    public const IMAGE_FETCH_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * P6-68: the image URL comes from the provider response, so treat it as
     * untrusted: https + public host only, pinned to the vetted IP (DNS
     * rebinding), every redirect hop re-vetted, short timeout, size cap and an
     * image/* content-type requirement.
     *
     * @return array{0: string, 1: string} [bytes, mime]
     */
    private function fetchProviderImage(string $url): array
    {
        $guard = SsrfGuard::pinnedOptions($url);
        if ($guard === null) {
            throw new AiProviderException("Provider [{$this->name}] returned an image URL that is not a public https address.", $this->name, false);
        }
        $max = self::IMAGE_FETCH_MAX_BYTES;
        $guard['on_headers'] = function ($response) use ($max): void {
            if ((int) $response->getHeaderLine('Content-Length') > $max) {
                throw new \RuntimeException('image too large');
            }
        };
        $guard['progress'] = function ($total, $downloaded) use ($max): void {
            if ($downloaded > $max) {
                throw new \RuntimeException('image too large');
            }
        };

        try {
            $img = Http::timeout(min(30, (int) config('ai.timeout', 60)))->connectTimeout(5)->withOptions($guard)->get($url);
        } catch (\Throwable $e) {
            throw new AiProviderException("Provider [{$this->name}] image fetch error: {$e->getMessage()}", $this->name, true, $e);
        }
        $body = $img->body();
        if ($img->failed() || $body === '') {
            throw new AiProviderException("Provider [{$this->name}] could not fetch generated image.", $this->name, true);
        }
        if (strlen($body) > $max) {
            throw new AiProviderException("Provider [{$this->name}] generated image exceeds the size cap.", $this->name, false);
        }
        $mime = strtolower(trim(explode(';', (string) $img->header('Content-Type'))[0]));
        if (! str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            throw new AiProviderException("Provider [{$this->name}] image URL did not return an image.", $this->name, false);
        }

        return [$body, $mime];
    }

    /* ---------------------------------------------------------------------
     | ZERO-TOUCH Track B — speech-to-text (OpenAI-compatible
     | /audio/transcriptions; Groq whisper-large-v3-turbo / OpenAI whisper-1).
     * -------------------------------------------------------------------- */

    /** Max audio upload accepted by the driver (mirrors OrderVoiceNote::MAX_BYTES). */
    public const TRANSCRIBE_MAX_BYTES = 2 * 1024 * 1024;

    /** Audio container/extension allowlist (webm/opus posts directly, no conversion). */
    public const TRANSCRIBE_EXTENSIONS = ['webm', 'mp4', 'm4a', 'ogg', 'wav', 'mp3', 'aac', 'opus'];

    public function supportsTranscription(): bool
    {
        return (bool) ($this->config['supports_transcription'] ?? false)
            && !empty($this->config['api_key']);
    }

    public function transcribe(string $audioPath, array $options = []): TranscriptionResult
    {
        if (!$this->supportsTranscription()) {
            throw new AiProviderException("Provider [{$this->name}] cannot transcribe audio.", $this->name, false);
        }

        // Defence in depth: size + format guards also run here so a direct
        // driver call can never post an oversized/foreign blob upstream.
        self::assertTranscribableFile($audioPath, $options, $this->name);

        $model    = $options['model'] ?? $this->config['transcription_model'] ?? 'whisper-1';
        $filename = (string) ($options['filename'] ?? basename($audioPath));

        $bytes = @file_get_contents($audioPath);
        if ($bytes === false || $bytes === '') {
            throw new AiProviderException("Provider [{$this->name}] could not read the audio file.", $this->name, false);
        }

        $fields = [
            'model' => (string) $model,
            // verbose_json carries language + duration (both OpenAI and Groq).
            'response_format' => 'verbose_json',
        ];
        if (!empty($options['language'])) {
            $fields['language'] = (string) $options['language'];
        }

        $base = rtrim($this->config['base_url'], '/');

        try {
            $response = Http::timeout((int) config('ai.timeout', 60))
                ->withToken($this->config['api_key'])
                ->acceptJson()
                ->attach('file', $bytes, $filename)
                ->post($base . '/audio/transcriptions', $fields);
        } catch (\Throwable $e) {
            throw new AiProviderException("Provider [{$this->name}] transcription connection error: {$e->getMessage()}", $this->name, true, $e);
        }

        if ($response->failed()) {
            $status    = $response->status();
            $retryable = $status === 429 || $status >= 500;
            throw new AiProviderException(
                "Provider [{$this->name}] transcription HTTP {$status}: " . $response->body(),
                $this->name,
                $retryable
            );
        }

        $data = $response->json();
        $text = data_get($data, 'text');

        if (!is_string($text)) {
            throw new AiProviderException("Provider [{$this->name}] returned no transcription.", $this->name, true);
        }

        $duration = data_get($data, 'duration');
        $language = data_get($data, 'language');

        return new TranscriptionResult(
            text: trim($text),
            provider: $this->name,
            model: (string) $model,
            language: is_string($language) && $language !== '' ? $language : null,
            durationSeconds: is_numeric($duration) ? (float) $duration : null,
        );
    }

    /**
     * Shared pre-flight validation for audio uploads (size cap + extension
     * allowlist). AiService::transcribe() calls this once before walking the
     * provider chain so a bad file fails fast with a NON-retryable error.
     *
     * @throws AiProviderException
     */
    public static function assertTranscribableFile(string $audioPath, array $options = [], string $providerName = 'transcribe'): void
    {
        if (!is_file($audioPath)) {
            throw new AiProviderException("Provider [{$providerName}] audio file not found.", $providerName, false);
        }

        $size = (int) @filesize($audioPath);
        if ($size <= 0 || $size > self::TRANSCRIBE_MAX_BYTES) {
            throw new AiProviderException("Provider [{$providerName}] audio exceeds the 2 MB transcription cap.", $providerName, false);
        }

        // Extension allowlist — taken from the upload name when supplied (temp
        // uploads have meaningless .tmp paths), else from the path itself.
        $name = (string) ($options['filename'] ?? basename($audioPath));
        $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::TRANSCRIBE_EXTENSIONS, true)) {
            throw new AiProviderException("Provider [{$providerName}] audio format .{$ext} is not supported.", $providerName, false);
        }
    }
}
