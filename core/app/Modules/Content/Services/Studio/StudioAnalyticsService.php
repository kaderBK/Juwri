<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Studio;

use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Modules\Content\Models\StudioSession;

/**
 * SI11 (#75): studio funnel analytics — where guests drop off, from the session
 * status ladder (exploring -> onboarding -> extracted -> designed/published).
 */
class StudioAnalyticsService
{
    /**
     * @return array{total_sessions:int, by_status: array<string,int>, reached_extract:int, converted:int, conversion_rate:float}
     */
    public function funnel(): array
    {
        $byStatus = StudioSession::selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->toArray();

        $total = array_sum($byStatus);
        $published = $byStatus['published'] ?? 0;
        $reachedExtract = ($byStatus['extracted'] ?? 0)
            + ($byStatus['designed'] ?? 0)
            + $published;

        return [
            'total_sessions'  => $total,
            'by_status'       => $byStatus,
            'reached_extract' => $reachedExtract,
            'converted'       => $published,
            'conversion_rate' => $total > 0 ? round($published / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * SI15 (#98): provider + fallback health visibility. Shows which providers
     * serve the studio and how often the extraction layer fell back.
     *
     * @return array{extraction_default:?string, extraction_fallbacks:array, image_provider_configured:bool, image_model:?string, ai_calls:int, fell_back:int, fallback_rate:float}
     */
    public function health(): array
    {
        $calls = AiUsage::count();
        $fellBack = AiUsage::where('fell_back', true)->count();

        return [
            'extraction_default'        => config('ai.default'),
            'extraction_fallbacks'      => (array) config('ai.fallback', []),
            'image_provider_configured' => !empty(config('studio.gemini.api_key')),
            'image_model'               => config('studio.gemini.model'),
            'ai_calls'                  => $calls,
            'fell_back'                 => $fellBack,
            'fallback_rate'             => $calls > 0 ? round($fellBack / $calls * 100, 1) : 0.0,
        ];
    }
}
