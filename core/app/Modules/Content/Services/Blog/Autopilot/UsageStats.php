<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Blog\Autopilot;

use Juwri\Modules\Ai\Models\AiUsage;
use Illuminate\Support\Carbon;

/**
 * Read-only AI-usage rollup for the Blog Autopilot admin tab. Every autopilot
 * AI call (topics, outline, sections, cleanup, hero image, translation) meters
 * as feature='blog_autopilot' in ai_usage, so this aggregates that slice into
 * calls + tokens per time window plus a provider breakdown.
 *
 * ai_usage records TOKENS, not billed cost, so the dollar figure is an explicit
 * estimate derived from config('blog_autopilot.cost_per_1k_tokens') and is null
 * (hidden) unless the owner sets a rate.
 */
class UsageStats
{
    /** The feature tag every autopilot call meters under (AutopilotWriter::FEATURE). */
    protected const FEATURE = 'blog_autopilot';

    /**
     * @return array{
     *   windows: array<string, array{calls:int, prompt:int, completion:int, tokens:int, cost:float|null}>,
     *   by_provider: list<array{provider:string, calls:int, tokens:int}>,
     *   rate: float|null,
     *   last_at: string|null,
     * }
     */
    public function platform(): array
    {
        $rate = $this->rate();

        $windows = [
            'today' => $this->window($this->now()->copy()->startOfDay(), $rate),
            'week' => $this->window($this->now()->copy()->subDays(7), $rate),
            'month' => $this->window($this->now()->copy()->startOfMonth(), $rate),
            'all' => $this->window(null, $rate),
        ];

        return [
            'windows' => $windows,
            'by_provider' => $this->byProvider($this->now()->copy()->startOfMonth()),
            'rate' => $rate,
            'last_at' => $this->lastAt(),
        ];
    }

    /**
     * Aggregate one window. $since null = all time.
     *
     * @return array{calls:int, prompt:int, completion:int, tokens:int, cost:float|null}
     */
    protected function window(?Carbon $since, ?float $rate): array
    {
        $q = AiUsage::where('feature', self::FEATURE);
        if ($since !== null) {
            $q->where('created_at', '>=', $since);
        }

        $row = $q->selectRaw('COUNT(*) as calls')
            ->selectRaw('COALESCE(SUM(prompt_tokens), 0) as prompt')
            ->selectRaw('COALESCE(SUM(completion_tokens), 0) as completion')
            ->first();

        $prompt = (int) ($row->prompt ?? 0);
        $completion = (int) ($row->completion ?? 0);
        $tokens = $prompt + $completion;

        return [
            'calls' => (int) ($row->calls ?? 0),
            'prompt' => $prompt,
            'completion' => $completion,
            'tokens' => $tokens,
            'cost' => $rate !== null ? round($tokens / 1000 * $rate, 2) : null,
        ];
    }

    /**
     * Provider split for the given window (this month by default).
     *
     * @return list<array{provider:string, calls:int, tokens:int}>
     */
    protected function byProvider(Carbon $since): array
    {
        return AiUsage::where('feature', self::FEATURE)
            ->where('created_at', '>=', $since)
            ->selectRaw('provider')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('COALESCE(SUM(prompt_tokens + completion_tokens), 0) as tokens')
            ->groupBy('provider')
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($r) => [
                'provider' => (string) $r->getAttribute('provider'),
                'calls' => (int) $r->getAttribute('calls'),
                'tokens' => (int) $r->getAttribute('tokens'),
            ])
            ->all();
    }

    protected function lastAt(): ?string
    {
        $last = AiUsage::where('feature', self::FEATURE)->max('created_at');

        return $last !== null ? (string) $last : null;
    }

    protected function rate(): ?float
    {
        $rate = config('blog_autopilot.cost_per_1k_tokens');

        return ($rate === null || ! is_numeric($rate) || (float) $rate <= 0) ? null : (float) $rate;
    }

    /** Overridable clock seam (tests pin the wall clock). */
    protected function now(): Carbon
    {
        return Carbon::now();
    }
}
