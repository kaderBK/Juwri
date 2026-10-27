<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Billing\PlanLimits;
use Juwri\Core\Contracts\Billing\PrepaidCredits;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Modules\Ai\Services\Drivers\OpenAiCompatibleProvider;

/**
 * Orchestrates AI calls across the provider fallback chain with:
 *  - JSON tasks: response_format=json_object + validate + retry-with-error + fallback
 *  - usage metering (ai_usage)
 *  - monthly plan gate (AiLimitExceededException)
 *
 * Untrusted content (menu text/images) must be passed as a user-role message —
 * never folded into the system prompt — so injected instructions stay in the
 * data channel. The caller reviews structured output before committing.
 */
class AiService implements Ai
{
    /** Credit packs: assertWithinLimit()'s answer for a store on its own key past the allowance (no credit drawn yet). */
    private const OWN_KEY_PAST_ALLOWANCE = '__own_key_past_allowance';

    /** @var array{user_id: int, store_id: int|null, feature: string}|null the call in progress, own key past the allowance */
    private ?array $ownKeyPastAllowance = null;

    public function __construct(protected AiProviderFactory $factory) {}

    /**
     * Free-text completion across the fallback chain.
     *
     * @param  array  $messages  [['role'=>..., 'content'=>..., 'image'=>?base64]]
     */
    public function complete(array $messages, array $options = [], ?int $userId = null, ?int $storeId = null, string $feature = 'general'): string
    {
        $credit = $this->assertWithinLimit($userId, $feature, $storeId);

        return $this->releasingOnFailure($userId, $storeId, $feature, $credit, function () use ($messages, $options, $userId, $storeId, $feature): string {
            $this->assertAbuseCaps($messages, $userId, $storeId);
            $options = $this->applyMaxTokens($options);

            $needsVision = $this->messagesHaveImage($messages);

            return $this->run($messages, $options, $storeId, $needsVision, $feature, $userId)->text;
        });
    }

