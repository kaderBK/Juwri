{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- delivery block: grouped ordering/aggregator buttons (bio-t15 A5, SPEC §2).
     config: {apps:[{name,url,icon?}], layout:'list'|'grid'} — same "Name | URL"
     config_pairs textarea idiom as the legacy `delivery_apps` block, kept as a
     separate type (see LEDGER "Contract changes"). A known app name gets a
     built-in tinted-monogram glyph; an unknown one gets a generic bag glyph —
     no external brand logos (CSP: no external hosts but Google Fonts). --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $apps = is_array($cfg['apps'] ?? null) ? $cfg['apps'] : [];
    $apps = \Juwri\Core\Support\ViewHelpers::namedApps($apps);
    $label = trim((string) ($b['label'] ?? $cfg['label'] ?? '')) ?: ___('Order online');
    $layout = ($cfg['layout'] ?? '') === 'grid' ? 'grid' : 'list';
    $id = $b['id'] ?? '';

    /** Known aggregators → a built-in tint (SPEC: "known names get a built-in brand glyph"). */
    $known = [
        'ubereats' => '#06C167', 'uber eats' => '#06C167',
        'doordash' => '#FF3008',
        'deliveroo' => '#00CCBC',
        'talabat' => '#FF5A00',
        'hungerstation' => '#FF6600',
        'jahez' => '#6A1B9A',
        'zomato' => '#E23744',
        'grubhub' => '#F63440',
    ];
@endphp
@if (! empty($apps))
    <div class="bio-delivery bio-delivery--{{ $layout }}" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        @if ($label !== '')<p class="bio-kicker">{{ $label }}</p>@endif
        <div class="bio-delivery__items">
            @foreach ($apps as $a)
                @php
                    $name = trim((string) $a['name']);
                    $href = trim((string) ($a['url'] ?? '#')) ?: '#';
                    $slug = strtolower(trim((string) $name));
                    $tint = $known[$slug] ?? null;
                    $initial = mb_strtoupper(mb_substr($name, 0, 1));
                @endphp
                <a class="bio-delivery__item" href="{{ external_url($href) }}" target="_blank" rel="noopener">
                    {{-- gap-fix: darken the brand tint (keeps the hue, lifts the white
                         monogram to a WCAG-AA contrast — bright brands like Deliveroo/
                         Talabat fail flat white-on-brand). Matches the unknown-app glyph. --}}
                    <span class="bio-delivery__glyph" @if ($tint) style="background:{{ $tint }};background:color-mix(in srgb, {{ $tint }} 68%, #000)" @endif>
                        {{ $tint ? $initial : '' }}
                        @unless ($tint)
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2 4 6v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6l-2-4H6zm2.5 2h7l1 2h-9l1-2zM6 8h12v10H6V8z"/></svg>
                        @endunless
                    </span>
                    <span class="bio-delivery__name">{{ $name }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
