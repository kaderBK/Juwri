{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Inline left-aligned header (avatar + name/sub). Used by store_rows & bento. Reads $profile. --}}
@php $p = is_array($profile ?? null) ? $profile : []; @endphp
<header class="bio-storehead">
    <div class="bio-avatar bio-avatar--sm">
        @if (! empty($p['avatar']))<img src="{{ $p['avatar'] }}" alt="{{ $p['name'] ?? '' }}">@else<span aria-hidden="true">{{ $p['initial'] ?? '◈' }}</span>@endif
    </div>
    <div class="bio-storehead__id">
        @unless (! empty($p['hide_title']))
            @if (! empty($p['name']))<p class="bio-storehead__name">{{ $p['name'] }}@if (! empty($p['verified']))<span class="bio-verified" aria-label="{{ ___('Verified') }}">✓</span>@endif</p>@endif
        @endunless
        @if (! empty($p['sub']))<p class="bio-storehead__sub">{{ $p['sub'] }}</p>@endif
    </div>
</header>
@if (! empty($p['tagline']))<p class="bio-storehead__bio">{{ $p['tagline'] }}</p>@endif
