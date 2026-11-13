{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Centered profile header. Reads $profile (resolved in bio.layout). --}}
@php $p = is_array($profile ?? null) ? $profile : []; @endphp
<header class="bio-profile">
    <div class="bio-avatar">
        @if (! empty($p['avatar']))<img src="{{ $p['avatar'] }}" alt="{{ $p['name'] ?? '' }}" loading="eager">@else<span aria-hidden="true">{{ $p['initial'] ?? '◈' }}</span>@endif
    </div>
    @unless (! empty($p['hide_title']))
        @if (! empty($p['name']))<h1 class="bio-name">{{ $p['name'] }}@if (! empty($p['verified']))<span class="bio-verified" aria-label="{{ ___('Verified') }}">✓</span>@endif</h1>@endif
    @endunless
    @if (! empty($p['tagline']))<p class="bio-tagline">{{ $p['tagline'] }}</p>@endif
    @if (! empty($p['status']))<span class="bio-status"><i aria-hidden="true"></i>{{ $p['status'] }}</span>@endif
</header>
