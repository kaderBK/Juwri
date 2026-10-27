{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Hero header: full-width cover media then an overlapping id. Used by photo_hero. Reads $profile. --}}
@php $p = is_array($profile ?? null) ? $profile : []; @endphp
<div class="bio-hero" @if (! empty($p['cover'])) style="background-image:linear-gradient(180deg,transparent 30%,var(--bg,#101014) 97%),url('{{ addslashes($p['cover']) }}')" @endif>
    @if (empty($p['cover']))<svg class="hero-ill bio-hero__ill" viewBox="0 0 120 120" aria-hidden="true"><use href="#ill-platter"/></svg>@endif
</div>
<header class="bio-heroid">
    <div class="bio-avatar bio-avatar--hero">
        @if (! empty($p['avatar']))<img src="{{ $p['avatar'] }}" alt="{{ $p['name'] ?? '' }}">@else<span aria-hidden="true">{{ $p['initial'] ?? '◈' }}</span>@endif
    </div>
    @unless (! empty($p['hide_title']))
        @if (! empty($p['name']))<h1 class="bio-name">{{ $p['name'] }}@if (! empty($p['verified']))<span class="bio-verified" aria-label="{{ ___('Verified') }}">✓</span>@endif</h1>@endif
    @endunless
    @if (! empty($p['tagline']))<p class="bio-tagline">{{ $p['tagline'] }}</p>@endif
</header>