    /**
     * JSON completion: forces json_object, decodes, validates with the optional
     * validator, and retries once (echoing the parse error back) before falling
     * through to the next provider.
     *
     * @param  callable|null  $validator  fn(array $decoded): bool — return false to reject
     * @return array decoded JSON
     *
     * @throws AiProviderException when every provider fails or returns unusable JSON
     */
    public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array
    {
        $credit = $this->assertWithinLimit($userId, $feature, $storeId);

        return $this->releasingOnFailure($userId, $storeId, $feature, $credit, fn (): array => $this->jsonWithinLimit($messages, $options, $validator, $userId, $storeId, $feature));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     */
    private function jsonWithinLimit(array $messages, array $options, ?callable $validator, ?int $userId, ?int $storeId, string $feature): array
    {
        $this->assertAbuseCaps($messages, $userId, $storeId);
        $options = $this->applyMaxTokens($options);

        $options['json'] = true;
        $needsVision = $this->messagesHaveImage($messages);
        $chain = $this->factory->chain($storeId, $needsVision);

        if (empty($chain)) {
            throw new AiProviderException('No AI provider is configured.', '', false);
        }

        $lastError = null;
        $fellBack = false;

        foreach ($chain as $provider) {
            $attemptMessages = $messages;

            for ($attempt = 0; $attempt <= (int) config('ai.retries', 1); $attempt++) {
                try {
                    $result = $provider->chat($attemptMessages, $options);
                    $decoded = $this->decodeJson($result->text);

                    if ($decoded === null) {
                        throw new AiProviderException('Malformed JSON from '.$provider->name(), $provider->name(), true);
                    }
                    if ($validator && ! $validator($decoded)) {
                        throw new AiProviderException('JSON failed validation from '.$provider->name(), $provider->name(), true);
                    }

                    // P1-15: fell_back records that an earlier provider failed (it was always false here).
                    $this->meter($result, $feature, $userId, $fellBack);

                    return $decoded;
                } catch (AiProviderException $e) {
                    $lastError = $e;

                    // Retryable + still have attempts left on this provider:
                    // feed the error back so the model self-corrects.
                    if ($e->retryable && $attempt < (int) config('ai.retries', 1)) {
                        $attemptMessages = array_merge($messages, [[
                            'role' => 'user',
                            'content' => 'Your previous reply was not valid JSON for this task ('
                                .$e->getMessage().'). Reply with ONLY a single valid JSON object.',
                        ]]);

                        continue;
                    }
                    break; // advance to next provider
                }
            }

            $fellBack = true;
            Log::warning('[AI] provider failed, falling back', [
                'provider' => $provider->name(),
                'feature' => $feature,
                'error' => $lastError?->getMessage(),
            ]);
        }

        throw $lastError ?? new AiProviderException('All AI providers failed.', '', false);
    }

    /**
     * Generate an image from a prompt across the image-capable chain.
     * Metered like any AI call (1 call = 1 credit) and guarded by the same
     * spend caps (BYOK bypasses the caps but is still metered).
     *
     *
     * @throws AiProviderException when no provider can generate images or all fail.
     */
    public function generateImage(string $prompt, array $options = [], ?int $userId = null, ?int $storeId = null, string $feature = 'image_gen'): AiImageResult
    {
        $credit = $this->assertWithinLimit($userId, $feature, $storeId);

        return $this->releasingOnFailure($userId, $storeId, $feature, $credit, fn (): AiImageResult => $this->generateImageWithinLimit($prompt, $options, $userId, $storeId, $feature));
    }

    /** @param  array<string, mixed>  $options */
    private function generateImageWithinLimit(string $prompt, array $options, ?int $userId, ?int $storeId, string $feature): AiImageResult
    {
        // Reuse the spend guard: no image input, so pass a synthetic user message
        // for the input-length check.
        $this->assertAbuseCaps([['role' => 'user', 'content' => $prompt]], $userId, $storeId);

        $chain = $this->factory->imageChain($storeId);
        if (empty($chain)) {
            throw new AiProviderException('No image-generation provider is configured.', '', false);
        }

        $lastError = null;
        $fellBack = false;

        foreach ($chain as $provider) {
            try {
                $image = $provider->generateImage($prompt, $options);

                // Meter as a single AiUsage row (image calls report no token usage).
                $this->meter(new AiResult(
                    text: '',
                    provider: $image->provider,
                    model: $image->model,
                    promptTokens: 0,
                    completionTokens: 0,
                ), $feature, $userId, $fellBack);

                return $image;
            } catch (AiProviderException $e) {
                $lastError = $e;
                $fellBack = true;
                Log::warning('[AI] image provider failed, falling back', [
                    'provider' => $provider->name(),
                    'feature' => $feature,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastError ?? new AiProviderException('All image providers failed.', '', false);
    }

    /**
     * ZERO-TOUCH Track B: transcribe an audio file across the STT-capable chain
     * (config('ai.transcribe')). Metered like any AI call (1 call = 1 credit)
     * with the audio length recorded as the billing unit (ai_usage.audio_seconds);
     * guarded by the same spend caps (BYOK bypasses caps but is still metered).
     *
     * @param  string  $audioPath  Absolute path to the audio file.
     * @param  array  $options  ['model','language','filename','mime','duration_seconds']
     *
     * @throws AiProviderException when no provider can transcribe or all fail.
     */
    public function transcribe(string $audioPath, array $options = [], ?int $userId = null, ?int $storeId = null, string $feature = 'transcription'): TranscriptionResult
    {
        $credit = $this->assertWithinLimit($userId, $feature, $storeId);

        return $this->releasingOnFailure($userId, $storeId, $feature, $credit, fn (): TranscriptionResult => $this->transcribeWithinLimit($audioPath, $options, $userId, $storeId, $feature));
    }

    /** @param  array<string, mixed>  $options */
    private function transcribeWithinLimit(string $audioPath, array $options, ?int $userId, ?int $storeId, string $feature): TranscriptionResult
    {
        // Reuse the spend guard; no text input, so pass a synthetic user message
        // for the input-length check (mirrors generateImage()).
        $this->assertAbuseCaps([['role' => 'user', 'content' => basename($audioPath)]], $userId, $storeId);

        // Fail fast (non-retryable) on an oversized/foreign file — pointless to
        // walk the chain when every provider would refuse the same blob.
        OpenAiCompatibleProvider::assertTranscribableFile($audioPath, $options);

        $chain = $this->factory->transcribeChain($storeId);
        if (empty($chain)) {
            throw new AiProviderException('No transcription provider is configured.', '', false);
        }

        $lastError = null;
        $fellBack = false;

        foreach ($chain as $provider) {
            try {
                $result = $provider->transcribe($audioPath, $options);

                // Bill by audio seconds: provider-reported duration first,
                // caller-known length as the fallback.
                $seconds = $result->durationSeconds ?? $options['duration_seconds'] ?? null;
                $this->meter(new AiResult(
                    text: '',
                    provider: $result->provider,
                    model: $result->model,
                    promptTokens: 0,
                    completionTokens: 0,
                ), $feature, $userId, $fellBack, is_numeric($seconds) ? (int) ceil((float) $seconds) : null);

                return $result;
            } catch (AiProviderException $e) {
                $lastError = $e;
                $fellBack = true;
                Log::warning('[AI] transcription provider failed, falling back', [
                    'provider' => $provider->name(),
                    'feature' => $feature,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastError ?? new AiProviderException('All transcription providers failed.', '', false);
    }

    /**
     * Run free-text across the chain (no JSON contract).
     */
    protected function run(array $messages, array $options, ?int $storeId, bool $needsVision, string $feature, ?int $userId): AiResult
    {
        $chain = $this->factory->chain($storeId, $needsVision);
        if (empty($chain)) {
            throw new AiProviderException('No AI provider is configured.', '', false);
        }

        $lastError = null;
        $fellBack = false;

        foreach ($chain as $i => $provider) {
            try {
                $result = $provider->chat($messages, $options);
                $this->meter($result, $feature, $userId, $fellBack);

                return $result;
            } catch (AiProviderException $e) {
                $lastError = $e;
                $fellBack = true;
                Log::warning('[AI] provider failed, falling back', [
                    'provider' => $provider->name(),
                    'feature' => $feature,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastError ?? new AiProviderException('All AI providers failed.', '', false);
    }

    /**
     * Tolerant JSON decode: strips ```json fences and trailing prose, returns
     * the first balanced object found. Null when nothing parses.
     */
    public function decodeJson(string $text): ?array
    {
        $text = trim($text);

        // Strip markdown fences.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
            $text = trim((string) $text);
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Last resort: grab the outermost {...}.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function meter(AiResult $result, string $feature, ?int $userId, bool $fellBack, ?int $audioSeconds = null): void
    {
        try {
            $row = [
                'user_id' => $userId,
                'provider' => $result->provider,
                'model' => $result->model,
                'feature' => $feature,
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
                'fell_back' => $fellBack,
            ];
            // ZERO-TOUCH Track B: STT billing unit. Only attached when known so
            // non-audio metering never references the (additive) column.
            if ($audioSeconds !== null) {
                $row['audio_seconds'] = $audioSeconds;
            }
            AiUsage::create($row);
            $this->settleOwnKeyFallback($result);

            // Abuse Guard P7: feed the per-tenant daily usage meter (1 call = 1 credit).
            if ($userId) {
                $resellerId = User::whereKey($userId)->value('reseller_id');
                TenantUsageDaily::bump((int) $userId, 'ai_credits', 1, $resellerId ? (int) $resellerId : null);
            }
        } catch (\Throwable $e) {
            // Metering must never break the feature.
            Log::warning('[AI] usage metering failed: '.$e->getMessage());
        }
    }

    /**
     * Abuse Guard P3: denial-of-wallet caps (per-user/tenant/platform daily +
     * anomaly + input length). BYOK calls bypass the spend caps.
     */
    protected function assertAbuseCaps(array $messages, ?int $userId, ?int $storeId): void
    {
        $isByok = $this->factory->hasByok($storeId);
        AiSpendGuard::assertInputLength($messages, $userId);
        AiSpendGuard::assert($userId, $storeId, $isByok);
    }

    /** Force a default max_tokens ceiling on every provider call (unless caller set one). */
    protected function applyMaxTokens(array $options): array
    {
        $max = AiSpendGuard::maxTokens();
        if ($max > 0 && ! isset($options['max_tokens'])) {
            $options['max_tokens'] = $max;
        }

        return $options;
    }

    /**
     * The monthly plan gate. Credit packs (PRICING.md §8): once the plan's allowance is used up, one prepaid AI credit
     * pays for a call on the platform's key — drawn now, given back by {@see releasingOnFailure()} when the call fails;
     * with none left the call is refused as before. A store on its own key is not gated past the allowance (a platform
     * fallback is settled after the call, {@see settleOwnKeyFallback()}). Returns the usage key of the credit drawn,
     * {@see OWN_KEY_PAST_ALLOWANCE}, or null when the allowance covered it.
     */
    protected function assertWithinLimit(?int $userId, string $feature = 'general', ?int $storeId = null): ?string
    {
        if (! $userId) {
            return null;
        }
        $limit = $this->monthlyCallLimit($userId);
        if ($limit <= 0) {
            return null;
        }

        $used = AiUsage::where('user_id', $userId)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        if ($used < $limit) {
            return null;
        }

        /* Coordinator default (DECISIONS 2026-10-07, owner may override): a store on its OWN AI key (or its reseller's)
           costs the platform nothing, so past the allowance it is neither charged a prepaid credit nor refused — the
           same rule as SMS on the store's own key. Its calls still count toward the allowance above. */
        if ($this->factory->hasByok($storeId)) {
            return self::OWN_KEY_PAST_ALLOWANCE; // the call itself decides: own key free, platform fallback → a credit
        }

        $credits = app(PrepaidCredits::class);
        $kind = $credits->kindFor('ai');
        $usageKey = 'ai:'.Str::uuid()->toString();
        if ($kind !== null && $credits->draw($userId, $kind, 1, $usageKey, $feature)) {
            return $usageKey;
        }

        throw new AiLimitExceededException;
    }

    /**
     * Run the call; when it fails (abuse cap, every provider down), give back the prepaid credit drawn for it.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function releasingOnFailure(?int $userId, ?int $storeId, string $feature, ?string $creditKey, callable $call): mixed
    {
        $outer = $this->ownKeyPastAllowance;
        $this->ownKeyPastAllowance = $creditKey === self::OWN_KEY_PAST_ALLOWANCE && $userId
            ? ['user_id' => $userId, 'store_id' => $storeId, 'feature' => $feature]
            : null;
        try {
            return $call();
        } catch (\Throwable $e) {
            if ($userId && $creditKey !== null && $creditKey !== self::OWN_KEY_PAST_ALLOWANCE) {
                app(PrepaidCredits::class)->release($userId, $creditKey);
            }
            throw $e;
        } finally {
            $this->ownKeyPastAllowance = $outer;
        }
    }

    /**
     * Credit packs (coordinator 2026-10-07): a store on its own key past the allowance whose call was answered by a
     * PLATFORM-key provider (its own key failed and the chain fell back) — the platform paid, so one prepaid AI credit
     * is drawn when there is one. The call has already happened: never refused here; logged when nothing was drawn.
     */
    private function settleOwnKeyFallback(AiResult $result): void
    {
        $ctx = $this->ownKeyPastAllowance;
        if ($ctx === null || $this->factory->keySource($result->provider, $ctx['store_id']) !== 'platform') {
            return;
        }
        $credits = app(PrepaidCredits::class);
        $kind = $credits->kindFor('ai');
        if ($kind === null || ! $credits->draw($ctx['user_id'], $kind, 1, 'ai:fallback:'.Str::uuid()->toString(), $ctx['feature'])) {
            Log::warning('[AI] own-key store past its allowance was served by the platform key; no prepaid credit to draw', [
                'user_id' => $ctx['user_id'], 'store_id' => $ctx['store_id'], 'provider' => $result->provider, 'feature' => $ctx['feature'],
            ]);
        }
    }

    /**
     * E3: the monthly AI-call cap for a user. The plan `ai_monthly_calls` value
     * wins when set (999 sentinel = unlimited → 0 = no gate); otherwise the
     * platform env fallback `ai.monthly_call_limit` (today's behavior). 0 anywhere
     * means "no monthly gate".
     */
    protected function monthlyCallLimit(int $userId): int
    {
        $user = User::find($userId);
        if ($user) {
            $raw = app(PlanLimits::class)->value($user, 'ai_monthly_calls');
            if ($raw !== null && $raw !== '') {
                $n = (int) $raw;

                return $n >= 999 ? 0 : max(0, $n);
            }
        }

        return (int) config('ai.monthly_call_limit', 0);
    }

    protected function messagesHaveImage(array $messages): bool
    {
        return array_any($messages, fn ($m) => ! empty($m['image']));
    }
}
