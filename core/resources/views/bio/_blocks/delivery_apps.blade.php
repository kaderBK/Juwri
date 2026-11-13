{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- delivery_apps block: row of delivery-app buttons. config: {apps:[{name,url}]} --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $apps = is_array($cfg['apps'] ?? null) ? $cfg['apps'] : [];
    $apps = \Juwri\Core\Support\ViewHelpers::namedApps($apps);
    $label = trim((string) ($b['label'] ?? ''));
    $id = $b['id'] ?? '';
@endphp
@if (! empty($apps))
    <div class="bio-block--apps" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        @if ($label !== '')<p class="bio-kicker">{{ $label }}</p>@endif
        <div class="bio-apps">
            @foreach ($apps as $a)
                <a href="{{ trim((string) ($a['url'] ?? '#')) ?: '#' }}" target="_blank" rel="noopener">{{ trim((string) $a['name']) }}</a>
            @endforeach
        </div>
    </div>
@endif
