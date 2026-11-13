{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- freshly — desktop-only storefront footer. Sits after .frs-scroll inside a
     .frs-screen (a flex column whose scroll is flex:1), so on a short screen the
     scroll fills the viewport and this footer pins to the bottom — giving the
     sparse desktop screens a finished edge instead of a raw whitespace void.
     Hidden on mobile (the fixed tabbar already anchors the bottom there). --}}
<footer class="frs-footer" aria-label="{{ $store->title }}">
    <div class="frs-footer-brand">{{ $store->title }}</div>
    <div class="frs-footer-tag">{{ $T['trustLine'] }}</div>
    @if(!empty($frsHoursToday))
        <div class="frs-footer-meta">{{ $frsIsOpen ? ___('Open now') : ___('Closed') }} · {{ $frsHoursToday }}</div>
    @endif
</footer>
