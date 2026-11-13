{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ai-badge> — AI transparency badge.

  Renders a compact line telling the owner WHICH provider+model will run and
  WHOSE key pays for it, using AiProviderFactory::describeChain() (the exact
  same resolution as the live chain — no duplicated provider logic here).

    "⚡ MiMo · mimo-v2.5-pro   [Your key]"

  The primary (first) provider in the resolved chain is shown inline; the full
  fallback chain is exposed via the native `title` tooltip. Owner UI kit
  classes only (ui-cluster, ui-tag, ui-text-2 …) — no Bootstrap.

  ATTRIBUTES
    store-id   restaurant/post id for BYOK resolution        (optional)
    needs-vision    resolve the vision-capable chain instead       (default: false)
    chain           pre-computed describeChain() array, to skip
                    re-resolution (optional; overrides store-id)
    label           short lead-in text before the badge            (optional)
    class           extra classes on the wrapper                   (optional)

  Empty chain (no provider configured) renders a muted "AI not configured" hint.
--}}
@props([
    'storeId' => null,
    'needsVision' => false,
    'chain' => null,
    'label' => null,
])
@php
    /** @var array<int,array{provider:string,model:?string,key_source:string}> $resolved */
    $resolved = is_array($chain)
        ? $chain
        : app(\Juwri\Modules\Ai\Services\AiProviderFactory::class)
            ->describeChain($storeId ? (int) $storeId : null, (bool) $needsVision);

    // provider key -> human display name (config carries no display name).
    $names = [
        'mimo'      => 'MiMo',
        'kimi'      => 'Kimi',
        'deepseek'  => 'DeepSeek',
        'openai'    => 'OpenAI',
        'gemini'    => 'Gemini',
        'anthropic' => 'Claude',
    ];
    // key_source -> owner-facing label.
    $sourceLabels = [
        'platform' => ___('Platform key'),
        'byok'     => ___('Your key'),
        'reseller' => ___('Reseller key'),
    ];
    // key_source -> kit tag tone.
    $sourceTones = [
        'platform' => 'neutral',
        'byok'     => 'success',
        'reseller' => 'accent',
    ];

    $primary = $resolved[0] ?? null;
    $providerName = $primary ? ($names[$primary['provider']] ?? ucfirst($primary['provider'])) : null;
    $sourceKey = $primary['key_source'] ?? 'platform';

    // Fallback chain for the tooltip.
    $tooltip = '';
    if (count($resolved) > 1) {
        $parts = [];
        foreach ($resolved as $i => $p) {
            $n = $names[$p['provider']] ?? ucfirst($p['provider']);
            $s = $sourceLabels[$p['key_source']] ?? $p['key_source'];
            $parts[] = ($i === 0 ? '' : '↳ ') . $n . ($p['model'] ? ' · ' . $p['model'] : '') . ' (' . $s . ')';
        }
        $tooltip = ___('Runs on the first available provider:') . "\n" . implode("\n", $parts);
    }
@endphp

@if($primary)
    <span {{ $attributes->merge(['class' => 'ai-badge ui-inline ui-cluster ui-small', 'style' => 'gap: var(--ui-space-2)']) }}
          @if($tooltip) title="{{ $tooltip }}" @endif>
        @if($label)
            <span class="ui-text-2">{{ $label }}</span>
        @endif
        <span class="ui-inline ui-cluster ui-text-2" style="gap: 4px">
            <span aria-hidden="true">⚡</span>
            <span>{{ ___('Runs on') }}</span>
            <span class="ui-strong" style="color: var(--ui-ink)">{{ $providerName }}</span>
            @if($primary['model'])
                <span>· {{ $primary['model'] }}</span>
            @endif
        </span>
        <span class="ui-tag" data-tone="{{ $sourceTones[$sourceKey] ?? 'neutral' }}">
            {{ $sourceLabels[$sourceKey] ?? $sourceKey }}
        </span>
    </span>
@else
    <span {{ $attributes->merge(['class' => 'ai-badge ui-inline ui-cluster ui-small ui-text-2', 'style' => 'gap: 4px']) }}>
        <span aria-hidden="true">⚡</span>
        <span>{{ ___('AI not configured yet') }}</span>
    </span>
@endif
