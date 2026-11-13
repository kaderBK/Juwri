{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- ZERO-TOUCH Track C: prep-time ETA line for order tracking pages.
     Server-renders the current estimate (null → hidden). T2: the line is now
     kept fresh by the shared single poller (_partials.track-poll), which paints
     #prepEtaText/#prepEtaLine from the same JSON as the status timeline — this
     partial no longer runs its own 15s poll (that was a second, redundant
     interval per tab). --}}
@php($prepEta = $prepEta ?? null)
<p class="prep-eta-line" id="prepEtaLine" @if(!$prepEta) hidden @endif dir="auto"
   style="margin:12px auto 0;font-size:13px;font-weight:600;opacity:.8;text-align:center;">
    <span aria-hidden="true">⏱ </span><span id="prepEtaText">{{ $prepEta ? $prepEta->toArray()['line'] : '' }}</span>
</p>
